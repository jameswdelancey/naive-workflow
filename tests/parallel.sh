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

source = Path(sys.argv[1])
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    for name in ("run.php", "child.php"):
        shutil.copy2(source / name, root / name)
    shutil.copytree(source / "src", root / "src")
    for name in ("hello", "poll"):
        shutil.copytree(source / "workflows" / name, root / "workflows" / name)
    # Focus this fixture on node 60, without the independent single-child demo.
    (root / "workflows/hello/55-child-poll.php").write_text("<?php\nreturn null;\n")
    (root / "workflows/hello/65-check-join.php").write_text(r'''<?php
$state = \NaiveWorkflow\Hello\State::requireState($state);
if (!isset($state->pollJoin)) { throw new RuntimeException('Join state missing'); }
file_put_contents(dirname(__DIR__, 2) . '/continued.json', json_encode($state->pollJoin));
''')
    env = dict(os.environ, HELLO_CHILD_POLL="1", HELLO_POLL="0", POLL_ONCE="0")
    original = (root / "workflows/hello/60-parallel-poll.php").read_text()

    for mode in ("success", "failure", "signal", "timeout"):
        # Only disposable fixture runtime data is removed.
        if (root / ".runs").exists():
            shutil.rmtree(root / ".runs")
        (root / "continued.json").unlink(missing_ok=True)
        (root / "workflows/hello/60-parallel-poll.php").write_text(original)
        if mode == "timeout":
            (root / "workflows/hello/60-parallel-poll.php").write_text(r'''<?php
require __DIR__ . '/ParallelPollNode.php';
return (new class extends \NaiveWorkflow\Hello\ParallelPollNode {
    protected function timeoutSeconds(): int { return 1; }
    protected function terminationGraceSeconds(): int { return 1; }
})->run($state);
''')
        # Both children must reach the barrier before either can finish.
        # A sequential implementation cannot pass this check.
        wait = r'''<?php
$runtime = $GLOBALS['argv'][2];
$slot = basename($runtime);
$pair = dirname($runtime);
file_put_contents($pair . '/' . $slot . '.ready', (string) getmypid());
$deadline = hrtime(true) + 3_000_000_000;
while (!is_file($pair . '/left.ready') || !is_file($pair . '/right.ready')) {
    if (hrtime(true) >= $deadline) { throw new RuntimeException('No concurrent sibling'); }
    usleep(10_000);
}
'''
        if mode == "success":
            wait += "sleep(5);\n"
        elif mode == "failure":
            wait += "if ($slot === 'left') { throw new RuntimeException('Injected parallel failure'); }\nsleep(20);\n"
        else:
            wait += "sleep(20);\n"
        (root / "workflows/poll/20-wait.php").write_text(wait)
        p = subprocess.Popen(
            ["php", str(root / "run.php"), "hello"], env=env,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True,
        )
        try:
            if mode == "signal":
                deadline = time.monotonic() + 8
                while len(list((root / ".runs/hello").glob("parallel-*/*.ready"))) < 2:
                    assert time.monotonic() < deadline and p.poll() is None, "Children did not start"
                    time.sleep(.02)
                p.send_signal(signal.SIGTERM)
            out, err = p.communicate(timeout=15)
            assert out == "", out
            pairs = list((root / ".runs/hello").glob("parallel-*"))
            assert len(pairs) == 1, pairs
            pair = pairs[0]
            pids = [int((pair / f"{slot}.ready").read_text()) for slot in ("left", "right")]
            assert len(set(pids + [p.pid])) == 3
            assert all(not Path(f"/proc/{pid}").exists() for pid in pids), (mode, pids)
            if mode == "success":
                assert p.returncode == 0, err
                joined = json.loads((root / ".runs/hello/last-poll-join.json").read_text())
                assert json.loads((root / "continued.json").read_text()) == joined
                for slot, pid in zip(("left", "right"), pids):
                    status = json.loads((pair / slot / "poll.json").read_text())
                    result = json.loads((pair / slot / "result.json").read_text())
                    assert status["status"] == "done" and status["pid"] == pid
                    assert joined[slot] == {"pid": pid, "time": result["state"]["time"]}
                    assert not (pair / slot / "poll.checkpoint.json").exists()
                assert not (root / ".runs/hello.checkpoint.json").exists()
                sys.stderr.write(err)
            else:
                assert p.returncode != 0, err
                assert not (root / "continued.json").exists()
                assert not (root / ".runs/hello/last-poll-join.json").exists()
                saved = json.loads((root / ".runs/hello.checkpoint.json").read_text())
                assert saved["node"] == "60-parallel-poll.php" and "poll_join" not in saved["state"]
                expected = {"failure": "failed with exit code 1",
                            "signal": "Child workflow interrupted",
                            "timeout": "timed out"}[mode]
                assert expected in err, err
            print(f"PASS: parallel poll {mode}, sibling cleanup and join semantics")
        finally:
            try:
                os.killpg(p.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            p.wait()
PYTEST
