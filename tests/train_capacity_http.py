"""Measure 500 HTTP observation decisions in one minute on a 50-service manifest."""
from datetime import datetime, timedelta, timezone
import json
import math
import os
from pathlib import Path
import platform
import sqlite3
import subprocess
import time


def measure(product,engineer,release,tester,owner,base,baseline,stages):
    services=release.call(base+"/release-services")["services"]
    example=services[-1]
    for index in range(len(services),50):
        code=f"capacity-{index}"
        s=release.call(base+"/release-services","POST",dict(code=code,name=code,owner_id=example["owner_id"],
                       repository_id=example["repository_id"]),201)
        engineer.call(f"{base}/release-services/{s['service_id']}/pin","PUT",dict(revision=s["revision"],artifact_id=example["artifact_id"],digest=example["digest"]))
    services=release.call(base+"/release-services")["services"]
    assert len(services)==50 and all(s["digest"] for s in services)
    envs=release.call(base+"/environments")["items"]
    for index in range(len(envs),10):
        code=f"load-env-{index}"
        env=release.call(base+"/environments","POST",dict(code=code,name=code,stage="production"),201)["id"]
        access=f"{base}/environments/{env}/train-access"
        product.call(access+"/owner","PUT",dict(environment_rev=0,owner_id=owner))
        tester.call(access+"/connector","PUT",dict(environment_rev=1,enabled=True,nodes=[dict(node="load-node",region="lab")]))
    assert len(release.call(base+"/environments")["items"])==10
    train_id=release.call(base+"/release-trains","POST",dict(code="capacity",title="Gate load",baseline_id=baseline,
        service_ids=[s["service_id"] for s in services],staging_id=stages["staging"],production_id=env),201)["id"]
    train=f"{base}/release-trains/{train_id}"
    release.call(train+"/freeze","POST",dict(revision=0),201)
    board=release.call(train+"/wave-plan","POST",dict(revision=1,min_requests=100,max_error_bps=200,
        interval_seconds=300,waves=[dict(percent=100,regions=["lab"])]),201)
    wave=f"{train}/waves/{board['waves'][0]['wave_id']}"
    anchor=datetime.fromisoformat(board["plan"]["observed_at"]).replace(tzinfo=timezone.utc)
    sample=dict(sample_key="before",measured_at=(anchor-timedelta(seconds=1)).strftime("%Y-%m-%dT%H:%M:%SZ"),requests=1000,errors=0)
    tester.call(wave+"/samples","POST",sample,201)
    def record(i):
        now=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        begin=time.perf_counter()
        tester.call(wave+"/samples","POST",dict(sample_key=f"load-{i}",measured_at=now,requests=250,errors=1),201)
        gate_start=time.perf_counter()
        r=tester.call(wave+"/observations","POST",{},201)
        end=time.perf_counter()
        return dict(index=i,observation_id=r["observation_id"],decision=r["decision"],requests=r["after_requests"],
                    gate_ms=round((end-gate_start)*1000,3),total_ms=round((end-begin)*1000,3))
    time.sleep(1)
    warmup=time.perf_counter()
    for i in range(15):
        assert record(-i-1)["decision"]=="allow"
    warm_seconds=time.perf_counter()-warmup
    start=time.perf_counter()
    rows=[]
    for i in range(500):
        time.sleep(max(0,start+i*0.12-time.perf_counter()))
        try:
            rows.append(record(i))
        except (AssertionError,OSError) as error:
            rows.append(dict(index=i,error=str(error)[:240],timeout="timed out" in str(error).lower()))
    time.sleep(max(0,start+60-time.perf_counter()))
    elapsed=time.perf_counter()-start
    good=[r for r in rows if r.get("decision")=="allow"]
    p95=sorted(r["gate_ms"] for r in good)[math.ceil(0.95*len(good))-1] if good else None
    report=dict(services=len(services),environments=10,workers=1,warmup_count=15,warmup_seconds=round(warm_seconds,3),
        measured_seconds=round(elapsed,3),success=len(good),timeouts=sum(r.get("timeout",False) for r in rows),
        rejected=sum(not r.get("timeout") and r.get("decision")!="allow" for r in rows),gate_p95_ms=p95,
        machine=dict(os=platform.platform(),arch=platform.machine(),cpu=platform.processor(),logical_cpus=os.cpu_count()),
        versions=dict(python=platform.python_version(),sqlite=sqlite3.sqlite_version,
            php=subprocess.check_output(["php","-r","echo PHP_VERSION;"],text=True),mysql=os.environ["DELIVERY_MYSQL_VERSION"]),
        observations=rows)
    target=Path(os.environ["DELIVERY_TRAIN_ADAPTER_DATA"]).parent/"train-capacity.json"
    target.write_text(json.dumps(report,indent=2),encoding="utf-8")
    assert len(good)==500 and p95<=2000 and elapsed<61,(len(good),p95,elapsed)
    assert good[-1]["requests"]==515*250
    print(f"A14 passed: {len(good)} observations in {elapsed:.2f}s; gate P95 {p95:.2f}ms.")
