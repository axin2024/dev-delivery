"""Exercise repository identities, signed receipts, and late build updates."""

import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from baselines_http import member, provision


def commit_series(name, count):
    with tempfile.TemporaryDirectory(prefix="delivery-git-") as folder:
        def git(*args):
            return subprocess.run(["git", "-C", folder, *args], check=True, text=True, capture_output=True).stdout.strip()

        git("init", "--initial-branch=feature/export")
        hashes = []
        for index in range(count):
            Path(folder, "contract.txt").write_text(f"{name} {index}\n", encoding="utf-8")
            git("add", "contract.txt")
            git("-c", "user.name=Dev Delivery Test", "-c", "user.email=fixture@example.test",
                "-c", "commit.gpgsign=false", "-c", "core.hooksPath=.git/disabled-hooks",
                "commit", "-m", f"Fixture {index}")
            hashes.append(git("rev-parse", "HEAD"))
        return hashes


def signed(origin, key, event, expected=200, *, stamp=None, secret=None):
    body = json.dumps(event, separators=(",", ":"), ensure_ascii=False).encode()
    timestamp = str(stamp if stamp is not None else int(time.time()))
    signer = (secret or key["signing_secret"]).encode()
    signature = hmac.new(signer, timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    headers = {"Content-Type": "application/json", "X-Delivery-Timestamp": timestamp,
               "X-Delivery-Signature": "sha256=" + signature}
    try:
        response = urlopen(Request(origin + key["path"], body, headers, method="POST"), timeout=15)
    except HTTPError as error:
        response = error
    with response:
        result = json.load(response)
        assert response.status == expected, (response.status, result)
        assert response.headers["Cache-Control"] == "no-store"
        assert response.headers.get("Set-Cookie") is None
        return result


def run(origin, peer):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    tenant = "git-delivery-" + secrets.token_hex(5)
    password = secrets.token_hex(16)
    admin = provision(origin, tenant, password)
    outside = provision(origin, "outside-" + secrets.token_hex(5), password)
    product, product_id = member(admin, origin, tenant, password, "product")
    _, signer_id = member(admin, origin, tenant, password, "business")
    engineer, engineer_id = member(admin, origin, tenant, password, "engineer")
    project = product.call("/api/projects", "POST", dict(code="release", title="Delivery", charter="Ship export",
                           product_owner_id=product_id, business_signer_id=signer_id), 201)
    base = f"/api/projects/{project['id']}"
    product.call(f"{base}/members/{engineer_id}", "PUT", {"enabled": True})
    req = product.call(base + "/requirements", "POST", dict(code="export", title="Export", summary="New columns"), 201)
    criterion = product.call(f"{base}/requirements/{req['id']}/criteria", "POST",
                             dict(code="c1", title="Columns", statement="Include timestamp"), 201)
    task = product.call(base + "/work-items", "POST", dict(code="report", title="Report exporter", criterion_id=criterion["id"],
                        revision_id=criterion["revision"]["id"], team="backend", owner_id=engineer_id, effort=3), 201)
    work = f"{base}/work-items/{task['id']}"
    assert product.call(work + "/claim", "POST", status=403)["error"] == "project_engineer_required"
    claimed = engineer.call(work + "/claim", "POST")
    assert claimed["claimed_by"] == engineer_id
    assert engineer.call(work + "/claim", "POST") == claimed

    def repo(name, source):
        item = product.call(base + "/repositories", "POST", dict(name=name, provider="github", provider_repository_id=source), 201)
        assert "connector_id" not in item
        return f"{base}/repositories/{item['id']}", item

    back, backend = repo("Backend", "backend-001")
    front, frontend = repo("Frontend", "frontend-001")
    assert engineer.call(back + "/connector", "POST", status=403)["error"] == "project_product_owner_required"
    assert outside.call(back, status=404)["error"] == "project_not_found"
    key = product.call(back + "/connector", "POST")
    other_key = product.call(front + "/connector", "POST")
    assert key["signing_secret"] != other_key["signing_secret"]
    a, b = commit_series("backend", 2)
    [c] = commit_series("frontend", 1)

    def event(event_id, event_type, data, repository=backend):
        return dict(version=1, provider=repository["provider"], provider_repository_id=repository["provider_repository_id"],
                    event_id=event_id, type=event_type, data=data)

    def ref(event_id, before, commit, forced=False, branch="feature/export", repository=backend, prior=None):
        return event(event_id, "ref.updated", dict(branch=branch, before_sha=before, before_snapshot_id=prior, commit_sha=commit,
                     forced=forced, message="Contract update"), repository)

    first = ref("first", None, a)
    assert signed(origin, key, first, 401, secret=other_key["signing_secret"])["error"] == "connector_signature_invalid"
    assert signed(origin, key, first, 401, stamp=int(time.time()) - 310)["error"] == "connector_signature_invalid"
    assert signed(origin, key, ref("bad-ref", None, a, branch="dev~2"), 422)["error"] == "git_branch_invalid"
    assert signed(origin, key, ref("bad-ref", None, a, branch=r"namespace\bad"), 422)["error"] == "git_branch_invalid"
    assert signed(origin, key, ref("bad-ref", None, a, branch="feature//nested"), 422)["error"] == "git_branch_invalid"
    assert signed(origin, key, ref("remote", None, c, repository=frontend), 422)["error"] == "connector_repository_mismatch"
    first_receipt = signed(origin, key, first)
    a_snapshot = first_receipt["snapshot"]["id"]
    assert signed(peer, key, first) == first_receipt
    sender = subprocess.run(["php", "bin/send-git-event.php"],
                            input=json.dumps(first, separators=(",", ":"), ensure_ascii=False).encode(),
                            capture_output=True, check=True, env={**os.environ,
                                "DELIVERY_CONNECTOR_ENDPOINT": peer + key["path"],
                                "DELIVERY_CONNECTOR_SIGNING_SECRET": key["signing_secret"]})
    assert json.loads(sender.stdout) == first_receipt
    assert signed(origin, key, ref("first", None, b), 409)["error"] == "connector_event_content_conflict"
    c_snapshot = product.call(front + "/events", "POST", ref("first", None, c, repository=frontend))["snapshot"]["id"]
    assert a_snapshot != c_snapshot

    def link(repository, snapshot):
        return engineer.call(work + "/git-links", "POST", dict(repository_id=repository["id"], snapshot_id=snapshot))

    assert engineer.call(work + "/git-links", "POST", dict(repository_id=backend["id"], snapshot_id=c_snapshot), 404)["error"] == "git_snapshot_not_found"
    assert link(backend, a_snapshot) == link(backend, a_snapshot)
    link(frontend, c_snapshot)
    links = engineer.call(work + "/git-links")["items"]
    assert [(row["repository_id"], row["branch"], row["commit_sha"]) for row in links] == [
        (backend["id"], "feature/export", a), (frontend["id"], "feature/export", c)]
    assert [row["action"] for row in engineer.call(work + "/timeline")["items"]] == [
        "work_item_created", "work_item_claimed", "git_snapshot_linked", "git_snapshot_linked"]

    def build(event_id, snapshot, sha, run_id, sequence, status):
        return event(event_id, "build.updated", dict(snapshot_id=snapshot, commit_sha=sha,
                     run_id=run_id, run_sequence=sequence, status=status))

    start_a = build("start-a", a_snapshot, a, "run-a", 1, "running")
    assert signed(origin, key, start_a)["current_snapshot"] is True
    b_snapshot = signed(origin, key, ref("next", a, b, forced=True, prior=a_snapshot))["snapshot"]["id"]
    assert signed(origin, key, ref("stale-ref", a, c, prior=a_snapshot), 409)["error"] == "git_reference_stale"
    assert signed(origin, key, build("wrong-sha", b_snapshot, a, "run-b", 2, "passed"), 409)["error"] == "connector_snapshot_commit_mismatch"
    assert signed(origin, key, build("reused-run", b_snapshot, b, "run-a", 1, "passed"), 409)["error"] == "build_run_identity_conflict"
    signed(origin, key, build("start-b", b_snapshot, b, "run-b", 2, "queued"))
    late = signed(peer, key, build("finish-a", a_snapshot, a, "run-a", 1, "passed"))
    assert late["status"] == "passed" and late["current_snapshot"] is False
    [active] = engineer.call(back)["items"]
    assert active["id"] == b_snapshot and active["build_status"] == "queued" and active["forced"] == 1
    assert engineer.call(back + f"/snapshots/{a_snapshot}")["build_runs"][0]["status"] == "passed"
    assert signed(origin, key, build("done-b", b_snapshot, b, "run-b", 2, "failed"))["status"] == "failed"
    assert signed(origin, key, build("late-b", b_snapshot, b, "run-b", 2, "running"))["state"] == "ignored_older_status"
    returned = signed(origin, key, ref("forced-back", b, a, forced=True, prior=b_snapshot))["snapshot"]["id"]
    assert returned != a_snapshot
    assert signed(origin, key, ref("late-before-rewind", a, b, prior=a_snapshot), 409)["details"]["snapshot_id"] == returned
    [active] = engineer.call(back)["items"]
    assert active["id"] == returned and active["commit_sha"] == a and active["build_status"] is None

    def merge(event_id, snapshot, sha, version, status):
        return event(event_id, "merge.updated", dict(snapshot_id=snapshot, commit_sha=sha, merge_id="mr-41",
                     source_version=version, state=status, title="Partner export"))

    signed(origin, key, merge("merge-closed", returned, a, 2, "merged"))
    assert signed(origin, key, merge("merge-older", b_snapshot, b, 1, "open"))["state"] == "ignored_older_version"
    assert engineer.call(back + f"/snapshots/{returned}")["merge_requests"][0]["state"] == "merged"
    journal = engineer.call(back + "/events")["items"]
    assert sum(row["external_id"] == "first" for row in journal) == 1
    assert all(row["imported_by"] is None for row in journal)
    assert engineer.call(front + "/events")["items"][0]["imported_by"] == product_id
    print("AC-05–06 passed: actual Git identities, repository isolation, signed and offline events, and late build fencing.")


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])
