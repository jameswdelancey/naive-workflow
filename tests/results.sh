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
declare(strict_types=1);
require __DIR__ . '/result.php';
$results = [
    workflow_result(),
    workflow_result('failed'),
    workflow_result('not ready', retry: 0),
    workflow_result('not ready', retry: 5),
    workflow_result(null, retry: 1),
    workflow_result('bad delay', retry: -1),
    workflow_result('bad delay', retry: PHP_INT_MAX),
    workflow_result(''),
];
foreach ($results as $result) {
    if (workflow_result_validate($result) !== null) { exit(1); }
}
echo json_encode($results), "\n";
''')
    result = subprocess.run(["php", str(root / "probe.php")],
                            text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and result.stderr == "", result
    values = json.loads(result.stdout)
    assert values[:4] == [
        None, {"error": "failed"},
        {"retry": 0, "error": "not ready"},
        {"retry": 5, "error": "not ready"},
    ]
    assert all(set(value) == {"error"} for value in values[4:])

    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)
    node = workflow / "10-work.php"
    command = ["php", str(root / "run.php"), "demo"]
    for expression in (
        "['error' => 'failed', 'typo' => true]",
        "['retry' => 0]",
        "['retry' => null, 'error' => 'failed']",
        "['retry' => -1, 'error' => 'failed']",
        "['error' => null]",
        "['error' => '']",
        "['erorr' => 'misspelled']",
        "'not a node result'",
        "workflow_result(null, retry: 0)",
    ):
        node.write_text("<?php\nreturn " + expression + ";\n")
        result = subprocess.run(command, text=True, capture_output=True, timeout=5)
        assert result.returncode == 1 and result.stdout == "", (expression, result)
        assert "demo: 10-work.php:" in result.stderr
        assert "Invalid " in result.stderr, result.stderr
        assert "Fatal error:" not in result.stderr
        saved = json.loads((root / ".runs/demo.checkpoint.json").read_text())
        assert saved["node"] == "10-work.php" and saved["state"] == []

    node.write_text(r'''<?php
if (!isset($state['attempt'])) {
    $state['attempt'] = 1;
    return workflow_result('try again', retry: 0);
}
return workflow_result();
''')
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and "retrying in 0s" in result.stderr, result.stderr
    assert not (root / ".runs/demo.checkpoint.json").exists()
    assert json.loads((root / ".runs/demo.json").read_text())["status"] == "done"

    (workflow / "exit.php").write_text(
        "<?php\nreturn workflow_result('handoff preparation failed');\n"
    )
    node.write_text("<?php\nreturn workflow_result();\n")
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1
    assert "demo: exit.php: handoff preparation failed" in result.stderr

for path in (source / "workflows").glob("*/*.php"):
    if path.name[0].isdigit():
        text = path.read_text()
        assert "return ['error' =>" not in text, path
        assert "return ['retry' =>" not in text, path
print("PASS: canonical node results, retry zero, invalid arguments/shapes, retained checkpoints and exit failures")
PYTEST
