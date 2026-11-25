"""Verify deadline enforcement, independent grades and scoped marking through HTTP."""

from datetime import datetime, timedelta, timezone
import secrets
import sys

from admission_http import Session, create_cohort, create_member, create_tenant, enroll, require_test_database
from enterprise_seats import publish


def utc_at(minutes):
    return (datetime.now(timezone.utc) + timedelta(minutes=minutes)).isoformat(timespec="seconds").replace("+00:00", "Z")


def run(origin):
    require_test_database()
    tenant = "coursework-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    learner, _ = create_member(admin, origin, tenant, password, "student", ["learner"])
    other, other_id = create_member(admin, origin, tenant, password, "other-student", ["learner"])
    marker, marker_id = create_member(admin, origin, tenant, password, "instructor", ["instructor"])
    assistant, assistant_id = create_member(admin, origin, tenant, password, "assistant", ["instructor"])
    course = publish(admin, "inventory-training")
    cohort = create_cohort(admin, course, "cohort-one", 2)["id"]
    second_cohort = create_cohort(admin, course, "cohort-two", 2)["id"]
    enrollment = enroll(learner, cohort, "coursework-enroll")["id"]
    other_enrollment = enroll(other, second_cohort, "coursework-other")["id"]

    base = "/api/coursework"
    staff = f"{base}/cohorts/{cohort}/staff"
    assert admin.call(f"{staff}/{other_id}", "PUT", {"duty": "assistant"}, 422)["error"] == "account_is_not_teaching_staff"
    admin.call(f"{staff}/{marker_id}", "PUT", {"duty": "instructor"})
    admin.call(f"{base}/cohorts/{second_cohort}/staff/{assistant_id}", "PUT", {"duty": "assistant"})
    assert [row["account_id"] for row in admin.call(staff)["items"]] == [marker_id]
    assert [row["id"] for row in marker.call(f"{base}/teaching-cohorts")["items"]] == [cohort]
    assert [row["id"] for row in assistant.call(f"{base}/teaching-cohorts")["items"]] == [second_cohort]

    assignment = dict(title="Incident review", instructions="List events and preventive actions.", due_at=utc_at(-1),
                      grace_minutes=30, required_for_completion=True, pass_score=60,
                      rubric=[dict(label="Events", points=40), dict(label="Controls", points=60)])
    assignments = f"{base}/cohorts/{cohort}/assignments"
    mine = f"{base}/enrollments/{enrollment}/assignments"
    assert admin.call(assignments, "POST", dict(assignment, rubric=[dict(label="Incomplete", points=99)]), 422)["error"] == "rubric_points_must_total_100"
    assert admin.call(assignments, "POST", dict(assignment, due_at="9999-12-31T23:59:00Z"), 422)["details"]["field"] == "grace_minutes"
    draft = admin.call(assignments, "POST", assignment, 201)
    task = f"{base}/assignments/{draft['id']}"
    work = f"{mine}/{draft['id']}"
    assert not marker.call(assignments)["items"] and not learner.call(mine)["items"]
    assert learner.call(work, status=404)["error"] == "assignment_not_found"
    edited = admin.call(task, "PUT", dict(assignment, version=1))
    assert edited["version"] == 2
    assert admin.call(task + "/publish", "POST", {"version": 1}, 409)["details"]["version"] == 2
    published = admin.call(task + "/publish", "POST", {"version": 2})
    assert published["published_at"] and published["rubric"] == assignment["rubric"]
    assert admin.call(task + "/publish", "POST", {"version": 2}) == published
    assert admin.call(task, "PUT", dict(assignment, version=2), 409)["error"] == "assignment_already_published"
    assert assistant.call(task + "/submissions", status=404)["error"] == "teaching_cohort_not_found"
    assert other.call(work, status=404)["error"] == "enrollment_not_found"
    assert other.call(f"{base}/enrollments/{other_enrollment}/assignments/{draft['id']}", status=404)["error"] == "assignment_not_found"

    first_answer = {"answer": "Valve failure\r\nReview the interlock"}
    first = learner.call(work, "POST", first_answer, 201, key="first")
    assert first["revision"] == 1 and first["late"]
    assert learner.call(work, "POST", first_answer, key="first") == dict(first, replayed=True)
    assert learner.call(work, "POST", {"answer": "other"}, 409, key="first")["error"] == "request_key_reused"
    second_answer = {"answer": "Valve failure at 09:00\nTest the interlock every week"}
    assert learner.call(work)["blocked_reason"] == "assignment_return_required"
    assert learner.call(work, "POST", second_answer, 409, key="second")["error"] == "assignment_return_required"
    submission = f"{base}/submissions/{first['id']}"
    grade = dict(marks=[dict(points=30), dict(points=22, note="Needs a schedule")], feedback="Add a specific schedule",
                 annotations=[dict(start_line=2, end_line=2, note="Set the inspection interval")])
    assert assistant.call(submission + "/grade", "POST", grade, 404)["error"] == "teaching_cohort_not_found"
    assert marker.call(submission + "/grade", "POST", dict(grade, annotations=[dict(start_line=3, end_line=3, note="Out of bounds")]), 422)["details"]["field"] == "start_line"
    assert marker.call(submission + "/grade", "POST", grade, 201)["score"] == 52
    assert marker.call(submission + "/grade", "POST", grade)["replayed"]
    assert marker.call(submission + "/grade", "POST", dict(grade, feedback="Changed"), 409)["error"] == "submission_already_graded"
    assert marker.call(submission + "/return", "POST", dict(expires_at=utc_at(-1), note="Expired"), 422)["error"] == "return_expiry_must_be_future"
    grant = dict(expires_at=utc_at(30), note="Include the control schedule.")
    assert marker.call(submission + "/return", "POST", grant, 201)["id"] == first["id"]
    assert learner.call(work)["can_submit"]
    second = learner.call(work, "POST", second_answer, 201, key="second")
    assert second["revision"] == 2 and second["late"]
    assert marker.call(submission + "/return", "POST", grant)["replayed"]
    assert marker.call(submission + "/return", "POST", dict(grant, note="Different"), 409)["error"] == "submission_is_not_latest"
    assert learner.call(work, "POST", second_answer, key="second") == dict(second, replayed=True)
    assert learner.call(work, "POST", {"answer": "third"}, 409, key="third")["error"] == "assignment_return_required"

    admin.call(f"{staff}/{assistant_id}", "PUT", {"duty": "assistant"})
    improved = dict(marks=[dict(points=38), dict(points=50)], feedback="Schedule is actionable")
    assert assistant.call(f"{base}/submissions/{second['id']}/grade", "POST", improved, 201)["score"] == 88
    assert marker.call(task + "/submissions")["items"][0]["score"] == 88
    history = learner.call(work)["items"]
    assert [row["score"] for row in history] == [52, 88]
    assert [row["answer"] for row in history] == [first_answer["answer"].replace("\r\n", "\n"), second_answer["answer"]]
    assert history[0]["annotations"] == grade["annotations"] and history[0]["return_consumed_by"] == second["id"]
    assert history[1]["marker_name"] == "assistant"
    assert marker.call(f"{task}/submissions/{enrollment}")["items"] == history
    admin.call(f"{staff}/{assistant_id}", "PUT", {"duty": "none"})
    assert assistant.call(f"{task}/submissions/{enrollment}", status=404)["error"] == "teaching_cohort_not_found"

    closed = admin.call(assignments, "POST", dict(assignment, title="Closed assignment", due_at=utc_at(-60), grace_minutes=10), 201)
    admin.call(f"{base}/assignments/{closed['id']}/publish", "POST", {"version": 1})
    assert learner.call(f"{mine}/{closed['id']}", "POST", first_answer, 409, key="too-late")["error"] == "assignment_submission_closed"

    timely = admin.call(assignments, "POST", dict(assignment, title="Open assignment", due_at=utc_at(30)), 201)
    admin.call(f"{base}/assignments/{timely['id']}/publish", "POST", {"version": 1})
    open_work = f"{mine}/{timely['id']}"
    assert not learner.call(open_work, "POST", first_answer, 201, key="on-time")["late"]
    assert learner.call(open_work, "POST", second_answer, 201, key="on-time-new")["revision"] == 2
    learner.call(f"/api/enrollments/{enrollment}/withdraw", "POST")
    assert learner.call(work)["items"] == history
    assert learner.call(open_work, "POST", second_answer, 409, key="withdrawn")["error"] == "enrollment_cannot_submit_coursework"
    print("AC-09–10 passed: both submission versions and scores retained, late revisions require a scoped one-use grant.")


if __name__ == "__main__":
    run(sys.argv[1])
