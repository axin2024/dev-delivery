"""Exercise immutable test evidence, JUnit import, and independent defect retests."""

from concurrent.futures import ThreadPoolExecutor
import base64
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


def upload(client, path, plan, attempt, key, xml, status=201, **overrides):
    headers = {"Content-Type": "application/xml", "X-CSRF-Token": client.csrf,
               "X-Delivery-Report-Key": key, "X-Delivery-Attempt": str(attempt),
               "X-Delivery-Artifact-Sha256": plan["sha256"], "X-Delivery-Build-Id": str(plan["build_id"]),
               "X-Delivery-Environment-Id": str(plan["environment_id"])}
    headers.update(overrides)
    try:
        response = client.client.open(Request(client.origin + path, xml, headers, method="POST"), timeout=15)
    except HTTPError as error:
        response = error
    with response:
        result = json.load(response)
        assert response.status == status, (response.status, result)
        assert response.headers["Cache-Control"] == "no-store"
        return result


def run(origin, peer):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    tenant = "qa-" + secrets.token_hex(5)
    password = secrets.token_hex(16)
    admin = provision(origin, tenant, password)
    outside = provision(origin, "outside-" + secrets.token_hex(5), password)
    product, owner = member(admin, origin, tenant, password, "product")
    business, signer = member(admin, origin, tenant, password, "business")
    tester, tester_id = member(admin, origin, tenant, password, "tester")
    engineer, engineer_id = member(admin, origin, tenant, password, "engineer")
    release, release_id = member(admin, origin, tenant, password, "release_manager")
    project = product.call("/api/projects", "POST", dict(code="reports", title="Report project", charter="Report accuracy",
                           product_owner_id=owner, business_signer_id=signer), 201)
    base = f"/api/projects/{project['id']}"
    for account in (tester_id, engineer_id, release_id):
        product.call(f"{base}/members/{account}", "PUT", {"enabled": True})

    req = product.call(base + "/requirements", "POST", dict(code="reports", title="Reports", summary="Export audit evidence"), 201)
    criterion = product.call(f"{base}/requirements/{req['id']}/criteria", "POST",
                             dict(code="c2", title="Date", statement="Include local date"), 201)
    original_scope = product.call(base + "/scope")
    b1 = product.call(base + "/baselines", "POST", dict(title="Initial", scope_digest=original_scope["scope_digest"]), 201)
    case_data = dict(code="report-date", criterion_id=criterion["id"], revision_id=criterion["revision"]["id"], title="Date check",
                     steps="Read exported file", expected_result="Local date", junit_class="Reports.Export", junit_name="date_column")
    case = tester.call(base + "/test-cases", "POST", case_data, 201)
    other_case = tester.call(base + "/test-cases", "POST",
                             dict(case_data, code="report-header", title="Header check", junit_name="header"), 201)
    case_url = f"{base}/test-cases/{case['id']}"
    assert tester.call(base + "/criteria/current")["items"][0]["revision_id"] == criterion["revision"]["id"]
    assert business.call(base + "/test-cases", "POST", dict(case_data, code="forbidden"), 403)["error"] == "project_tester_required"

    envs = base + "/environments"
    qa = release.call(envs, "POST", dict(code="qa", name="QA", stage="staging"), 201)
    other_env = release.call(envs, "POST", dict(code="sandbox", name="Sandbox", stage="development"), 201)
    assert tester.call(envs, "POST", dict(code="private", name="Private", stage="staging"), 403)["error"] == "project_release_manager_required"
    repo = product.call(base + "/repositories", "POST", dict(name="Reports", provider="manual", provider_repository_id="qa/reports"), 201)
    git_events = f"{base}/repositories/{repo['id']}/events"
    a, b = commit_series("report", 2)

    def event(kind, key, data):
        return product.call(git_events, "POST", dict(version=1, provider="manual", provider_repository_id="qa/reports",
                            type=kind, event_id=key, data=data))

    def artifact(name, commit, previous=None):
        snapshot = event("ref.updated", f"push-{name}", dict(branch="qa", commit_sha=commit,
                         before_sha=previous["commit_sha"] if previous else None, before_snapshot_id=previous["id"] if previous else None,
                         message=name, forced=False))["snapshot"]
        build = event("build.updated", f"build-{name}", dict(snapshot_id=snapshot["id"], commit_sha=commit,
                      run_id=name, run_sequence=snapshot["generation"], status="passed"))
        sha = hashlib.sha256(f"synthetic artifact {name} {commit}".encode()).hexdigest()
        payload = dict(build_id=build["build_id"], name="reports.tar", sha256=sha)
        created = release.call(base + "/artifacts", "POST", payload, 201)
        assert release.call(base + "/artifacts", "POST", payload, 201) == created
        return snapshot, created

    old, h1 = artifact("h1", a)
    _, h2 = artifact("h2", b, old)

    def plan(code, baseline, artifact_data, environment, versions, status=201):
        return tester.call(base + "/test-plans", "POST", dict(code=code, name=code, baseline_id=baseline["id"], artifact_id=artifact_data["id"],
                           environment_id=environment["id"], case_version_ids=versions), status)

    p1 = plan("initial", b1, h1, qa, [case["case_version_id"], other_case["case_version_id"]])
    p1url = f"{base}/test-plans/{p1['id']}"
    assert not p1["gate"]["ready"] and p1["gate"]["counts"]["missing"] == 2
    failed = b"""<testsuites><testsuite name="Export">
        <testcase classname="Reports.Export" name="date_column" time="0.042"><failure message="Date not in report">Fixture output</failure></testcase>
        <testcase classname="Reports.Export" name="header"><skipped message="Fixture paused"/></testcase>
        <testcase classname="Other" name="unrelated"/></testsuite></testsuites>"""
    passed = b'<testsuite><testcase classname="Reports.Export" name="date_column"/><testcase classname="Reports.Export" name="header"/></testsuite>'
    duplicate = b'<testsuite><testcase classname="Reports.Export" name="date_column"/><testcase classname="Reports.Export" name="date_column"/></testsuite>'
    assert upload(tester, p1url + "/reports", p1, 1, "dup", duplicate, 422)["error"] == "junit_duplicate_mapped_case"
    document_type = b'<!DOCTYPE testsuite><testsuite><testcase name="report-date"/></testsuite>'
    assert upload(tester, p1url + "/reports", p1, 1, "doctype", document_type, 422)["error"] == "junit_unsupported_xml"
    wrapped = b'<testsuite><system-out><testsuite><testcase classname="Reports.Export" name="date_column"/></testsuite></system-out></testsuite>'
    assert upload(tester, p1url + "/reports", p1, 1, "not-a-suite", wrapped, 422)["error"] == "junit_invalid_document"
    assert upload(tester, p1url + "/reports", p1, 1, "broken", passed[:-15], 422)["error"] == "junit_invalid_document"
    assert upload(tester, p1url + "/reports", p1, 1, "wrong", passed, 409,
                  **{"X-Delivery-Artifact-Sha256": h2["sha256"]})["error"] == "test_execution_target_mismatch"
    assert upload(product, p1url + "/reports", p1, 1, "bypass", passed, 403)["error"] == "project_tester_required"
    tester_peer = Session(peer)
    tester_peer.login(tenant, "tester@example.test", password)
    barrier = Barrier(2)

    def report(client):
        barrier.wait(timeout=5)
        return upload(client, p1url + "/reports", p1, 1, "initial-report", failed)

    with ThreadPoolExecutor(max_workers=2) as pool:
        receipt, replay = list(pool.map(report, [tester, tester_peer]))
    assert receipt == replay and receipt["matched_count"] == 2 and receipt["other_count"] == 1
    assert receipt["unmatched_cases"] == [dict(row=3, junit_class="Other", junit_name="unrelated",
                                               outcome="passed", reason="case_outside_plan")]
    assert receipt["unreported_cases"] == []
    assert len(tester.call(p1url + "/reports")["items"]) == 1
    assert upload(tester, p1url + "/reports", p1, 1, "initial-report", passed, 409)["error"] == "test_report_content_conflict"
    assert upload(tester, p1url + "/reports", p1, 1, "same-attempt", passed, 409)["error"] == "test_attempt_already_recorded"
    fail_id = receipt["results"][0]["id"]
    assert receipt["results"][0]["duration_ms"] == 42
    assert tester.call(p1url)["gate"]["counts"] == {"passed": 0, "failed": 1, "skipped": 1, "missing": 0}
    initial = f"{base}/test-reports/{receipt['id']}"
    with tester.client.open(origin + initial + "/xml") as raw:
        assert raw.read() == failed and raw.headers["Content-Type"] == "application/octet-stream"
    assert business.call(initial + "/xml", status=403)["error"] == "raw_report_access_required"
    assert "unmatched_cases" not in business.call(initial)
    assert outside.call(initial, status=404)["error"] == "project_not_found"
    defect = tester.call(base + "/defects", "POST", dict(failed_result_id=fail_id, severity="major",
                         title="Missing date", description="Invoice output has no date"), 201)
    bug = f"{base}/defects/{defect['id']}"
    assert tester.call(bug + "/close", "POST", dict(retest_result_id=fail_id, note="Premature"), 409)["error"] == "defect_requires_fix"
    assert engineer.call(bug + "/fix", "POST", dict(artifact_id=h1["id"], note="Old"), 422)["error"] == "defect_fix_requires_new_build"

    change = product.call(base + "/changes", "POST", dict(baseline_id=b1["id"], reason="Use ISO date", criteria=[
        dict(criterion_id=criterion["id"], title="Date", statement="Include ISO-8601 date")]), 201)
    approved = business.call(f"{base}/changes/{change['id']}/decision", "POST", dict(decision="approved", note="Confirm format"))
    revision = approved["criteria"][0]["approved_revision_id"]
    case2 = tester.call(case_url, "PUT", dict(case_data, version=1, revision_id=revision, expected_result="ISO date"))
    assert case2["version"] == 2 and case2["review_change_id"] is None
    assert tester.call(case_url, "PUT", dict(case_data, version=1, revision_id=revision), 409)["error"] == "test_case_version_conflict"
    other2 = tester.call(f"{base}/test-cases/{other_case['id']}", "PUT",
                         dict(case_data, version=1, revision_id=revision, title="Header check", junit_name="header"))
    b2 = product.call(base + "/baselines", "POST", dict(title="Revised", scope_digest=product.call(base + "/scope")["scope_digest"]), 201)
    assert plan("bad", b2, h2, qa, [case["case_version_id"]], 422)["error"] == "test_case_outside_plan_baseline"
    p2 = plan("revised", b2, h2, qa, [case2["case_version_id"], other2["case_version_id"]])
    p2url = f"{base}/test-plans/{p2['id']}"
    assert tester.call(p2url)["gate"]["counts"]["missing"] == 2
    assert engineer.call(bug + "/fix", "POST", dict(artifact_id=h2["id"], note="Implemented date"))["state"] == "fixed"
    old_pass = upload(tester, p1url + "/reports", p1, 2, "old-pass", passed)
    old_retest = old_pass["results"][0]["id"]
    assert tester.call(bug + "/close", "POST", dict(retest_result_id=old_retest, note="Old build"), 422)["error"] == "defect_retest_evidence_mismatch"
    assert tester.call(p1url + "/gate", "POST", dict(baseline_id=b1["id"], artifact_id=h1["id"], environment_id=qa["id"]))["ready"]
    mismatch = tester.call(p1url + "/gate", "POST", dict(baseline_id=b1["id"], artifact_id=h2["id"], environment_id=qa["id"]), 409)
    assert mismatch == {"error": "test_plan_target_mismatch", "details": {"field": "artifact_id"}}
    same_build_other = plan("sandbox-retest", b2, h2, other_env, [case2["case_version_id"]])

    def manual(target, attempt, outcome, source):
        return tester.call(f"{base}/test-plans/{target['id']}/manual", "POST",
                           dict(attempt=attempt, source_key=source, case_version_id=case2["case_version_id"],
                                artifact_sha256=target["sha256"], build_id=target["build_id"], environment_id=target["environment_id"],
                                outcome=outcome, summary="Manual observation"), 201)["results"][0]["id"]

    wrong_env = manual(same_build_other, 1, "passed", "wrong-environment")
    assert tester.call(bug + "/close", "POST", dict(retest_result_id=wrong_env, note="Sandbox"), 422)["error"] == "defect_retest_evidence_mismatch"
    last = upload(tester, p2url + "/reports", p2, 3, "retest-release", passed)
    assert upload(tester, p2url + "/reports", p2, 1, "late-fail", failed)["matched_count"] == 2
    retest = last["results"][0]["id"]
    assert tester.call(p2url)["gate"]["ready"]
    assert tester.call(bug + "/close", "POST", dict(retest_result_id=retest, note="QA verified report"))["state"] == "closed"
    closed = tester.call(bug)
    assert closed["failure"]["outcome"] == "failed" and closed["retest"]["outcome"] == "passed"
    assert closed["failure"]["build_id"] != closed["retest"]["build_id"]
    assert tester.call(initial) == receipt
    assert [v["version"] for v in tester.call(case_url)["items"]] == [1, 2]
    actions = [row["action"] for row in tester.call(case_url + "/timeline")["items"]]
    assert actions.count("test_result_recorded") == 5 and "defect_closed" in actions
    assert [row["action"] for row in tester.call(bug + "/timeline")["items"]] == ["defect_opened", "defect_fixed", "defect_closed"]
    partial = plan("partial-retry", b2, h2, qa, [case2["case_version_id"], other2["case_version_id"]])
    partial_url = f"{base}/test-plans/{partial['id']}"
    date_only = b'<testsuite><testcase classname="Reports.Export" name="date_column"/></testsuite>'
    header_only = b'<testsuite><testcase classname="Reports.Export" name="header"/></testsuite>'
    outside_only = b'<testsuite><testcase classname="Other" name="unrelated"/></testsuite>'
    rejected = upload(tester, partial_url + "/reports", partial, 1, "rejected", outside_only, 422)
    assert rejected == {"error": "junit_no_mapped_cases",
                        "details": {"unmatched_rows": [dict(row=1, junit_class="Other", junit_name="unrelated")]}}
    initial_partial = upload(tester, partial_url + "/reports", partial, 1, "partial", date_only)
    header_version = other2["case_version_id"]
    assert [row["case_version_id"] for row in initial_partial["unreported_cases"]] == [header_version]
    retry_url = partial_url + "/report-retries"
    retry_request = dict(source_report_id=initial_partial["id"], case_version_ids=[header_version],
                         source_key="retry-header", attempt=2, environment_id=partial["environment_id"],
                         build_id=partial["build_id"], artifact_sha256=partial["sha256"],
                         xml_base64=base64.b64encode(header_only).decode())
    assert business.call(retry_url, "POST", retry_request, 403)["error"] == "project_tester_required"
    assert tester.call(retry_url, "POST", dict(retry_request, source_report_id=receipt["id"]), 404)["error"] == "test_report_not_found"
    assert tester.call(retry_url, "POST", dict(retry_request, case_version_ids=[case2["case_version_id"]]), 422)["error"] == "test_retry_case_ineligible"
    extra = tester.call(retry_url, "POST", dict(retry_request, xml_base64=base64.b64encode(passed).decode()), 422)
    assert extra["details"]["extra_case_version_ids"] == [case2["case_version_id"]]
    assert tester.call(partial_url)["gate"]["counts"] == {"passed": 1, "failed": 0, "skipped": 0, "missing": 1}
    retry_barrier = Barrier(2)

    def retry_selected(client):
        retry_barrier.wait(timeout=5)
        return client.call(retry_url, "POST", retry_request, 201)

    with ThreadPoolExecutor(max_workers=2) as pool:
        retried, duplicate_retry = list(pool.map(retry_selected, [tester, tester_peer]))
    assert retried == duplicate_retry and retried["retry_of_report_id"] == initial_partial["id"]
    assert [row["case_version_id"] for row in retried["results"]] == [header_version]
    assert tester.call(partial_url)["gate"]["ready"]
    assert tester.call(f"{base}/test-reports/{initial_partial['id']}") == initial_partial
    assert upload(tester, partial_url + "/reports", partial, 2, "retry-header", header_only, 409)["error"] == "test_report_content_conflict"
    assert len(tester.call(partial_url + "/reports")["items"]) == 2
    print("AC-07 and test-gate contract passed: immutable JUnit evidence, scoped outcomes, ordered runs, and exact-build defect revalidation.")
    print("JUnit partial receipt and exact selection passed: per-row rejects, source linkage, unchanged evidence, and one concurrent retry.")


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])
