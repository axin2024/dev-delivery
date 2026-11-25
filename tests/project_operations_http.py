"""Verify milestone timing, ownership transfers, and isolated delivery packages."""

from datetime import datetime, timedelta, timezone
import hashlib
import json

from release_candidates_http import content


def start_milestone(base, baseline, production, owner, business, outside, fixture):
    milestone = owner.call(base + "/milestones", "POST", dict(code="export-rollout", title="Dispatch delivery",
                           baseline_id=baseline["id"], environment_id=production["id"],
                           owner_id=fixture["engineer_id"], due_on="2020-01-01"), 201)
    path = f"{base}/milestones/{milestone['id']}"
    assert milestone["phase"] == "development" and milestone["overdue"]
    assert milestone["elapsed"]["development"]["end_at"] is None
    assert outside.call(path, status=404)["error"] == "project_not_found"
    assert business.call(path + "/metrics", "POST", status=403)["error"] == "project_product_owner_required"
    assert owner.call(f"{base}/members/{fixture['reviewer_id']}", "PUT", {"enabled": True}, 422)["error"] == "external_reviewer_cannot_join_project"
    return milestone


def check_waiting(base, milestone, deployment, owner, business):
    path = f"{base}/milestones/{milestone['id']}"
    item = business.call(path)
    assert item["phase"] == "waiting_for_signoff" and item["overdue"]
    assert len(item["signoff_blocking_criterion_ids"]) == 2 and item["production_id"] == deployment["id"]
    assert business.call(base + "/milestones")["items"][0]["phase"] == "waiting_for_signoff"
    elapsed = item["elapsed"]
    assert elapsed["development"]["end_at"] == item["candidate_created_at"]
    assert elapsed["waiting_for_test"]["start_at"] == item["candidate_created_at"]
    assert elapsed["waiting_for_test"]["end_at"] == item["tests_started_at"]
    assert elapsed["waiting_for_signoff"]["start_at"] == deployment["completed_at"] and elapsed["waiting_for_signoff"]["end_at"] is None
    snapshot = owner.call(path + "/metrics", "POST", status=201)
    assert snapshot["data"]["phase"] == "waiting_for_signoff" and snapshot["window_start"] == item["baseline_frozen_at"]
    assert snapshot["data"]["elapsed"]["waiting_for_signoff"]["elapsed_ms"] >= elapsed["waiting_for_signoff"]["elapsed_ms"]
    assert snapshot["data"]["elapsed"]["development"] == elapsed["development"]
    rebind = dict(version=milestone["version"], candidate_id=None, due_on=milestone["due_on"])
    assert owner.call(path, "PUT", rebind, 409)["error"] == "milestone_release_started"


def verify_operations(base, baseline, milestone, deployment, owner, business, fixture):
    path = f"{base}/milestones/{milestone['id']}"
    accepted = business.call(path)
    assert accepted["phase"] == "accepted" and not accepted["overdue"] and accepted["signed_off_late"]
    assert accepted["elapsed"]["waiting_for_signoff"]["end_at"] == accepted["signed_off_at"]
    previous_snapshot = business.call(path + "/metrics")["items"][0]
    assert previous_snapshot["data"]["phase"] == "waiting_for_signoff"
    assert previous_snapshot["data"]["elapsed"]["development"] == accepted["elapsed"]["development"]

    transfer = dict(expected_owner_id=fixture["engineer_id"], next_owner_id=fixture["next_engineer_id"], reason="Hand over the dispatch rollout")
    assert business.call(path + "/ownership", "PUT", transfer, 403)["error"] == "project_product_owner_required"
    owner.call(path + "/ownership", "PUT", transfer)
    assert owner.call(path + "/ownership", "PUT", transfer, 409)["error"] == "ownership_changed"
    assert business.call(path)["owner_id"] == fixture["next_engineer_id"]
    assert business.call(path + "/ownership")["items"][0]["previous_owner_id"] == fixture["engineer_id"]
    assert "ownership_transferred" in [entry["action"] for entry in business.call(path + "/timeline")["items"]]

    task = f"{base}/work-items/{fixture['task_id']}"
    original_links = owner.call(task + "/git-links")
    owner.call(task + "/ownership", "PUT", transfer)
    history = business.call(task + "/ownership")["items"][0]
    assert history["previous_claimed_by"] == fixture["engineer_id"] and history["previous_claimed_at"]
    assert not owner.call(task)["claimed_by"]
    assert fixture["engineer"].call(task + "/claim", "POST", status=403)["error"] == "work_item_owner_required"
    assert fixture["next_engineer"].call(task + "/claim", "POST")["claimed_by"] == fixture["next_engineer_id"]
    assert owner.call(task + "/git-links") == original_links
    print("AC-15 passed: milestone delay attribution, durable metric snapshots, and ownership transfer history.")

    packages = base + "/review-packages"
    date, header = baseline["criteria"]
    info = dict(deployment_id=deployment["id"], title="Partner dispatch review", criterion_ids=[date["criterion_id"]])
    assert owner.call(packages, "POST", dict(info, criterion_ids=[999_999]), 422)["error"] == "package_criterion_outside_report"
    assert business.call(packages, "POST", info, 403)["error"] == "project_product_owner_required"
    one = owner.call(packages, "POST", info, 201)
    two = owner.call(packages, "POST", dict(info, title="Restricted header review", criterion_ids=[header["criterion_id"]]), 201)
    recipient = fixture["reviewer"]
    other = fixture["other_reviewer"]
    reviewer_path = "/api/review-packages"
    one_external = f"{reviewer_path}/{one['id']}"
    other_external = f"{reviewer_path}/{two['id']}"
    assert recipient.call(reviewer_path)["items"] == []
    assert recipient.call(one_external, status=404)["error"] == "review_package_not_found"
    grants = f"{packages}/{one['id']}/grants"
    grant = f"{grants}/{fixture['reviewer_id']}"
    until = (datetime.now(timezone.utc).date() + timedelta(days=14)).isoformat()
    assert owner.call(grant, "PUT", dict(expires_on="2010-01-01"), 422)["error"] == "review_grant_date_in_past"
    assert owner.call(f"{grants}/{fixture['engineer_id']}", "PUT", dict(expires_on=until), 422)["error"] == "external_reviewer_required"
    assert owner.call(grant, "PUT", dict(expires_on=until))["expires_on"] == until
    owner.call(f"{packages}/{two['id']}/grants/{fixture['other_reviewer_id']}", "PUT", dict(expires_on=until))
    assert [item["id"] for item in recipient.call(reviewer_path)["items"]] == [one["id"]]
    assert [item["id"] for item in other.call(reviewer_path)["items"]] == [two["id"]]
    assert not fixture["external_other_tenant"].call(reviewer_path)["items"]
    assert fixture["external_other_tenant"].call(one_external, status=404)["error"] == "review_package_not_found"
    assert recipient.call(other_external + "/content", status=404)["error"] == "review_package_not_found"
    assert other.call(one_external, status=404)["error"] == "review_package_not_found"
    assert recipient.call(base + "/signoffs/" + str(deployment["id"]) + "/report", status=404)["error"] == "route_not_found"
    for forbidden in ("/api/projects", base, base + "/test-plans", base + "/repositories", "/api/accounts", grants, path):
        assert recipient.call(forbidden, status=404)["error"] == "route_not_found"
    external = recipient.call(one_external)
    assert set(external) == {"id", "title", "digest", "created_at", "content", "expires_on"}
    assert set(external["content"]) == {"version", "title", "release", "released_at", "criteria"}
    assert [row["code"] for row in external["content"]["criteria"]] == [date["code"]]
    assert set(external["content"]["criteria"][0]) == {"requirement", "code", "version", "title", "statement", "status", "retest_due_on"}
    blob = content(recipient, one_external + "/content", filename=f"review-package-{one['id']}.json")
    assert hashlib.sha256(blob).hexdigest() == external["digest"] and json.loads(blob) == one["content"]
    assert owner.call(grants)["items"][0]["active"]
    assert owner.call(grant + "/revoke", "POST")["revoked_at"]
    assert recipient.call(one_external + "/content", status=404)["error"] == "review_package_not_found"
    assert recipient.call(reviewer_path)["items"] == []
    assert not owner.call(grants)["items"][0]["active"]
    assert [item["action"] for item in owner.call(f"{packages}/{one['id']}/timeline")["items"]] == [
        "review_package_created", "review_grant_saved", "review_grant_revoked"]
    print("AC-16 passed: per-package access, exported fields, tenant isolation, and revocation.")
