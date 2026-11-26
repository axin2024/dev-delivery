"""Verify deployment-bound acceptance and the immutable delivery report."""

from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
import hashlib
import json
from threading import Barrier

from release_candidates_http import content
from testing_http import upload


def verify_signoff(base, baseline, plan, previous, production, owner, signer, tester, outside, fixture):
    prefix = f"{base}/signoffs"
    case = f"{prefix}/{production['id']}"
    report = case + "/report"
    date, header = baseline["criteria"]
    date_url = f"{case}/criteria/{date['criterion_id']}"
    header_url = f"{case}/criteria/{header['criterion_id']}"
    date_decision = dict(revision_id=date["revision_id"], decision="accepted", note="Dispatch date verified")
    block = dict(revision_id=header["revision_id"], decision="blocked", note="The business glossary still needs a review")
    due = (datetime.now(timezone.utc).date() + timedelta(days=7)).isoformat()
    waiver = dict(block, decision="waived", note="Operations accepted the existing abbreviation for this release",
                  responsible_id=fixture["engineer_id"], retest_due_on=due)

    assert not owner.call(case)["can_decide"] and signer.call(case)["can_decide"]
    assert outside.call(case, status=404)["error"] == "project_not_found"
    assert [row["id"] for row in signer.call(prefix)["items"]] == [previous["id"], production["id"]]
    assert signer.call(f"{prefix}/{production['promotion_source_id']}", status=404)["error"] == "signoff_deployment_not_found"
    assert signer.call(f"{prefix}/{previous['id']}/criteria/{date['criterion_id']}", "PUT", date_decision, 409)["error"] == "signoff_deployment_no_longer_current"
    assert owner.call(date_url, "PUT", date_decision, 403)["error"] == "project_business_signer_required"
    assert outside.call(report, status=404)["error"] == "project_not_found"
    assert signer.call(report, status=404)["error"] == "delivery_report_not_found"
    assert signer.call(date_url, "PUT", dict(date_decision, revision_id=header["revision_id"]), 422)["error"] == "signoff_criterion_outside_release"
    assert signer.call(header_url, "PUT", waiver, 422)["error"] == "signoff_waiver_requires_block_and_due_date"

    current = signer.call(case + "/report-preview")
    approved_ids = current["content"]["approval"]["result_ids"]
    traces = {item["criterion_id"]: item for item in current["content"]["criteria"]}
    assert current["content"]["candidate"]["manifest"]["commit_sha"] == fixture["commit_sha"]
    assert current["content"]["candidate"]["manifest"]["artifact_sha256"] == production["artifact_sha256"]
    assert current["content"]["approval"]["gate_digest"] == production["approved_gate_digest"]
    assert current["content"]["approval"]["approved_by"] == fixture["approval_delegate_id"]
    assert current["content"]["approval"]["delegated_by"] == fixture["approval_grantor_id"]
    assert current["content"]["approval"]["delegation_id"] == fixture["approval_delegation_id"]
    assert [leg["stage"] for leg in current["content"]["deployment"]] == ["production", "staging", "development"]
    assert current["content"]["deployment"][0]["id"] == production["id"]
    assert all(leg["completion_event_id"] for leg in current["content"]["deployment"])
    work = traces[date["criterion_id"]]["work_items"][0]
    assert work["id"] == fixture["task_id"] and traces[date["criterion_id"]]["requirement"]["id"] == fixture["requirement_id"]
    assert [link["matches_build_commit"] for link in work["git_links"]] == [0, 1]
    assert work["git_links"][-1]["commit_sha"] == fixture["commit_sha"]
    retest = traces[header["criterion_id"]]["defect_retests"][0]
    assert [retest[k] for k in ("id", "failed_result_id", "retest_result_id", "original_outcome")] == [
        fixture["defect_id"], fixture["failed_id"], fixture["retest_id"], "failed"]
    assert retest["used_for_gate"]
    assert next(result for result in traces[header["criterion_id"]]["test_results"])["id"] == fixture["retest_id"]

    failed_xml = b'<testsuite><testcase name="header"><failure message="After production"/></testcase></testsuite>'
    upload(tester, f"{base}/test-plans/{plan['id']}/reports", plan, 78, "post-production", failed_xml)
    assert not owner.call(f"{base}/candidates/{production['candidate_id']}")["approval_valid"]
    assert signer.call(case + "/report-preview")["content"]["approval"]["result_ids"] == approved_ids

    signed = signer.call(date_url, "PUT", date_decision)
    assert signed["decision"] == "accepted" and signed["signer_name"] == "business"
    assert fixture["peer"].call(date_url, "PUT", date_decision, 409)["details"]["decision_id"] == signed["id"]
    blocked = signer.call(header_url, "PUT", block)
    prior_digest = signer.call(case + "/report-preview")["digest"]
    rejected = signer.call(report, "POST", {"digest": prior_digest}, 409)
    assert rejected == {"error": "signoff_requires_resolution", "details": {"criterion_ids": [header["criterion_id"]]}}
    waiver["expected_decision_id"] = blocked["id"]
    assert signer.call(header_url, "PUT", dict(waiver, retest_due_on="2001-01-01"), 422)["error"] == "signoff_waiver_requires_block_and_due_date"
    exception = signer.call(header_url, "PUT", waiver)
    assert exception["responsible_name"] == "engineer" and exception["retest_due_on"] == due
    assert [row["decision"] for row in signer.call(header_url)["items"]] == ["blocked", "waived"]
    assert signer.call(report, "POST", {"digest": prior_digest}, 409)["error"] == "signoff_preview_changed"
    preview = signer.call(case + "/report-preview")
    assert owner.call(report, "POST", {"digest": preview["digest"]}, 403)["error"] == "project_business_signer_required"
    barrier = Barrier(2)

    def archive(client):
        barrier.wait(timeout=10)
        return client.call(report, "POST", {"digest": preview["digest"]}, 201)

    with ThreadPoolExecutor(max_workers=2) as pool:
        archived, replayed = pool.map(archive, [signer, fixture["peer"]])
    assert archived == replayed and archived["digest"] == preview["digest"]
    filename = f"delivery-report-{production['id']}.json"
    frozen = content(owner, report, filename=filename)
    assert hashlib.sha256(frozen).hexdigest() == archived["digest"] and json.loads(frozen) == preview["content"]
    assert signer.call(date_url, "PUT", dict(date_decision, expected_decision_id=signed["id"]), 409)["error"] == "signoff_report_archived"
    assert owner.call(f"{base}/candidates/{production['candidate_id']}")["state"] == "accepted"
    assert [item["action"] for item in owner.call(case + "/timeline")["items"]] == [
        "signoff_accepted", "signoff_blocked", "signoff_waived", "delivery_report_archived"]

    change = owner.call(base + "/changes", "POST", dict(baseline_id=baseline["id"], reason="Check the release signoff impact",
                        criteria=[dict(criterion_id=header["criterion_id"], title="Glossary review", statement="Use business names")]), 201)
    impact = change["impact"][0]
    assert [item["id"] for item in impact["signoff_items"]] == [exception["id"]] and "signoff_items" not in impact["missing_links"]
    assert content(signer, report, filename=filename) == frozen
    print("AC-13 and AC-14 passed: exact release evidence, named waivers, frozen reports, and serialized archive.")
    return archived["digest"]
