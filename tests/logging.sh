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
import signal
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
production = [source / name for name in (
    "run.php", "status.php", "log.php", "state.php", "parallel.php", "child-poll.php"
)]
production += list((source / "workflows").rglob("*.php"))
writers = [
    path.relative_to(source).as_posix() for path in production
    if re.search(r"fwrite\s*\(\s*STDERR", path.read_text())
]
assert writers == ["log.php"], writers

with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    for name in ("run.php", "status.php", "log.php", "state.php", "parallel.php", "child-poll.php"):
        shutil.copy2(source / name, root / name)
    shutil.copytree(source / "workflows/poll", root / "workflows/poll")
    common = root / "workflows/mes-observe/common.php"
    common.parent.mkdir()
    shutil.copy2(source / "workflows/mes-observe/common.php", common)
    (root / "probe.php").write_text(r'''<?php
require __DIR__ . '/log.php';
require __DIR__ . '/workflows/mes-observe/common.php';
workflow_log_context("demo\nscope", "first\rsecond", 'saved');
workflow_log_context('mes-observe', "database\nmessage");
$state = [];
require __DIR__ . '/workflows/poll/10-tick.php';
echo json_encode($state), "\n";
''')
    result = subprocess.run(
        ["php", "-d", "date.timezone=Asia/Kolkata", str(root / "probe.php")],
        text=True, capture_output=True, timeout=5,
    )
    assert result.returncode == 0, result.stderr
    lines = result.stderr.splitlines()
    state = json.loads(result.stdout)
    assert len(lines) == 3, lines
    assert lines[0] == r"[saved] demo\nscope: first\rsecond"
    assert re.fullmatch(
        r"\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30\] mes-observe: database\\nmessage",
        lines[1],
    ), lines[1]
    assert lines[2] == f'[{state["time"]}] poll: polling'

    (root / ".runs").mkdir()
    (root / ".runs/poll.json").write_text("{")
    result = subprocess.run(
        ["php", str(root / "status.php"), "--json"],
        text=True, capture_output=True, timeout=5,
    )
    assert result.returncode == 1
    rows = json.loads(result.stdout)
    assert next(row for row in rows if row["workflow"] == "poll")["status"] == "unknown"
    assert len(result.stderr.splitlines()) == 1
    assert "status: poll:" in result.stderr

    workflow = root / "workflows/controller"
    workflow.mkdir()
    (workflow / "10-map.php").write_text(r'''<?php
require_once dirname(__DIR__, 2) . '/parallel.php';
$jobs = [
    ['workflow' => 'poll', 'state' => ['item' => 1], 'env' => ['POLL_ONCE' => '1']],
    ['workflow' => 'poll', 'state' => ['item' => 2], 'env' => ['POLL_ONCE' => '1']],
];
$error = workflow_parallel($jobs, 2, 5, $results);
return $error === null ? null : ['error' => $error];
''')
    (root / "workflows/poll/20-wait.php").write_text(r'''<?php
if ($state['item'] === 1) {
    usleep(300000);
    return ['error' => 'Injected messaging failure'];
}
sleep(10);
''')
    process = subprocess.Popen(
        ["php", str(root / "run.php"), "controller"],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        start_new_session=True,
    )
    try:
        out, err = process.communicate(timeout=10)
        assert process.returncode == 1 and out == "", (process.returncode, out, err)
        assert err.count("Injected messaging failure") == 1, err
        assert err.count("failed with exit code 1") == 1, err
        assert err.count("Starting child workflow poll") == 2, err
        assert "completed." not in err, err
        for path in (root / ".runs/controller/parallel").glob("batch-*/job-*/runtime/poll.json"):
            pid = json.loads(path.read_text())["pid"]
            assert not Path(f"/proc/{pid}").exists()
    finally:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()

print("PASS: single diagnostic writer, shared contexts, event timestamps, clean JSON and nonduplicated failure reports")
PYTEST
