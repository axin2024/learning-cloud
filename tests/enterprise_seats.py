"""Exercise the enterprise purchase workflow and race separate MySQL connections."""

from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
import json
import secrets
import subprocess
import sys

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, require_test_database, revision


FIXTURE = [*PHP, "tests/enterprise_fixture.php"]


def fixture(*args, data=None):
    result = subprocess.run([*FIXTURE, *map(str, args)], input=data, encoding="utf-8", capture_output=True, timeout=25)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def publish(admin, slug):
    course = admin.call("/api/courses", "POST", {"slug": slug}, 201)["id"]
    rev = admin.call(f"/api/courses/{course}/revisions", "POST", revision(slug, chapter("Reading", "Synthetic material")), 201)
    admin.call(f"/api/courses/{course}/revisions/{rev['id']}/publish", "POST", {"edit_version": 1})
    return course


def company(admin, code):
    return admin.call("/api/companies", "POST", {"code": code, "name": code}, 201)["id"]


def purchase(client, url, course, total, code, *, expired=False, status=201):
    today = datetime.now(timezone.utc).date()
    return client.call(url, "POST", dict(course_id=course, contract_code=code, total=total,
                                        starts_on=(today - timedelta(days=2)).isoformat(),
                                        ends_on=(today + timedelta(days=-1 if expired else 1)).isoformat()), status)


def race(identity, requests, executable=FIXTURE, command="race"):
    processes = []
    try:
        for _ in requests:
            process = subprocess.Popen([*executable, command, *map(str, identity)], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                       stderr=subprocess.PIPE, text=True, encoding="utf-8")
            processes.append(process)
        for process in processes:
            ready = process.stdout.readline()
            assert ready == "ready\n", ready or process.stderr.read()

        def run(pair):
            process, request = pair
            output, error = process.communicate(json.dumps(request) + "\n", timeout=20)
            assert process.returncode == 0, error
            return json.loads(output)

        with ThreadPoolExecutor(max_workers=len(requests)) as executor:
            return list(executor.map(run, zip(processes, requests)))
    finally:
        for process in processes:
            if process.poll() is None:
                process.kill()
                process.communicate()


def run(origin):
    require_test_database()
    tenant = "enterprise-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    info = create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    manager, manager_id = create_member(admin, origin, tenant, password, "company-manager", ["learner"])
    other, other_id = create_member(admin, origin, tenant, password, "other-manager", ["learner"])
    course = publish(admin, "workplace-safety")
    other_course = publish(admin, "workplace-orientation")
    cohort = create_cohort(admin, course, "company-safety", 1)["id"]
    other_cohort = create_cohort(admin, other_course, "company-orientation", 2)["id"]
    company_id = company(admin, "harbor-plant")
    other_company_id = company(admin, "other-plant")
    url = f"/api/companies/{company_id}"
    other_url = f"/api/companies/{other_company_id}"
    admin.call(f"{url}/administrators/{manager_id}", "PUT", {"enabled": True})
    admin.call(f"{other_url}/administrators/{other_id}", "PUT", {"enabled": True})
    assert "enterprise_admin" in manager.call("/api/session")["actor"]["roles"]
    assert [item["id"] for item in manager.call("/api/companies")["items"]] == [company_id]
    assert other.call(url, status=404)["error"] == "company_not_found"
    assert manager.call(f"{url}/administrators", status=403)["error"] == "admin_required"

    learners = fixture("seed", info["tenant_id"], 101, data=password + "\n")
    departments = []
    for code, low, high in (("production", 0, 40), ("systems", 40, 75), ("warehouse", 75, 101)):
        dept = manager.call(f"{url}/departments", "POST", {"code": code, "name": code}, 201)["id"]
        member_ids = [learner["id"] for learner in learners[low:high]]
        result = admin.call(f"{url}/learners", "POST", {"department_id": dept, "learner_ids": member_ids})
        assert all(item["outcome"] == "added" for item in result["items"])
        departments.extend([dept] * (high - low))
    assert manager.call(f"{url}/learners", "POST", {"department_id": departments[0], "learner_ids": [other_id]}, 403)["error"] == "admin_required"

    purchase_url = f"{url}/purchases"
    assert purchase(manager, purchase_url, course, 100, "contract-100", status=403)["error"] == "admin_required"
    pool = purchase(admin, purchase_url, course, 100, "contract-100")
    assert pool["available"] == 100
    assert purchase(admin, purchase_url, course, 100, "contract-100", status=409)["error"] == "contract_course_exists"
    detail_url = f"{purchase_url}/{pool['id']}"
    batch_url = f"{detail_url}/batches"
    assert other.call(detail_url, status=404)["error"] == "company_not_found"
    rows = [dict(department_id=dept, learner_id=learner["id"]) for dept, learner in zip(departments, learners)]
    assert manager.call(batch_url, "POST", {"cohort_id": other_cohort, "rows": rows[:1]}, 422, "wrong-course")["error"] == "cohort_not_in_contract"
    expired = purchase(admin, purchase_url, course, 10, "expired-contract", expired=True)
    assert manager.call(f"{purchase_url}/{expired['id']}/batches", "POST", {"cohort_id": cohort, "rows": rows[:1]}, 409, "expired")["error"] == "contract_outside_validity"

    body = {"cohort_id": cohort, "rows": [rows[0], rows[0], {}, {**rows[1], "department_id": departments[-1]}, *rows[1:]]}
    receipt = manager.call(batch_url, "POST", body, 201, "hundred-seats")
    assert Counter(row["outcome"] for row in receipt["items"]) == {"assigned": 100, "duplicate": 1, "rejected": 3}
    assert [row["reason"] for row in receipt["items"] if row["outcome"] == "rejected"] == [
        "invalid_input", "learner_not_in_department", "contract_quota_exhausted"]
    assert receipt["available_at_commit"] == 0
    allocated = [item for item in receipt["items"] if item["outcome"] == "assigned"]
    assert [item["seat_number"] for item in allocated] == list(range(1, 101))
    assert manager.call(batch_url, "POST", body, key="hundred-seats") == {**receipt, "replayed": True}
    assert manager.call(batch_url, "POST", {**body, "rows": rows[:1]}, 409, "hundred-seats")["error"] == "request_key_reused"
    assert manager.call(f"{batch_url}/{receipt['id']}")["items"] == receipt["items"]
    assert manager.call(batch_url)["items"][0]["row_count"] == 104
    totals = manager.call(detail_url)["departments"]
    assert [item["assigned"] + item["activated"] for item in totals] == [40, 35, 25]
    source_url = f"{batch_url}/{receipt['id']}"
    retry_url = f"{source_url}/retry"
    full_row = receipt["items"][-1]["row"]
    assert other.call(retry_url, "POST", {"rows": [full_row]}, 404, "outside-company")["error"] == "company_not_found"
    assert manager.call(retry_url, "POST", {"rows": [full_row, full_row]}, 422, "repeat-row")["error"] == "duplicate_seat_retry_row"
    blocked = manager.call(retry_url, "POST", {"rows": [full_row, 3, 1, 500]}, 201, "retry-full")
    assert blocked["retry_of"] == receipt["id"]
    assert [(item["source_row"], item["reason"]) for item in blocked["items"]] == [
        (1, "seat_retry_row_unavailable"), (3, "seat_retry_row_unavailable"),
        (full_row, "contract_quota_exhausted"), (500, "seat_retry_row_unavailable"),
    ]
    assert manager.call(retry_url, "POST", {"rows": [1, 500, full_row, 3]}, key="retry-full") == {**blocked, "replayed": True}
    assert manager.call(retry_url, "POST", {"rows": [full_row]}, 409, "retry-full")["error"] == "request_key_reused"

    a_id, b_id, c_id = [item["assignment_id"] for item in allocated[:3]]
    learner_a, learner_b = Session(origin), Session(origin)
    learner_a.login(tenant, learners[0]["email"], password)
    learner_b.login(tenant, learners[1]["email"], password)
    assert [item["id"] for item in learner_b.call("/api/me/seat-assignments")["items"]] == [b_id]
    activate = lambda identity: f"/api/seat-assignments/{identity}/activate"
    reclaim = lambda identity: f"{detail_url}/assignments/{identity}/reclaim"
    assert learner_a.call(activate(b_id), "POST", status=404)["error"] == "seat_assignment_not_found"
    enrollment = learner_a.call(activate(a_id), "POST")["enrollment_id"]
    assert learner_a.call(activate(a_id), "POST")["enrollment_id"] == enrollment
    assert learner_a.call(f"/api/enrollments/{enrollment}")["state"] == "enrolled"
    assert learner_b.call(activate(b_id), "POST", status=409)["error"] == "enterprise_cohort_full"
    assert learner_b.call("/api/me/seat-assignments")["items"][0]["state"] == "assigned"
    assert manager.call(reclaim(a_id), "POST", status=409)["error"] == "activated_seat_cannot_reclaim"
    learner_a.call(f"/api/enrollments/{enrollment}/withdraw", "POST")
    assert manager.call(reclaim(a_id), "POST", status=409)["error"] == "activated_seat_cannot_reclaim"
    assert learner_b.call(activate(b_id), "POST")["enrollment_id"] != enrollment
    assert manager.call(reclaim(c_id), "POST")["state"] == "reclaimed"
    assert manager.call(reclaim(c_id), "POST")["state"] == "reclaimed"
    assert manager.call(detail_url)["available"] == 1
    reassigned = manager.call(retry_url, "POST", {"rows": [full_row]}, 201, "reassigned")
    assert reassigned["retry_of"] == receipt["id"] and reassigned["available_at_commit"] == 0
    assert len(reassigned["items"]) == 1 and reassigned["items"][0]["source_row"] == full_row
    assert reassigned["items"][0]["seat_number"] == 101 and reassigned["items"][0]["outcome"] == "assigned"
    assert manager.call(retry_url, "POST", {"rows": [full_row]}, key="reassigned") == {**reassigned, "replayed": True}
    assert manager.call(source_url)["items"] == receipt["items"]
    assert manager.call(f"{batch_url}/{reassigned['id']}")["items"] == reassigned["items"]

    race_pool = purchase(admin, purchase_url, course, 5, "race-contract")
    identity = [info["tenant_id"], manager_id, company_id, race_pool["id"]]
    requests = [{"input": {"cohort_id": cohort, "rows": rows[index:index + 10]}, "key": f"race:{index}"} for index in range(0, 100, 10)]
    concurrent = race(identity, requests)
    states = Counter(row["outcome"] for batch in concurrent for row in batch["items"])
    assert states == {"assigned": 5, "rejected": 95}
    replays = race(identity, [requests[0]] * 8)
    assert all(item["replayed"] and item["id"] == concurrent[0]["id"] for item in replays)
    assert fixture("check", info["tenant_id"], race_pool["id"]) == {
        "active_assignments": 5, "distinct_seat_numbers": 5, "batches": 10, "assigned_events": 5}
    admin.call(f"{url}/administrators/{manager_id}", "PUT", {"enabled": False})
    assert "enterprise_admin" not in manager.call("/api/session")["actor"]["roles"]
    assert manager.call(detail_url, status=404)["error"] == "company_not_found"
    print("Enterprise seats passed: 40/35/25 allocation, mixed receipts and selected retry, scope and revocation, activation and reclaim, 100 contenders across 10 MySQL connections for 5 seats.")


if __name__ == "__main__":
    run(sys.argv[1])
