"""Exercise dependency ordering and owner-signed build provenance via real project HTTP endpoints."""
import hashlib
import json
import os
import re
import secrets
import sys

from baselines_http import member, provision
from release_candidates_http import content


def run(origin):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN","")):
        raise RuntimeError("Use a disposable delivery_test_* database.")
    tenant = "trains-" + secrets.token_hex(5)
    secret = secrets.token_hex(20)
    admin = provision(origin,tenant,secret)
    product, product_id = member(admin,origin,tenant,secret,"product")
    _, signer_id = member(admin,origin,tenant,secret,"business")
    engineer, owner = member(admin,origin,tenant,secret,"engineer")
    release, manager = member(admin,origin,tenant,secret,"release_manager")
    project = product.call("/api/projects","POST",dict(code="dispatch",title="Dispatch",charter="Release distribution services",
                           product_owner_id=product_id,business_signer_id=signer_id),201)["id"]
    base = f"/api/projects/{project}"
    for account in (owner,manager):
        product.call(f"{base}/members/{account}","PUT",{"enabled":True})
    path = base + "/release-services"
    outsider = provision(origin,"elsewhere-"+secrets.token_hex(5),secret)
    outsider.call(path,status=404)
    ids, pins, repositories = [], [], []
    for name in ("billing","orders","schema"):
        repo=product.call(base+"/repositories","POST",dict(name=name,provider="manual",provider_repository_id=name),201)["id"]
        repositories.append(repo)
        service=release.call(path,"POST",dict(code=name,name=name,repository_id=repo,owner_id=owner),201)
        ids.append(service["service_id"])
        events=f"{base}/repositories/{repo}/events"
        def event(action,body):
            return product.call(events,"POST",dict(version=1,provider="manual",provider_repository_id=name,type=action,event_id=action,data=body))
        payload=json.dumps({"protocol":1,"name":name,"release":1,"add_fields":["billing_code"] if name=="schema" else [],
                            "requires":["billing_code"],"forbids":["old_index"] if name=="schema" else []}).encode()
        commit=hashlib.sha1(payload).hexdigest()
        snapshot=event("ref.updated",dict(branch="main",before_sha=None,before_snapshot_id=None,commit_sha=commit,forced=False,message=name))["snapshot"]
        build=event("build.updated",dict(snapshot_id=snapshot["id"],commit_sha=commit,run_id="run-1",run_sequence=1,status="passed"))
        digest=hashlib.sha256(payload).hexdigest()
        artifact=release.call(base+"/artifacts","POST",dict(build_id=build["build_id"],name=name+".bin",sha256=digest),201)
        pin={"revision":1,"artifact_id":artifact["id"],"digest":digest}
        pins.append(pin)
        sign=path+"/"+str(ids[-1])+"/pin"
        assert engineer.call(sign,"PUT",pin,409)["error"]=="service_artifact_mismatch"
        content(release,f"{base}/artifacts/{artifact['id']}/content",payload)
        assert release.call(sign,"PUT",pin,403)["error"]=="service_owner_required"
        signed=engineer.call(sign,"PUT",pin)
        assert signed["signed_by"]==owner and signed["commit_sha"]==commit and signed["digest"]==digest
    for consumer in ids[:2]:
        engineer.call(f"{path}/{consumer}/dependencies","PUT",dict(revision=2,upstream_ids=ids[2:]))
    graph=product.call(path)
    assert graph["order"]==[ids[2],ids[0],ids[1]] and graph["cycle"]==[]
    loop=engineer.call(f"{path}/{ids[2]}/dependencies","PUT",dict(revision=2,upstream_ids=ids[:1]),409)
    assert loop["details"]["cycle"]==[ids[0],ids[2],ids[0]]
    assert product.call(path)==graph
    assert engineer.call(f"{path}/{ids[0]}/pin","PUT",dict(pins[1],revision=3),409)["error"]=="service_artifact_mismatch"
    print("Release services passed: verified files in three repositories, owner signatures, schema before both consumers, cycle path and unchanged revisions.")
    return tenant,secret,admin,product,engineer,release,base,ids,pins,repositories


if __name__=="__main__":
    run(sys.argv[1])
