"""Measure the paginated sprint endpoint with 10,000 assigned work items."""

from concurrent.futures import ThreadPoolExecutor
import json
import math
import os
import platform
import re
import secrets
import subprocess
import sys
import time

from baselines_http import PHP, Session, member, provision


def run(origin, peer):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    tenant, password = "capacity-" + secrets.token_hex(5), secrets.token_hex(16)
    admin = provision(origin, tenant, password)
    owner, account = member(admin, origin, tenant, password, "product")
    _, business = member(admin, origin, tenant, password, "business")
    project = owner.call("/api/projects", "POST", dict(code="dispatch", title="Dispatch export", charter="Sprint capacity fixture",
                         product_owner_id=account, business_signer_id=business), 201)
    base = f"/api/projects/{project['id']}"
    req = owner.call(base + "/requirements", "POST", dict(code="export", title="Export", summary="Render approved columns"), 201)
    criterion = owner.call(f"{base}/requirements/{req['id']}/criteria", "POST",
                           dict(code="headers", title="Headers", statement="Render all dispatch headers"), 201)
    sprint = owner.call(base + "/sprints", "POST", dict(code="dispatch", title="Dispatch sprint", team="dispatch",
                        starts_on="2026-12-01", ends_on="2026-12-14", capacity=12_000), 201)
    ids = [project["id"], criterion["id"], criterion["revision"]["id"], sprint["id"], account]
    actor = owner.call("/api/session")["actor"]
    seeded = subprocess.run([*PHP, "tests/seed-capacity.php", *map(str, [actor["tenant_id"], *ids])],
                            capture_output=True, encoding="utf-8", check=True)
    ranges = json.loads(seeded.stdout)
    queries = [0, ranges["first"] + 2249, ranges["first"] + 4999, ranges["last"] - 51]
    path = f"{base}/sprints/{sprint['id']}"
    sessions = [Session(value) for value in (origin, peer, origin, peer)]
    for client in sessions:
        client.login(tenant, "product@example.test", password)

    def measure(job):
        worker, count = job
        client = sessions[worker]
        durations = []
        for iteration in range(count):
            start = time.perf_counter()
            result = client.call(path + f"?after={queries[(iteration + worker) % len(queries)]}")
            durations.append((time.perf_counter() - start) * 1000)
            assert int(result["used"]) == 10_000 and len(result["items"]) == 50
            assert result["next_after"] == result["items"][-1]["id"]
        return durations

    with ThreadPoolExecutor(max_workers=len(sessions)) as pool:
        list(pool.map(measure, [(worker, 8) for worker in range(len(sessions))]))
        start = time.perf_counter()
        durations = sorted(value for timings in pool.map(measure, [(worker, 50) for worker in range(len(sessions))]) for value in timings)
        runtime = time.perf_counter() - start
    p95 = durations[math.ceil(0.95 * len(durations)) - 1]
    print(json.dumps(dict(cases="sprint-capacity", work_items=10_000, page_size=50, workers=len(sessions),
                          warmup_request_count=32, measured_request_count=len(durations), measured_seconds=round(runtime, 3),
                          p50_ms=round(durations[len(durations) // 2 - 1], 2), p95_ms=round(p95, 2),
                          max_ms=round(durations[-1], 2), errors=0, platform=platform.system())))
    assert p95 <= 2000, p95


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])
