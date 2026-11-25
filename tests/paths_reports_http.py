"""Exercise AC-15 and AC-16 with scoped reports and normal prerequisite gates."""

from datetime import datetime, timedelta, timezone
import json
import secrets
import subprocess
import sys

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, enroll, require_test_database, revision
from enterprise_seats import company, purchase
from exams_http import automatic_attempt, publish_paper, schedule


def publish_course(admin, slug):
    course = admin.call("/api/courses", "POST", {"slug": slug}, 201)["id"]
    path = f"/api/courses/{course}/revisions"
    content = revision(slug.title(), dict(chapter("Reading", "Synthetic reading"), required_seconds=0))
    draft = admin.call(path, "POST", dict(content, minimum_study_seconds=0), 201)
    admin.call(f"{path}/{draft['id']}/publish", "POST", {"edit_version": 1})
    return course


def bind_cohort(admin, course, step, name, status=201):
    return admin.call("/api/cohorts", "POST", dict(course_id=course, path_step_id=step, code=name, name=name, capacity=9,
                      registration_open=True, starts_on="2026-12-01", ends_on="2026-12-31"), status)


def test_exam(admin, course, cohort, now):
    pool = dict(name="Check", draw=1, points=100,
                questions=[dict(kind="single", prompt="Confirm first?", options=["Yes", "No"], correct=[1])])
    paper = publish_paper(admin, course, "Knowledge check", [pool])["id"]
    return schedule(admin, cohort, paper, "Assessment", now - timedelta(minutes=2), now + timedelta(minutes=10))


def complete(admin, learner, enrollment, sitting):
    assert automatic_attempt(learner, enrollment, sitting, [1])["score"] == 100
    return admin.call(f"/api/credentials/enrollments/{enrollment}/issue", "POST", {"valid_days": 365}, 201)


def assign(admin, company_url, pool, cohort, people, key):
    path = f"{company_url}/purchases/{pool}/batches"
    receipt = admin.call(path, "POST", {"cohort_id": cohort, "rows": people}, 201, key=key)
    assert all(row["outcome"] == "assigned" for row in receipt["items"])
    return [row["assignment_id"] for row in receipt["items"]]


def activate(learner, assignment, status=200):
    return learner.call(f"/api/seat-assignments/{assignment}/activate", "POST", status=status)


def run(origin):
    require_test_database()
    tenant = "paths-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    ids = create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    qualified, qualified_id = create_member(admin, origin, tenant, password, "qualified", ["learner"])
    withdrawn, withdrawn_id = create_member(admin, origin, tenant, password, "withdrawn", ["learner"])
    mover, mover_id = create_member(admin, origin, tenant, password, "mover", ["learner"])
    personal, personal_id = create_member(admin, origin, tenant, password, "personally-funded", ["learner"])
    manager, manager_id = create_member(admin, origin, tenant, password, "company-manager", ["learner"])
    other, other_id = create_member(admin, origin, tenant, password, "other-manager", ["learner"])
    outside_tenant = "outside-" + secrets.token_hex(5)
    create_tenant(outside_tenant, password)
    outside = Session(origin)
    outside.login(outside_tenant, "admin@example.test", password)

    basic = publish_course(admin, "foundation")
    advanced = publish_course(admin, "advanced")
    console = "/api/learning-paths"
    request = {"code": "operators-path", "title": "Operators path", "description": "Ordered field training", "course_ids": [basic, advanced]}
    assert qualified.call(console, "POST", request, 403)["error"] == "registrar_required"
    assert admin.call(console, "POST", dict(request, course_ids=[basic, basic]), 422)["error"] == "learning_path_courses_must_be_distinct"
    draft = admin.call(console, "POST", request, 201)
    path = f"{console}/{draft['id']}"
    assert qualified.call(path, status=404)["error"] == "learning_path_not_found"
    assert outside.call(path, status=404)["error"] == "learning_path_not_found"
    assert bind_cohort(admin, basic, draft["steps"][0]["id"], "draft-binding", 422)["error"] == "path_step_course_mismatch"
    draft = admin.call(path, "PUT", dict(request, version=1, title="Operator qualification"))
    assert draft["version"] == 2
    assert admin.call(path + "/publish", "POST", {"version": 1}, 409)["details"]["version"] == 2
    published = admin.call(path + "/publish", "POST", {"version": 2})
    assert admin.call(path + "/publish", "POST", {"version": 2}) == published
    assert admin.call(path, "PUT", dict(request, version=2), 409)["error"] == "learning_path_already_published"
    first, second = published["steps"]
    assert [step["course_id"] for step in qualified.call(path)["steps"]] == [basic, advanced]
    assert [step["eligible"] for step in qualified.call(path)["steps"]] == [True, False]
    assert bind_cohort(admin, basic, second["id"], "wrong-course", 422)["error"] == "path_step_course_mismatch"
    foundation = bind_cohort(admin, basic, first["id"], "foundation-morning")["id"]
    foundation_next = bind_cohort(admin, basic, first["id"], "foundation-evening")["id"]
    practicum = bind_cohort(admin, advanced, second["id"], "advanced-practicum")["id"]
    separate = create_cohort(admin, advanced, "independent-practicum", 9)["id"]
    assert qualified.call(path + f"/steps/{second['id']}/cohorts")["items"][0]["id"] == practicum
    assert qualified.call(f"/api/cohorts/{practicum}")["missing_prerequisites"][0]["course_id"] == basic
    assert enroll(qualified, practicum, "before-prerequisite", 409)["error"] == "path_prerequisites_required"
    assert enroll(admin, practicum, "registrar-before-prerequisite", 409, qualified_id)["error"] == "path_prerequisites_required"
    started = enroll(personal, separate, "own-unbound-course")["id"]
    assert personal.call(f"/api/enrollments/{started}/transfer", "POST", {"target_cohort_id": practicum}, 409)["error"] == "path_prerequisites_required"
    assert personal.call(f"/api/enrollments/{started}")["state"] == "enrolled"

    company_id = company(admin, "plant")
    company_url = f"/api/companies/{company_id}"
    other_company = f"/api/companies/{company(admin, 'separate-company')}"
    admin.call(f"{company_url}/administrators/{manager_id}", "PUT", {"enabled": True})
    admin.call(f"{other_company}/administrators/{other_id}", "PUT", {"enabled": True})
    operations = admin.call(company_url + "/departments", "POST", {"code": "operations", "name": "Operations"}, 201)["id"]
    maintenance = admin.call(company_url + "/departments", "POST", {"code": "maintenance", "name": "Maintenance"}, 201)["id"]
    admin.call(company_url + "/learners", "POST", {"department_id": operations, "learner_ids": [qualified_id, withdrawn_id]})
    admin.call(company_url + "/learners", "POST", {"department_id": maintenance, "learner_ids": [mover_id, personal_id]})
    foundation_pool = purchase(admin, company_url + "/purchases", basic, 15, "foundation-contract")["id"]
    advanced_pool = purchase(admin, company_url + "/purchases", advanced, 15, "advanced-contract")["id"]
    paid_advanced = assign(admin, company_url, advanced_pool, practicum,
                           [{"department_id": operations, "learner_id": qualified_id}], "advanced-seats")[0]
    assert activate(qualified, paid_advanced, 409)["error"] == "path_prerequisites_required"
    assert qualified.call("/api/me/seat-assignments")["items"][0]["state"] == "assigned"
    paid_withdrawn, paid_mover = assign(admin, company_url, foundation_pool, foundation,
                                      [{"department_id": operations, "learner_id": withdrawn_id},
                                       {"department_id": maintenance, "learner_id": mover_id}], "foundation-seats")

    source = enroll(qualified, foundation, "personal-prerequisite")["id"]
    withdrawn_enrollment = activate(withdrawn, paid_withdrawn)["enrollment_id"]
    mover_enrollment = activate(mover, paid_mover)["enrollment_id"]
    now = datetime.now(timezone.utc)
    today = now.date()
    prior_day = today - timedelta(days=1)
    first_exam = test_exam(admin, basic, foundation, now)
    complete(admin, qualified, source, first_exam)
    assert [step["eligible"] for step in qualified.call(path)["steps"]] == [True, True]
    assert qualified.call(path)["steps"][0]["completed"]
    assert qualified.call(f"/api/cohorts/{practicum}")["missing_prerequisites"] == []
    advance = activate(qualified, paid_advanced)["enrollment_id"]
    report_age = subprocess.run([*PHP, "tests/admission_fixture.php", "report-date", str(ids["tenant_id"]), str(ids["account_id"]),
                                 str(advance), f"{prior_day}T12:00:00Z"], text=True, capture_output=True, check=True)
    assert json.loads(report_age.stdout)["changed"] == 1
    advanced_exam = test_exam(admin, advanced, practicum, now)
    complete(admin, qualified, advance, advanced_exam)
    assert all(step["completed"] for step in qualified.call(path)["steps"])
    assert automatic_attempt(withdrawn, withdrawn_enrollment, first_exam, [1])["score"] == 100
    withdrawn.call(f"/api/enrollments/{withdrawn_enrollment}/withdraw", "POST")
    moved = mover.call(f"/api/enrollments/{mover_enrollment}/transfer", "POST", {"target_cohort_id": foundation_next})["id"]
    personal_foundation = enroll(personal, foundation_next, "personally-funded")["id"]

    departments = company_url + "/reports/departments"
    entries = company_url + "/reports/enrollments"
    current = f"?from={today}&to={today}"
    past = f"?from={prior_day}&to={prior_day}"
    assert other.call(departments, status=404)["error"] == "company_not_found"
    assert outside.call(entries, status=404)["error"] == "company_not_found"
    assert personal.call(entries, status=404)["error"] == "company_not_found"
    assert manager.call("/api/reports/courses", status=403)["error"] == "registrar_required"
    assert admin.call("/api/reports/courses" + f"?from={today}&to={prior_day}", status=422)["error"] == "invalid_report_period"

    report = manager.call(departments + current)
    effective = manager.call(entries + current + "&view=effective")["items"]
    history = manager.call(entries + current + "&view=history")["items"]
    metrics = {row["id"]: row for row in report["items"]}
    assert report["totals"] == {"effective_enrollments": 2, "effective_learners": 2}
    assert manager.call(departments + current + "&after=999999")["totals"] == report["totals"]
    assert sum(row["effective_enrollments"] for row in metrics.values()) == len(effective)
    assert {row["id"] for row in effective} == {advance, moved}
    assert personal_foundation not in {row["id"] for row in history + effective}
    assert {row["id"] for row in history} == {withdrawn_enrollment, mover_enrollment}
    old_score = next(row for row in history if row["id"] == withdrawn_enrollment)
    assert old_score["state"] == "withdrawn" and old_score["exam_score"] == 100 and not old_score["effective"]
    assert withdrawn.call(f"/api/exams/enrollments/{withdrawn_enrollment}")["effective_result"]["score"] == 100
    assert metrics[operations]["effective_completions"] == 1 and metrics[operations]["completion_percent"] == 100
    assert metrics[operations]["withdrawals_in_period"] == 1
    assert metrics[maintenance]["transfers_in_period"] == 1 and metrics[maintenance]["effective_enrollments"] == 1
    assert manager.call(entries + current + f"&department_id={maintenance}&cohort_id={foundation_next}")["items"][0]["id"] == moved

    past_advance = manager.call(entries + past + "&view=effective")["items"]
    assert len(past_advance) == 1 and past_advance[0]["id"] == advance
    assert past_advance[0]["state"] == "enrolled" and past_advance[0]["exam_score"] is None
    earlier = manager.call(departments + past)["items"][0]
    assert earlier["enrollments_in_period"] == 1 and earlier["completions_in_period"] == 0
    filtered = manager.call(departments + current + f"&cohort_id={practicum}")["items"][0]
    assert filtered["enrollments_in_period"] == 0 and filtered["completions_in_period"] == 1
    operations_report = admin.call("/api/reports/courses" + current + f"&course_id={advanced}")["items"]
    advanced_report = next(row for row in operations_report if row["id"] == practicum)
    assert advanced_report["enrollments_in_period"] == 0 and advanced_report["completions_in_period"] == 1
    assert advanced_report["effective_enrollments"] == 1 and advanced_report["completion_percent"] == 100
    assert manager.call(entries + current + "&view=effective&after=" + str(moved))["items"] == []
    print("AC-15–16 passed: prerequisite gates, department/report reconciliation, retained withdrawn score, transfer lineage and independent UTC dates.")


if __name__ == "__main__":
    run(sys.argv[1])
