"""Exercise three-stage promotion, timeout reconciliation and artifact rollback."""

from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
from threading import Barrier
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from project_operations_http import start_milestone, check_waiting, verify_operations
from release_candidates_http import content, run
from signoffs_http import verify_signoff
from testing_http import upload


def send_signed(origin, connector, event, status=200, *, corrupt=False):
    body = json.dumps(event, separators=(",", ":")).encode()
    now = str(int(time.time()))
    signature = hmac.new(connector["signing_secret"].encode(), now.encode() + b"." + body, hashlib.sha256).hexdigest()
    if corrupt:
        signature = "0" * 64
    headers = {"Content-Type": "application/json", "X-Delivery-Timestamp": now, "X-Delivery-Signature": "sha256=" + signature}
    try:
        response = urlopen(Request(origin + connector["path"], body, headers, method="POST"), timeout=15)
    except HTTPError as error:
        response = error
    with response:
        result = json.load(response)
        assert response.status == status, (response.status, result)
        return result


def exercise(base, baseline, plans, dev, staging, release, peer, owner, business, tester, outside, git_event, first_commit, hotfix_commit, trace):
    prod = release.call(base + "/environments", "POST", dict(code="prod", name="Production", stage="production"), 201)
    secondary_qa = release.call(base + "/environments", "POST", dict(code="qa2", name="QA secondary", stage="staging"), 201)
    endpoint = base + "/deployments"
    prod_connector = f"{base}/environments/{prod['id']}/deployment-connector"
    connector = release.call(prod_connector, "POST")
    assert peer.call(prod_connector, "POST") == connector
    dev_connector = release.call(f"{base}/environments/{dev['id']}/deployment-connector", "POST")
    assert dev_connector["signing_secret"] != connector["signing_secret"]
    assert tester.call(prod_connector, "POST", status=403)["error"] == "project_release_manager_required"
    current = {env["id"]: None for env in [dev, staging, prod]}
    milestone = start_milestone(base, baseline, prod, owner, business, outside, trace)

    def candidate(plan, n):
        item = release.call(base + "/candidates", "POST", dict(code=f"prod-{n}", title=f"Ship {n}", baseline_id=baseline["id"],
                            test_plan_id=plan["id"], artifact_id=plan["artifact_id"]), 201)
        address = f"{base}/candidates/{item['id']}"
        proof = dict(manifest_digest=item["manifest_digest"])
        for step in ("freeze", "start-testing"):
            release.call(address + "/" + step, "POST", proof)
        return item, address, proof

    def request_data(env, selected, source=None, key=None):
        return dict(environment_id=env["id"], expected_head_id=current.get(env["id"]), artifact_sha256=selected["manifest"]["artifact_sha256"],
                    candidate_id=selected["id"], request_key=key or f"promote/{selected['id']}/{env['code']}", promotion_source_id=source,
                    reason="Publish the verified dispatch export")

    def receipt(deployment, status, sequence, **overrides):
        return dict(dict(version=1, event_id=f"event-{deployment['id']}-{sequence}", deployment_id=deployment["id"],
                    environment_id=deployment["environment_id"], request_key=deployment["request_key"], artifact_sha256=deployment["artifact_sha256"],
                    external_run_id=f"tool-run-{deployment['id']}", sequence=sequence, status=status), **overrides)

    def offline(deployment, event, status=200):
        return release.call(f"{endpoint}/{deployment['id']}/receipts", "POST", event, status)

    def execute(deployment, root):
        # Represent the deployment tool by atomically replacing a local environment file.
        blob = content(release, f"{base}/artifacts/{deployment['artifact_id']}/content")
        assert hashlib.sha256(blob).hexdigest() == deployment["artifact_sha256"]
        live = root / f"env-{deployment['environment_id']}.bin"
        temporary = root / f"op-{deployment['id']}.bin"
        temporary.write_bytes(blob)
        temporary.replace(live)
        return live

    def finish_offline(deployment, root):
        assert offline(deployment, receipt(deployment, "running", 1))["applied"]
        execute(deployment, root)
        assert offline(deployment, receipt(deployment, "succeeded", 2))["applied"]
        current[deployment["environment_id"]] = deployment["id"]

    prod1, prod2, hotfix = None, None, None
    with TemporaryDirectory(prefix="delivery-worker-") as directory:
        root = Path(directory)
        for number, plan in enumerate(plans, start=1):
            selected, address, proof = candidate(plan, number)
            if number == 2:
                milestone = owner.call(f"{base}/milestones/{milestone['id']}", "PUT", dict(
                    version=milestone["version"], candidate_id=selected["id"], due_on=milestone["due_on"]))
                assert milestone["phase"] == "testing" and milestone["quality"]["counts"]["passed"] == 2
            wrong = request_data(dev, selected)
            assert release.call(endpoint, "POST", dict(wrong, artifact_sha256="f" * 64), 409)["error"] == "deployment_artifact_mismatch"
            assert release.call(endpoint, "POST", request_data(staging, selected), 422)["error"] == "deployment_promotion_source_invalid"
            assert release.call(endpoint, "POST", request_data(prod, selected), 409)["error"] == "deployment_candidate_approval_required"
            assert tester.call(endpoint, "POST", wrong, 403)["error"] == "project_release_manager_required"
            dev_request = release.call(endpoint, "POST", wrong, 201)
            assert offline(dev_request, receipt(dev_request, "succeeded", 2), 409)["error"] == "deployment_start_receipt_required"
            assert release.call(endpoint, "POST", dict(wrong, request_key="another-active"), 409)["error"] == "environment_has_active_deployment"
            finish_offline(dev_request, root)
            mismatch = request_data(secondary_qa, selected, dev_request["id"])
            assert release.call(endpoint, "POST", mismatch, 409)["error"] == "deployment_staging_target_mismatch"
            stage_request = release.call(endpoint, "POST", request_data(staging, selected, dev_request["id"]), 201)
            finish_offline(stage_request, root)
            pending = release.call(address + "/submit", "POST", proof)
            approval = dict(proof, gate_digest=pending["gate_digest"], note="Reviewed for production")
            if number == 1:
                owner.call(address + "/approve", "POST", approval)
            else:
                grant = dict(proof, gate_digest=pending["gate_digest"], expected_delegation_id=None,
                             delegate_id=trace["approval_delegate_id"], reason="Production review coverage")
                delegation = owner.call(address + "/approval-delegation", "PUT", grant)["approval_delegation"]
                trace["approval_delegation_id"] = delegation["id"]
                trace["approval_delegate"].call(address + "/approve", "POST", dict(approval, delegation_id=delegation["id"]))
            assert release.call(endpoint, "POST", request_data(prod, selected, dev_request["id"]), 409)["error"] == "deployment_promotion_source_mismatch"
            payload = request_data(prod, selected, stage_request["id"])
            barrier = Barrier(2)

            def enqueue(client):
                barrier.wait(timeout=10)
                return client.call(endpoint, "POST", payload, 201)

            with ThreadPoolExecutor(max_workers=2) as pool:
                operation, duplicate = pool.map(enqueue, [release, peer])
            assert operation == duplicate
            queue_url = f"{endpoint}/{operation['id']}"
            assert outside.call(queue_url, status=404)["error"] == "project_not_found"
            assert peer.call(endpoint, "POST", dict(payload, reason="Conflicting"), 409)["error"] == "deployment_request_conflict"
            begin = receipt(operation, "running", 1)
            assert send_signed(peer.origin, connector, begin, 401, corrupt=True)["error"] == "connector_signature_invalid"
            assert send_signed(peer.origin, dev_connector, begin, 403)["error"] == "deployment_connector_environment_mismatch"
            assert send_signed(peer.origin, connector, begin)["status"] == "running"
            live = execute(operation, root)
            result = receipt(operation, "succeeded", 3)
            if number == 2:
                assert release.call(queue_url + "/timeout", "POST", dict(request_key=payload["request_key"], reason="Tool response timed out"))["status"] == "unknown"
                query = urlencode(dict(environment_id=prod["id"], request_key=payload["request_key"]))
                observed = peer.call(endpoint + "/lookup?" + query)
                assert observed["status"] == "unknown" and observed["current_head_id"] == prod1["id"]
                assert live.read_bytes() == content(release, f"{base}/artifacts/{operation['artifact_id']}/content")
                report = b'<testsuite><testcase name="date"/></testsuite>'
                assert upload(tester, f"{base}/test-plans/{plan['id']}/reports", plan, 77, "during-production", report, 409)["error"] == "deployment_uses_test_plan"
                criterion = baseline["criteria"][0]
                proposal = owner.call(base + "/changes", "POST", dict(baseline_id=baseline["id"], reason="Another contract update",
                                      criteria=[dict(criterion_id=criterion["criterion_id"], title="Date", statement="Show the year")]), 201)
                decision = f"{base}/changes/{proposal['id']}/decision"
                assert business.call(decision, "POST", dict(decision="approved", note="Reviewed"), 409)["error"] == "deployment_uses_criteria"
                business.call(decision, "POST", dict(decision="rejected", note="Withdrawn for current release"))
                assert peer.call(endpoint, "POST", payload, 201)["status"] == "unknown"
            barrier = Barrier(2)

            def deliver(origin):
                barrier.wait(timeout=10)
                return send_signed(origin, connector, result)

            with ThreadPoolExecutor(max_workers=2) as pool:
                done, retried = pool.map(deliver, [release.origin, peer.origin])
            assert done == retried and done["applied"] and done["status"] == "succeeded"
            assert not send_signed(peer.origin, connector, receipt(operation, "running", 2))["applied"]
            assert send_signed(peer.origin, connector, dict(result, status="failed"), 409)["error"] == "deployment_receipt_conflict"
            assert send_signed(peer.origin, connector, receipt(operation, "failed", 4), 409)["error"] == "deployment_outcome_already_final"
            timeline = release.call(queue_url + "/timeline")["items"]
            assert sum(row["action"] == "deployment_succeeded" for row in timeline) == 1
            assert timeline[-1]["actor_id"] is None
            assert release.call(address)["state"] == "deployed"
            current[prod["id"]] = operation["id"]
            if number == 1:
                prod1 = release.call(queue_url)
                hotfix = check_parallel_reviews(base, baseline, plans[1], prod, prod1, selected, first_commit, hotfix_commit,
                                                git_event, release, owner, tester)
            else:
                prod2 = release.call(queue_url)
                changed = release.call(f"{base}/candidates/{hotfix['id']}")
                assert not changed["approval_valid"] and not changed["quality"]["production_base_current"]

        check_waiting(base, milestone, prod2, owner, business)
        archived = verify_signoff(base, baseline, plans[1], prod1, prod2, owner, business, tester, outside, trace)
        verify_operations(base, baseline, milestone, prod2, owner, business, trace)
        request = dict(environment_id=prod["id"], expected_head_id=prod2["id"], request_key="rollback/old-export",
                       reason="Restore the earlier approved export", artifact_sha256=prod1["artifact_sha256"])
        rollback_path = f"{endpoint}/{prod2['id']}/rollback"
        rollback = release.call(rollback_path, "POST", request, 201)
        assert rollback["id"] not in [prod1["id"], prod2["id"]] and rollback["kind"] == "rollback"
        assert rollback["previous_head_id"] == prod2["id"] and rollback["restored_from_id"] == prod1["id"]
        assert rollback["candidate_id"] == prod1["candidate_id"] and rollback["artifact_id"] == prod1["artifact_id"]
        assert send_signed(release.origin, connector, receipt(rollback, "running", 1))["applied"]
        live = execute(rollback, root)
        assert send_signed(peer.origin, connector, receipt(rollback, "succeeded", 2))["applied"]
        assert live.read_bytes() == content(release, f"{base}/artifacts/{prod1['artifact_id']}/content")
        assert release.call(f"{endpoint}/{prod2['id']}")["current_head_id"] == rollback["id"]
        assert release.call(rollback_path, "POST", request, 201)["id"] == rollback["id"]
        old = business.call(f"{base}/signoffs/{prod2['id']}")
        assert old["current_head_id"] == rollback["id"] and old["report"]["digest"] == archived and not old["can_decide"]
        assert business.call(f"{base}/signoffs/{rollback['id']}", status=404)["error"] == "signoff_deployment_not_found"
    print("AC-11 and AC-12 passed: artifact promotion, timeout readback, exactly-once confirmation and rollback lineage.")


def check_parallel_reviews(base, baseline, next_plan, prod, installed, installed_candidate, original_sha, fork_sha,
                           git_event, release, owner, tester):
    env = release.call(base + "/environments", "POST", dict(code="hotfix-qa", name="Hotfix staging", stage="staging"), 201)
    branch = "hotfix/export"
    initial = git_event("ref.updated", "fork-from-production", dict(branch=branch, before_sha=None, before_snapshot_id=None,
                        commit_sha=original_sha, forced=False, message="Start at real production commit"))["snapshot"]
    assert initial["commit_sha"] == installed_candidate["manifest"]["commit_sha"]
    fixed = git_event("ref.updated", "push-hotfix", dict(branch=branch, before_sha=original_sha, before_snapshot_id=initial["id"],
                      commit_sha=fork_sha, forced=False, message="One-off hotfix"))["snapshot"]
    build = git_event("build.updated", "build-hotfix", dict(snapshot_id=fixed["id"], commit_sha=fork_sha, status="passed",
                      run_id="hotfix-package", run_sequence=1))
    blob = b"printed hotfix\x00\xfa"
    sha = hashlib.sha256(blob).hexdigest()
    artifact = release.call(base + "/artifacts", "POST", dict(build_id=build["build_id"], name="hotfix.bin", sha256=sha), 201)
    content(release, f"{base}/artifacts/{artifact['id']}/content", blob)
    versions = [row["case_version_id"] for row in next_plan["gate"]["cases"]]
    plan = tester.call(base + "/test-plans", "POST", dict(code="hotfix", name="Hotfix plan", baseline_id=baseline["id"],
                       artifact_id=artifact["id"], environment_id=env["id"], case_version_ids=versions), 201)
    upload(tester, f"{base}/test-plans/{plan['id']}/reports", plan, 1, "hotfix-passed",
           b'<testsuite><testcase name="date"/><testcase name="header"/></testsuite>')
    candidates = base + "/candidates"
    main_fields = dict(code="formal-2026", title="Regular release", baseline_id=baseline["id"], artifact_id=next_plan["artifact_id"],
                       test_plan_id=next_plan["id"])
    patch_fields = dict(code="emergency-2026", title="Production hotfix", baseline_id=baseline["id"], artifact_id=artifact["id"],
                        test_plan_id=plan["id"], track="hotfix", production_environment_id=prod["id"], production_base_id=installed["id"])
    assert release.call(candidates, "POST", dict(patch_fields, production_base_id=installed["promotion_source_id"]), 409)["error"] == "hotfix_production_base_changed"
    assert release.call(candidates, "POST", dict(patch_fields, artifact_id=next_plan["artifact_id"], test_plan_id=next_plan["id"]), 422)["error"] == "hotfix_requires_branch_from_production"
    forced_branch = "hotfix/rewritten"
    old = git_event("ref.updated", "fork-rewritten", dict(branch=forced_branch, before_sha=None, before_snapshot_id=None,
                    commit_sha=original_sha, forced=False, message="Start at production commit"))["snapshot"]
    rewritten = git_event("ref.updated", "force-hotfix", dict(branch=forced_branch, before_sha=original_sha, before_snapshot_id=old["id"],
                          commit_sha=fork_sha, forced=True, message="Forced branch rewrite"))["snapshot"]
    forced_build = git_event("build.updated", "build-forced", dict(snapshot_id=rewritten["id"], commit_sha=fork_sha, status="passed",
                             run_id="rewritten-hotfix-package", run_sequence=1))
    forced = release.call(base + "/artifacts", "POST", dict(build_id=forced_build["build_id"], name="rewritten.bin", sha256=sha), 201)
    assert release.call(candidates, "POST", dict(patch_fields, artifact_id=forced["id"]), 422)["error"] == "hotfix_requires_branch_from_production"
    main = release.call(candidates, "POST", main_fields, 201)
    patch = release.call(candidates, "POST", patch_fields, 201)
    assert patch["track"] == "hotfix" and main["track"] == "release"
    assert patch["manifest"]["commit_sha"] == fork_sha and patch["manifest"]["production_base"]["deployment_id"] == installed["id"]
    assert patch["manifest"]["production_base"]["fork_snapshot_id"] == initial["id"]
    for item in [main, patch]:
        url = f"{candidates}/{item['id']}"
        proof = dict(manifest_digest=item["manifest_digest"])
        for action in ["freeze", "start-testing", "submit"]:
            assert release.call(url + "/" + action, "POST", proof)["state"] in ["frozen", "testing", "pending_approval"]
    regular_review, patch_review = [owner.call(f"{candidates}/{item['id']}") for item in [main, patch]]
    assert regular_review["state"] == patch_review["state"] == "pending_approval"
    for review in [patch_review, regular_review]:
        assert owner.call(f"{candidates}/{review['id']}/approve", "POST", dict(manifest_digest=review["manifest_digest"],
                          gate_digest=review["gate_digest"], note="Reviewed separately"))["approval_valid"]
    alternate = release.call(base + "/environments", "POST", dict(code="prod2", name="Second production", stage="production"), 201)
    assert release.call(base + "/deployments", "POST", dict(environment_id=alternate["id"], candidate_id=patch["id"], artifact_sha256=sha,
                        request_key="wrong-hotfix-target", reason="Attempt another target"), 409)["error"] == "hotfix_production_target_mismatch"
    print("AC-09 passed: independently reviewable release and hotfix candidates rooted at an actual production deployment.")
    return patch


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2], exercise)
