#!/usr/bin/env bash
set -euo pipefail
unset WORKFLOW_LOG_LEVEL WORKFLOW_LOG_FORMAT
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

python3 - "$root" <<'PYTEST'
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    shutil.copy2(source / "run.php", root / "run.php")
    shutil.copy2(source / "log.php", root / "log.php")
    shutil.copy2(source / "state.php", root / "state.php")
    shutil.copy2(source / "result.php", root / "result.php")
    shutil.copy2(source / "next.php", root / "next.php")
    shutil.copy2(source / "reduce.php", root / "reduce.php")
    shutil.copytree(source / "workflows/hello", root / "workflows/hello")
    shutil.copytree(source / "workflows/poll", root / "workflows/poll")

    # Keep poll's real tick and wait nodes; stop after one iteration in this
    # isolated fixture instead of running its production self-chain forever.
    (root / "workflows/poll/exit.php").write_text("<?php\nreturn null;\n")

    env = dict(os.environ, USER="chain-test", HELLO_APPROVED="1", HELLO_POLL="1", HELLO_CHILD_POLL="0")
    result = subprocess.run(
        ["php", str(root / "run.php"), "hello"],
        env=env, capture_output=True, text=True, timeout=20,
    )
    sys.stderr.write(result.stderr)
    assert result.returncode == 0, (result.returncode, result.stdout)
    assert result.stdout == "", result.stdout

    messages = result.stderr.splitlines()
    assert messages and all(re.match(
        r"^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\] ", line
    ) for line in messages)
    assert result.stderr.count("Handing off to poll.") == 1
    assert result.stderr.count(" polling\n") == 1
    assert result.stderr.index("Finished for chain-test: approved.") < result.stderr.index(
        "Handing off to poll."
    ) < result.stderr.index(" polling\n")

    statuses = []
    for name in ("hello", "poll"):
        saved = json.loads((root / f".runs/{name}.json").read_text())
        assert saved["status"] == "done" and saved["node"] == "exit.php", saved
        assert not (root / f".runs/{name}.checkpoint.json").exists()
        statuses.append(saved)

    # pcntl_exec replaces the process rather than creating a child runner.
    assert statuses[0]["pid"] == statuses[1]["pid"], statuses

    # The same fixture must stop after hello when handoff is disabled.
    env["HELLO_POLL"] = "0"
    result = subprocess.run(
        ["php", str(root / "run.php"), "hello"],
        env=env, capture_output=True, text=True, timeout=10,
    )
    assert result.returncode == 0 and result.stdout == ""
    assert "Handing off" not in result.stderr and " polling\n" not in result.stderr
    assert json.loads((root / ".runs/poll.json").read_text()) == statuses[1]

print("PASS: opt-in hello-to-poll handoff, same PID, stderr, completion and default stop")
PYTEST
