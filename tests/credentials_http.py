"""Check qualification gates, public privacy, unique issuance and the renewal lifecycle."""

from datetime import datetime, timedelta, timezone
import json
import secrets
import subprocess
import sys
import time
import urllib.request

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, enroll, require_test_database, revision
from coursework_http import utc_at
from enterprise_seats import race
from exams_http import command, publish_paper, schedule, start, submit


FIXTURE = [*PHP, "tests/credentials_fixture.php"]


def study(learner, enrollment, chapter_id, passes=1):
    session = learner.call(f"/api/study/enrollments/{enrollment}/chapters/{chapter_id}/start", "POST")["id"]
    for iteration in range(passes):
        for second in range(0, 600, 30):
            learner.call(f"/api/study/sessions/{session}/intervals", "POST",
                         {"start_second": second, "end_second": second + 30}, 201, f"pass-{iteration}-{second}")


def exam(learner, enrollment, sitting, count):
    attempt = start(learner, enrollment, sitting)["id"]
    body = {"answer_version": 0, "answers": [dict(ordinal=i, answer=[1]) for i in range(1, count + 1)]}
    learner.call(f"/api/exams/attempts/{attempt}/answers", "PUT", body)
    return submit(learner, attempt)


def verify(origin, serial):
    with urllib.request.urlopen(origin + "/api/verify-credential/" + serial, timeout=5) as response:
        assert response.headers.get("Set-Cookie") is None
        assert response.headers["Cache-Control"] == "no-store" and "noindex" in response.headers["X-Robots-Tag"]
        result = json.load(response)
        assert set(result) == {"serial", "issuer_name", "course_title", "course_revision", "issued_at", "expires_at", "status"}
        return result


def run(origin):
    require_test_database()
    tenant = "credential-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    identity = create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    learner, _ = create_member(admin, origin, tenant, password, "learner", ["learner"])
    other, _ = create_member(admin, origin, tenant, password, "other", ["learner"])
    foreign_tenant = "foreign-" + secrets.token_hex(5)
    create_tenant(foreign_tenant, password)
    foreign = Session(origin)
    foreign.login(foreign_tenant, "admin@example.test", password)

    course = admin.call("/api/courses", "POST", {"slug": "plant-preservation"}, 201)["id"]
    rev = admin.call(f"/api/courses/{course}/revisions", "POST", revision("Plant preservation", chapter("Isolation", "Synthetic course material")), 201)
    admin.call(f"/api/courses/{course}/revisions/{rev['id']}/publish", "POST", {"edit_version": 1})
    chapter_id = rev["chapters"][0]["id"]
    cohort = create_cohort(admin, course, "plant-first", 3)["id"]
    renew_cohort = create_cohort(admin, course, "plant-renewal", 3)["id"]
    enrollment = enroll(learner, cohort, "qualification")["id"]
    other_enrollment = enroll(other, renew_cohort, "unrelated")["id"]

    base = "/api/credentials"
    review = f"{base}/enrollments/{enrollment}"
    issue = review + "/issue"
    config = {"valid_days": 180}
    assert other.call(review, status=404)["error"] == "enrollment_not_found"
    assert foreign.call(review, status=404)["error"] == "enrollment_not_found"
    assert learner.call(issue, "POST", config, 403)["error"] == "registrar_required"
    assignment = dict(title="Isolation procedure", instructions="Write a checklist.", due_at=utc_at(15), grace_minutes=15,
                      required_for_completion=True, pass_score=60, rubric=[dict(label="Checklist", points=100)])
    task = f"/api/coursework/cohorts/{cohort}/assignments"
    task_id = admin.call(task, "POST", assignment, 201)["id"]
    admin.call(f"/api/coursework/assignments/{task_id}/publish", "POST", {"version": 1})
    assert admin.call(issue, "POST", config, 409)["details"]["requirements"] == ["study", "coursework", "exam"]
    study(learner, enrollment, chapter_id, passes=2)
    assert learner.call(review)["study"]["studied_seconds"] == 600
    work = f"/api/coursework/enrollments/{enrollment}/assignments/{task_id}"
    first_answer = learner.call(work, "POST", {"answer": "Complete a risk check."}, 201, key="assignment-original")
    admin.call(f"/api/coursework/submissions/{first_answer['id']}/grade", "POST", {"marks": [{"points": 85}]}, 201)
    second_answer = learner.call(work, "POST", {"answer": "Verify isolation first, then check all risks."}, 201, key="assignment-new")
    assert admin.call(review)["unmet"] == ["coursework", "exam"]
    admin.call(f"/api/coursework/submissions/{second_answer['id']}/grade", "POST", {"marks": [{"points": 90}]}, 201)

    pools = [dict(name=f"Pool {i}", draw=1, points=weight,
                  questions=[dict(kind="single", prompt=f"Control {i}", options=["Right", "Wrong"], correct=[1])])
             for i, weight in enumerate([55, 23, 22], 1)]
    paper = publish_paper(admin, course, "Qualification exam", pools)["id"]
    now = datetime.now(timezone.utc)
    boundary = now + timedelta(seconds=7)
    initial = schedule(admin, cohort, paper, "First", now - timedelta(minutes=1), boundary)
    second = schedule(admin, cohort, paper, "Retake", boundary, boundary + timedelta(minutes=10))
    assert exam(learner, enrollment, initial, 1)["score"] == 55
    assert admin.call(issue, "POST", config, 409)["details"]["requirements"] == ["exam"]
    delay = (boundary - datetime.now(timezone.utc)).total_seconds()
    if delay > 0:
        time.sleep(delay + 0.2)
    retake = exam(learner, enrollment, second, 2)
    assert retake["score"] == 78
    assert admin.call(review)["can_issue"]
    receipts = race(["issue", identity["tenant_id"], identity["account_id"], enrollment], [config] * 6, FIXTURE)
    assert sum(not result["replayed"] for result in receipts) == 1
    assert {result["issued_events"] for result in receipts} == {1}
    assert len({(result["id"], result["serial"], result["issued_at"]) for result in receipts}) == 1
    certificate = admin.call(issue, "POST", config)
    assert certificate["replayed"] and certificate["status"] == "valid"
    assert certificate["evidence"]["exam"]["attempt_id"] == retake["id"]
    assert certificate["evidence"]["required_assignments"][0]["submission_id"] == second_answer["id"]
    assert admin.call(issue, "POST", {"valid_days": 365}, 409)["error"] == "credential_issue_conflict"
    assert learner.call(f"/api/enrollments/{enrollment}")["state"] == "completed"
    assert learner.call(review)["credential"]["holder_name"] == "learner"
    assert foreign.call(f"{base}/{certificate['id']}/revoke", "POST", {"reason": "Foreign tenant"}, 404)["error"] == "credential_not_found"
    assert verify(origin, certificate["serial"].lower())["status"] == "valid"
    assert Session(origin).call("/api/verify-credential/LC-" + "0" * 32, status=404)["error"] == "credential_not_found"
    assert command("credential-sweep") == {"retraining_tasks_created": 0}

    aging = subprocess.run([*FIXTURE, "age", str(identity["tenant_id"]), str(certificate["id"])],
                           check=True, capture_output=True, text=True, encoding="utf-8")
    assert json.loads(aging.stdout) == {"aged": 1}
    assert verify(origin, certificate["serial"])["status"] == "expired"
    counts = race(["sweep"], [{}] * 6, FIXTURE)
    assert sum(row["retraining_tasks_created"] for row in counts) == 1
    assert command("credential-sweep") == {"retraining_tasks_created": 0}
    tasks = base + "/retraining"
    records = learner.call(tasks + "?state=pending")["items"]
    assert len(records) == 1 and records[0]["original_serial"] == certificate["serial"]
    assert other.call(tasks)["items"] == [] and foreign.call(tasks)["items"] == []
    assignment_path = f"{tasks}/{records[0]['id']}/enrollment"
    assert admin.call(assignment_path, "PUT", {"enrollment_id": other_enrollment}, 422)["error"] == "invalid_retraining_enrollment"
    renewal = enroll(learner, renew_cohort, "qualify-renewal")["id"]
    assert learner.call(assignment_path, "PUT", {"enrollment_id": renewal}, 403)["error"] == "registrar_required"
    assert not admin.call(assignment_path, "PUT", {"enrollment_id": renewal})["replayed"]
    assert admin.call(assignment_path, "PUT", {"enrollment_id": renewal})["replayed"]
    assert learner.call(tasks + "?state=enrolled")["items"][0]["target_enrollment_id"] == renewal
    study(learner, renewal, chapter_id)
    now = datetime.now(timezone.utc)
    renewal_exam = schedule(admin, renew_cohort, paper, "Renewal exam", now - timedelta(minutes=1), now + timedelta(minutes=10))
    assert exam(learner, renewal, renewal_exam, 2)["score"] == 78
    new = admin.call(f"{base}/enrollments/{renewal}/issue", "POST", config, 201)
    assert new["original_serial"] == certificate["serial"] and new["serial"] != certificate["serial"]
    assert learner.call(tasks + "?state=completed")["items"][0]["renewed_serial"] == new["serial"]
    assert verify(origin, new["serial"])["status"] == "valid"
    revoked = admin.call(f"{base}/{new['id']}/revoke", "POST", {"reason": "Internal examiner decision"})
    assert revoked["revocation_reason"] == "Internal examiner decision" and not revoked["replayed"]
    assert admin.call(f"{base}/{new['id']}/revoke", "POST", {"reason": "Internal examiner decision"})["replayed"]
    assert verify(origin, new["serial"])["status"] == "revoked"
    assert learner.call(f"/api/enrollments/{renewal}")["state"] == "completed"
    assert learner.call(tasks + "?state=completed")["items"][0]["renewed_serial"] == new["serial"]
    print("AC-13–14 passed: 600 seconds, scores 55/78, six parallel issuers, private evidence, unique retraining and renewal.")


if __name__ == "__main__":
    run(sys.argv[1])
