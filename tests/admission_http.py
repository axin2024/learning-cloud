"""Exercise the initial teaching and admissions workflows through the HTTP entry point."""

import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.request


PHP = ["php", "-d", "extension=pdo_mysql"] if sys.platform == "win32" else ["php"]


def require_test_database():
    dsn = os.environ.get("LEARNING_DSN", "")
    if not re.search(r"(?:^|;)dbname=learning_test_[a-z0-9_]+(?:;|$)", dsn.removeprefix("mysql:")):
        raise RuntimeError("Tests require an isolated learning_test_* MySQL database.")


class Session:
    def __init__(self, origin):
        self.origin = origin
        self.csrf = None
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(self, path, method="GET", data=None, status=200, key=None, with_csrf=True):
        headers = {"Accept": "application/json"}
        if method != "GET":
            headers["Content-Type"] = "application/json"
            if with_csrf and self.csrf:
                headers["X-CSRF-Token"] = self.csrf
            if key:
                headers["Idempotency-Key"] = key
            data = json.dumps(data if data is not None else {}).encode()
        request = urllib.request.Request(self.origin + path, data, headers, method=method)
        try:
            response = self.client.open(request, timeout=12)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            result = json.load(response)
            assert response.status == status, (path, method, status, response.status, result)
            assert response.headers.get_content_type() == "application/json", response.headers
            assert response.headers["Cache-Control"] == "no-store", response.headers
        if "csrf_token" in result:
            self.csrf = result["csrf_token"]
        return result

    def login(self, tenant, email, password):
        self.call("/api/session")
        return self.call("/api/login", "POST", dict(tenant=tenant, email=email, password=password))["actor"]


def create_tenant(tenant, password):
    result = subprocess.run(
        [*PHP, "bin/learning.php", "create-tenant", tenant, "Harbor Institute", "admin@example.test", "Academy Admin"],
        input=password + "\n", text=True, encoding="utf-8", capture_output=True, check=True,
    )
    return json.loads(result.stdout)


def create_member(admin, origin, tenant, password, member, roles):
    email = f"{member}@example.test"
    account = admin.call("/api/accounts", "POST", dict(name=member, email=email, password=password, roles=roles), 201)
    session = Session(origin)
    assert session.login(tenant, email, password)["account_id"] == account["account_id"]
    return session, account["account_id"]


def chapter(title, content, rights="original"):
    return dict(title=title, content=content, duration_seconds=600, required_seconds=600, kind="text",
                rights_basis=rights, rights_reference="Test course authorship statement" if rights != "unverified" else "")


def revision(title, *chapters):
    return dict(title=title, summary="Operations course", minimum_study_seconds=600, pass_score=60,
                retake_mode="highest", chapters=list(chapters))


def create_cohort(actor, course, name, capacity, *, revision_id=None, status=201):
    body = dict(course_id=course, code=name, name=name, capacity=capacity, registration_open=True,
                starts_on="2026-12-01", ends_on="2026-12-15")
    if revision_id is not None:
        body["revision_id"] = revision_id
    return actor.call("/api/cohorts", "POST", body, status)


def enroll(actor, cohort, key, status=201, learner=None):
    return actor.call(f"/api/cohorts/{cohort}/enrollments", "POST",
                      {} if learner is None else {"learner_id": learner}, status, key)


def run(origin):
    require_test_database()
    suffix = secrets.token_hex(4)
    tenant = "academy-" + suffix
    password = secrets.token_hex(18)
    assert Session(origin).call("/healthz") == {"ready": True}
    with urllib.request.urlopen(origin + "/", timeout=5) as response:
        assert "登录教学工作台" in response.read().decode()
        assert "frame-ancestors 'none'" in response.headers["Content-Security-Policy"]
    with urllib.request.urlopen(origin + "/workspace.js", timeout=5) as response:
        assert "javascript" in response.headers.get_content_type()
        assert b"showCohorts" in response.read()

    tenant_admin = create_tenant(tenant, password)
    admin = Session(origin)
    assert admin.call("/api/courses", status=401)["error"] == "login_required"
    assert admin.login(tenant, "admin@example.test", password) == tenant_admin
    registrar, _ = create_member(admin, origin, tenant, password, "registrar", ["registrar"])
    instructor, _ = create_member(admin, origin, tenant, password, "instructor", ["instructor"])
    alice, alice_id = create_member(admin, origin, tenant, password, "alice", ["learner"])
    bob, bob_id = create_member(admin, origin, tenant, password, "bob", ["learner"])
    carla, _ = create_member(admin, origin, tenant, password, "carla", ["learner"])
    dora, _ = create_member(admin, origin, tenant, password, "dora", ["learner"])
    account_rows = admin.call("/api/accounts")["items"]
    assert len(account_rows) == 7 and not any("password_hash" in row for row in account_rows)
    assert instructor.call("/api/accounts", status=403)["error"] == "admin_required"
    assert instructor.call("/api/courses", "POST", {"slug": "blocked"}, 403)["error"] == "registrar_required"
    assert admin.call("/api/courses", "POST", {"slug": "blocked"}, 403, with_csrf=False)["error"] == "invalid_csrf_token"

    course = registrar.call("/api/courses", "POST", {"slug": "plant-safety"}, 201)["id"]
    path = f"/api/courses/{course}/revisions"
    first = registrar.call(path, "POST", revision("Safety handbook v1", chapter("Old chapter", "Original v1 wording")), 201)
    first_path = f"{path}/{first['id']}"
    published = registrar.call(first_path + "/publish", "POST", {"edit_version": 1})
    assert published["published_at"] and published["revision"] == 1
    assert registrar.call(first_path + "/publish", "POST", {"edit_version": 1}) == published
    old = create_cohort(registrar, course, "old-class", 1)
    other = create_cohort(registrar, course, "same-version-class", 1)
    assert old["revision_id"] == other["revision_id"] == first["id"]
    a = enroll(alice, old["id"], "first-a")
    assert a["state"] == "enrolled" and a["enrolled_at"]
    assert enroll(alice, old["id"], "first-a", 200)["id"] == a["id"]
    assert enroll(alice, old["id"], "different-key", 200)["id"] == a["id"]
    b = enroll(bob, old["id"], "first-b")
    c = enroll(carla, old["id"], "first-c")
    assert [b["state"], c["state"]] == ["waitlisted", "waitlisted"]
    assert [b["waitlist_position"], c["waitlist_position"]] == [1, 2]
    assert carla.call(first_path, status=404)["error"] == "revision_not_found"
    assert bob.call(f"/api/enrollments/{a['id']}", status=404)["error"] == "enrollment_not_found"

    pending = revision("Safety handbook v2", chapter("Replacement chapter", "Issued v2 wording", "unverified"))
    second = registrar.call(path, "POST", pending, 201)
    second_path = f"{path}/{second['id']}"
    assert registrar.call(second_path + "/publish", "POST", {"edit_version": 1}, 422)["details"] == {"chapters": [1]}
    assert create_cohort(registrar, course, "cannot-pin-draft", 1, revision_id=second["id"], status=409)["error"] == "published_revision_required"
    pending["chapters"][0].update(rights_basis="licensed", rights_reference="Publisher redistribution receipt L-2026-7")
    saved = registrar.call(second_path, "PUT", dict(pending, edit_version=1))
    assert saved["edit_version"] == 2
    assert registrar.call(second_path, "PUT", dict(pending, edit_version=1), 409)["details"]["edit_version"] == 2
    assert registrar.call(second_path + "/publish", "POST", {"edit_version": 2})["published_at"]
    assert registrar.call(second_path, "PUT", dict(pending, edit_version=2), 409)["error"] == "revision_already_published"
    new = create_cohort(registrar, course, "new-class", 2)
    assert new["revision_id"] == second["id"]
    assert enroll(dora, new["id"], "new-v2")["state"] == "enrolled"
    old_material = alice.call(first_path)["chapters"][0]
    assert old_material["content"] == "Original v1 wording" and "rights_reference" not in old_material
    assert alice.call(second_path, status=404)["error"] == "revision_not_found"
    assert dora.call(second_path)["chapters"][0]["content"] == "Issued v2 wording"
    assert dora.call(first_path, status=404)["error"] == "revision_not_found"
    assert alice.call("/api/courses")["items"][0]["title"] == "Safety handbook v2"

    target_fill = enroll(dora, other["id"], "fill-other")
    a_path = f"/api/enrollments/{a['id']}"
    transfer = dict(target_cohort_id=other["id"])
    assert alice.call(a_path + "/transfer", "POST", transfer, 409)["error"] == "target_cohort_full"
    assert alice.call(a_path)["state"] == "enrolled"
    assert alice.call(a_path + "/transfer", "POST", {"target_cohort_id": new["id"]}, 409)["error"] == "course_equivalence_required"
    alice.call(a_path + "/withdraw", "POST")
    assert alice.call(a_path + "/withdraw", "POST")["state"] == "withdrawn"
    assert enroll(alice, old["id"], "first-a", 200)["state"] == "withdrawn"
    assert alice.call(first_path, status=404)["error"] == "revision_not_found"
    b_path = f"/api/enrollments/{b['id']}"
    assert bob.call(b_path)["state"] == "enrolled"
    assert carla.call(f"/api/enrollments/{c['id']}")["waitlist_position"] == 1
    assert bob.call(first_path)["chapters"][0]["content"] == "Original v1 wording"
    dora.call(f"/api/enrollments/{target_fill['id']}/withdraw", "POST")
    moved = bob.call(b_path + "/transfer", "POST", transfer)
    assert moved["state"] == "enrolled" and moved["transferred_from_id"] == b["id"]
    assert bob.call(b_path + "/transfer", "POST", transfer) == moved
    assert bob.call(b_path)["state"] == "transferred"
    assert carla.call(f"/api/enrollments/{c['id']}")["state"] == "enrolled"
    assert [item["state"] for item in bob.call("/api/me/enrollments")["items"]] == ["transferred", "enrolled"]

    appointed = enroll(registrar, new["id"], "clerk-receipt", learner=alice_id)
    assert appointed["state"] == "enrolled"
    assert enroll(registrar, new["id"], "clerk-receipt", 409, bob_id)["error"] == "request_key_reused"
    queued_new = enroll(bob, new["id"], "queued-before-close")
    assert queued_new["state"] == "waitlisted"
    assert registrar.call(f"/api/cohorts/{new['id']}", "PUT", {"version": 1, "capacity": 1, "registration_open": True}, 409)["error"] == "capacity_below_occupancy"
    assert registrar.call(f"/api/cohorts/{new['id']}", "PUT", {"version": 1, "capacity": 2, "registration_open": False})["version"] == 2
    assert enroll(carla, new["id"], "closed-cohort", 409)["error"] == "registration_closed"
    alice.call(f"/api/enrollments/{appointed['id']}/withdraw", "POST")
    assert bob.call(f"/api/enrollments/{queued_new['id']}")["state"] == "enrolled"
    assert bob.call(f"/api/cohorts/{old['id']}/enrollments", status=403)["error"] == "registrar_required"

    outsider_tenant = "outside-" + suffix
    create_tenant(outsider_tenant, password)
    outsider = Session(origin)
    outsider.login(outsider_tenant, "admin@example.test", password)
    assert outsider.call("/api/courses")["items"] == []
    assert outsider.call("/api/cohorts")["items"] == []
    outsider.call(f"/api/courses/{course}", status=404)
    outsider.call(first_path, status=404)
    outsider.call(f"/api/cohorts/{old['id']}", status=404)
    outsider.call(a_path, status=404)
    outsider.call(a_path + "/withdraw", "POST", status=404)
    assert outsider.call("/api/logout", "POST")["actor"] is None
    outsider.call("/api/courses", status=401)

    print("HTTP passed: course rights and version pinning, FIFO, idempotent replay, transfer rollback, scoped access.")


if __name__ == "__main__":
    run(sys.argv[1].rstrip("/"))
