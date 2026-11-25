"""Load and verify a fictional company training program in a local demo database."""

import argparse
from datetime import datetime, timedelta, timezone
from getpass import getpass
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import secrets
import subprocess
import sys
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request


ROOT = Path(__file__).resolve().parent.parent
ADMIN = "admin@example.test"
MANAGER = "manager@example.test"
LEARNER = "operator@example.test"


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Session:
    def __init__(self, origin):
        self.origin = origin
        self.client = build_opener(HTTPCookieProcessor(CookieJar()), NoRedirect())
        self.csrf = None

    def call(self, path, method="GET", data=None, status=200, *, key=None, token=None):
        headers = {"Accept": "application/json"}
        if method != "GET":
            headers["Content-Type"] = "application/json"
            if self.csrf:
                headers["X-CSRF-Token"] = self.csrf
            if key:
                headers["Idempotency-Key"] = key
            if token:
                headers["Authorization"] = "Bearer " + token
            data = json.dumps(data or {}).encode()
        request = Request(self.origin + path, data, headers, method=method)
        try:
            response = self.client.open(request, timeout=15)
        except HTTPError as error:
            response = error
        with response:
            result = json.load(response)
            require(response.status == status, f"{method} {path}: {response.status} {result.get('error', '')}")
        self.csrf = result.get("csrf_token", self.csrf)
        return result

    def login(self, tenant, email, password):
        self.call("/api/session")
        self.call("/api/login", "POST", dict(tenant=tenant, email=email, password=password))


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def php(command, *args, input_text=None):
    return subprocess.run([*command, *args], input=input_text, cwd=ROOT, encoding="utf-8",
                          stdout=subprocess.PIPE, check=True, timeout=60).stdout


def course(admin, slug, title, content, kind, seconds):
    course_id = admin.call("/api/courses", "POST", {"slug": slug}, 201)["id"]
    base = f"/api/courses/{course_id}/revisions"
    chapter = dict(title=title, content=content, duration_seconds=600, required_seconds=seconds,
                   kind=kind, rights_basis="original", rights_reference="Learning Cloud 原创演示课件")
    draft = admin.call(base, "POST", dict(title=title, summary="港湾培训中心内部案例",
                       minimum_study_seconds=seconds, pass_score=60, retake_mode="highest", chapters=[chapter]), 201)
    published = admin.call(f"{base}/{draft['id']}/publish", "POST", {"edit_version": 1})
    return course_id, published["chapters"][0]["id"]


def run(origin, command, password):
    dsn = php(command, "-r", "echo getenv('LEARNING_DSN');")
    require(re.search(r"(?:^mysql:|;)dbname=learning_(?:demo|test)_[a-z0-9_]+(?:;|$)", dsn),
            "Use a dedicated MySQL database named learning_demo_*.")
    require(Session(origin).call("/healthz") == {"ready": True}, "Migrate the database and start the web service.")
    tenant = "harbor-demo-" + secrets.token_hex(5)
    php(command, "bin/learning.php", "create-tenant", tenant, "港湾培训中心", ADMIN, "培训教务",
        input_text=password + "\n")
    admin, manager, learner = (Session(origin) for _ in range(3))
    admin.login(tenant, ADMIN, password)
    manager_id = admin.call("/api/accounts", "POST", dict(name="运行部管理员", email=MANAGER,
                            password=password, roles=["learner"]), 201)["account_id"]
    learner_id = admin.call("/api/accounts", "POST", dict(name="演示学员", email=LEARNER,
                            password=password, roles=["learner"]), 201)["account_id"]
    manager.login(tenant, MANAGER, password)
    learner.login(tenant, LEARNER, password)

    basic, chapter = course(admin, "handover", "运行交接基础",
                            "交班前在交接单记录设备状态、异常和待处理事项。接班人逐项复述并确认责任人。", "live", 600)
    advanced, _ = course(admin, "handover-practice", "异常交接实训",
                         "演练发现未关闭的告警时，如何记录临时措施、升级联系人和复查时间。", "text", 0)
    paths = "/api/learning-paths"
    path_id = admin.call(paths, "POST", dict(code="operations", title="运行岗位资格",
                         description="先完成基础交接，再进入异常交接演练。", course_ids=[basic, advanced]), 201)["id"]
    path = f"{paths}/{path_id}"
    steps = admin.call(path + "/publish", "POST", {"version": 1})["steps"]
    now = datetime.now(timezone.utc)
    dates = dict(starts_on=(now.date() - timedelta(days=1)).isoformat(),
                 ends_on=(now.date() + timedelta(days=7)).isoformat())
    cohorts = []
    for step, code, name in zip(steps, ["handover-morning", "exception-practice"], ["交接基础班", "异常实训班"]):
        cohort = admin.call("/api/cohorts", "POST", dict(course_id=step["course_id"], path_step_id=step["id"],
                            code=code, name=name, capacity=12, registration_open=True, **dates), 201)
        cohorts.append(cohort["id"])
    foundation, practicum = cohorts
    require(not learner.call(path)["steps"][1]["eligible"], "Advanced cohort must require the first course.")

    company_id = admin.call("/api/companies", "POST", dict(code="harbor-works", name="港湾设备公司"), 201)["id"]
    company = f"/api/companies/{company_id}"
    admin.call(f"{company}/administrators/{manager_id}", "PUT", {"enabled": True})
    department = admin.call(company + "/departments", "POST", dict(code="operations", name="运行部"), 201)["id"]
    admin.call(company + "/learners", "POST", dict(department_id=department, learner_ids=[learner_id]))
    purchase = admin.call(company + "/purchases", "POST", dict(course_id=basic, contract_code="demo-contract",
                          total=4, **dates), 201)["id"]
    batch = manager.call(f"{company}/purchases/{purchase}/batches", "POST",
                         dict(cohort_id=foundation, rows=[dict(department_id=department, learner_id=learner_id)]),
                         201, key="demo-assign")
    require(batch["items"][0]["outcome"] == "assigned", "The department seat allocation must succeed.")
    seat = batch["items"][0]["assignment_id"]
    enrollment = learner.call(f"/api/seat-assignments/{seat}/activate", "POST")["enrollment_id"]

    provider = admin.call("/api/study/providers", "POST", dict(code="local-replay", name="本地出勤回放"), 201)
    admin.call(f"/api/study/cohorts/{foundation}/live/{chapter}", "PUT", {"provider_id": provider["id"]})
    study = f"/api/study/enrollments/{enrollment}"
    session = learner.call(f"{study}/chapters/{chapter}/start", "POST")
    event = dict(event_id="demo-attendance", role="learner", session_code=session["session_code"],
                 start_second=0, end_second=600)
    replay = Session(origin)
    first = replay.call("/api/live-attendance", "POST", event, 202, token=provider["token"])
    repeated = replay.call("/api/live-attendance", "POST", event, token=provider["token"])
    progress = learner.call(study)
    require(first["status"] == "accepted" and repeated == dict(first, replayed=True)
            and progress["studied_seconds"] == 600 and progress["study_requirement_met"],
            "Replayed attendance must count for exactly 600 seconds.")

    homework = admin.call(f"/api/coursework/cohorts/{foundation}/assignments", "POST",
                          dict(title="提交交接清单", instructions="列出交接项和接班确认方式。",
                               due_at=utc(now + timedelta(days=1)), grace_minutes=0,
                               required_for_completion=True, pass_score=60,
                               rubric=[dict(label="交接流程", points=100)]), 201)["id"]
    admin.call(f"/api/coursework/assignments/{homework}/publish", "POST", {"version": 1})
    submitted = learner.call(f"/api/coursework/enrollments/{enrollment}/assignments/{homework}", "POST",
                             {"answer": "记录设备状态、未关闭告警和负责人。接班人复述并签字确认。"},
                             201, key="demo-homework")
    grade = admin.call(f"/api/coursework/submissions/{submitted['id']}/grade", "POST",
                       {"marks": [{"points": 90}]}, 201)
    require(grade["score"] == 90, "Assignment grade must be 90.")

    paper = admin.call(f"/api/exams/courses/{basic}/papers", "POST", dict(title="交接核验",
                       pools=[dict(name="交接责任", draw=1, points=100, questions=[
                           dict(kind="single", prompt="谁应当复述并确认交接项？", options=["接班人", "访客"], correct=[1])
                       ])]), 201)["id"]
    admin.call(f"/api/exams/papers/{paper}/publish", "POST", {"edit_version": 1})
    sitting = admin.call(f"/api/exams/cohorts/{foundation}/sittings", "POST",
                         dict(title="基础班结业考", paper_id=paper, duration_seconds=300,
                              opens_at=utc(now - timedelta(minutes=2)), closes_at=utc(now + timedelta(hours=1))), 201)["id"]
    exams = f"/api/exams/enrollments/{enrollment}"
    attempt = learner.call(f"{exams}/sittings/{sitting}/start", "POST", status=201)["id"]
    attempt_url = f"/api/exams/attempts/{attempt}"
    learner.call(attempt_url + "/answers", "PUT", dict(answer_version=0, answers=[dict(ordinal=1, answer=[1])]))
    mark = learner.call(attempt_url + "/submit", "POST", {"answer_version": 1})
    replayed = learner.call(attempt_url + "/submit", "POST", {"answer_version": 1})
    require(not mark["replayed"] and mark["score"] == 100 and replayed == dict(mark, replayed=True)
            and learner.call(exams)["effective_result"]["score"] == 100, "A replay must return the original exam result.")

    certificate = admin.call(f"/api/credentials/enrollments/{enrollment}/issue", "POST", {"valid_days": 365}, 201)
    serial = certificate["serial"]
    verified = Session(origin).call("/api/verify-credential/" + serial)
    require(verified["status"] == "valid" and "learner_name" not in verified, "Anonymous verification must be valid and private.")
    require(learner.call(path)["steps"][1]["eligible"], "Issuing the certificate must unlock the practicum.")
    report = manager.call(company + "/reports/departments" + f"?from={dates['starts_on']}&to={now.date().isoformat()}")
    require(report["totals"] == {"effective_enrollments": 1, "effective_learners": 1}
            and report["items"][0]["effective_completions"] == 1, "The department must show one completion.")
    return dict(tenant=tenant, admin=ADMIN, manager=MANAGER, learner=LEARNER, cohort_ids=cohorts, exam_score=100,
                attendance_seconds=600, assignment_score=90, certificate=serial, practicum_eligible=True,
                department_completions=1)


def utc(date):
    return date.strftime("%Y-%m-%dT%H:%M:%SZ")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("origin", help="URL of the local HTTP or HTTPS workbench")
    parser.add_argument("--compose", action="store_true", help="run PHP inside the Compose web service")
    parser.add_argument("--php-arg", action="append", default=[], help="repeat to pass options to native PHP")
    args = parser.parse_args()
    origin = urlsplit(args.origin)
    if (origin.scheme not in ["http", "https"] or origin.hostname not in ["127.0.0.1", "localhost", "::1"]
            or origin.username or origin.password or origin.path not in ["", "/"] or origin.query or origin.fragment):
        parser.error("Use the origin of a loopback-only demo server.")
    password = getpass("Demo password: ") if sys.stdin.isatty() else sys.stdin.readline().rstrip("\r\n")
    require(12 <= len(password.encode()) <= 72, "Enter a demo password of 12 to 72 bytes.")
    command = ["docker", "compose", "exec", "-T", "web", "php"] if args.compose else ["php"]
    print(json.dumps(run(args.origin.rstrip("/"), command + args.php_arg, password), ensure_ascii=True, indent=2))


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError, subprocess.SubprocessError) as error:
        sys.exit(str(error))
