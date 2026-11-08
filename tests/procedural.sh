#!/usr/bin/env bash
set -euo pipefail
unset WORKFLOW_LOG_LEVEL WORKFLOW_LOG_FORMAT
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
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    shutil.copy2(source / "run.php", root / "run.php")
    shutil.copy2(source / "log.php", root / "log.php")
    shutil.copy2(source / "state.php", root / "state.php")
    shutil.copy2(source / "result.php", root / "result.php")
    shutil.copy2(source / "next.php", root / "next.php")
    shutil.copy2(source / "reduce.php", root / "reduce.php")
    workflow = root / "workflows/check"
    workflow.mkdir(parents=True)
    env = dict(os.environ)

    def node(name, text):
        (workflow / name).write_text("<?php\n" + text + "\n")

    def run():
        return subprocess.run(
            ["php", str(root / "run.php"), "check"],
            capture_output=True, text=True, env=env, timeout=5,
        )

    def saved():
        return json.loads((root / ".runs/check.checkpoint.json").read_text())

    node("10-seed.php", "$state['value'] = 'input'; return null;")
    node("20-work.php", "$state['value'] = 'discard'; return ['error' => 'explicit failure'];")
    result = run()
    assert result.returncode == 1 and not result.stdout, result
    assert "explicit failure" in result.stderr
    assert saved() == {"node": "20-work.php", "state": {"value": "input"}}

    node("20-work.php", """
if ($state['value'] !== 'input') {
    return ['error' => 'Input state was not restored'];
}
return null;
""")
    assert run().returncode == 0
    assert not (root / ".runs/check.checkpoint.json").exists()

    node("20-work.php", """
$state['attempts'] = ($state['attempts'] ?? 0) + 1;
if ($state['attempts'] === 1) {
    $GLOBALS['nextSequence'] = 999;
    return ['retry' => 10, 'error' => 'persisted wait'];
}
if ($state['attempts'] !== 2) {
    return ['error' => 'Retry state was not restored'];
}
return null;
""")
    process = subprocess.Popen(
        ["php", str(root / "run.php"), "check"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env,
    )
    try:
        deadline = time.monotonic() + 3
        while time.monotonic() < deadline:
            path = root / ".runs/check.json"
            if path.exists():
                status = json.loads(path.read_text())
                if status["status"] == "retrying":
                    break
            time.sleep(0.02)
        else:
            raise AssertionError("Retry wait was not observed")

        checkpoint = saved()
        assert checkpoint["state"]["attempts"] == 1
        assert checkpoint["retry_at"] > time.time()

        contender = run()
        assert contender.returncode == 1 and "acquire lock" in contender.stderr
        assert saved() == checkpoint

        process.send_signal(signal.SIGTERM)
        process.wait(timeout=3)
        assert saved() == checkpoint

        # Keep the real resume path but use a short synthetic saved deadline.
        checkpoint["retry_at"] = int(time.time()) + 2
        (root / ".runs/check.checkpoint.json").write_text(json.dumps(checkpoint))
        before = time.time()
        result = run()
        assert result.returncode == 0 and not result.stdout, result
        assert time.time() >= checkpoint["retry_at"]
        assert time.time() - before >= 0.5
        assert not (root / ".runs/check.checkpoint.json").exists()
    finally:
        if process.poll() is None:
            process.kill()
        process.wait()

    node("20-work.php", "return ['retry' => -1, 'error' => 'bad delay'];")
    result = run()
    assert result.returncode == 1 and "Invalid retry result" in result.stderr
    assert saved()["node"] == "20-work.php"
    assert saved()["state"] == {"value": "input"}

print("PASS: explicit errors, input restoration, retry state/deadline resume, ignored retry branch and lock contention")
PYTEST
