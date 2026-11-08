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
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    for name in ("run.php", "log.php", "state.php", "result.php", "next.php", "reduce.php"):
        shutil.copy2(source / name, root / name)
    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)
    (root / "probe.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/result.php';
if (workflow_exit() !== null) { exit(1); }
$command = workflow_exit('demo');
if ($command !== [PHP_BINARY, __DIR__ . '/run.php', 'demo']) { exit(2); }
foreach (['', '../demo', '/demo', 'missing', "demo\0"] as $name) {
    $result = workflow_exit($name);
    if (!is_string($result['error'] ?? null) ||
        workflow_result_validate($result, true) !== null) { exit(3); }
}
foreach ([[], ['bad'=>'argv'], ['php'], ['/'], [PHP_BINARY, 1],
          [PHP_BINARY, "bad\0argument"]] as $argv) {
    if (workflow_exit_validate($argv) === null) { exit(4); }
}
$GLOBALS['jobDir'] = __DIR__;
$result = workflow_exit('demo');
if (!str_contains($result['error'] ?? '', 'cannot hand off')) { exit(5); }
if (workflow_exit() !== null) { exit(6); }
''')
    result = subprocess.run(["php", str(root / "probe.php")],
                            text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and result.stdout == result.stderr == "", result

    node = workflow / "exit.php"
    node.write_text("<?php\nreturn workflow_exit('missing');\n")
    command = ["php", str(root / "run.php"), "demo"]
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and "demo: exit.php: Invalid exit workflow" in result.stderr
    checkpoint = root / ".runs/demo.checkpoint.json"
    assert json.loads(checkpoint.read_text())["node"] == "exit.php"
    node.write_text("<?php\nreturn workflow_exit();\n")
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and not checkpoint.exists(), result.stderr

    # A real finite handoff checks same PID and completion/unlock ordering.
    target = root / "workflows/target"
    target.mkdir()
    (target / "10-check.php").write_text(r'''<?php
$path = dirname(__DIR__, 2);
$saved = json_decode(file_get_contents("$path/.runs/demo.json"), true);
if ($saved['status'] !== 'done' || $saved['pid'] !== getmypid() ||
    is_file("$path/.runs/demo.checkpoint.json")) {
    return workflow_result('Handoff preceded completion');
}
$handle = fopen("$path/.runs/demo.lock", 'c');
if (!flock($handle, LOCK_EX | LOCK_NB)) {
    fclose($handle);
    return workflow_result('Handoff retained caller lock');
}
flock($handle, LOCK_UN);
fclose($handle);
return workflow_result();
''')
    (target / "exit.php").write_text("<?php\nreturn workflow_exit();\n")
    node.write_text("<?php\nreturn workflow_exit('target');\n")
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and result.stdout == "", result.stderr
    caller = json.loads((root / ".runs/demo.json").read_text())
    callee = json.loads((root / ".runs/target.json").read_text())
    assert caller["pid"] == callee["pid"]
    assert caller["status"] == callee["status"] == "done"

    # Constructor errors are reported before completion, including job mode.
    job = root / "job"
    job.mkdir()
    (job / "input.json").write_text("[]")
    result = subprocess.run([*command, "--job-dir", str(job)],
                            text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and "cannot hand off" in result.stderr
    assert (job / "runtime/demo.checkpoint.json").exists()
    assert not (job / "output.json").exists()

print("PASS: checked exit construction, invalid targets/argv, stop/resume, finite handoff ordering and job rejection")
PYTEST
