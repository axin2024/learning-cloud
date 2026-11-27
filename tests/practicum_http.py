"""Exercise the practicum rubric against a disposable tenant and a real HTTP service."""

import base64
import hashlib
import json
from pathlib import Path
import secrets
import sys
import struct
import zlib

from admission_http import Session, create_cohort, create_member, create_tenant, enroll, require_test_database
from enterprise_seats import company, publish
from coursework_http import utc_at


def excerpt(item):
    out = {"artifact_id": item["artifact_id"], "anonymous_text": "已核对点检记录及图示。"}
    if item["kind"] in ("image", "video"):
        frames = 1 if item["kind"] == "image" else 2
        pixels = bytes((180,100,30,60,120,40)) * frames
        out["preview"] = dict(width=2,height=1,frames=frames,frame_ms=0 if frames == 1 else 300,
                              pixels_zlib=base64.b64encode(zlib.compress(pixels)).decode())
    return out


def run(origin, observe=None, evidence_output=None):
    require_test_database()
    tenant = "practicum-" + secrets.token_hex(5)
    password = secrets.token_hex(20)
    create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    student, student_id = create_member(admin, origin, tenant, password, "learner", ["learner", "instructor"])
    course = publish(admin, "workshop")
    old = create_cohort(admin, course, "spring", 10)["id"]
    new = create_cohort(admin, course, "autumn", 10)["id"]
    enroll(student, old, "join-spring")
    base = "/api/competency"
    catalog = base + "/rubrics"
    dimensions = [{"code": code, "name": name, "parent_code": None if i == 0 else "delivery",
                   "weight_pct": 25, "minimum_score": 60, "mandatory": i != 3,
                   "evidence_kind": "document", "evidence_scope": "team" if i == 0 else "personal",
                   "evidence_requirement": "标注作品页码和验证步骤"}
                  for i, (code, name) in enumerate([("delivery", "交付"), ("plan", "计划"), ("check", "核验"), ("record", "记录")])]
    proposal = dict(code="workshop-tech", title="实训技术员", level="初级", previous_revision=0,
                    passing_score=60, arbitration_gap=15, dimensions=dimensions)
    dimensions[1].update(evidence_kind="image", evidence_requirement="标记图上的检查区域")
    dimensions[3].update(evidence_kind="video", evidence_requirement="标记读数变化的时间段")
    a = admin.call(catalog, "POST", proposal, 201, key="publish-first")
    assert admin.call(catalog, "POST", proposal, 201, key="publish-first") == a
    assert admin.call(catalog, "POST", dict(proposal, title="不同请求"), 409, key="publish-first")["error"] == "request_key_conflict"
    cohort = lambda value: f"{base}/cohorts/{value}/rubric"
    admin.call(cohort(old), "PUT", {"rubric_id": a["rubric_id"]}, key="lock-spring")
    assert admin.call(catalog + "/" + str(a["rubric_id"]), "PUT", {"remove": "check"}, 409)["error"] == "publish_replacement_rubric"
    additional = dict(dimensions[-1], code="quality", name="质量")
    second = dict(proposal, previous_revision=1, dimensions=[dict(d, weight_pct=20) for d in [*dimensions, additional]])
    b = admin.call(catalog, "POST", second, 201, key="publish-second")
    assert admin.call(cohort(old), "PUT", {"rubric_id": b["rubric_id"]}, 409, key="rebind")["error"] == "cohort_rubric_frozen"
    assert admin.call(cohort(new), "PUT", {"rubric_id": b["rubric_id"]}, key="lock-autumn")["rubric_rev"] == 2
    assert len(student.call(cohort(old))["dimensions"]) == 4 and len(admin.call(cohort(new))["dimensions"]) == 5
    assert student.call(cohort(new), status=404)["error"] == "cohort_rubric_not_found"
    print("A1-A2 passed: old cohort kept four dimensions, the new cohort received five, and in-place edits were rejected.")
    teacher, teacher_id = create_member(admin, origin, tenant, password, "mentor", ["instructor"])
    admin.call(f"/api/coursework/cohorts/{old}/staff/{teacher_id}", "PUT", {"duty": "instructor"})
    students = [(student, student_id)]
    for nickname in ("builder", "inspector"):
        member, account = create_member(admin, origin, tenant, password, nickname, ["learner"])
        enroll(member, old, "join-" + nickname)
        students.append((member, account))
    outsider, _ = create_member(admin, origin, tenant, password, "outsider", ["learner"])
    enroll(outsider, old, "join-outsider")
    tasks = f"{base}/cohorts/{old}/practicums"
    brief = {"title": "产线设备点检", "instructions": "交付联合点检报告，分别标记贡献。", "sample": "样例：设备、读数、结论。",
             "due_at": utc_at(-20)}
    task = teacher.call(tasks, "POST", brief, 201, key="issue")
    task_id = task["practicum_id"]
    detail = f"/api/practicums/{task_id}"
    submit = f"/practicums/{task_id}/submissions"
    roster = [{"learner_id": account, "responsibility": role, "material": "内部材料-" + role}
              for (_, account), role in zip(students, ["组长", "装配", "核查"])]
    group = teacher.call(detail + "/teams", "POST", {"name": "甲班一组", "members": roster}, 201, key="assign")
    team_id = group["team_id"]
    team = f"{detail}/teams/{team_id}"
    assert len(set(group["assignment_ids"])) == 3 and group["brief_rev"] == 1
    assert outsider.call(detail)["assignments"] == []
    assert [t["id"] for t in student.call(tasks)["items"]] == [task_id]
    assert [m["material"] for m in teacher.call(detail)["assignments"]] == [m["material"] for m in roster]
    for index, (member, account) in enumerate(students):
        assignments = member.call(detail)["assignments"]
        assert len(assignments) == 3
        assert [v["material"] for v in assignments if v["material"] is not None] == [roster[index]["material"]]
        if index < 2:
            member.call(team + "/contribution", "PUT", {"previous_revision": 0, "body": f"点检记录 {account}"}, key="mine")

    def upload(member, code, body):
        raw = body.encode()
        anchor = {"page": 1}
        if code == "plan":
            def chunk(name, data):
                return struct.pack(">I",len(data)) + name + data + struct.pack(">I",zlib.crc32(name + data))
            raw = (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR",struct.pack(">IIBBBBB",2,1,8,2,0,0,0))
                   + chunk(b"tEXt",b"Description\0" + raw) + chunk(b"IDAT",zlib.compress(bytes((0,180,100,30,60,120,40))))
                   + chunk(b"IEND",b""))
            anchor = {"x_pct":0,"y_pct":0,"width_pct":100,"height_pct":100}
        elif code == "record":
            raw = (Path(__file__).parent / "fixtures/gauge-inspection.webm").read_bytes()
            anchor = {"start_ms":0,"end_ms":2000}
        claim = member.call(team + "/artifacts", "POST", {"dimension_code": code, "content_base64": base64.b64encode(raw).decode(),
                            "anchor": anchor}, 201, key="upload-" + hashlib.sha256(raw).hexdigest()[:20])
        assert claim["digest"] == "sha256:" + hashlib.sha256(raw).hexdigest()
        return claim

    shared = upload(student, "delivery", "三人联合点检报告")
    hashes = [shared["digest"]]
    hashes.append(upload(student, "record", "两秒仪表录像")["digest"])
    for i, (member, _) in enumerate(students):
        hashes.append(upload(member, "plan", f"第{i}份个人计划")["digest"])
        if i < 2:
            hashes.append(upload(member, "check", f"第{i}份个人核验记录")["digest"])
    request = {"team_id": team_id, "attempt": 1, "artifact_hashes": hashes, "rubric_rev": 1}
    assert student.call(submit, "POST", request, 409, key="submit")["error"] == "late_approval_required"
    student.call(team + "/extension", "POST", {"attempt": 1, "reason": "现场设备更换"}, 201, key="request")
    assert student.call(submit, "POST", request, 409, key="submit")["error"] == "late_approval_required"
    grant = {"attempt": 1, "until_at": utc_at(40)}
    student.call(team + "/extension", "PUT", grant, 404, key="approve")
    approved = teacher.call(team + "/extension", "PUT", grant, key="approve")
    no_shared = student.call(submit, "POST", dict(request, artifact_hashes=hashes[1:]), 422, key="no-shared")
    assert no_shared["details"]["dimensions"] == [{"code": "delivery", "name": "交付"}]
    missing = student.call(submit, "POST", request, 422, key="submit")
    assert missing["details"]["members"] == [{"learner_id": students[2][1], "dimensions": ["计划", "核验"]}]
    students[2][0].call(team + "/contribution", "PUT", {"previous_revision": 0, "body": "个人点检记录"}, key="mine")
    first = student.call(submit, "POST", request, 201, key="submit")
    assert first == student.call(detail + "/submissions", "POST", request, 201, key="submit")
    assert first["late"] and first["extension_id"] == approved["extension_id"]
    assert first["missing_evidence"] == [{"assignment_id": group["assignment_ids"][2], "code": "check", "name": "核验"}]
    manifest = first["evidence_manifest"]
    canonical = json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()
    assert hashlib.sha256(canonical).hexdigest() == first["manifest_digest"]
    assert len(manifest["members"]) == 3 and sum(c["dimension_code"] == "delivery" for c in manifest["artifacts"]) == 1
    outsider.call(team + "/submissions/1", status=404)
    assert first == teacher.call(team + "/submissions/1")
    addition = upload(students[2][0], "check", "补充后核验记录")
    second = dict(request, attempt=2, artifact_hashes=[*hashes, addition["digest"]])
    assert student.call(submit, "POST", second, 409, key="submit-again")["error"] == "late_approval_required"
    student.call(team + "/extension", "POST", {"attempt": 2, "reason": "补核验记录"}, 201, key="second-request")
    teacher.call(team + "/extension", "PUT", dict(grant, attempt=2), key="second-grant")
    later = student.call(submit, "POST", second, 201, key="submit-again")
    assert later["changes"] == {"added": [addition["artifact_id"]], "removed": [], "changed_contributions": []}
    assert later["missing_evidence"] == [] and first["manifest_digest"] != later["manifest_digest"]
    assert first == student.call(team + "/submissions/1")
    assert base64.b64decode(students[1][0].call(team + "/artifacts/" + str(shared["artifact_id"]))["content_base64"]).decode() == "三人联合点检报告"
    personal = team + "/artifacts/" + str(addition["artifact_id"])
    student.call(personal, status=404)
    assert teacher.call(personal)["digest"] == students[2][0].call(personal)["digest"]
    print("A3-A6 passed: three contributions, private materials, per-attempt late approval, immutable submissions and named evidence gaps.")
    review_root = "/api/practicum-reviews"
    cases = f"{review_root}/submissions/{first['submission_id']}/cases"
    assignment_id = group["assignment_ids"][0]
    own_files = [v for v in manifest["artifacts"] if v["assignment_id"] == assignment_id or v["scope"] == "team"]
    excerpts = [excerpt(item) for item in own_files]
    prep = {"assignment_id": assignment_id, "excerpts": excerpts}
    assert admin.call(cases, "POST", dict(prep, excerpts=[dict(excerpts[0], anonymous_text="学号 S12431"), *excerpts[1:]]),
                      422, key="prepare")["error"] == "identifying_review_text"
    case = admin.call(cases, "POST", prep, 201, key="prepare")["case_id"]
    path = f"{review_root}/cases/{case}"
    judges = [create_member(admin, origin, tenant, password, "judge-" + code, ["instructor"]) for code in ("one", "two", "three")]
    seat = path + "/seats"
    assert admin.call(seat, "PUT", {"seat": "first", "reviewer_id": student_id}, 409, key="conflict")["error"] == "reviewer_conflict"
    assert admin.call(seat, "PUT", {"seat": "first", "reviewer_id": teacher_id}, 409, key="mentor")["error"] == "reviewer_conflict"
    for role, (_, judge_id) in zip(["first", "second"], judges):
        admin.call(seat, "PUT", {"seat": role, "reviewer_id": judge_id}, key=role)
    ballot = path + "/ballot"
    judge1, judge2, arbiter = [p for p, _ in judges]
    packet = judge1.call(ballot)
    for item in packet["packet"]:
        fields = {"dimension_code", "kind", "anchor", "text"}
        if item["kind"] in ("image","video"):
            fields.add("preview")
            preview = item["preview"]
            assert zlib.decompress(base64.b64decode(preview["pixels_zlib"])) == bytes((180,100,30,60,120,40)) * preview["frames"]
        assert set(item) == fields
    assert "learner_id" not in json.dumps(packet) and "artifact_id" not in json.dumps(packet)
    student.call(ballot, status=404)
    judge1.call(personal, status=404)
    assert [v["case_id"] for v in judge2.call(review_root + "/inbox")["items"]] == [case]
    def marks(score):
        return {"scores": [{"code": d["code"], "score": score if d["code"] == "check" else 80,
                            "reason": "已根据量规核对"} for d in dimensions]}
    judge1.call(ballot, "PUT", marks(80), key="lock")
    assert judge2.call(ballot)["scores"] is None
    assert admin.call(path + "/result")["final_scores"] is None
    assert judge1.call(ballot, "PUT", marks(60), 409, key="modify")["error"] == "review_locked"
    judge2.call(ballot, "PUT", marks(60), key="lock")
    outstanding = admin.call(path + "/result")
    assert outstanding["status"] == "arbitration" and outstanding["disputed"] == ["check"]
    assert admin.call(seat, "PUT", {"seat": "arbiter", "reviewer_id": judges[1][1]}, 409, key="duplicate")["error"] == "independent_reviewers_required"
    arb = admin.call(seat, "PUT", {"seat": "arbiter", "reviewer_id": judges[2][1]}, key="third")
    assert [d["code"] for d in arbiter.call(ballot)["dimensions"]] == ["check"]
    arbiter.call(ballot, "PUT", {"scores": [{"code": "check", "score": 74, "reason": "依据复测结果裁定"}]}, key="lock")
    final = student.call(path + "/result")
    assert final["arbitration_id"] == arb["review_id"] and len(final["review_ids"]) == 3
    assert final["status"] == "scored" and final["weighted_score"] == "78.5" and final["meets_standard"] is True
    students[1][0].call(path + "/result", status=404)
    print("A7-A8 passed: same-team conflict, separate ballots, 80/60 arbitration, and a 78.5 weighted result.")
    def score_case(source, member_index, score):
        assignment = group["assignment_ids"][member_index]
        sources = [a for a in source["evidence_manifest"]["artifacts"] if a["scope"] == "team" or a["assignment_id"] == assignment]
        prepared = {"assignment_id": assignment, "excerpts": [excerpt(a) for a in sources]}
        suffix = f"{source['submission_id']}-{member_index}"
        cid = admin.call(f"{review_root}/submissions/{source['submission_id']}/cases", "POST", prepared, 201, key="case-" + suffix)["case_id"]
        case_path = f"{review_root}/cases/{cid}"
        for role, (reviewer, uid) in zip(("first", "second"), judges):
            admin.call(case_path + "/seats", "PUT", {"seat": role, "reviewer_id": uid}, key=role + suffix)
            reviewer.call(case_path + "/ballot", "PUT", marks(score), key="vote-" + suffix)
        return case_path

    anonymous = Session(origin)
    def public(key):
        return anonymous.call("/api/verify-credential/" + key)

    original = admin.call(path + "/credential", "POST", {}, 201, key="first-card")
    company_id = company(admin,"training-plant")
    company_path = f"/api/companies/{company_id}"
    dept = admin.call(company_path+"/departments","POST",{"code":"inspection","name":"点检车间"},201)["id"]
    admin.call(company_path+"/learners","POST",{"department_id":dept, "learner_ids":[uid for _,uid in students]})
    manager, manager_id = create_member(admin, origin, tenant, password, "training-manager", ["learner"])
    admin.call(f"{company_path}/administrators/{manager_id}","PUT",{"enabled":True})
    manager.login(tenant,"training-manager@example.test",password)
    plans = company_path+"/competencies"
    policy = {"department_id":dept,"cohort_id":old,"disclosure_minimum":3}
    plan_id = admin.call(plans,"POST",policy,201,key="plan")["plan_id"]
    report = f"{plans}/{plan_id}"
    employee_ids = [uid for _,uid in students]
    admin.call(report+"/roster","PUT",{"learner_ids":employee_ids,"required":True},key="participants")
    assert manager.call(report)["qualified_count"] == 1
    outsider.call(report,status=404)
    manager.call(plans,"POST",policy,403,key="policy-change")
    assert not ({"title","score","holder","learner_id"} & public(original["serial"]).keys())
    assert admin.call(path + "/credential", "POST", {}, 201, key="first-card-retry") == original
    assert admin.call(path + "/disclosure", "PUT", {"show_title": True,"show_score": True}, 403, key="not-holder")["error"] == "holder_required"
    student.call(path + "/disclosure", "PUT", {"show_title": True,"show_score": False}, key="my-fields")
    assert public(original["serial"])["title"] == proposal["title"] and "score" not in public(original["serial"])
    second_case = score_case(later, 0, 55)
    assert admin.call(second_case + "/credential", "POST", {}, 422, key="unqualified")["error"] == "competency_not_met"
    def appeal(member, where, source, key):
        return member.call(where + "/appeals", "POST", {"dimension_code": "check", "evidence_submission_id": source["submission_id"],
                           "statement": "请复核点检附件的复测结果。"}, 201, key=key)["appeal_id"]
    new_appeal = appeal(student, second_case, later, "challenge")
    pending_report = manager.call(report)
    assert pending_report["required_count"] == 3 and pending_report["qualified_count"] == 0
    assert [m["learner_id"] for m in pending_report["members"] if m["appeal_pending"]] == [student_id]
    dispute = second_case + "/appeals/" + str(new_appeal)
    assert len(admin.call(dispute)["votes"]) == 2 and all(v["score"] == 55 for v in admin.call(dispute)["votes"])
    students[1][0].call(dispute, status=404)
    assert admin.call(path + "/credential", "POST", {}, 409, key="pending")["error"] == "appeal_pending"
    decision = {"decision": "amended", "score": 80, "reason": "原始量测值与复测记录一致，改为达标。"}
    replacement = admin.call(dispute, "PUT", decision, key="approve-challenge")
    assert replacement == admin.call(dispute, "PUT", decision, key="approve-challenge")
    assert replacement["replaced_credential"] == original["credential_id"] and replacement["revision"] == 2
    assert public(original["serial"])["replaced"] and public(original["serial"])["revision"] == 2
    assert public(replacement["serial"])["status"] == "valid"
    other_case = score_case(first, 1, 76)
    other = admin.call(other_case + "/credential", "POST", {}, 201, key="other-card")
    missing_case = score_case(first, 2, 50)
    before_supplement = manager.call(report)
    assert before_supplement["qualified_count"] == 2 and before_supplement["required_count"] == 3
    assert before_supplement["gaps"] == {"check":{"name":"核验","missing_count":1}}
    assert before_supplement["members"][-1]["score"] == "72.5"
    if observe:
        observe(tenant, password, "awaiting_evidence")
    admin.call(plans,"POST",dict(policy,disclosure_minimum=4),201,key="privacy")
    hidden = manager.call(report)
    assert hidden["suppressed"] and hidden["members"] == [] and hidden["qualified_count"] == 2
    assert not any(person in json.dumps(hidden) for person in ("inspector","builder","learner_id","assignment_id"))
    manager.call(report+"/training","POST",{"cohort_id":new,"instructions":"核对漏项"},422,key="wrong-rubric")
    train = {"cohort_id":old,"instructions":"重新点检传感器，上传核验记录。"}
    assert manager.call(report+"/training","POST",train,201,key="train")["created"] == 1
    assert manager.call(report+"/training","POST",train,201,key="train-again")["created"] == 0
    assert len(students[2][0].call("/api/me/competency-training")["items"]) == 1 and not student.call("/api/me/competency-training")["items"]
    admin.call(plans,"POST",policy,201,key="restore-threshold")
    students[2][0].call(team+"/extension","POST",{"attempt":3,"reason":"复训后重新交件"},201,key="third-try")
    teacher.call(team+"/extension","PUT",dict(grant,attempt=3),key="approve-third")
    improved = upload(students[2][0],"check","复训后独立测得的压力值和复检记录")
    retake = students[2][0].call(submit,"POST",dict(second, attempt=3,artifact_hashes=[*hashes,improved["digest"]]),201,key="post-training")
    assert admin.call(missing_case + "/certification")["missing_dimensions"] == {"check":"核验"}
    assert admin.call(missing_case + "/credential", "POST", {}, 422, key="needs-evidence")["error"] == "competency_not_met"
    initial_appeal = appeal(students[2][0], missing_case, first, "missing-file")
    refused = missing_case + "/appeals/" + str(initial_appeal)
    assert admin.call(refused, "PUT", decision, 422, key="missing")["error"] == "supplemental_evidence_required"
    admin.call(refused, "PUT", {"decision":"upheld","reason":"该版尚无个人核验证据。"}, key="uphold")
    supplemented = appeal(students[2][0], missing_case, retake, "supplement")
    if observe:
        observe(tenant, password, "under_appeal")
    granted = admin.call(missing_case + "/appeals/" + str(supplemented), "PUT", decision, key="accept-supplement")
    assert granted["evaluation"]["meets_standard"] and granted["revision"] == 1
    after_supplement = manager.call(report)
    assert after_supplement["qualified_count"] == after_supplement["required_count"] == 3
    assert after_supplement["training_completed"] == 1 and after_supplement["gaps"] == {}
    assert after_supplement["members"][-1]["score_change"] == 7.5
    if observe:
        observe(tenant, password, "qualified")
    assert students[2][0].call("/api/me/competency-training")["items"][0]["final_score"] == "80.0"
    employee_url = plans+"/employment/"+str(students[2][1])
    admin.call(employee_url,"PUT",{"employed":False},key="leave")
    departed = manager.call(report)
    assert departed["qualified_count"] == departed["required_count"] == 2 and departed["members"] == []
    admin.call(employee_url,"PUT",{"employed":True},key="return")
    assert len(students[2][0].call(missing_case + "/certification")["appeals"]) == 2
    student.call(second_case + "/disclosure", "PUT", {"show_title": True, "show_score": True}, key="score-consent")
    assert public(replacement["serial"])["score"] == "80.0"
    admin.call(second_case + "/revoke", "POST", {"credential_id": replacement["credential_id"], "reason": "撤销演练"}, key="withdraw")
    for serial in (original["serial"], replacement["serial"]):
        invalid = public(serial)
        assert set(invalid) == {"status", "date"} and invalid["status"] == "revoked"
    assert public(other["serial"])["status"] == "valid"
    assert manager.call(report)["qualified_count"] == 2
    print("A9-A10 passed: individual appeal with locked scores, evidence-bound amendment, successor and revoked public readback.")
    print("A11-A13 passed: deduplicated 2/3 -> 3/3 after supplemental evidence and appeal, protected small cohort, retraining 72.5 -> 80.0.")
    if evidence_output is not None:
        evidence_output.write_text(json.dumps({
            "cohort_id":old, "report_plan":policy, "initial_submission_input": request, "initial_receipt":first,
            "supplement_receipt":retake, "department_before":before_supplement, "privacy_readback":hidden,
            "officer_input":decision, "appeal_receipt":granted, "department_after":after_supplement,
            "replacement_receipt":replacement, "public_after_revocation":invalid, "after_departure":departed,
        },ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    return tenant, password


if __name__ == "__main__":
    run(sys.argv[1], evidence_output=Path(sys.argv[2]) if len(sys.argv)>2 else None)
