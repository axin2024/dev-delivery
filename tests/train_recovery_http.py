"""Apply incompatible production data on the last member of wave two and recover only actual writes."""
import base64
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
import secrets
import subprocess
import time
from urllib.request import Request, urlopen


def node_call(action,payload):
    url=os.environ["DELIVERY_TRAIN_ADAPTER_URL"]+"/"+action
    headers={"Authorization":"Bearer "+os.environ["DELIVERY_TRAIN_ADAPTER_TOKEN"],"Content-Type":"application/json"}
    with urlopen(Request(url,json.dumps(payload).encode(),headers),timeout=10) as response:
        return json.load(response)


def verify(product,release,tester,tester_id,base,stage,baseline,service,digest,incompatible):
    from release_trains_http import instant
    code="forward-fix" if incompatible else "scoped-restore"
    env=release.call(base+"/environments","POST",dict(code=code,name=code,stage="production"),201)["id"]
    access=f"{base}/environments/{env}/train-access"
    product.call(access+"/owner","PUT",dict(environment_rev=0,owner_id=tester_id))
    nodes=[dict(node=f"node-{i:02}",region="lab") for i in range(10)]
    tester.call(access+"/connector","PUT",dict(environment_rev=1,nodes=nodes,enabled=True))
    scope=f"{tester.call('/api/session')['actor']['tenant_id']}:{base.split('/')[-1]}:{env}"
    old={}
    for i,node in enumerate(nodes):
        name=node["node"]
        bundle=dict(protocol=1,name="schema",release=0,add_fields=["old_index"] if i==4 else [],
                    forbids=["billing_code"] if incompatible and i==0 else [])
        raw=json.dumps(bundle).encode()
        old[name]=hashlib.sha256(raw).hexdigest()
        seeded=node_call("apply",dict(scope=scope,node=name,service=service,operation=secrets.token_hex(16),digest=old[name],
                                     expected_digest=None,content_base64=base64.b64encode(raw).decode()))
        assert seeded["probe"]["passed"]
    t=release.call(base+"/release-trains","POST",dict(code=code,title=code,baseline_id=baseline,service_ids=[service],
                                                       staging_id=stage,production_id=env),201)
    train=f"{base}/release-trains/{t['id']}"
    release.call(train+"/freeze","POST",dict(revision=0),201)
    assert tester.call(train+"/rehearse","POST",dict(revision=1,service_id=service,node="stage-1"))["state"]=="passed"
    tester.call(train+"/approve","POST",dict(revision=1,service_id=service,digest=digest,staging_rev=2,production_rev=2,expires_at=instant(45)),201)
    approval=release.call(train+"/window","POST",dict(revision=1,starts_at=instant(-1),ends_at=instant(20)),201)
    board=release.call(train+"/wave-plan","POST",dict(revision=1,min_requests=10,max_error_bps=200,interval_seconds=300,
        waves=[dict(percent=p,regions=["lab"]) for p in (10,40,50)]),201)
    for wave in board["waves"][:2]:
        url=f"{train}/waves/{wave['wave_id']}"
        observed=tester.call(url+"/observations","POST",{},201)
        anchor=datetime.fromisoformat(observed["anchor_at"]).replace(tzinfo=timezone.utc,microsecond=0)
        for delta in (-1,1):
            when=anchor+timedelta(seconds=delta)
            time.sleep(max(0,when.timestamp()-time.time()+0.02))
            tester.call(url+"/samples","POST",dict(sample_key=str(delta),measured_at=when.strftime("%Y-%m-%dT%H:%M:%SZ"),requests=1000,errors=0),201)
        obs=tester.call(url+"/observations","POST",{},201)
        accepted=release.call(url+"/advance","POST",dict(revision=1,observation_id=obs["observation_id"],approval_id=approval["approval_id"]),
                              202,key=f"{code}-{wave['wave_id']}")
        for _ in wave["targets"]:
            result=json.loads(subprocess.check_output(["php","bin/delivery.php","train-step",str(accepted["execution_id"])],timeout=20))
    assert result["state"]=="failed" and result["receipt"]["probe"]["incompatible"]==["old_index"]
    assert tester.call(train+"/resume","POST",dict(reason="Retry"),409)["error"]=="failed_wave_requires_recovery"
    recovery=train+"/recovery"
    request=dict(revision=1,reason="Recover five actually changed nodes after production health probe failed")
    release.call(recovery,"POST",request,403)
    state=tester.call(recovery,"POST",request)
    assert state==tester.call(recovery,"POST",request)
    changed=[item["node"] for item in state["items"]]
    assert changed==[f"node-{i:02}" for i in reversed(range(5))]
    assert tester.call(train+"/resume","POST",dict(reason="Retry"),409)["error"]=="recovery_in_progress"
    for phase in ("inspect","restore"):
        for item in state["items"]:
            step=dict(revision=1,item_id=item["id"],phase=phase)
            current=tester.call(recovery+"/step","POST",step)
            assert current==tester.call(recovery+"/step","POST",step)
            if current["recovery"]["state"]=="repair":
                assert incompatible and phase=="inspect" and current["repair"]["evidence"]["incompatible"]==["billing_code"]
                break
        if current["recovery"]["state"]=="repair": break
        state=current
    for item in nodes:
        name=item["node"]
        read=node_call("current",dict(scope=scope,node=name,service=service))
        assert read["digest"]==(digest if incompatible and name in changed else old[name])
    if incompatible:
        assert all(item["receipt"] is None for item in current["items"])
    else:
        assert current["recovery"]["state"]=="done" and all(i["receipt"]["verified_digest"]==old[i["node"]] for i in current["items"])
    print("A12 passed: incompatible archived release blocked all restores and created a forward repair ticket." if incompatible else
          "A11 passed: failing second wave changed five nodes; all five previous digests restored and independently read, remaining five untouched.")
