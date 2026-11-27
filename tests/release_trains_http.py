"""Freeze a cross-repository release, rehearse it on a separate node and race real production reservations."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import time
from threading import Barrier

from baselines_http import Session, member
from release_candidates_http import content
from release_services_http import run as setup


def instant(minutes):
    return (datetime.now(timezone.utc)+timedelta(minutes=minutes)).strftime("%Y-%m-%dT%H:%M:%SZ")


def run(origin, peer):
    tenant,secret,admin,product,engineer,release,base,ids,pins,repos=setup(origin)
    tester,tester_id=member(admin,origin,tenant,secret,"tester")
    product.call(f"{base}/members/{tester_id}","PUT",{"enabled":True})
    project=int(base.split("/")[-1])
    req=product.call(base+"/requirements","POST",dict(code="invoice",title="Invoice field",summary="New invoice field"),201)
    product.call(f"{base}/requirements/{req['id']}/criteria","POST",dict(code="field",title="Invoice import",statement="Can read a new invoice field"),201)
    baseline=product.call(base+"/baselines","POST",dict(title="Invoice launch",scope_digest=product.call(base+"/scope")["scope_digest"]),201)
    stages={}
    for stage,nodes in [("staging",[{"node":"stage-1","region":"cn"}]),("production",[{"node":f"prod-{i:02}","region":"cn" if i<5 else "eu"} for i in range(10)])]:
        env=release.call(base+"/environments","POST",dict(code=stage,name=stage,stage=stage),201)["id"]
        stages[stage]=env
        access=f"{base}/environments/{env}/train-access"
        assert not tester.call(access)["enabled"]
        grant=product.call(access+"/owner","PUT",dict(environment_rev=0,owner_id=tester_id))
        assert not grant["enabled"]
        policy=dict(environment_rev=1,nodes=nodes,enabled=True)
        assert release.call(access+"/connector","PUT",policy,403)["error"]=="environment_owner_required"
        assert tester.call(access+"/connector","PUT",policy)["environment_rev"]==2
    path=base+"/release-trains"
    create=dict(code="nov-release",title="nov-release",baseline_id=baseline["id"],service_ids=ids,staging_id=stages["staging"],production_id=stages["production"])
    assert release.call(path,"POST",create,409)["error"]=="train_pilot_not_accepting"
    def pilot(cmd):
        return json.loads(subprocess.check_output(["php","bin/delivery.php","train-pilot",tenant,cmd],timeout=10))
    assert pilot("preflight")["conflict_ids"]==[]
    migrated=pilot("backfill")
    assert migrated["complete"] and migrated["through"]==baseline["id"] and pilot("backfill")==migrated
    assert pilot("enable")["state"]=="enabled"
    paths=[]
    for code in ("nov-release","reserve"):
        t=release.call(path,"POST",dict(code=code,title=code,baseline_id=baseline["id"],service_ids=ids,staging_id=stages["staging"],production_id=stages["production"]),201)
        train=path+"/"+str(t["id"])
        frozen=release.call(train+"/freeze","POST",{"revision":0},201)
        raw=content(engineer,train+"/manifests/1",filename=f"release-train-{t['id']}-r1.json")
        assert hashlib.sha256(raw).hexdigest()==frozen["manifest_digest"]
        manifest=json.loads(raw)
        assert manifest["baseline"]["manifest_digest"]==baseline["manifest_digest"]
        assert [p["service_id"] for p in manifest["components"]]==[ids[2],*ids[:2]]
        assert len({p["repository_id"] for p in manifest["components"]})==len({p["commit_sha"] for p in manifest["components"]})==3
        paths.append((train,manifest,raw))
    window={"revision":1,"starts_at":instant(-1),"ends_at":instant(30)}
    expiry=instant(90)
    for train,manifest,_ in paths:
        assert release.call(train+"/window","POST",window,409)["error"]=="production_approval_required"
        early={"service_id":ids[0],"revision":1,"node":"stage-1"}
        assert tester.call(train+"/rehearse","POST",early,409)["error"]=="upstream_rehearsal_required"
        for pin in manifest["components"]:
            service=pin["service_id"]
            sign=dict(service_id=service,digest=pin["digest"],staging_rev=2,production_rev=2,revision=1,expires_at=expiry)
            assert tester.call(train+"/approve","POST",sign,409)["error"]=="rehearsal_required"
            poll=dict(service_id=service,revision=1,node="stage-1")
            receipt=tester.call(train+"/rehearse","POST",poll)
            assert receipt["state"]=="passed" and receipt["receipt"]["digest"]==pin["digest"] and receipt["receipt"]["probe"]["passed"]
            assert tester.call(train+"/rehearse","POST",poll)==receipt
            if service==ids[2] and train==paths[0][0]:
                assert receipt["receipt"]["migration"]["added"]==["billing_code"]
            assert release.call(train+"/approve","POST",sign,403)["error"]=="independent_environment_owner_required"
            tester.call(train+"/approve","POST",sign,201)
        assert tester.call(train+"/gates")["ready"]
    second=Session(peer)
    second.login(tenant,"release_manager@example.test",secret)
    barrier=Barrier(2)
    def reserve(args):
        actor,(train,_,_)=args
        barrier.wait(timeout=10)
        return actor.call(train+"/window","POST",window,(201,409))
    with ThreadPoolExecutor(2) as workers:
        results=list(workers.map(reserve,zip([release,second],paths)))
    assert sum("window_id" in r for r in results)==1
    winner=0 if "window_id" in results[0] else 1
    assert results[1-winner]["details"]["window_id"]==results[winner]["window_id"]
    train,manifest,old=paths[winner]
    assert release.call(train+"/window","POST",window,201)==results[winner]
    loser=paths[1-winner][0]
    assert release.call(loser+"/window","POST",dict(window,starts_at=window["ends_at"],ends_at=instant(45)),201)["state"]=="scheduled"

    def replace_bundle(number,requires):
        previous=product.call(f"{base}/repositories/{repos[0]}")["items"][0]
        blob=json.dumps({"protocol":1,"name":"billing","release":number,"requires":requires}).encode()
        commit=hashlib.sha1(blob).hexdigest()
        digest=hashlib.sha256(blob).hexdigest()
        def event(action,data):
            return product.call(f"{base}/repositories/{repos[0]}/events","POST",dict(version=1,provider="manual",provider_repository_id="billing",
                                type=action,event_id=f"{action}-{number}",data=data))
        snapshot=event("ref.updated",dict(branch="main",before_sha=previous["commit_sha"],before_snapshot_id=previous["id"],
                       commit_sha=commit,forced=False,message="New billing release"))["snapshot"]
        build=event("build.updated",dict(snapshot_id=snapshot["id"],commit_sha=commit,run_id=f"run-{number}",run_sequence=1,status="passed"))
        artifact=release.call(base+"/artifacts","POST",dict(build_id=build["build_id"],name="billing.bin",sha256=digest),201)
        content(release,f"{base}/artifacts/{artifact['id']}/content",blob)
        service=engineer.call(f"{base}/release-services/{ids[0]}")
        engineer.call(f"{base}/release-services/{ids[0]}/pin","PUT",dict(revision=service["revision"],digest=digest,artifact_id=artifact["id"]))
        return release.call(train+"/replace","POST",dict(revision=number-1,service_id=ids[0],digest=digest,reason="Billing compatibility correction"),201)
    replacement=replace_bundle(2,["unknown_field"])
    assert replacement["train_rev"]==2
    gates=engineer.call(train+"/gates")
    assert [c["service_id"] for c in gates["components"] if c["approval"] is None]==[ids[0]]
    assert content(engineer,train+"/manifests/1",filename=f"release-train-{manifest['train_id']}-r1.json")==old
    for service in [ids[2],ids[0]]:
        r=tester.call(train+"/rehearse","POST",dict(revision=2,service_id=service,node="stage-1"))
    assert r["state"]=="failed" and r["receipt"]["probe"]["missing"]==["unknown_field"]
    assert release.call(train+"/window","POST",dict(window,revision=2),409)["error"]=="production_approval_required"
    print("A3-A6 passed: immutable three-repository download, one winner across two HTTP servers, adjacent window, actual migration and failed health probe, scoped approval invalidation.")
    rollout(release,tester,path,baseline["id"],stages,ids[2],manifest["components"][0]["digest"],tenant,secret)
    from train_recovery_http import verify
    for incompatible in (False,True):
        verify(product,release,tester,tester_id,base,stages["staging"],baseline["id"],ids[2],manifest["components"][0]["digest"],incompatible)
    if os.getenv("DELIVERY_VERIFY_TRAIN_CAPACITY"):
        from train_capacity_http import measure
        measure(product,engineer,release,tester,tester_id,base,baseline["id"],stages)
    assert pilot("drain")["state"]=="draining"
    assert release.call(path,"POST",dict(create,code="post-drain"),409)["error"]=="train_pilot_not_accepting"
    assert isinstance(pilot("pending")["items"],list)


def rollout(release,tester,path,baseline,stages,service,digest,tenant,secret):
    create=dict(code="canary",title="Schema rollout",baseline_id=baseline,service_ids=[service],
                staging_id=stages["staging"],production_id=stages["production"])
    train=path+"/"+str(release.call(path,"POST",create,201)["id"])
    release.call(train+"/freeze","POST",dict(revision=0),201)
    assert tester.call(train+"/rehearse","POST",dict(revision=1,service_id=service,node="stage-1"))["state"]=="passed"
    tester.call(train+"/approve","POST",dict(service_id=service,digest=digest,revision=1,staging_rev=2,production_rev=2,expires_at=instant(60)),201)
    approval=release.call(train+"/window","POST",dict(revision=1,starts_at=instant(-1),ends_at=instant(15)),201)["approval_id"]
    spec=dict(revision=1,min_requests=100,max_error_bps=200,interval_seconds=300,
              waves=[dict(percent=p,regions=["cn","eu"]) for p in (10,40,50)])
    board=release.call(train+"/wave-plan","POST",spec,201)
    assert [len(w["targets"]) for w in board["waves"]]==[1,4,5]
    assert len({n for w in board["waves"] for n in w["targets"]})==10
    assert board==release.call(train+"/wave-plan","POST",spec,201)
    evidence=[]
    for i,wave in enumerate(board["waves"]):
        path_wave=f"{train}/waves/{wave['wave_id']}"
        empty=tester.call(path_wave+"/observations","POST",{},201)
        assert empty["decision"]=="await_samples"
        request=dict(revision=1,approval_id=approval,observation_id=empty["observation_id"])
        key=f"promote-{i}"
        assert release.call(path_wave+"/advance","POST",request,409,key=key)["error"]=="quality_gate_closed"
        anchor=datetime.fromisoformat(empty["anchor_at"]).replace(tzinfo=timezone.utc,microsecond=0)
        for offset,errors in ((-1,0),(1,30 if i==1 else 0)):
            measured=anchor+timedelta(seconds=offset)
            time.sleep(max(0,measured.timestamp()-time.time()+0.05))
            sample=dict(sample_key=f"quality-{offset}",measured_at=measured.strftime("%Y-%m-%dT%H:%M:%SZ"),requests=1000,errors=errors)
            saved=tester.call(path_wave+"/samples","POST",sample,201)
            assert tester.call(path_wave+"/samples","POST",sample,201)==saved
            assert tester.call(path_wave+"/samples","POST",dict(sample,requests=1001),409)["error"]=="sample_key_conflict"
        obs=tester.call(path_wave+"/observations","POST",{},201)
        request["observation_id"]=obs["observation_id"]
        if i==1:
            assert obs["decision"]=="pause" and obs["after_errors"]==30 and obs["after_requests"]==1000
            assert release.call(path_wave+"/advance","POST",request,409,key=key)["error"]=="quality_gate_closed"
            waiver=dict(observation_id=obs["observation_id"],reason="Operator reviewed incident and authorized bounded exposure",expires_at=instant(5))
            release.call(path_wave+"/waivers","POST",waiver,403)
            tester.call(path_wave+"/waivers","POST",waiver,201)
        else:
            assert obs["decision"]=="allow"
        accepted=release.call(path_wave+"/advance","POST",request,202,key=key)
        assert accepted==release.call(path_wave+"/advance","POST",request,202,key=key)
        assert release.call(path_wave+"/advance","POST",request,409,key=key+"-other")["error"]=="wave_already_accepted"
        xid=accepted["execution_id"]
        def step():
            cmd=subprocess.run(["php","bin/delivery.php","train-step",str(xid)],capture_output=True,text=True,timeout=25)
            assert cmd.returncode==0,(cmd.stdout,cmd.stderr)
            return json.loads(cmd.stdout)
        detail=f"{train}/executions/{xid}"
        if i==0:
            assert step()["state"]=="unknown"
            uncertain=release.call(detail)
            assert uncertain["targets"][0]["state"]=="unknown"
            if os.getenv("DELIVERY_VERIFY_BROWSER"):
                browser_trace(release.origin,tenant,secret,uncertain,"unknown")
        if i==1:
            tester.call(train+"/pause","POST",dict(reason="Check second wave"))
            assert step()["state"]=="paused"
            tester.call(train+"/resume","POST",dict(reason="Continue pinned targets"))
        if i==2:
            worker=subprocess.Popen(["php","bin/delivery.php","train-worker"],stdout=subprocess.DEVNULL)
            try:
                until=time.monotonic()+35
                while time.monotonic()<until and release.call(detail)["wave_state"]!="done":
                    assert worker.poll() is None
                    time.sleep(0.25)
                assert release.call(detail)["wave_state"]=="done"
            finally:
                worker.terminate()
                worker.wait(timeout=5)
        else:
            for _ in wave["targets"]:
                result=step()
            assert result["state"]=="done",result
        done=release.call(detail)
        assert [r["node"] for r in done["targets"]]==accepted["pinned_targets"]
        assert all(r["state"]=="done" and r["receipt"]["observed_digest"]==digest for r in done["targets"])
        if i==0:
            assert [e["command"] for e in done["timeline"]]==["result","current","apply","result","current"]
            op=done["targets"][0]["operation_key"]
            stores=Path(os.environ["DELIVERY_TRAIN_ADAPTER_DATA"])
            rows=[]
            for dbfile in stores.glob("*.sqlite"):
                with sqlite3.connect(dbfile.as_uri()+"?mode=ro",uri=True) as db:
                    if db.execute("SELECT 1 FROM operations WHERE op=?",(op,)).fetchone():
                        rows.extend(db.execute("SELECT op,digest,kind FROM operations").fetchall())
            assert rows==[(op,digest,"apply")],rows
            evidence.append(dict(method="POST",path=path_wave+"/advance",idempotency_key=key,request=request,
                                 accepted=accepted,unknown=uncertain,done=done,node_operations=rows))
            if os.getenv("DELIVERY_VERIFY_BROWSER"):
                browser_trace(release.origin,tenant,secret,done,"done")
            record=dict(operation=op,node=done["targets"][0]["node"],digest=digest,external_receipt="shift-log/canary-first")
            release.call(detail+"/handover","POST",record,403)
            receipt=tester.call(detail+"/handover","POST",record)
            assert receipt["verified"]["digest"]==digest and tester.call(detail+"/handover","POST",record)==receipt
            assert tester.call(detail+"/handover","POST",dict(record,external_receipt="changed"),409)["error"]=="manual_receipt_conflict"
    final=release.call(train+"/wave-plan")
    assert final["state"]=="completed" and all(w["state"]=="done" for w in final["waves"])
    (stores.parent/"wave-evidence.json").write_text(json.dumps(dict(waves=final,evidence=evidence),indent=2),encoding="utf-8")
    print("A7-A10 passed: ten unique nodes in waves of one, four and five; empty sample gate, 3% error pause, approved waiver, paused worker and single node effect after lost response.")


def browser_trace(origin,tenant,secret,execution,state):
    from playwright.sync_api import sync_playwright, expect
    with sync_playwright() as pw:
        browser=pw.chromium.launch(channel=os.environ.get("DELIVERY_BROWSER_CHANNEL","chrome"),headless=True)
        page=browser.new_page(viewport={"width":1280,"height":950})
        page.set_default_timeout(10000)
        page.goto(origin)
        form=page.locator("#login")
        form.get_by_label("租户").fill(tenant)
        form.get_by_label("邮箱").fill("release_manager@example.test")
        form.get_by_label("密码").fill(secret)
        form.get_by_role("button",name="登录").click()
        page.get_by_role("navigation",name="项目").get_by_role("button",name="dispatch",exact=False).click()
        page.get_by_role("button",name="打开发布看板").click()
        page.get_by_role("button",name="Schema rollout",exact=False).click()
        card=page.locator("#train-detail article").filter(has=page.get_by_role("heading",name="第 1 波",exact=False))
        card.get_by_role("button",name="查看节点回执与调用时间线").click()
        report=card.locator(".wave-trace")
        expect(report).to_contain_text(execution["trace_id"])
        expect(report).to_contain_text("prod-00")
        expect(report).to_contain_text(state)
        expect(report).to_contain_text(execution["targets"][0]["digest"])
        if state=="done":
            expect(report).to_contain_text("实际 "+execution["targets"][0]["digest"])
        evidence_dir=Path(os.environ["DELIVERY_TRAIN_ADAPTER_DATA"]).parent
        report.screenshot(path=evidence_dir/f"wave-{state}.png")
        browser.close()


if __name__=="__main__":
    run(sys.argv[1],sys.argv[2])
