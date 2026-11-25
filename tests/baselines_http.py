"""Exercise AC-01 and AC-02 against an isolated MySQL and HTTP server."""

from concurrent.futures import ThreadPoolExecutor
from http.cookiejar import CookieJar
import json
import os
import re
import secrets
import subprocess
import sys
from threading import Barrier
from urllib.error import HTTPError
from urllib.request import build_opener, HTTPCookieProcessor, Request


PHP = ["php", "-d", "extension=pdo_mysql"] if sys.platform == "win32" else ["php"]


class Session:
    def __init__(self, origin):
        self.origin = origin
        self.client = build_opener(HTTPCookieProcessor(CookieJar()))
        self.csrf = None

    def call(self, path, method="GET", data=None, status=200, *, csrf=True):
        headers = {"Accept": "application/json"}
        if method != "GET":
            headers["Content-Type"] = "application/json"
            if csrf and self.csrf:
                headers["X-CSRF-Token"] = self.csrf
            data = json.dumps(data or {}).encode()
        try:
            response = self.client.open(Request(self.origin + path, data, headers, method=method), timeout=15)
        except HTTPError as error:
            response = error
        with response:
            body = json.load(response)
            expected = status if isinstance(status, tuple) else (status,)
            assert response.status in expected, (method, path, response.status, body)
            assert response.headers["Cache-Control"] == "no-store"
        if "csrf_token" in body:
            self.csrf = body["csrf_token"]
        return body

    def login(self, tenant, email, password):
        self.call("/api/session")
        return self.call("/api/login", "POST", dict(tenant=tenant, email=email, password=password))["actor"]


def provision(origin, tenant, password):
    subprocess.run([*PHP, "bin/delivery.php", "create-tenant", tenant, "Harbor Delivery", "admin@example.test", "Admin"],
                   input=password + "\n", encoding="utf-8", capture_output=True, check=True)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    return admin


def member(admin, origin, tenant, password, role):
    email = f"{role}@example.test"
    account = admin.call("/api/accounts", "POST", dict(email=email, name=role, password=password, roles=[role]), 201)
    session = Session(origin)
    assert session.login(tenant, email, password) == account
    return session, account["account_id"]


def run(origin, parallel_origin):
    if not re.search(r"(?:^mysql:|;)dbname=delivery_test_[a-z0-9_]+(?:;|$)", os.environ.get("DELIVERY_DSN", "")):
        raise RuntimeError("Use an isolated delivery_test_* database.")
    password = secrets.token_hex(16)
    tenant = "harbor-" + secrets.token_hex(5)
    assert Session(origin).call("/healthz") == {"ready": True}
    assert Session(origin).call("/api/projects", status=401)["error"] == "login_required"
    admin = provision(origin, tenant, password)
    outside = provision(origin, "outside-" + secrets.token_hex(5), password)
    product, product_id = member(admin, origin, tenant, password, "product")
    signer, signer_id = member(admin, origin, tenant, password, "business")
    engineer, engineer_id = member(admin, origin, tenant, password, "engineer")
    charter = dict(code="dispatch", title="Dispatch release", charter="Release the carrier dashboard",
                   product_owner_id=product_id, business_signer_id=signer_id)
    assert engineer.call("/api/projects", "POST", charter, 403)["error"] == "product_required"
    assert product.call("/api/projects", "POST", charter, 403, csrf=False)["error"] == "csrf_invalid"
    project = product.call("/api/projects", "POST", charter, 201)
    base = f"/api/projects/{project['id']}"
    assert outside.call(base, status=404)["error"] == "project_not_found"
    assert engineer.call("/api/projects")["items"] == []
    product.call(f"{base}/members/{engineer_id}", "PUT", {"enabled": True})
    assert engineer.call(base)["title"] == charter["title"]
    assert signer.call(base + "/scope", status=403)["error"] == "project_product_owner_required"

    shell = product.call(base + "/requirements", "POST", dict(code="export", title="Export", summary="Export the result"), 201)
    revision = dict(code="c1", title="Format", statement="The first row contains the column labels.")
    criteria = f"{base}/requirements/{shell['id']}/criteria"
    criterion = product.call(criteria, "POST", revision, 201)
    details = f"{base}/criteria/{criterion['id']}"
    old_scope = product.call(base + "/scope")
    newer = product.call(details, "PUT", dict(revision, version=1, statement="Use the UTC date and a CSV header."), 201)
    assert product.call(base + "/baselines", "POST", dict(title="B1", scope_digest=old_scope["scope_digest"]), 409)["error"] == "baseline_scope_changed"
    other = product.call(base + "/requirements", "POST", dict(code="history", title="History", summary="Retain old exports"), 201)
    preview = product.call(base + "/scope")
    assert [row["id"] for row in preview["uncovered_requirements"]] == [other["id"]]
    assert product.call(base + "/baselines", "POST", dict(title="B1", scope_digest=preview["scope_digest"]), 422)["error"] == "requirements_without_criteria"
    second = product.call(f"{base}/requirements/{other['id']}/criteria", "POST",
                          dict(code="c2", title="Retention", statement="Read the prior dispatch output."), 201)

    preview = product.call(base + "/scope")
    second_product = Session(parallel_origin)
    second_product.login(tenant, "product@example.test", password)
    barrier = Barrier(2)

    def freeze(client):
        barrier.wait(timeout=5)
        return client.call(base + "/baselines", "POST", dict(title="Initial dispatch scope", scope_digest=preview["scope_digest"]), (201, 409))

    with ThreadPoolExecutor(max_workers=2) as pool:
        receipts = list(pool.map(freeze, (product, second_product)))
    [baseline] = [row for row in receipts if "manifest_digest" in row]
    [rejected] = [row for row in receipts if "error" in row]
    assert rejected == {"error": "baseline_scope_already_frozen", "details": {"baseline_id": baseline["id"]}}
    assert baseline["manifest_digest"] == preview["scope_digest"]
    assert baseline["criteria"] == preview["criteria"]
    assert [row["revision_id"] for row in baseline["criteria"]] == [newer["id"], second["revision"]["id"]]
    frozen_url = f"{base}/baselines/{baseline['id']}"
    assert signer.call(frozen_url) == baseline
    assert outside.call(frozen_url, status=404)["error"] == "project_not_found"
    assert product.call(details, "PUT", dict(revision, version=2), 409)["error"] == "criterion_change_review_required"
    assert [row["statement"] for row in engineer.call(details)["revisions"]] == [revision["statement"], newer["statement"]]
    product.call(base, "PUT", dict(title="Dispatch phase two", charter="Add regional lanes", version=1))
    assert product.call(frozen_url) == baseline and baseline["charter_snapshot"]["charter"] == charter["charter"]
    timeline = product.call(base + "/timeline")["items"]
    assert sum(row["action"] == "baseline_frozen" for row in timeline) == 1
    assert any(row["details"].get("previous_revision_id") == criterion["revision"]["id"] for row in timeline)
    product.call(f"{base}/members/{engineer_id}", "PUT", {"enabled": False})
    assert engineer.call(frozen_url, status=404)["error"] == "project_not_found"
    print("AC-01–02 passed: historical charter and criteria, stale scope, concurrent freeze, review gate, and membership isolation.")


if __name__ == "__main__":
    run(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1])
