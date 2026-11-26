"""Exercise exact candidate gates, independent approval and replacement fencing."""

from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
import re
import secrets
import sys
from threading import Barrier
from urllib.error import HTTPError
from urllib.request import Request

from baselines_http import Session, member, provision
from git_delivery_http import commit_series
from testing_http import upload


def content(client, path, body=None, status=200, *, csrf=True, filename="artifact.bin"):
    headers = {"Content-Type": "application/octet-stream"}
    if csrf and client.csrf:
        headers["X-CSRF-Token"] = client.csrf
    request = Request(client.origin + path, body, headers, method="GET" if body is None else "PUT")
    try:
        response = client.client.open(request, timeout=15)
    except HTTPError as error:
        response = error
    with response:
        assert response.status == status, (response.status, response.read(1000))
        assert response.headers["Cache-Control"] == "no-store"
        if body is None and status == 200:
            assert response.headers["Content-Disposition"] == f'attachment; filename="{filename}"'
            return response.read()
        return json.load(response)


def run(origin, peer, after_review=None):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    token = secrets.token_hex(5)
    tenant, password = "release-" + token, secrets.token_hex(16)
    admin = provision(origin, tenant, password)
    outside = provision(peer, "outside-" + token, password)
    owner, owner_id = member(admin, origin, tenant, password, "product")
    business, signer_id = member(admin, origin, tenant, password, "business")
    tester, tester_id = member(admin, origin, tenant, password, "tester")
    release, release_id = member(admin, origin, tenant, password, "release_manager")
    release_peer = Session(peer)
    release_peer.login(tenant, "release_manager@example.test", password)

    def additional_account(email, name, role):
        account = admin.call("/api/accounts", "POST", dict(email=email, name=name, password=password, roles=[role]), 201)
        client = Session(peer)
        client.login(tenant, email, password)
        return client, account["account_id"]

    delegate, delegate_id = additional_account("approver@example.test", "Backup Product Reviewer", "product")
    delegate_peer = Session(origin)
    delegate_peer.login(tenant, "approver@example.test", password)
    project = owner.call("/api/projects", "POST", dict(code="ship", title="Dispatch", charter="Publish dispatch data",
                         product_owner_id=owner_id, business_signer_id=signer_id), 201)
    base = f"/api/projects/{project['id']}"
    for account in (tester_id, release_id, delegate_id):
        owner.call(f"{base}/members/{account}", "PUT", {"enabled": True})
    req = owner.call(base + "/requirements", "POST", dict(code="export", title="Export", summary="Export dispatch data"), 201)
    versions = []
    for key in ("date", "header"):
        criterion = owner.call(f"{base}/requirements/{req['id']}/criteria", "POST",
                               dict(code=key, title=key, statement=f"Include {key}"), 201)
        case = tester.call(base + "/test-cases", "POST", dict(code=key, criterion_id=criterion["id"], revision_id=criterion["revision"]["id"],
                           title=key, steps="Read export", expected_result=f"Contains {key}", junit_name=key), 201)
        versions.append(case["case_version_id"])
    baseline = owner.call(base + "/baselines", "POST", dict(title="Export baseline", scope_digest=owner.call(base + "/scope")["scope_digest"]), 201)
    staging = release.call(base + "/environments", "POST", dict(code="qa", name="Staging", stage="staging"), 201)
    development = release.call(base + "/environments", "POST", dict(code="dev", name="Dev", stage="development"), 201)
    repo = owner.call(base + "/repositories", "POST", dict(name="Dispatch", provider="manual", provider_repository_id="dispatch/export"), 201)
    snapshots = []

    def event(kind, key, data):
        return owner.call(f"{base}/repositories/{repo['id']}/events", "POST", dict(version=1, provider="manual",
                          provider_repository_id="dispatch/export", type=kind, event_id=key, data=data))

    def artifact(commit, blob, number):
        previous = snapshots[-1] if snapshots else {}
        snapshot = event("ref.updated", f"push-{number}", dict(branch="main", before_sha=previous.get("commit_sha"),
                         before_snapshot_id=previous.get("id"), commit_sha=commit, forced=False, message=f"Build {number}"))["snapshot"]
        snapshots.append(snapshot)
        build = event("build.updated", f"build-{number}", dict(snapshot_id=snapshot["id"], commit_sha=commit, run_id=f"dispatch-{number}",
                      run_sequence=1, status="passed"))
        return release.call(base + "/artifacts", "POST", dict(build_id=build["build_id"], name="dispatch.bin",
                            sha256=hashlib.sha256(blob).hexdigest()), 201)

    first_commit, second_commit, *fork = commit_series("release", 2, fork_from_first=after_review is not None)
    h1, h2 = b"dispatch first\x00\xff\n", b"dispatch next\x00\xfe\n"
    artifact1, artifact2 = artifact(first_commit, h1, 1), artifact(second_commit, h2, 2)
    path1 = f"{base}/artifacts/{artifact1['id']}/content"
    path2 = f"{base}/artifacts/{artifact2['id']}/content"
    assert content(release, path1, h1, 403, csrf=False)["error"] == "csrf_invalid"
    assert content(tester, path1, h1, 403)["error"] == "project_release_manager_required"
    assert content(release, path1, h2, 422)["error"] == "artifact_digest_mismatch"
    assert content(release, path2, h2)["byte_length"] == len(h2)

    def plan(artifact_id, code, environment, coverage):
        return tester.call(base + "/test-plans", "POST", dict(code=code, name=code, baseline_id=baseline["id"], artifact_id=artifact_id,
                           environment_id=environment["id"], case_version_ids=coverage), 201)

    p1 = plan(artifact1["id"], "old", staging, versions)
    p2 = plan(artifact2["id"], "next", staging, versions)
    partial = plan(artifact1["id"], "partial", staging, versions[:1])
    wrong_env = plan(artifact1["id"], "dev", development, versions)
    candidates = base + "/candidates"
    fields = dict(code="r1", title="Dispatch R1", baseline_id=baseline["id"], artifact_id=artifact1["id"], test_plan_id=p1["id"])
    for bad, error, status in [(p2, "candidate_test_plan_target_mismatch", 409), (wrong_env, "candidate_requires_staging_plan", 422),
                               (partial, "candidate_coverage_missing", 422)]:
        assert release.call(candidates, "POST", dict(fields, test_plan_id=bad["id"]), status)["error"] == error
    initial = release.call(candidates, "POST", fields, 201)
    assert release_peer.call(candidates, "POST", fields, 201)["id"] == initial["id"]
    assert initial["manifest"]["commit_sha"] == first_commit and initial["manifest"]["test_environment_id"] == staging["id"]
    manifest = json.dumps(initial["manifest"], ensure_ascii=False, separators=(",", ":")).encode()
    assert hashlib.sha256(manifest).hexdigest() == initial["manifest_digest"]
    c1 = f"{candidates}/{initial['id']}"
    m1 = {"manifest_digest": initial["manifest_digest"]}
    assert release.call(c1 + "/freeze", "POST", m1, 409)["error"] == "candidate_target_not_ready"
    assert content(release, path1, h1)["byte_length"] == len(h1)
    assert content(release_peer, path1, h1)["byte_length"] == len(h1)
    assert content(tester, path1) == h1
    assert content(business, path1, status=403)["error"] == "artifact_content_access_required"
    assert outside.call(c1, status=404)["error"] == "project_not_found"
    assert release.call(c1 + "/freeze", "POST", m1)["state"] == "frozen"
    assert release.call(c1 + "/start-testing", "POST", m1)["state"] == "testing"

    def junit(plan_data, attempt, report):
        return upload(tester, f"{base}/test-plans/{plan_data['id']}/reports", plan_data, attempt, f"run-{attempt}",
                      b'<testsuite><testcase name="date"/>' + report + b'</testsuite>')

    passing = b'<testcase name="header"/>'
    failing = b'<testcase name="header"><failure message="header missing"/></testcase>'
    passed_report = junit(p2, 1, passing)
    blocked = release.call(c1 + "/submit", "POST", m1, 409)
    assert blocked["error"] == "candidate_gate_blocked" and blocked["details"]["counts"]["missing"] == 2
    junit(p1, 1, passing)
    gate1 = release.call(c1 + "/submit", "POST", m1)
    approve1 = dict(m1, gate_digest=gate1["gate_digest"], note="Checked dispatch scope and test results")
    assert release.call(c1 + "/approve", "POST", approve1, 403)["error"] == "project_product_owner_required"
    assert owner.call(c1 + "/approve", "POST", approve1)["approval_valid"]
    failed_report = junit(p1, 2, failing)
    assert not owner.call(c1)["approval_valid"]
    assert owner.call(c1 + "/approve", "POST", approve1, 409)["error"] == "candidate_gate_blocked"
    junit(p1, 3, passing)
    assert owner.call(c1 + "/approve", "POST", approve1, 409)["error"] == "candidate_gate_changed"
    gate2 = release.call(c1 + "/submit", "POST", m1)
    assert gate2["state"] == "pending_approval" and gate2["gate_digest"] != gate1["gate_digest"]
    assert owner.call(c1 + "/approve", "POST", dict(approve1, gate_digest=gate2["gate_digest"]))["approval_valid"]

    replacement = dict(fields, title="Dispatch R1 fixed", artifact_id=artifact2["id"], test_plan_id=p2["id"])
    barrier = Barrier(2)

    def replace(client):
        barrier.wait(timeout=10)
        return client.call(c1 + "/replacements", "POST", replacement, 201)

    with ThreadPoolExecutor(max_workers=2) as pool:
        next_one, retried = pool.map(replace, [release, release_peer])
    assert next_one["id"] == retried["id"] and next_one["previous_id"] == initial["id"] and next_one["revision"] == 2
    old = owner.call(c1)
    assert old["state"] == "superseded" and not old["approval_valid"] and old["successor_id"] == next_one["id"]
    approvals = owner.call(c1 + "/approvals")["items"]
    assert len(approvals) == 2 and all(row["revoked_at"] for row in approvals) and approvals[0]["result_ids"] != approvals[1]["result_ids"]
    assert owner.call(c1 + "/timeline")["items"][-1]["action"] == "candidate_superseded"
    assert release.call(c1 + "/submit", "POST", m1, 409)["error"] == "candidate_state_conflict"
    assert next_one["state"] == "draft" and not next_one["approval_valid"] and not next_one["gate_digest"]
    assert next_one["manifest"]["commit_sha"] == second_commit and next_one["manifest"]["artifact_sha256"] == artifact2["sha256"]
    c2 = f"{candidates}/{next_one['id']}"
    m2 = {"manifest_digest": next_one["manifest_digest"]}
    assert release.call(c2 + "/freeze", "POST", m1, 409)["error"] == "candidate_manifest_changed"
    release.call(c2 + "/freeze", "POST", m2)
    release.call(c2 + "/start-testing", "POST", m2)
    pending = release.call(c2 + "/submit", "POST", m2)
    assert pending["state"] == "pending_approval" and pending["quality"]["result_ids"] != gate2["quality"]["result_ids"]
    assert owner.call(c2 + "/approve", "POST", dict(m2, gate_digest=gate2["gate_digest"], note="Wrong gate"), 409)["error"] == "candidate_gate_changed"
    delegate_url = c2 + "/approval-delegation"
    grant = dict(m2, gate_digest=pending["gate_digest"], expected_delegation_id=None, delegate_id=delegate_id, reason="Authorized time off coverage")
    assert delegate.call(delegate_url, "PUT", grant, 403)["error"] == "project_product_owner_required"
    assert owner.call(delegate_url, "PUT", dict(grant, delegate_id=release_id), 422)["error"] == "candidate_delegate_ineligible"
    assert owner.call(delegate_url, "PUT", dict(grant, delegate_id=signer_id), 422)["error"] == "candidate_delegate_ineligible"
    first_grant = owner.call(delegate_url, "PUT", grant)["approval_delegation"]
    delegate_approval = dict(m2, gate_digest=pending["gate_digest"], delegation_id=first_grant["id"], note="Replacement reviewed")
    assert first_grant["delegated_by"] == owner_id and first_grant["delegated_to"] == delegate_id
    assert release.call(c2 + "/approve", "POST", delegate_approval, 403)["error"] == "candidate_delegated_approver_required"
    assert owner.call(delegate_url, "PUT", dict(grant, delegate_id=None), 409)["error"] == "candidate_delegation_changed"
    revoked = owner.call(delegate_url, "PUT", dict(grant, expected_delegation_id=first_grant["id"], delegate_id=None))["approval_delegation"]
    assert revoked["closed_reason"] == "revoked"
    assert delegate.call(c2 + "/approve", "POST", delegate_approval, 409)["error"] == "candidate_delegation_changed"
    expiring = owner.call(delegate_url, "PUT", dict(grant, expected_delegation_id=revoked["id"]))["approval_delegation"]
    passed_report = junit(p2, 2, passing)
    assert delegate.call(c2 + "/approve", "POST", dict(delegate_approval, delegation_id=expiring["id"]), 409)["error"] == "candidate_gate_changed"
    pending = release.call(c2 + "/submit", "POST", m2)
    assert pending["approval_delegation"]["closed_reason"] == "candidate_evidence_changed"
    final_grant = owner.call(delegate_url, "PUT", dict(grant, expected_delegation_id=expiring["id"], gate_digest=pending["gate_digest"]))["approval_delegation"]
    by_delegate = dict(delegate_approval, gate_digest=pending["gate_digest"], delegation_id=final_grant["id"])
    owner.call(f"{base}/members/{delegate_id}", "PUT", {"enabled": False})
    assert delegate.call(c2 + "/approve", "POST", by_delegate, 404)["error"] == "project_not_found"
    owner.call(f"{base}/members/{delegate_id}", "PUT", {"enabled": True})

    delegate_barrier = Barrier(2)
    def approve_delegated(client):
        delegate_barrier.wait(timeout=10)
        return client.call(c2 + "/approve", "POST", by_delegate)

    with ThreadPoolExecutor(max_workers=2) as pool:
        reviews = list(pool.map(approve_delegated, [delegate, delegate_peer]))
    assert all(review["approval_valid"] and review["approval_delegation"]["closed_reason"] == "approved" for review in reviews)
    final_approvals = owner.call(c2 + "/approvals")["items"]
    assert len(final_approvals) == 1 and final_approvals[0]["approved_by"] == delegate_id
    assert final_approvals[0]["delegation_id"] == final_grant["id"] and final_approvals[0]["delegated_by"] == owner_id
    if after_review is not None:
        engineer, engineer_id = member(admin, origin, tenant, password, "engineer")
        owner.call(f"{base}/members/{engineer_id}", "PUT", {"enabled": True})
        second_signer = Session(peer)
        second_signer.login(tenant, "business@example.test", password)
        date = baseline["criteria"][0]
        task = owner.call(base + "/work-items", "POST", dict(code="render-date", title="Render dispatch date", team="dispatch",
                          criterion_id=date["criterion_id"], revision_id=date["revision_id"], effort=2, owner_id=engineer_id), 201)
        task_url = f"{base}/work-items/{task['id']}"
        engineer.call(task_url + "/claim", "POST")
        for snapshot in snapshots:
            engineer.call(task_url + "/git-links", "POST", dict(repository_id=repo["id"], snapshot_id=snapshot["id"]))
        fail = next(row for row in failed_report["results"] if row["outcome"] == "failed")
        retest = next(row for row in passed_report["results"] if row["case_version_id"] == fail["case_version_id"])
        defect = tester.call(base + "/defects", "POST", dict(failed_result_id=fail["id"], title="Fix dispatch header",
                             severity="major", description="The original output omitted the header"), 201)
        defect_url = f"{base}/defects/{defect['id']}"
        engineer.call(defect_url + "/fix", "POST", dict(artifact_id=artifact2["id"], note="Header updated"))
        tester.call(defect_url + "/close", "POST", dict(retest_result_id=retest["id"], note="Retest approved"))
        reviewer, reviewer_id = member(admin, origin, tenant, password, "external_reviewer")
        other_tenant_reviewer, _ = member(outside, peer, "outside-" + token, password, "external_reviewer")

        other_reviewer, other_reviewer_id = additional_account("partner@example.test", "Partner", "external_reviewer")
        next_engineer, next_engineer_id = additional_account("backup@example.test", "Engineer Backup", "engineer")
        owner.call(f"{base}/members/{next_engineer_id}", "PUT", {"enabled": True})
        trace = dict(peer=second_signer, task_id=task["id"], engineer_id=engineer_id, defect_id=defect["id"],
                     approval_delegate=delegate, approval_delegate_id=delegate_id, approval_grantor_id=owner_id,
                     failed_id=fail["id"], retest_id=retest["id"], commit_sha=second_commit, requirement_id=req["id"],
                     reviewer=reviewer, reviewer_id=reviewer_id, other_reviewer=other_reviewer, other_reviewer_id=other_reviewer_id,
                     external_other_tenant=other_tenant_reviewer, engineer=engineer, next_engineer=next_engineer, next_engineer_id=next_engineer_id)
        after_review(base, baseline, [p1, p2], development, staging, release, release_peer, owner, business, tester, outside,
                     event, first_commit, fork[0], trace)
    criterion = baseline["criteria"][0]
    reviewed = owner.call(base + "/changes", "POST", dict(baseline_id=baseline["id"], reason="Published export contract changed",
                          criteria=[dict(criterion_id=criterion["criterion_id"], title="UTC Date", statement="Use an ISO date")]), 201)
    business.call(f"{base}/changes/{reviewed['id']}/decision", "POST", dict(decision="approved", note="Contract updated"))
    stale = owner.call(c2)
    assert not stale["approval_valid"] and stale["quality"]["stale_criteria"] == [
        {"criterion_id": criterion["criterion_id"], "revision_id": criterion["revision_id"]}]
    assert release.call(c2 + "/submit", "POST", m2, 409)["error"] == "candidate_gate_blocked"
    print("AC-08 and AC-10 passed: artifact digest, exact candidate gates, independent approval and concurrent replacement.")


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2])
