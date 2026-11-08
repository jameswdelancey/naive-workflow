#!/usr/bin/env bash
set -euo pipefail
unset WORKFLOW_LOG_LEVEL WORKFLOW_LOG_FORMAT
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    for name in ("run.php", "log.php", "state.php", "result.php", "next.php"):
        shutil.copy2(source / name, root / name)
    (root / "probe.php").write_text(r'''<?php
require __DIR__ . '/state.php';
$state = ['name' => 'kept', 'nested' => ['nullable' => null], 'count' => 1];
$before = $state;
$checks = [
    workflow_state_set($state, 'naem', 'bad'),
    workflow_state_set($state, 'name', 'bad', 'add'),
    workflow_state_set($state, 'count', 'bad'),
    workflow_state_set($state, ['missing', 'child'], 2, 'add'),
    workflow_state_remove($state, 'missing'),
    workflow_state_set($state, 'object', (object) [], 'add'),
];
foreach ($checks as $error) {
    if (!is_string($error) || $error === '') { exit(1); }
}
if ($state !== $before) { exit(2); }
$error = workflow_state_get($state, ['nested', 'nullable'], $value, '?string');
if ($error !== null || $value !== null) { exit(3); }
if (workflow_state_set($state, 'count', 2) !== null) { exit(4); }
if (workflow_state_set($state, 'new', true, 'add') !== null) { exit(5); }
if (workflow_state_remove($state, ['nested', 'nullable']) !== null) { exit(6); }
$error = workflow_state_get($state, ['nested', 'nullable'], $value);
if (!str_contains($error ?? '', 'is missing')) { exit(7); }
$error = workflow_state_validate(['naem' => 'bad'], ['name' => 'string']);
if (!str_contains($error ?? '', 'not declared')) { exit(8); }
$recursive = [];
$recursive['self'] =& $recursive;
if (workflow_state_value($recursive) === null) { exit(9); }
echo json_encode($checks), "\n";
''')
    result = subprocess.run(["php", str(root / "probe.php")],
                            text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and result.stderr == "", result
    errors = json.loads(result.stdout)
    assert 'state["naem"]' in errors[0] and "use add" in errors[0]
    assert "cannot change type from int to string" in errors[2]

    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)
    (workflow / "fields.php").write_text("<?php\nreturn ['name' => 'string'];\n")
    node = workflow / "10-work.php"
    node.write_text("<?php\n$state['naem'] = 'typo';\n")
    command = ["php", str(root / "run.php"), "demo"]
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and result.stdout == ""
    assert 'state["naem"]' in result.stderr and "10-work.php" in result.stderr
    assert "Warning:" not in result.stderr and "Fatal error:" not in result.stderr
    checkpoint = root / ".runs/demo.checkpoint.json"
    assert json.loads(checkpoint.read_text())["state"] == []

    node.write_text(
        "<?php\n$error = workflow_state_get($state, 'name', $name, 'string');\n"
        "return $error === null ? null : ['error' => $error];\n"
    )
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and 'state["name"] is missing' in result.stderr
    assert 'demo: 10-work.php: state["name"] is missing' in result.stderr
    assert json.loads(checkpoint.read_text())["state"] == []

    checkpoint.write_text(json.dumps({"node": "10-work.php", "state": {"name": 123}}))
    original = checkpoint.read_bytes()
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and "must be string; got int" in result.stderr
    assert checkpoint.read_bytes() == original

    checkpoint.unlink()
    node.write_text("<?php\n$state = 'not an array';\n")
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and "must remain an array" in result.stderr
    assert "Fatal error:" not in result.stderr
print("PASS: guarded add/replace/remove, typed reads, failed-write safety, field validation and readable failures")
PYTEST
