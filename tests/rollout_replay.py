"""Read a v1.1 learner's imported history and replay one publish across a service restart."""
import argparse
import json
import sys

from admission_http import Session, require_test_database


def run(origin, tenant, password, state):
    require_test_database()
    learner = Session(origin)
    learner.login(tenant, "operator@example.test", password)
    history = learner.call("/api/me/competency-prerequisites")["items"]
    assert len(history) == 1 and len(history[0]["source_digest"]) == 64
    admin = Session(origin)
    admin.login(tenant, "admin@example.test", password)
    body = dict(code="handover", title="Handover inspection", level="Operator", previous_revision=0,
                passing_score=60, arbitration_gap=15, dimensions=[dict(code="pressure", name="Pressure",
                weight_pct=100, minimum_score=60, mandatory=True, evidence_kind="document", evidence_scope="personal",
                evidence_requirement="Record pressure before handover")])
    path = "/api/competency/rubrics"
    receipt = admin.call(path, "POST", body, 201, key="first-rollout-publish")
    if state == "drained":
        error = admin.call(path, "POST", dict(body, code="secondary"), 409, key="second-publish")
        assert error["error"] == "competency_writes_paused"
    print(json.dumps(dict(history=history, receipt=receipt), sort_keys=True))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("origin")
    parser.add_argument("tenant")
    parser.add_argument("state", choices=["enabled", "drained"])
    args = parser.parse_args()
    run(args.origin, args.tenant, sys.stdin.readline().rstrip("\r\n"), args.state)
