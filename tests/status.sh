#!/usr/bin/env bash
set -euo pipefail
unset WORKFLOW_LOG_LEVEL WORKFLOW_LOG_FORMAT
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

python3 - "$root" <<'PY'
import json
from datetime import datetime
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    shutil.copy2(Path(sys.argv[1]) / "status.php", root / "status.php")
    shutil.copy2(Path(sys.argv[1]) / "log.php", root / "log.php")
    shutil.copy2(Path(sys.argv[1]) / "state.php", root / "state.php")
    (root / "workflows/demo").mkdir(parents=True)
    (root / "workflows/invalid name").mkdir()
    (root / ".runs").mkdir()
    status_file = root / ".runs/demo.json"

    def run(*args):
        return subprocess.run(
            ["php", str(root / "status.php"), *args],
            text=True, capture_output=True, timeout=5, check=False
        )

    def save(value):
        status_file.write_text(json.dumps(value))

    result = run("--json")
    assert result.returncode == 0 and not result.stderr
    assert json.loads(result.stdout) == [{"workflow": "demo", "status": "idle", "updated_at": None}]

    saved = {
        "pid": os.getpid(), "status": "retrying", "node": "15-retry.php",
        "started": "2026-11-04T12:00:00-08:00",
        "retry_at": "2026-11-04T12:00:05-08:00",
        "retry_after_seconds": 5, "retry_error": "Not ready"
    }
    save(saved)
    result = run("--json")
    assert result.returncode == 0 and not result.stderr
    row = json.loads(result.stdout)[0]
    assert row["status"] == "retrying" and row["retry_at"] == saved["retry_at"]
    result = run()
    assert result.returncode == 0 and not result.stderr
    assert "RETRY AT" in result.stdout and saved["retry_at"] in result.stdout
    assert "UPDATED AT" in result.stdout and row["updated_at"] in result.stdout

    # A fixed file mtime must be reported, not the current time or a JSON override.
    fixed_mtime = 1700000000
    save(dict(saved, updated_at="not authoritative"))
    os.utime(status_file, (fixed_mtime, fixed_mtime))
    result = run("--json")
    assert result.returncode == 0 and not result.stderr
    timestamp = json.loads(result.stdout)[0]["updated_at"]
    assert int(datetime.fromisoformat(timestamp).timestamp()) == fixed_mtime
    result = run()
    assert timestamp in result.stdout

    for state in ("running", "retrying"):
        save(dict(saved, status=state, pid=2147483647))
        result = run("--json")
        assert result.returncode == 0
        assert json.loads(result.stdout)[0]["status"] == "stopped"

    for state in ("done", "failed"):
        save({"pid": 2147483647, "status": state, "node": None})
        result = run("--json")
        assert result.returncode == 0
        assert json.loads(result.stdout)[0]["status"] == state

    save(dict(saved, node="bad\x1b[31m\nnode"))
    result = run()
    assert result.returncode == 0 and "\x1b" not in result.stdout
    assert len(result.stdout.splitlines()) == 2

    for invalid in (
        "{", "null", "[]",
        json.dumps(dict(saved, pid="../self")),
        json.dumps(dict(saved, node=[])),
        json.dumps(dict(saved, retry_at={})),
        json.dumps(dict(saved, status="unexpected")),
    ):
        status_file.write_text(invalid)
        result = run("--json")
        assert result.returncode == 1
        assert json.loads(result.stdout)[0]["status"] == "unknown"
        assert re.match(
            r"^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\] status: demo:",
            result.stderr
        )
        assert "Warning:" not in result.stderr and "Fatal error:" not in result.stderr

print("PASS: status states, retry deadline, stdout JSON, validation and diagnostics")
PY
