"""Verify directional transfer rules, fractional spans, frozen grades and capacity in MySQL."""

from datetime import datetime, timedelta, timezone
import secrets
import sys

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, enroll, require_test_database, revision
from coursework_http import utc_at
from enterprise_seats import company, purchase, race
from exams_http import automatic_attempt, publish_paper, schedule


def publish_revision(admin, course, title, *chapters):
    endpoint = f"/api/courses/{course}/revisions"
    draft = admin.call(endpoint, "POST", revision(title, *chapters), 201)
    return admin.call(f"{endpoint}/{draft['id']}/publish", "POST", {"edit_version": 1})


def add_work(learner, admin, cohort, enrollment):
    assignment = dict(title="Inspection checklist", instructions="List the controls.", due_at=utc_at(40), grace_minutes=15,
                      required_for_completion=True, pass_score=60, rubric=[dict(label="Checks", points=100)])
    identity = admin.call(f"/api/coursework/cohorts/{cohort}/assignments", "POST", assignment, 201)["id"]
    admin.call(f"/api/coursework/assignments/{identity}/publish", "POST", {"version": 1})
    path = f"/api/coursework/enrollments/{enrollment}/assignments/{identity}"
    first = learner.call(path, "POST", {"answer": "Review the pressure gauge."}, 201, key="before-transfer")
    admin.call(f"/api/coursework/submissions/{first['id']}/grade", "POST", {"marks": [{"points": 82}]}, 201)
    second = learner.call(path, "POST", {"answer": "Inspect the gauge and labels."}, 201, key="pending-grade")
    return first["id"], second["id"]


def interval(learner, session, start, end):
    learner.call(f"/api/study/sessions/{session}/intervals", "POST", {"start_second": start, "end_second": end},
                 201, key=f"{start}-{end}")


def run(origin):
    require_test_database()
    tenant = "transfer-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    ids = create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    learner, learner_id = create_member(admin, origin, tenant, password, "candidate", ["learner"])
    other, other_id = create_member(admin, origin, tenant, password, "other", ["learner"])
    funded, funded_id = create_member(admin, origin, tenant, password, "funded", ["learner"])
    outside_tenant = "other-" + secrets.token_hex(5)
    create_tenant(outside_tenant, password)
    outside = Session(origin)
    outside.login(outside_tenant, "admin@example.test", password)

    course = admin.call("/api/courses", "POST", {"slug": "plant-safety"}, 201)["id"]
    first = publish_revision(admin, course, "Plant operations v1", chapter("Inspect", "Original equipment"),
                             chapter("Retired operations", "Synthetic source material"))
    second = publish_revision(admin, course, "Plant operations v2", chapter("Updated equipment", "Revised material"))
    foreign_course = admin.call("/api/courses", "POST", {"slug": "refresher"}, 201)["id"]
    third = publish_revision(admin, foreign_course, "Site refresher", chapter("Refresher", "Separate course"))
    old = create_cohort(admin, course, "plant-v1", 1, revision_id=first["id"])["id"]
    new = create_cohort(admin, course, "plant-v2", 1, revision_id=second["id"])["id"]
    alternate = create_cohort(admin, course, "plant-v2-alternate", 1, revision_id=second["id"])["id"]
    refresher = create_cohort(admin, foreign_course, "site-refresher", 2)["id"]
    source = enroll(learner, old, "source")["id"]
    occupied = enroll(other, new, "occupied")["id"]
    queued = enroll(other, old, "queued")
    assert queued["state"] == "waitlisted"

    enrollment = f"/api/enrollments/{source}"
    transfer = enrollment + "/transfer"
    options = enrollment + "/transfer-options"
    body = {"target_cohort_id": new}
    pair = f"/api/course-equivalences/{first['id']}/{second['id']}"
    mapping = dict(source_chapter_id=first["chapters"][0]["id"], target_chapter_id=second["chapters"][0]["id"],
                   source_start_second=20, target_start_second=10, seconds=40)
    config = {"version": 0, "enabled": True, "reason": "Shared inspection procedure", "mappings": [mapping]}
    assert admin.call(pair)["version"] == 0
    assert learner.call(pair, status=403)["error"] == "registrar_required"
    assert outside.call(pair, status=404)["error"] == "equivalence_published_revisions_required"
    assert outside.call(transfer, status=404)["error"] == "enrollment_not_found"
    assert other.call(options, status=404)["error"] == "enrollment_not_found"
    assert learner.call(options)["items"] == []
    assert learner.call(transfer, "POST", body, 409)["error"] == "course_equivalence_required"
    assert admin.call(pair, "PUT", dict(config, mappings=[mapping, mapping]), 422)["error"] == "invalid_equivalence_mapping"
    assert admin.call(pair, "PUT", dict(config, mappings=[dict(mapping, seconds=600)]), 422)["error"] == "invalid_equivalence_mapping"
    assert admin.call(pair, "PUT", dict(config, mappings=[dict(mapping, target_chapter_id=third["chapters"][0]["id"])]),
                      422)["error"] == "invalid_equivalence_mapping"

    progress = f"/api/study/enrollments/{source}"
    session = learner.call(f"{progress}/chapters/{mapping['source_chapter_id']}/start", "POST")["id"]
    retired = learner.call(f"{progress}/chapters/{first['chapters'][1]['id']}/start", "POST")["id"]
    interval(learner, session, 0, 30)
    interval(learner, session, 40, 65)
    interval(learner, retired, 0, 30)
    graded, pending = add_work(learner, admin, old, source)
    pool = dict(name="Checks", draw=1, points=100,
                questions=[dict(kind="single", prompt="Inspect first?", options=["Yes", "No"], correct=[1])])
    paper = publish_paper(admin, course, "Equipment inspection", [pool])["id"]
    now = datetime.now(timezone.utc)
    sitting = schedule(admin, old, paper, "Original examination", now - timedelta(minutes=1), now + timedelta(minutes=10))
    graded_exam = automatic_attempt(learner, source, sitting, [1])["id"]

    rule = admin.call(pair, "PUT", config)
    assert rule["enabled"] and rule["version"] == 1
    assert admin.call(pair, "PUT", config, 409)["details"]["version"] == 1
    assert {item["id"] for item in learner.call(options)["items"]} == {new, alternate}
    assert learner.call(transfer, "POST", body, 409)["error"] == "target_cohort_full"
    assert learner.call(enrollment)["state"] == "studying" and learner.call(progress)["studied_seconds"] == 85
    assert learner.call(transfer)["items"] == []
    other.call(f"/api/enrollments/{occupied}/withdraw", "POST")
    results = race([ids["tenant_id"], learner_id, source], [body] * 6, [*PHP, "tests/admission_fixture.php"])
    assert len({result["id"] for result in results}) == 1 and {result["transfer_events"] for result in results} == {1}
    moved = learner.call(transfer, "POST", body)
    target = moved["id"]
    assert moved["state"] == "studying" and learner.call(enrollment)["state"] == "transferred"
    assert other.call(f"/api/enrollments/{queued['id']}")["state"] == "enrolled"
    assert learner.call(progress)["studied_seconds"] == 85
    target_progress = f"/api/study/enrollments/{target}"
    assert learner.call(target_progress)["studied_seconds"] == 30
    continued = learner.call(f"{target_progress}/chapters/{mapping['target_chapter_id']}/start", "POST")
    assert continued["resume_second"] == 0
    interval(learner, continued["id"], 0, 30)
    assert learner.call(target_progress)["chapters"][0]["resume_second"] == 50

    receipt = learner.call(f"/api/enrollments/{target}/transfer")["items"][0]
    assert receipt == learner.call(transfer)["items"][0]
    assert receipt["credited_seconds"] == 30 and receipt["equivalence_snapshot"] == rule
    assert receipt["grade_snapshot"]["exams"][0]["attempt_id"] == graded_exam
    assert receipt["grade_snapshot"]["exams"][0]["score"] == 100
    assert [(work["submission_id"], work["score"]) for work in receipt["grade_snapshot"]["coursework"]] == [(graded, 82)]
    assert other.call(f"/api/enrollments/{target}/transfer", status=404)["error"] == "enrollment_not_found"
    assert learner.call(f"/api/exams/enrollments/{target}")["effective_result"] is None
    admin.call(f"/api/coursework/submissions/{pending}/grade", "POST", {"marks": [{"points": 91}]}, 201)
    assert admin.call(pair, "PUT", dict(config, version=1, enabled=False))["version"] == 2
    assert learner.call(transfer, "POST", body) == moved
    assert learner.call(transfer)["items"] == [receipt]
    assert learner.call(f"/api/enrollments/{target}/transfer", "POST", {"target_cohort_id": old}, 409)["error"] == "course_equivalence_required"
    assert other.call(f"/api/enrollments/{queued['id']}/transfer", "POST",
                      {"target_cohort_id": alternate}, 409)["error"] == "course_equivalence_required"

    base = f"/api/companies/{company(admin, 'plant-company')}"
    department = admin.call(base + "/departments", "POST", {"code": "operators", "name": "Operators"}, 201)["id"]
    admin.call(base + "/learners", "POST", {"department_id": department, "learner_ids": [funded_id]})
    contract = purchase(admin, base + "/purchases", course, 1, "operators-contract")["id"]
    paid_cohort = create_cohort(admin, course, "enterprise-v2", 1)["id"]
    assignment = admin.call(f"{base}/purchases/{contract}/batches", "POST", {
        "cohort_id": paid_cohort, "rows": [{"department_id": department, "learner_id": funded_id}],
    }, 201, key="company-seat")["items"][0]["assignment_id"]
    paid = funded.call(f"/api/seat-assignments/{assignment}/activate", "POST")["enrollment_id"]
    paid = funded.call(f"/api/enrollments/{paid}/transfer", "POST", {"target_cohort_id": alternate})["id"]
    cross_pair = f"/api/course-equivalences/{second['id']}/{third['id']}"
    cross_map = dict(source_chapter_id=mapping["target_chapter_id"], target_chapter_id=third["chapters"][0]["id"],
                     source_start_second=0, target_start_second=0, seconds=60)
    admin.call(cross_pair, "PUT", dict(config, mappings=[cross_map]))
    paid_path = f"/api/enrollments/{paid}"
    assert refresher not in {item["id"] for item in funded.call(paid_path + "/transfer-options")["items"]}
    assert funded.call(paid_path + "/transfer", "POST", {"target_cohort_id": refresher}, 409)["error"] == "transfer_requires_purchased_course"
    assert learner.call(f"/api/enrollments/{target}/transfer", "POST", {"target_cohort_id": refresher})["state"] == "studying"
    print("Transfers passed: 85 source / 30 mapped / 50 resumed seconds, immutable grades, six retries, full-cohort rollback and enterprise scope.")


if __name__ == "__main__":
    run(sys.argv[1])
