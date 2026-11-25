"""Check enrollment-bound progress and provider receipts through HTTP."""

import json
import os
import secrets
import subprocess
import sys
import urllib.error
import urllib.request

from admission_http import PHP, Session, chapter, create_cohort, create_member, create_tenant, enroll, require_test_database, revision


def provider_call(origin, token, body, status):
    request = urllib.request.Request(origin + "/api/live-attendance", json.dumps(body).encode(), {
        "Authorization": "Bearer " + token, "Content-Type": "application/json", "Accept": "application/json"}, method="POST")
    try:
        response = urllib.request.urlopen(request, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        result = json.load(response)
        assert response.status == status, (response.status, status, result)
        assert response.headers.get("Set-Cookie") is None
        return result


def run(origin):
    require_test_database()
    tenant = "study-" + secrets.token_hex(5)
    password = secrets.token_hex(18)
    create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    alice, _ = create_member(admin, origin, tenant, password, "alice", ["learner"])
    bob, _ = create_member(admin, origin, tenant, password, "bob", ["learner"])
    course = admin.call("/api/courses", "POST", {"slug": "recorded-and-live"}, 201)["id"]
    recorded = {**chapter("Recorded lesson", "Synthetic recording description"), "kind": "recorded", "media_url": "https://example.test/recording.mp4"}
    live = {**chapter("Live lesson", "Synthetic instructor session"), "kind": "live", "required_seconds": 150}
    rev_url = f"/api/courses/{course}/revisions"
    bad_recorded = {**recorded, "media_url": "https://unsafe-user@example.test/recording.mp4"}
    assert admin.call(rev_url, "POST", revision("Hybrid training", bad_recorded, live), 422)["error"] == "invalid_recorded_media_url"
    draft = admin.call(rev_url, "POST", revision("Hybrid training", recorded, live), 201)
    assert draft["chapters"][0]["media_url"] == recorded["media_url"]
    draft = admin.call(rev_url + f"/{draft['id']}", "PUT", {**revision("Hybrid training", recorded, live), "edit_version": 1})
    published = admin.call(rev_url + f"/{draft['id']}/publish", "POST", {"edit_version": 2})
    video_id, live_id = [item["id"] for item in published["chapters"]]
    initial = create_cohort(admin, course, "hybrid-one", 3)["id"]
    destination = create_cohort(admin, course, "hybrid-two", 2)["id"]
    alice_id = enroll(alice, initial, "alice-initial")["id"]
    bob_id = enroll(bob, initial, "bob-initial")["id"]
    study_url = lambda identity: f"/api/study/enrollments/{identity}"
    start_url = lambda identity, chapter_id: f"{study_url(identity)}/chapters/{chapter_id}/start"
    assert alice.call(study_url(bob_id), status=404)["error"] == "enrollment_not_found"
    assert admin.call(start_url(alice_id, video_id), "POST", status=404)["error"] == "enrollment_not_found"
    assert alice.call(start_url(alice_id, live_id), "POST", status=409)["error"] == "live_provider_not_configured"
    assert alice.call(study_url(alice_id))["studied_seconds"] == 0
    session = alice.call(start_url(alice_id, video_id), "POST")
    assert session == alice.call(start_url(alice_id, video_id), "POST")
    interval_url = f"/api/study/sessions/{session['id']}/intervals"
    send = lambda client, start, end, key, status=201: client.call(interval_url, "POST", {"start_second": start, "end_second": end}, status, key)
    assert send(bob, 0, 20, "foreign", 404)["error"] == "enrollment_not_found"
    assert send(alice, 0, 60, "long", 422)["error"] == "study_interval_too_long"
    assert send(alice, 595, 610, "overflow", 422)["error"] == "study_interval_outside_chapter"
    first = send(alice, 0, 20, "zero")
    assert send(alice, 0, 20, "zero", 200) == {**first, "replayed": True}
    assert send(alice, 1, 20, "zero", 409)["error"] == "request_key_reused"
    send(alice, 40, 60, "later")
    progress = alice.call(study_url(alice_id))
    assert progress["studied_seconds"] == 40 and progress["chapters"][0]["resume_second"] == 20
    send(alice, 15, 44, "bridge")
    assert alice.call(study_url(alice_id))["studied_seconds"] == 60
    for replay in range(2):
        for start in range(0, 600, 30):
            send(alice, start, start + 30, f"pass-{replay}-{start}")
    progress = alice.call(study_url(alice_id))
    assert progress["studied_seconds"] == 600 and progress["chapters"][0]["resume_second"] == 600
    assert not progress["study_requirement_met"] and progress["enrollment_state"] == "studying"

    providers = "/api/study/providers"
    assert alice.call(providers, status=403)["error"] == "registrar_required"
    provider = admin.call(providers, "POST", {"code": "webinar", "name": "Webinar"}, 201)
    wrong = admin.call(providers, "POST", {"code": "wrong-webinar", "name": "Other provider"}, 201)
    assert all("token" not in item for item in admin.call(providers)["items"])
    binding = f"/api/study/cohorts/{initial}/live/{live_id}"
    admin.call(binding, "PUT", {"provider_id": provider["id"]})
    live_session = alice.call(start_url(alice_id, live_id), "POST")
    assert live_session["channel"] == "live" and len(live_session["session_code"]) == 48
    assert admin.call(binding, "PUT", {"provider_id": wrong["id"]}, 409)["error"] == "live_provider_already_in_use"
    assert alice.call(f"/api/study/sessions/{live_session['id']}/intervals", "POST", {"start_second": 10, "end_second": 20}, 403, "forged")["error"] == "live_provider_interval_required"

    initial_event = {"event_id": "attendance-first", "role": "learner", "start_second": 0, "end_second": 100, "session_code": live_session["session_code"]}
    assert provider_call(origin, "0" * 64, initial_event, 401)["error"] == "attendance_provider_unauthorized"
    wrong_receipt = provider_call(origin, wrong["token"], initial_event, 202)
    assert wrong_receipt["reason"] == "attendance_session_unmatched"
    accepted = provider_call(origin, provider["token"], initial_event, 202)
    assert accepted["status"] == "accepted"
    assert provider_call(origin, provider["token"], initial_event, 200) == {**accepted, "replayed": True}
    assert provider_call(origin, provider["token"], {**initial_event, "end_second": 110}, 409)["error"] == "request_key_reused"
    host = provider_call(origin, provider["token"], {**initial_event, "event_id": "attendance-host", "role": "host", "end_second": 600}, 202)
    assert host["status"] == "rejected" and host["reason"] == "attendance_role_not_learner"
    missing = {"event_id": "attendance-missing", "role": "learner", "start_second": 80, "end_second": 160}
    held = provider_call(origin, provider["token"], missing, 202)
    assert held["status"] == "review" and held["reason"] == "attendance_session_code_missing"
    inbox = "/api/study/attendance"
    assert alice.call(inbox, status=403)["error"] == "registrar_required"
    assert {item["id"] for item in admin.call(inbox)["items"]} == {held["id"], wrong_receipt["id"]}
    decision = {"decision": "accept", "session_code": live_session["session_code"], "note": "Verified roster and interval receipt"}
    assert admin.call(f"{inbox}/{wrong_receipt['id']}/review", "POST", decision, 404)["error"] == "attendance_session_unmatched"
    admin.call(f"{inbox}/{wrong_receipt['id']}/review", "POST", {"decision": "reject", "note": "Different provider"})
    assert alice.call(study_url(alice_id))["studied_seconds"] == 700
    admin.call(f"{inbox}/{held['id']}/review", "POST", decision)
    assert admin.call(f"{inbox}/{held['id']}/review", "POST", decision, 409)["error"] == "attendance_event_already_reviewed"
    completed = alice.call(study_url(alice_id))
    assert completed["studied_seconds"] == 760 and completed["study_requirement_met"]
    rotated = admin.call(f"{providers}/{provider['id']}/rotate", "POST")
    assert provider_call(origin, provider["token"], missing, 401)["error"] == "attendance_provider_unauthorized"
    assert provider_call(origin, rotated["token"], missing, 200)["status"] == "accepted"
    replayed = subprocess.run([*PHP, "bin/replay-attendance.php", origin], input=json.dumps(missing) + "\n", text=True,
                              env={**os.environ, "LEARNING_ATTENDANCE_TOKEN": rotated["token"]}, capture_output=True, timeout=12)
    assert replayed.returncode == 0, replayed.stderr
    assert json.loads(replayed.stdout)["receipt"] == {"id": held["id"], "status": "accepted", "reason": "attendance_session_code_missing", "replayed": True}

    result = alice.call(f"/api/enrollments/{alice_id}/transfer", "POST", {"target_cohort_id": destination})
    assert result["state"] == "studying"
    assert alice.call(study_url(result["id"]))["studied_seconds"] == 760
    assert alice.call(start_url(result["id"], video_id), "POST")["resume_second"] == 600
    assert send(alice, 0, 20, "after-transfer", 409)["error"] == "enrollment_cannot_study"
    assert alice.call(study_url(alice_id))["studied_seconds"] == 760
    print("Study passed: 600 seconds across repeated playback and overlap, live receipts and reviews, credential rotation, same-version transfer and scoped access.")


if __name__ == "__main__":
    run(sys.argv[1])
