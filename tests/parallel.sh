#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time

with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    source = Path(sys.argv[1])
    for name in ("run.php", "parallel.php", "child-poll.php"):
        shutil.copy2(source / name, root / name)
    for name in ("hello", "poll"):
        shutil.copytree(source / "workflows" / name, root / "workflows" / name)
    (root / "workflows/hello/70-receipt.php").write_text(
        "<?php\nreturn workflow_write(__DIR__ . '/../../receipt.json', $state);\n"
    )
    env = dict(os.environ, HELLO_CHILD_POLL="1", HELLO_POLL="0",
               HELLO_CHILD_ITEMS="[3,1,2,4]", HELLO_CHILD_CONCURRENCY="2",
               HELLO_APPROVED="1", POLL_ONCE="1")
    wait_node = root / "workflows/poll/20-wait.php"
    wait_node.write_text("""<?php
$state['pid'] = getmypid();
$state['began'] = microtime(true);
usleep(400000);
$state['ended'] = microtime(true);
""")

    def start(args=None):
        return subprocess.Popen(
            ["php", str(root / "run.php"), *(args or ["hello"])],
            env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, start_new_session=True,
        )

    def kill_group(p):
        try:
            os.killpg(p.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        p.wait()

    def finish(p, code=0):
        try:
            out, err = p.communicate(timeout=15)
            assert p.returncode == code and out == "", (p.returncode, out, err)
            return err
        finally:
            kill_group(p)

    def workers(p):
        deadline = time.monotonic() + 8
        while time.monotonic() < deadline:
            found = []
            for path in (root / ".runs/hello/parallel").glob("batch-*/job-*/runtime/poll.json"):
                saved = json.loads(path.read_text())
                if saved["status"] == "running" and Path(f'/proc/{saved["pid"]}').exists():
                    found.append(saved["pid"])
            if len(found) >= 2:
                return found
            assert p.poll() is None, p.communicate()
            time.sleep(0.01)
        raise AssertionError("Workers did not start")

    err = finish(start())
    receipt = json.loads((root / "receipt.json").read_text())
    results = receipt["child_poll_results"]
    assert [r["item"] for r in results] == [3, 1, 2, 4]
    assert [r["result"] for r in results] == [9, 1, 4, 16]
    assert receipt["child_poll_sum"] == 30
    assert len({r["pid"] for r in results}) == 4
    events = []
    for r in results:
        assert not Path(f'/proc/{r["pid"]}').exists()
        events.extend([(r["began"], 1), (r["ended"], -1)])
    active = peak = 0
    for _, delta in sorted(events):
        active += delta
        peak = max(peak, active)
    assert peak == 2, peak
    assert err.index("Child workflow poll completed") < err.index("Reduced child poll results")
    assert not (root / ".runs/poll.json").exists()

    env["HELLO_CHILD_ITEMS"] = "[]"
    finish(start())
    assert json.loads((root / "receipt.json").read_text())["child_poll_sum"] == 0

    finish(start(["poll"]))
    direct = (root / ".runs/poll.json").read_bytes()
    env["HELLO_CHILD_ITEMS"] = "[3,1,2,4]"
    (root / "receipt.json").unlink()
    wait_node.write_text("""<?php
if ($state['item'] === 3) {
    usleep(700000);
    return ['error' => 'Injected map failure'];
}
sleep(20);
""")
    p = start()
    try:
        pids = workers(p)
        err = finish(p, 1)
        assert "failed with exit code 1" in err
        assert "Reduced child poll results" not in err
        assert not (root / "receipt.json").exists()
        assert all(not Path(f"/proc/{pid}").exists() for pid in pids)
    finally:
        kill_group(p)
    checkpoint = json.loads((root / ".runs/hello.checkpoint.json").read_text())
    assert checkpoint["node"] == "55-child-poll.php"
    assert "child_poll_results" not in checkpoint["state"]
    assert (root / ".runs/poll.json").read_bytes() == direct

    wait_node.write_text("<?php\nsleep(20);\n")
    p = start()
    try:
        pids = workers(p)
        p.send_signal(signal.SIGTERM)
        assert "Parallel workflows interrupted" in finish(p, 1)
        assert all(not Path(f"/proc/{pid}").exists() for pid in pids)
    finally:
        kill_group(p)

    controller = root / "child-poll.php"
    original = controller.read_text()
    controller.write_text(original.replace(
        "workflow_parallel($jobs, $concurrency, 30, $results)",
        "workflow_parallel($jobs, $concurrency, 1, $results)"
    ))
    p = start()
    try:
        pids = workers(p)
        assert "Parallel workflows timed out" in finish(p, 1)
        assert all(not Path(f"/proc/{pid}").exists() for pid in pids)
    finally:
        kill_group(p)
    controller.write_text(original)
    wait_node.write_text("<?php\nusleep(50000);\n")
    finish(start())
    assert json.loads((root / "receipt.json").read_text())["child_poll_sum"] == 30

    job = root / "manual-job"
    job.mkdir()
    (job / "input.json").write_text('{"item":7}')
    (job / "output.json").write_text('{"stale":true}')
    wait_node.write_text("<?php\nsleep(2);\n")
    args = ["poll", "--job-dir", str(job)]
    p = start(args)
    try:
        deadline = time.monotonic() + 3
        while not (job / "runtime/poll.checkpoint.json").exists():
            assert time.monotonic() < deadline
            time.sleep(0.01)
        assert not (job / "output.json").exists()
        contender = start(args)
        assert "acquire lock" in finish(contender, 1)
        finish(p)
    finally:
        kill_group(p)
    assert json.loads((job / "output.json").read_text())["result"] == 49

print("PASS: bounded parallel map, ordered reduce, empty map, isolated locks, failure/stop/timeout cleanup and resume")
PYTEST
