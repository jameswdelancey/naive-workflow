#!/usr/bin/env bash
set -euo pipefail
unset WORKFLOW_LOG_LEVEL WORKFLOW_LOG_FORMAT
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
env = dict(os.environ)
for key in ("WORKFLOW_LOG_LEVEL", "WORKFLOW_LOG_FORMAT"):
    env.pop(key, None)

with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    for name in ("run.php", "status.php", "log.php", "state.php", "result.php", "next.php", "reduce.php", "parallel.php"):
        shutil.copy2(source / name, root / name)
    shutil.copytree(source / "workflows/poll", root / "workflows/poll")
    (root / "workflows/poll/20-wait.php").write_text("<?php\nusleep(100000);\n")
    controller = root / "workflows/controller"
    controller.mkdir()
    (controller / "10-map.php").write_text(r'''<?php
require_once dirname(__DIR__, 2) . '/parallel.php';
$jobs = [
    ['workflow' => 'poll', 'state' => ['item' => 1], 'env' => ['POLL_ONCE' => '1']],
    ['workflow' => 'poll', 'state' => ['item' => 2], 'env' => ['POLL_ONCE' => '1']],
];
$error = workflow_parallel($jobs, 2, 5, $results);
return $error === null ? null : ['error' => $error];
''')
    (root / "probe.php").write_text(r'''<?php
require __DIR__ . '/log.php';
workflow_log_context('probe', "line\nbreak", 'saved', 'info');
workflow_log_context('probe', 'retry', null, 'warning');
workflow_log_context('probe', 'failure', null, 'error');
workflow_log_context('probe', "bad\xffutf8", null, 'error');
echo "data\n";
''')

    def run(script, *args, options=None):
        current = dict(env)
        current.update(options or {})
        return subprocess.run(["php", str(root / script), *args],
                              env=current, text=True, errors='replace', capture_output=True, timeout=10)

    result = run("probe.php", options={"WORKFLOW_LOG_LEVEL": "error"})
    assert result.returncode == 0 and result.stdout == "data\n"
    assert len(result.stderr.splitlines()) == 2
    assert "retry" not in result.stderr and "line" not in result.stderr

    result = run("probe.php", options={"WORKFLOW_LOG_FORMAT": "json"})
    rows = [json.loads(line) for line in result.stderr.splitlines()]
    assert len(rows) == 4 and rows[0]["message"] == "line\nbreak"
    assert rows[0]["timestamp"] == "saved" and rows[0]["level"] == "info"
    assert all(isinstance(row["pid"], int) for row in rows)
    assert "\ufffd" in rows[-1]["message"]

    result = run("run.php", "controller", options={"WORKFLOW_LOG_FORMAT": "json"})
    assert result.returncode == 0 and result.stdout == "", result.stderr
    rows = [json.loads(line) for line in result.stderr.splitlines()]
    ticks = [row for row in rows if row["message"] == "polling"]
    assert len(ticks) == 2
    assert {row["job"] for row in ticks} == {"job-0", "job-1"}
    assert len({row["pid"] for row in ticks}) == 2
    assert len({row["batch"] for row in ticks}) == 1
    assert all(row["workflow"] == "poll" and row["node"] == "10-tick.php" for row in ticks)
    assert all(row["job"] is None for row in rows if row["workflow"] == "controller")

    result = run("run.php", "controller")
    assert result.returncode == 0
    ticks = [line for line in result.stderr.splitlines() if line.endswith("poll: polling")]
    assert len(ticks) == 2 and all("pid=" in line and "batch=" in line for line in ticks)

    result = run("run.php", "controller", options={"WORKFLOW_LOG_LEVEL": "error"})
    assert result.returncode == 0 and result.stderr == "", result.stderr

    bad = root / "workflows/bad"
    bad.mkdir()
    (bad / "10-fail.php").write_text("<?php\nreturn ['error' => 'Explicit failure'];\n")
    result = run("run.php", "bad", options={
        "WORKFLOW_LOG_LEVEL": "error", "WORKFLOW_LOG_FORMAT": "json",
    })
    rows = [json.loads(line) for line in result.stderr.splitlines()]
    assert result.returncode == 1 and len(rows) == 1
    assert rows[0]["level"] == "error" and rows[0]["node"] == "10-fail.php"

    job = root / "invalid-job"
    job.mkdir()
    (job / "input.json").write_text("{")
    result = run("run.php", "poll", "--job-dir", str(job), options={
        "WORKFLOW_LOG_LEVEL": "error", "WORKFLOW_LOG_FORMAT": "json",
    })
    row = json.loads(result.stderr)
    assert result.returncode == 1
    assert "decode job input" in row["message"] and str(job / "input.json") in row["message"]

    (root / ".runs/poll.json").write_text("{")
    result = run("status.php", "--json", options={
        "WORKFLOW_LOG_LEVEL": "error", "WORKFLOW_LOG_FORMAT": "json",
    })
    assert result.returncode == 1
    assert isinstance(json.loads(result.stdout), list)
    row = json.loads(result.stderr)
    assert row["level"] == "error" and row["context"] == "status: poll"

for path in [source / "status.php", *list((source / "workflows").rglob("*.php"))]:
    assert "mes_log(" not in path.read_text() and "status_diagnose(" not in path.read_text(), path
print("PASS: job/PID identity, levels, JSON diagnostics, UTF-8 safety, contextual errors and removed wrappers")
PYTEST
