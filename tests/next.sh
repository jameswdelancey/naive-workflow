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
require __DIR__ . '/next.php';
if (workflow_next(20) !== 'workflow_next requires an active runner node') { exit(1); }
$GLOBALS['workflowRouting'] = [
    'steps' => ['10-start.php', '020-work.php', 'exit.php'],
    'node' => '10-start.php',
];
if (workflow_next('00020') !== null || $GLOBALS['nextSequence'] !== '00020') { exit(2); }
foreach ([-1, 1.5, true, null, [], '', '1e2', 30] as $bad) {
    if (workflow_next($bad) === null || $GLOBALS['nextSequence'] !== '00020') { exit(3); }
}
$GLOBALS['workflowRouting']['steps'][] = '20-duplicate.php';
if (!str_contains(workflow_next(20) ?? '', 'exactly one')) { exit(4); }
if ($GLOBALS['nextSequence'] !== '00020') { exit(5); }
$GLOBALS['workflowRouting']['node'] = 'exit.php';
if (workflow_next(10) !== 'nextSequence cannot be set by exit.php') { exit(6); }
if ($GLOBALS['nextSequence'] !== '00020') { exit(7); }
''')
    probe = subprocess.run(["php", str(root / "probe.php")],
                           text=True, capture_output=True, timeout=5)
    assert probe.returncode == 0 and probe.stdout == probe.stderr == "", probe

    directory = root / "workflows/demo"
    directory.mkdir(parents=True)

    def node(name, body):
        (directory / name).write_text("<?php\n" + body + "\n")

    def run():
        return subprocess.run(["php", str(root / "run.php"), "demo"],
                              text=True, capture_output=True, timeout=5)

    node("10-start.php", r'''
$error = workflow_next('0030');
if ($error !== null) { return workflow_result($error); }
$state['continued_after_selection'] = true;
return workflow_result();
''')
    node("20-unused.php", "return workflow_result('Wrong branch');")
    node("30-finish.php", r'''
if (!($state['continued_after_selection'] ?? false)) {
    return workflow_result('Selection returned from the node too early');
}
return workflow_result();
''')
    node("exit.php", "return null;")
    result = run()
    assert result.returncode == 0, result.stderr

    node("10-start.php", "return workflow_result(workflow_next(999));")
    result = run()
    assert result.returncode == 1 and "exactly one" in result.stderr
    checkpoint = root / ".runs/demo.checkpoint.json"
    assert json.loads(checkpoint.read_text())["node"] == "10-start.php"

    node("10-start.php", r'''
$error = workflow_next(30);
if ($error !== null) { return workflow_result($error); }
$error = workflow_next(999);
if ($error === null) { return workflow_result('Invalid target accepted'); }
$state['continued_after_selection'] = true;
return workflow_result();
''')
    assert run().returncode == 0

    # A retry discards its branch, then the next attempt falls through.
    node("10-start.php", r'''
if (!isset($state['retried'])) {
    $state['retried'] = true;
    $error = workflow_next(30);
    return workflow_result($error ?? 'retry branch ignored', retry: 0);
}
return workflow_result();
''')
    node("20-unused.php", "$state['fell_through'] = true; return workflow_result();")
    node("30-finish.php", r'''
if (!($state['fell_through'] ?? false)) {
    return workflow_result('Retry selection leaked to the next attempt');
}
return workflow_result();
''')
    result = run()
    assert result.returncode == 0 and "retrying in 0s" in result.stderr, result.stderr

    node("exit.php", "return workflow_result(workflow_next(10));")
    result = run()
    assert result.returncode == 1 and "nextSequence cannot be set by exit.php" in result.stderr
    assert json.loads(checkpoint.read_text())["node"] == "exit.php"

for path in (source / "workflows").glob("*/*.php"):
    text = path.read_text()
    assert "$GLOBALS['nextSequence'] =" not in text, path
    assert "global $nextSequence" not in text, path
print("PASS: checked selection, preserved prior target, leading zeroes, ambiguity, resume, retry reset and exit rejection")
PYTEST
