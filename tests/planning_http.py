"""Exercise change impact and cross-team planning against MySQL and HTTP."""

from concurrent.futures import ThreadPoolExecutor
import os
import re
import secrets
import sys
from threading import Barrier

from baselines_http import Session, provision, member


def run(origin, peer):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    password = secrets.token_hex(16)
    tenant = "planning-" + secrets.token_hex(5)
    admin = provision(origin, tenant, password)
    outside = provision(origin, "outside-" + secrets.token_hex(5), password)
    product, owner = member(admin, origin, tenant, password, "product")
    signer, reviewer = member(admin, origin, tenant, password, "business")
    _, engineer = member(admin, origin, tenant, password, "engineer")
    project = product.call("/api/projects", "POST", dict(code="fulfillment", title="Fulfillment", charter="Ship an integration",
                           product_owner_id=owner, business_signer_id=reviewer), 201)
    base = f"/api/projects/{project['id']}"
    product.call(f"{base}/members/{engineer}", "PUT", {"enabled": True})
    requirement = product.call(base + "/requirements", "POST",
                               dict(code="fulfillment", title="Fulfillment", summary="Partner API"), 201)
    criteria = [product.call(f"{base}/requirements/{requirement['id']}/criteria", "POST",
                             dict(code=f"c{index}", title=title, statement=rule), 201)
                for index, title, rule in [(1, "Packing", "Pack safely"), (2, "Dispatch", "Respond in ten seconds"),
                                           (3, "Duties", "Show local duties")]]

    def freeze(title):
        scope = product.call(base + "/scope")
        return product.call(base + "/baselines", "POST", dict(title=title, scope_digest=scope["scope_digest"]), 201)

    b1 = freeze("Initial scope")

    def link(index):
        item = criteria[index - 1]
        return dict(criterion_id=item["id"], revision_id=item["revision"]["id"])

    def task(code, team, index, effort=3):
        return product.call(base + "/work-items", "POST",
                            dict(**link(index), code=code, title=code, team=team, owner_id=engineer, effort=effort), 201)

    build = task("pack", "warehouse", 1)
    ship = task("dispatch", "integrations", 2)
    cases = [product.call(base + "/test-cases", "POST",
                          dict(**link(i), code=f"tc-{i}", title=f"Test {i}", steps="Exercise the API", expected_result="200 OK"), 201)
             for i in (1, 2)]
    proposed = dict(baseline_id=b1["id"], reason="Partner SLA changed",
                    criteria=[dict(criterion_id=criteria[1]["id"], title="Dispatch", statement="Respond in five seconds"),
                              dict(criterion_id=criteria[2]["id"], title="Duties", statement="Show duties with currency")])
    change = product.call(base + "/changes", "POST", proposed, 201)
    conflicting = product.call(base + "/changes", "POST", proposed, 201)
    url = f"{base}/changes/{change['id']}"
    [covered, missing] = change["impact"]
    assert [row["id"] for row in covered["work_items"]] == [ship["id"]]
    assert [row["id"] for row in covered["test_cases"]] == [cases[1]["id"]]
    assert covered["missing_links"] == ["signoff_items"]
    assert missing["missing_links"] == ["work_items", "test_cases", "signoff_items"]
    assert outside.call(url, status=404)["error"] == "project_not_found"
    assert change["criteria"][0]["previous_statement"] == "Respond in ten seconds"
    decision = dict(decision="approved", note="Supplier contract signed")
    assert product.call(url + "/decision", "POST", decision, 403)["error"] == "project_business_signer_required"
    signer_peer = Session(peer)
    signer_peer.login(tenant, "business@example.test", password)
    barrier = Barrier(2)

    def review(client):
        barrier.wait(timeout=5)
        return client.call(url + "/decision", "POST", decision)

    with ThreadPoolExecutor(max_workers=2) as pool:
        approved, replay = list(pool.map(review, (signer, signer_peer)))
    assert approved == replay and approved["status"] == "approved"
    assert approved["impact"] == change["impact"]
    assert signer.call(url + "/decision", "POST", dict(decision, note="different"), 409)["error"] == "change_already_reviewed"
    stale = f"{base}/changes/{conflicting['id']}"
    assert signer.call(stale + "/decision", "POST", decision, 409)["error"] == "change_revision_stale"
    assert signer.call(stale + "/decision", "POST", dict(decision="rejected", note="Superseded"))["status"] == "rejected"
    assert [row["review_change_id"] for row in product.call(base + "/work-items")["items"]] == [None, change["id"]]
    assert [row["review_change_id"] for row in product.call(base + "/test-cases")["items"]] == [None, change["id"]]
    assert signer.call(f"{base}/baselines/{b1['id']}") == b1
    b2 = freeze("Revised scope")
    assert [c["version"] for c in b2["criteria"]] == [1, 2, 2]
    assert [c["revision_id"] for c in b2["criteria"]][1:] == [c["approved_revision_id"] for c in approved["criteria"]]
    assert sum(row["action"] == "change_approved" for row in product.call(base + "/timeline")["items"]) == 1
    assert [row["action"] for row in signer.call(url + "/timeline")["items"]] == ["change_proposed", "change_approved"]
    assert outside.call(url + "/timeline", status=404)["error"] == "project_not_found"

    def sprint(code, team, start, end, capacity):
        return product.call(base + "/sprints", "POST", dict(code=code, team=team, title=code, starts_on=start,
                            ends_on=end, capacity=capacity), 201)["id"]

    first = sprint("first", "warehouse", "2026-11-01", "2026-11-03", 3)
    late = sprint("late", "warehouse", "2026-11-05", "2026-11-10", 8)
    next_sprint = sprint("next", "integrations", "2026-11-04", "2026-11-11", 10)
    overflow = task("extra-pack", "warehouse", 1, 1)
    up = build["id"]
    downstream = ship["id"]

    def dependency_path(work, predecessor):
        return f"{base}/work-items/{work}/dependencies/{predecessor}"

    def schedule(work, sprint_id, status=200, client=product):
        return client.call(f"{base}/work-items/{work}/schedule", "PUT", dict(sprint_id=sprint_id), status)

    forward = dependency_path(downstream, up)
    backward = dependency_path(up, downstream)
    dependency = dict(owner_id=engineer, due_on="2026-11-04")
    assert product.call(forward, "PUT", dependency)["dependencies"][0]["owner_id"] == engineer
    circuit = product.call(backward, "PUT", dependency)["cycle"]
    assert circuit[0] == circuit[-1] and set(circuit) == {up, downstream}
    assert schedule(up, first, 409)["error"] == "dependency_cycle"
    assert product.call(backward, "DELETE")["cycle"] == []
    assert schedule(downstream, next_sprint, 409)["details"]["schedule_conflicts"][0]["reason"] == "predecessor_unscheduled"
    assert schedule(up, first)["sprint_id"] == first
    assert schedule(overflow["id"], first, 409)["error"] == "sprint_capacity_exceeded"
    assert schedule(downstream, next_sprint)["sprint_id"] == next_sprint
    conflict = schedule(up, late, 409)
    assert conflict["error"] == "dependency_schedule_conflict"
    assert set(conflict["details"]["affected_sprint_ids"]) == {late, next_sprint}
    assert int(product.call(f"{base}/sprints/{first}")["used"]) == 3
    assert int(product.call(f"{base}/sprints/{late}")["used"]) == 0
    assert set(product.call(forward, "PUT", dict(dependency, due_on="2026-11-02"))["affected_sprint_ids"]) == {first, next_sprint}
    assert not product.call(forward, "PUT", dependency)["schedule_conflicts"]
    assert outside.call(f"{base}/sprints/{first}", status=404)["error"] == "project_not_found"
    actions = product.call(f"{base}/work-items/{up}/timeline")["items"]
    assert sum(row["action"] == "work_item_scheduled" for row in actions) == 1

    concurrent = sprint("concurrent", "warehouse", "2026-11-15", "2026-11-20", 5)
    contender = task("concurrent-pack", "warehouse", 1, 5)["id"]
    another = task("other-pack", "warehouse", 1, 5)["id"]
    product_peer = Session(peer)
    product_peer.login(tenant, "product@example.test", password)
    barrier = Barrier(2)

    def contend(pair):
        client, work = pair
        barrier.wait(timeout=5)
        return schedule(work, concurrent, (200, 409), client)

    with ThreadPoolExecutor(max_workers=2) as pool:
        receipts = list(pool.map(contend, [(product, contender), (product_peer, another)]))
    assert sorted(row.get("error", "scheduled") for row in receipts) == ["scheduled", "sprint_capacity_exceeded"]
    assert len(product.call(f"{base}/sprints/{concurrent}")["items"]) == 1
    print("AC-03–04 passed: revision-specific impact, concurrent approvals, dependency cycle, affected sprints, and capacity race.")


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])
