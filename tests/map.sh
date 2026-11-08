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
    for name in ("run.php", "log.php", "state.php", "result.php", "next.php", "reduce.php", "parallel.php", "map.php"):
        shutil.copy2(source / name, root / name)
    parent = root / "workflows/parent"
    child = root / "workflows/double"
    parent.mkdir(parents=True)
    child.mkdir()
    (parent / "10-map.php").write_text(r'''<?php
require_once dirname(__DIR__, 2) . '/map.php';
$inputs = json_decode(getenv('TEST_INPUTS'), true);
$error = workflow_map('double', $inputs, 2, 5, $results, ['MAP_FACTOR' => '2']);
if ($error !== null) { return workflow_result($error); }
return workflow_result(workflow_state_set($state, 'mapped', $results, 'add'));
''')
    (parent / "20-reduce.php").write_text(r'''<?php
$error = workflow_state_get($state, 'mapped', $mapped, 'array');
if ($error !== null) { return workflow_result($error); }
$sum = 0;
foreach ($mapped as $output) { $sum += $output['result']; }
return workflow_result(workflow_write(dirname(__DIR__, 2) . '/receipt.json', [
    'outputs' => $mapped, 'sum' => $sum,
]));
''')
    (child / "10-work.php").write_text(r'''<?php
$error = workflow_state_get($state, 'value', $value, 'int');
if ($error !== null) { return workflow_result($error); }
if ($value < 0) { return workflow_result('Rejected map input'); }
return workflow_result(workflow_state_set(
    $state, 'result', $value * (int) getenv('MAP_FACTOR'), 'add'
));
''')
    for inputs, expected in [([], 0), ([{'value': 3}], 6),
                             ([{'value': 3}, {'value': 1}], 8)]:
        env = dict(os.environ, TEST_INPUTS=json.dumps(inputs))
        result = subprocess.run(["php", str(root / "run.php"), "parent"],
                                env=env, text=True, capture_output=True, timeout=10)
        assert result.returncode == 0 and result.stdout == "", result.stderr
        receipt = json.loads((root / "receipt.json").read_text())
        assert receipt["sum"] == expected
        assert [row["value"] for row in receipt["outputs"]] == [row["value"] for row in inputs]

    (root / "receipt.json").unlink()
    for inputs, message in [
        ([{"value": -1}], "Rejected map input"),
        ([3], "Map input 0 must be a child state array"),
        ({"not": "list"}, "Map inputs must be an ordered list"),
    ]:
        env = dict(os.environ, TEST_INPUTS=json.dumps(inputs))
        result = subprocess.run(["php", str(root / "run.php"), "parent"],
                                env=env, text=True, capture_output=True, timeout=10)
        assert result.returncode == 1, result.stderr
        if "Rejected" in message:
            assert "failed with exit code 1" in result.stderr
        else:
            assert message in result.stderr
        assert not (root / "receipt.json").exists()
        saved = json.loads((root / ".runs/parent.checkpoint.json").read_text())
        assert saved["node"] == "10-map.php" and saved["state"] == []

assert "HELLO_CHILD" not in (source / "map.php").read_text()
assert not (source / "child-poll.php").exists()
node = (source / "workflows/hello/55-child-poll.php").read_text()
assert "workflow: 'poll'," in node and "timeout: 30," in node
assert "workflow_map_into(" in node and "POLL_ONCE" in node
print("PASS: explicit workflow map API, separate inputs/outputs, singleton/empty maps, ordered reduction and failure barrier")
PYTEST

python3 - "$root" <<'PYNAME'
from pathlib import Path
import sys
root = Path(sys.argv[1])
worker = root / "workflows/poll/30-square.php"
assert worker.is_file()
assert not (root / "workflows/poll/30-map.php").exists()
text = worker.read_text()
assert "workflow_map(" not in text and "workflow_map_into(" not in text
print("PASS: square worker is distinct from parent map orchestration")
PYNAME
