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
    child = root / "workflows/echo"
    parent.mkdir(parents=True)
    child.mkdir()
    (parent / "10-map.php").write_text(r'''<?php
require_once dirname(__DIR__, 2) . '/map.php';
$state['kept'] = 'parent';
if (getenv('TEST_COLLISION') === '1') {
    $state['mapped'] = ['old'];
}
$before = $state;
$error = workflow_map_into(
    state: $state,
    workflow: 'echo',
    items: getenv('TEST_ITEMS'),
    concurrency: getenv('TEST_CONCURRENCY'),
    timeout: 5,
    environment: ['CHILD_MODE' => getenv('TEST_CHILD_MODE') ?: 'ok'],
    inputField: 'payload',
    resultsField: 'mapped',
    completedField: 'map_done'
);
if ($error !== null) {
    if ($state !== $before) {
        return workflow_result('Failed map mutated parent state');
    }
    return workflow_result($error);
}
return workflow_result();
''')
    (parent / "20-receipt.php").write_text(
        "<?php\nreturn workflow_result(workflow_write(dirname(__DIR__, 2) . '/receipt.json', $state));\n"
    )
    (child / "10-echo.php").write_text(r'''<?php
if (getenv('CHILD_MODE') === 'fail') {
    return workflow_result('Injected worker failure');
}
return workflow_result();
''')
    env = dict(os.environ, TEST_CONCURRENCY="2", TEST_CHILD_MODE="ok")
    command = ["php", str(root / "run.php"), "parent"]
    for items in ("[]", "[1]", '[null,false,{"key":"value"},[1,2]]'):
        env["TEST_ITEMS"] = items
        result = subprocess.run(command, env=env, text=True, capture_output=True, timeout=10)
        assert result.returncode == 0, result.stderr
        receipt = json.loads((root / "receipt.json").read_text())
        assert receipt["kept"] == "parent" and receipt["map_done"] is True
        assert [row["payload"] for row in receipt["mapped"]] == json.loads(items)
        assert all("kept" not in row for row in receipt["mapped"])

    (root / "receipt.json").unlink()
    for items, concurrency, collision, mode, expected in [
        ("{}", "2", "0", "ok", "Map items must be a JSON list"),
        ("[", "2", "0", "ok", "Map items must be a JSON list"),
        ("[1]", "0", "0", "ok", "Map concurrency"),
        ("[1]", "02", "0", "ok", "Map concurrency"),
        ("[1]", "33", "0", "ok", "Map concurrency"),
        ("[1]", "2", "1", "ok", "already exists"),
        ("[1]", "2", "0", "fail", "failed with exit code 1"),
    ]:
        env.update(TEST_ITEMS=items, TEST_CONCURRENCY=concurrency,
                   TEST_COLLISION=collision, TEST_CHILD_MODE=mode)
        result = subprocess.run(command, env=env, text=True, capture_output=True, timeout=10)
        assert result.returncode == 1 and expected in result.stderr, result.stderr
        assert "Failed map mutated parent state" not in result.stderr
        assert not (root / "receipt.json").exists()
        checkpoint = json.loads((root / ".runs/parent.checkpoint.json").read_text())
        assert checkpoint == {"node": "10-map.php", "state": []}

assert "getenv(" not in (source / "map.php").read_text()
node = (source / "workflows/hello/55-child-poll.php").read_text()
assert "json_decode(" not in node and "workflow_state_set(" not in node
assert "workflow: 'poll'" in node and "timeout: 30" in node
print("PASS: reusable item mapping, JSON configuration, explicit input/output fields, publication safety and failure barrier")
PYTEST
