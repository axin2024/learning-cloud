"""Measure authenticated submissions and anonymous HTTP verification against an isolated deployment."""

import argparse
import asyncio
import base64
from collections import Counter
from datetime import datetime, timezone
import gzip
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import secrets
import subprocess
import time
import urllib.request

import aiohttp

from admission_http import Session, create_cohort, create_member, create_tenant, enroll, require_test_database
from coursework_http import utc_at
from enterprise_seats import publish


def prepare(origin, teams):
    tenant = "capacity-" + secrets.token_hex(6)
    password = secrets.token_hex(20)
    create_tenant(tenant, password)
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    course = publish(admin, "line-inspection")
    cohort = create_cohort(admin, course, "certification", teams)["id"]
    criteria = dict(code="inspection", name="Inspection", weight_pct=100, minimum_score=60, mandatory=True,
                    evidence_kind="document", evidence_scope="personal", evidence_requirement="Record measured pressure")
    standard = dict(code="inspect", title="Line inspection", level="Operator", previous_revision=0,
                    passing_score=60, arbitration_gap=15, dimensions=[criteria])
    rubric = admin.call("/api/competency/rubrics", "POST", standard, 201, key="publish")
    url = f"/api/competency/cohorts/{cohort}"
    admin.call(url + "/rubric", "PUT", {"rubric_id":rubric["rubric_id"]}, key="freeze")
    task = admin.call(url + "/practicums", "POST", {"title":"Inspect pressure","instructions":"Record a reading",
                      "sample":"Gauge 1 measures 20 kPa","due_at":utc_at(240)}, 201, key="task")["practicum_id"]
    judges = [create_member(admin, origin, tenant, password, "judge-" + k, ["instructor"]) for k in ("one","two")]
    writers, cards = [], []
    for index in range(teams):
        suffix = str(index)
        student, uid = create_member(admin, origin, tenant, password, "student-" + suffix, ["learner"])
        enroll(student,cohort,"join")
        path = f"/api/practicums/{task}"
        team = admin.call(path + "/teams","POST",{"name":"team-" + suffix, "members":[{"learner_id":uid,
                          "responsibility":"Inspection","material":"Gauge " + suffix}]},201,key="team-" + suffix)
        team_path = path + "/teams/" + str(team["team_id"])
        student.call(team_path+"/contribution","PUT",{"body":"Measured the assigned gauge","previous_revision":0},key="log")
        content = base64.b64encode(("Gauge reading for " + suffix).encode()).decode()
        artifact = student.call(team_path+"/artifacts","POST",{"dimension_code":"inspection",
                                "content_base64":content,"anchor":{"page":1}},201,key="upload")
        body = {"team_id":team["team_id"],"rubric_rev":1,"artifact_hashes":[artifact["digest"]]}
        first = student.call(path+"/submissions","POST",dict(body,attempt=1),201,key="initial")
        case = admin.call(f"/api/practicum-reviews/submissions/{first['submission_id']}/cases","POST",
                          {"assignment_id":team["assignment_ids"][0],"excerpts":[{"artifact_id":artifact["artifact_id"],
                          "anonymous_text":"Gauge within working range"}]},201,key="prepare-"+suffix)["case_id"]
        vote = f"/api/practicum-reviews/cases/{case}"
        for role,(reviewer,account) in zip(("first","second"),judges):
            admin.call(vote+"/seats","PUT",{"seat":role,"reviewer_id":account},key=role+suffix)
            reviewer.call(vote+"/ballot","PUT",{"scores":[{"code":"inspection","score":80,"reason":"Measurement verified"}]},key="vote"+suffix)
        card = admin.call(vote+"/credential","POST",{},201,key="issue"+suffix)
        if index % 4 == 0:
            admin.call(vote+"/revoke","POST",{"credential_id":card["credential_id"],"reason":"Load scenario"},key="revoke"+suffix)
        cards.append((card["serial"], "revoked" if index % 4 == 0 else "valid"))
        cookie_request = urllib.request.Request(origin+path)
        for handler in student.client.handlers:
            if hasattr(handler,"cookiejar"):
                handler.cookiejar.add_cookie_header(cookie_request)
        cookie = cookie_request.get_header("Cookie")
        assert cookie
        writers.append((body,{"Cookie":cookie,"X-CSRF-Token":student.csrf}))
    return task,writers,cards


def statistics(rows):
    times = sorted(row["ms"] for row in rows)
    outcomes = Counter(row["outcome"] for row in rows)
    return {"requests":len(rows), "success":outcomes["ok"], "rejected":outcomes["rejected"],
            "timeouts":outcomes["timeout"], "errors":outcomes["error"],
            "p50_ms":times[math.ceil(len(times)*.5)-1], "p95_ms":times[math.ceil(len(times)*.95)-1],
            "max_ms":times[-1], "elapsed_seconds":round(max(r["end"] for r in rows) - min(r["start"] for r in rows),3)}


async def measure(opts, task, writers, cards):
    rows = []
    committed = set()
    warm_clients = min(500,opts.readers)
    started = time.perf_counter()
    connector = aiohttp.TCPConnector(limit=opts.readers+len(writers)+10)
    async with aiohttp.ClientSession(connector=connector,timeout=aiohttp.ClientTimeout(total=20)) as http:
        async def request(phase, path, expected, fields, *, headers=None, body=None):
            start = time.perf_counter()
            record = {"phase":phase, **fields, "start":round(start-started,6)}
            try:
                async with http.request("POST" if body else "GET",opts.origin+path,json=body,headers=headers) as resp:
                    payload = await resp.json(content_type=None)
                    record["http_status"] = resp.status
                    if body:
                        record["submission_id"] = payload.get("submission_id")
                        valid = bool(record["submission_id"]) and payload.get("attempt")==fields["attempt"] and payload.get("missing_evidence")==[]
                    else:
                        valid = payload.get("status")==expected and (expected!="revoked" or set(payload)=={"status","date"})
                    record["outcome"] = "ok" if valid and resp.status==(201 if body else 200) else "rejected"
                    if record["outcome"]!="ok": record["response"] = payload
            except asyncio.TimeoutError:
                record["outcome"] = "timeout"
            except (aiohttp.ClientError,ValueError,OSError) as error:
                record.update(outcome="error", error=str(error)[:250])
            end = time.perf_counter()
            record.update(end=round(end-started,6),ms=round((end-start)*1000,3))
            rows.append(record)
            return record

        async def wave(phase, cycle, clients):
            gate = asyncio.Event()
            async def verify(i):
                await gate.wait()
                serial, state = cards[(i+cycle) % len(cards)]
                await request(phase,"/api/verify-credential/"+serial,state,{"client":i,"cycle":cycle,"expected":state})
            jobs = [asyncio.create_task(verify(i)) for i in range(clients)]
            await asyncio.sleep(0)
            gate.set()
            await asyncio.gather(*jobs)

        async def writer(worker, count):
            body, auth = writers[worker]
            for attempt in range(2,count+2):
                receipt = await request("submit",f"/practicums/{task}/submissions",None,{"worker":worker,"attempt":attempt},
                    headers={**auth,"Idempotency-Key":f"load-{attempt}"},body=dict(body,attempt=attempt))
                if receipt["outcome"]!="ok": break
                committed.add(receipt["submission_id"])
                if len(committed)%2000 == 0: print("Committed",len(committed),flush=True)

        for cycle in range(2):
            await wave("warmup",cycle,warm_clients)
        writes = asyncio.gather(*(writer(i,opts.submissions//len(writers)+(i<opts.submissions%len(writers))) for i in range(len(writers))))
        while not writes.done() and len(committed)<len(writers)*2:
            await asyncio.sleep(.05)
        for cycle in range(opts.waves):
            await wave("mixed",cycle,opts.readers)
        await writes
        for cycle in range(opts.waves):
            await wave("populated",cycle,opts.readers)
    return rows,len(committed)


if __name__=="__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("origin")
    parser.add_argument("output",type=Path)
    parser.add_argument("--submissions",type=int,default=10000)
    parser.add_argument("--readers",type=int,default=500)
    parser.add_argument("--teams",type=int,default=25)
    parser.add_argument("--waves",type=int,default=6)
    options = parser.parse_args()
    require_test_database()
    if min(options.teams,options.submissions,options.readers,options.waves)<1 or options.submissions<options.teams:
        parser.error("Positive counts and at least one submission per team are required.")
    options.output.mkdir(parents=True,exist_ok=True)
    print("Preparing isolated teams and credentials",flush=True)
    task, writers, cards = prepare(options.origin,options.teams)
    print("Starting measurement",flush=True)
    rows,distinct = asyncio.run(measure(options,task,writers,cards))
    raw = options.output/"requests.jsonl.gz"
    with gzip.open(raw,"wt",encoding="utf-8") as stream:
        for row in rows: stream.write(json.dumps(row,separators=(",",":"))+"\n")
    phases = {p:statistics([r for r in rows if r["phase"]==p]) for p in ("warmup","mixed","submit","populated")}
    summary = {"utc":datetime.now(timezone.utc).isoformat(),"os":platform.platform(),"cpu":platform.processor(),"logical_cpu":os.cpu_count(),
        "php":subprocess.check_output(["php","-v"],text=True).splitlines()[0],"python":platform.python_version(),"aiohttp":aiohttp.__version__,
        "readers":options.readers,"waves":options.waves,"teams":options.teams,"distinct_submissions":distinct,"credential_population":len(cards),
        "sha256_raw":hashlib.sha256(raw.read_bytes()).hexdigest(),"phases":phases}
    print(json.dumps(summary,indent=2),flush=True)
    (options.output/"summary.json").write_text(json.dumps(summary,indent=2)+"\n",encoding="utf-8")
    assert distinct==options.submissions and all(v["success"]==v["requests"] for v in phases.values())
    assert max(phases[k]["p95_ms"] for k in ("mixed","populated")) <=500
