"""Verify real contention and durable receipts with independent MySQL connections."""

from concurrent.futures import ThreadPoolExecutor
import json
import subprocess
import time

from admission_http import PHP, require_test_database


FIXTURE = [*PHP, "tests/admission_fixture.php"]


def fixture(*args):
    result = subprocess.run([*FIXTURE, *args], capture_output=True, encoding="utf-8", check=True, timeout=30)
    return json.loads(result.stdout)


def compete(identity, jobs, request_key):
    processes = []
    try:
        for job in jobs:
            process = subprocess.Popen(
                [*FIXTURE, "worker", *identity, request_key, ",".join(map(str, job))],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding="utf-8",
            )
            processes.append(process)
        for process in processes:
            line = process.stdout.readline()
            if not line:
                raise RuntimeError(process.stderr.read())
            assert json.loads(line) == {"ready": True}

        def run(process):
            output, error = process.communicate("start\n", timeout=25)
            assert process.returncode == 0, error
            return json.loads(output)

        with ThreadPoolExecutor(max_workers=len(jobs)) as pool:
            started = time.perf_counter()
            rows = [row for group in pool.map(run, processes) for row in group]
            elapsed = time.perf_counter() - started
        return rows, elapsed
    finally:
        for process in processes:
            if process.poll() is None:
                process.kill()
                process.communicate()


def run():
    require_test_database()
    setup = fixture("prepare")
    identity = list(map(str, [setup["tenant"], setup["actor"], setup["cohort"]]))
    contenders = setup["contenders"]
    jobs = [contenders[index:index + 10] for index in range(0, len(contenders), 10)]
    rows, elapsed = compete(identity, jobs, "capacity")
    assert len(rows) == 100 and len({row["id"] for row in rows}) == 100 and all(row["created"] for row in rows)
    pos = {row["learner_id"]: row for row in rows}
    assert sum(row["state"] == "enrolled" for row in rows) == 5
    assert sum(row["state"] == "waitlisted" for row in rows) == 95
    assert fixture("read", *identity) == {"states": {"enrolled": 10, "waitlisted": 95}, "requests": 105, "creation_events": 105}

    learner = contenders[0]
    reused, _ = compete(identity, [[learner]] * 10, "new-shared-key")
    assert len(reused) == 10 and all(row["id"] == pos[learner]["id"] and not row["created"] for row in reused)
    assert fixture("read", *identity) == {"states": {"enrolled": 10, "waitlisted": 95}, "requests": 106, "creation_events": 105}
    print(json.dumps({"independent_connections": 10, "contenders": 100, "admitted": 5, "waitlisted": 95,
                      "seconds_after_start": round(elapsed, 4), "same_key_responses": 10, "new_replay_receipts": 1}))


if __name__ == "__main__":
    run()
