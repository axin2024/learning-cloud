"""Exercise frozen papers, reconnect, timeouts and effective marks using MySQL."""

from datetime import datetime, timedelta, timezone
import json
import secrets
import subprocess
import sys
import time

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, enroll, require_test_database, revision
from enterprise_seats import publish, race


def iso(timestamp):
    return timestamp.isoformat(timespec="milliseconds").replace("+00:00", "Z")


def command(*args):
    result = subprocess.run([*PHP, "bin/learning.php", *args], capture_output=True, text=True, encoding="utf-8", check=True)
    return json.loads(result.stdout)


def publish_paper(admin, course, title, pools):
    paper = admin.call(f"/api/exams/courses/{course}/papers", "POST", dict(title=title, pools=pools), 201)
    admin.call(f"/api/exams/papers/{paper['id']}/publish", "POST", {"edit_version": 1})
    return paper


def schedule(admin, cohort, paper, title, start, end):
    return admin.call(f"/api/exams/cohorts/{cohort}/sittings", "POST",
                      dict(title=title, paper_id=paper, opens_at=iso(start), closes_at=iso(end), duration_seconds=300), 201)["id"]


def start(session, enrollment, sitting, status=201):
    return session.call(f"/api/exams/enrollments/{enrollment}/sittings/{sitting}/start", "POST", status=status)


def submit(session, attempt, version=1, status=200):
    return session.call(f"/api/exams/attempts/{attempt}/submit", "POST", {"answer_version": version}, status)


def automatic_attempt(learner, enrollment, sitting, answer):
    attempt = start(learner, enrollment, sitting)
    learner.call(f"/api/exams/attempts/{attempt['id']}/answers", "PUT", {"answer_version": 0, "answers": [{"ordinal": 1, "answer": answer}]})
    return submit(learner, attempt["id"])


def run(origin):
    require_test_database()
    tenant = "exams-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    tenant_id = create_tenant(tenant, password)["tenant_id"]
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    learner, learner_id = create_member(admin, origin, tenant, password, "candidate", ["learner"])
    timeout, _ = create_member(admin, origin, tenant, password, "timeout", ["learner"])
    absent, _ = create_member(admin, origin, tenant, password, "absent", ["learner"])
    marker, marker_id = create_member(admin, origin, tenant, password, "marker", ["instructor"])
    outsider, _ = create_member(admin, origin, tenant, password, "outsider", ["instructor"])

    course = publish(admin, "kiln-operations")
    cohort = create_cohort(admin, course, "kiln", 3)["id"]
    latest_course = admin.call("/api/courses", "POST", {"slug": "latest-policy"}, 201)["id"]
    body = dict(revision("Latest policy", chapter("Operating controls", "Synthetic material")), retake_mode="latest")
    rev = admin.call(f"/api/courses/{latest_course}/revisions", "POST", body, 201)
    admin.call(f"/api/courses/{latest_course}/revisions/{rev['id']}/publish", "POST", {"edit_version": 1})
    latest_cohort = create_cohort(admin, latest_course, "latest-policy-cohort", 1)["id"]
    a = enroll(learner, cohort, "exam-candidate")["id"]
    b = enroll(timeout, cohort, "exam-timeout")["id"]
    c = enroll(absent, cohort, "exam-absent")["id"]
    latest_enrollment = enroll(learner, latest_cohort, "exam-latest")["id"]
    admin.call(f"/api/coursework/cohorts/{cohort}/staff/{marker_id}", "PUT", {"duty": "instructor"})

    def single(prompt, correct=1):
        return dict(kind="single", prompt=prompt, options=["First", "Second"], correct=[correct])

    pools = [
        dict(name="Safety check", draw=1, points=55, questions=[single("Valve A"), single("Valve B"), single("Valve C")]),
        dict(name="Written procedure", draw=1, points=45, questions=[dict(kind="written", prompt="Describe how to isolate the line.", rubric="Order and tolerances")]),
    ]
    path = f"/api/exams/courses/{course}/papers"
    assert learner.call(path, status=403)["error"] == "registrar_required"
    assert admin.call(path, "POST", {"title": "Bad draw", "pools": pools[:1]}, 422)["error"] == "exam_points_must_total_100"
    paper = admin.call(path, "POST", {"title": "Kiln practice", "pools": pools}, 201)
    paper_path = f"/api/exams/papers/{paper['id']}"
    change = {"title": "Kiln assessment", "pools": pools, "edit_version": 1}
    assert admin.call(paper_path, "PUT", change)["edit_version"] == 2
    assert admin.call(paper_path + "/publish", "POST", {"edit_version": 1}, 409)["error"] == "exam_paper_version_conflict"
    admin.call(paper_path + "/publish", "POST", {"edit_version": 2})
    assert admin.call(paper_path, "PUT", dict(change, edit_version=2), 409)["error"] == "exam_paper_already_published"
    next_pools = [pools[0], dict(pools[1], questions=[dict(pools[1]["questions"][0], rubric="Order, tolerances and safe restart")])]
    retake = publish_paper(admin, course, "Kiln assessment revised", next_pools)
    latest_paper = publish_paper(admin, latest_course, "Latest policy exercise",
                                 [dict(name="Safety", draw=1, points=100, questions=[dict(kind="multiple", prompt="Identify both controls.",
                                                                                           options=["A", "B", "C"], correct=[3, 1])])])
    assert retake["revision"] == 2
    now = datetime.now(timezone.utc)
    transition = now + timedelta(seconds=15)
    stop = transition + timedelta(minutes=5)
    first = schedule(admin, cohort, paper["id"], "First sitting", now - timedelta(minutes=1), transition)
    second = schedule(admin, cohort, retake["id"], "Retake", transition, stop)
    first_latest = schedule(admin, latest_cohort, latest_paper["id"], "Latest initial", now - timedelta(minutes=1), transition)
    second_latest = schedule(admin, latest_cohort, latest_paper["id"], "Latest retake", transition, stop)
    assert absent.call(f"/api/exams/enrollments/{c}")["items"][0]["can_start"]
    assert start(learner, a, second, 409)["error"] == "exam_retake_requires_prior_grade"

    initial = start(learner, a, first)
    expired_attempt = start(timeout, b, first)["id"]
    assert automatic_attempt(learner, latest_enrollment, first_latest, [3, 1])["score"] == 100
    assert initial["paper_revision"] == 1 and len(initial["items"]) == 2
    assert initial["items"][0]["prompt"] in {"Valve A", "Valve B", "Valve C"}
    assert "seed" not in initial and all("guidance" not in item for item in initial["items"])
    attempt_id = initial["id"]
    work = f"/api/exams/attempts/{attempt_id}"
    assert timeout.call(work, status=404)["error"] == "enrollment_not_found"
    assert marker.call(work + "/marking", status=409)["error"] == "exam_attempt_not_submitted"
    assert outsider.call(work + "/marking", status=404)["error"] == "teaching_cohort_not_found"
    assert start(learner, a, first, 200)["items"] == initial["items"]
    answers = dict(answer_version=0, answers=[dict(ordinal=1, answer=[1]), dict(ordinal=2, answer="Close valve and check the reading.")])
    assert learner.call(work + "/answers", "PUT", answers)["answer_version"] == 1
    assert learner.call(work + "/answers", "PUT", answers)["replayed"]
    reconnect = Session(origin)
    reconnect.login(tenant, "candidate@example.test", password)
    resume = reconnect.call(work)
    assert resume["answer_version"] == 1 and resume["items"][1]["answer"] == answers["answers"][1]["answer"]
    assert submit(learner, attempt_id, 0, 409)["details"]["answer_version"] == 1
    submissions = race([tenant_id, learner_id, attempt_id], [{"answer_version": 1}] * 6, [*PHP, "tests/exams_fixture.php"])
    assert sum(not response["replayed"] for response in submissions) == 1
    assert {response["submitted_events"] for response in submissions} == {1}
    receipt = next({key: value for key, value in response.items() if key != "submitted_events"}
                   for response in submissions if not response["replayed"])
    assert receipt["submission_mode"] == "manual" and receipt["score"] is None
    assert submit(learner, attempt_id) == dict(receipt, replayed=True)
    assert marker.call(work + "/marking")["items"][1]["guidance"] == "Order and tolerances"
    grade = {"marks": [dict(ordinal=2, points=0, feedback="Missing isolation verification")]}
    assert marker.call(work + "/grade", "POST", grade)["score"] == 55
    assert marker.call(work + "/grade", "POST", grade)["replayed"]
    assert reconnect.call(f"/api/exams/enrollments/{a}")["effective_result"]["passed"] is False

    delay = (transition - datetime.now(timezone.utc)).total_seconds()
    if delay > 0:
        time.sleep(delay + 0.15)
    assert command("exam-sweep") == {"exam_attempts_finalized": 1}
    assert command("exam-sweep") == {"exam_attempts_finalized": 0}
    assert submit(timeout, expired_attempt, 0)["submission_mode"] == "expired"
    assert timeout.call(f"/api/exams/attempts/{expired_attempt}/answers", "PUT", {"answer_version": 0, "answers": [{"ordinal": 1, "answer": [1]}]}, 409)["error"] == "exam_answers_closed"
    assert start(absent, c, second, 409)["error"] == "exam_retake_requires_prior_grade"

    repeat = start(reconnect, a, second)
    assert repeat["id"] != attempt_id and repeat["paper_revision"] == 2
    practice = f"/api/exams/attempts/{repeat['id']}"
    reconnect.call(practice + "/answers", "PUT", answers)
    submit(reconnect, repeat["id"])
    assert marker.call(practice + "/marking")["items"][1]["guidance"].endswith("safe restart")
    assert marker.call(practice + "/grade", "POST", {"marks": [dict(ordinal=2, points=23)]})["score"] == 78
    rows = reconnect.call(f"/api/exams/enrollments/{a}")
    assert [item["score"] for item in rows["items"]] == [55, 78]
    assert rows["effective_result"]["score"] == 78 and rows["effective_result"]["passed"] is True
    assert reconnect.call(work)["items"][1]["feedback"] == "Missing isolation verification"

    assert automatic_attempt(learner, latest_enrollment, second_latest, [1])["score"] == 0
    latest = learner.call(f"/api/exams/enrollments/{latest_enrollment}")["effective_result"]
    assert latest["sequence"] == 2 and latest["score"] == 0 and not latest["passed"]
    assert len(marker.call(f"/api/exams/sittings/{first}/attempts")["items"]) == 2
    print("AC-11–12 passed: frozen resit papers, scores 55/78, latest policy, reconnect, six competing submits with one result, and server sweep.")


if __name__ == "__main__":
    run(sys.argv[1])
