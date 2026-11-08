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
    for name in ("run.php", "log.php", "state.php", "result.php", "next.php", "reduce.php"):
        shutil.copy2(source / name, root / name)
    (root / "probe.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/reduce.php';

function test_reduce(array &$state, ?callable $reducer = null, mixed $initial = 0): ?string
{
    return workflow_reduce_into(
        $state, 'mapped', 'done', 'total', $reducer ?? 'workflow_reduce_sum', $initial
    );
}
function collect_item(mixed $accumulator, mixed $item, int $index, mixed &$next): ?string
{
    $next = $accumulator;
    $next[] = $item['label'];
    return null;
}
function bad_result(mixed $accumulator, mixed $item, int $index, mixed &$next): mixed
{
    return false;
}
function invalid_accumulator(mixed $accumulator, mixed $item, int $index, mixed &$next): ?string
{
    $next = fopen('php://memory', 'r+');
    return null;
}
$skipped = ['kept' => 'unchanged'];
$before = $skipped;
if (test_reduce($skipped) !== null || $skipped !== $before) { exit(1); }

foreach ([[], [['result' => 3]], [['result' => 3], ['result' => -2], ['result' => 4]]] as $items) {
    $state = ['mapped' => $items, 'done' => true, 'kept' => 'parent'];
    if (test_reduce($state) !== null ||
        $state['total'] !== array_sum(array_column($items, 'result')) ||
        $state['kept'] !== 'parent') { exit(2); }
}
$state = ['mapped' => [['label' => 'second'], ['label' => 'first']], 'done' => true];
if (test_reduce($state, 'collect_item', []) !== null ||
    $state['total'] !== ['second', 'first']) { exit(3); }

$cases = [
    ['mapped' => [], 'done' => false],
    ['mapped' => []],
    ['done' => true],
    ['mapped' => [], 'done' => 'yes'],
    ['mapped' => ['not-a-list' => []], 'done' => true],
    ['mapped' => [['result' => '3']], 'done' => true],
    ['mapped' => [null], 'done' => true],
    ['mapped' => [], 'done' => true, 'total' => 1],
    ['mapped' => [['result' => PHP_INT_MAX], ['result' => 1]], 'done' => true],
    ['mapped' => [['result' => PHP_INT_MIN], ['result' => -1]], 'done' => true],
];
foreach ($cases as $state) {
    $before = $state;
    $error = test_reduce($state);
    if (!is_string($error) || $error === '' || $state !== $before) { exit(4); }
}
foreach (['bad_result', 'invalid_accumulator'] as $reducer) {
    $state = ['mapped' => [['result' => 1]], 'done' => true];
    $before = $state;
    $error = test_reduce($state, $reducer);
    if (!is_string($error) || $state !== $before) { exit(5); }
}
$state = ['mapped' => [], 'done' => true];
$error = workflow_reduce_into($state, 'mapped', 'done', 'mapped', 'workflow_reduce_sum', 0);
if ($error === null) { exit(6); }
echo "ok\n";
''')
    result = subprocess.run(["php", str(root / "probe.php")],
                            text=True, capture_output=True, timeout=5)
    assert result.returncode == 0 and result.stdout == "ok\n" and result.stderr == "", result

    # A reduce failure resumes its checkpoint without replaying map work.
    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)
    (workflow / "10-map.php").write_text(r'''<?php
file_put_contents(dirname(__DIR__, 2) . '/map-count', "map\n", FILE_APPEND);
$state['mapped'] = [['result' => 2], ['result' => 3]];
$state['done'] = true;
return workflow_result();
''')
    (workflow / "20-reduce.php").write_text(r'''<?php
require_once dirname(__DIR__, 2) . '/reduce.php';
function demo_reducer(mixed $accumulator, mixed $item, int $index, mixed &$next): ?string
{
    if (!is_file(dirname(__DIR__, 2) . '/allow')) {
        return 'Injected reducer failure';
    }
    return workflow_reduce_sum($accumulator, $item, $index, $next);
}
return workflow_result(workflow_reduce_into(
    $state, 'mapped', 'done', 'total', 'demo_reducer', 0
));
''')
    (workflow / "30-finish.php").write_text(
        "<?php\nreturn workflow_result(workflow_write(dirname(__DIR__, 2) . '/receipt.json', $state));\n"
    )
    command = ["php", str(root / "run.php"), "demo"]
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 1 and "Reduce item 0: Injected reducer failure" in result.stderr
    checkpoint = root / ".runs/demo.checkpoint.json"
    saved = json.loads(checkpoint.read_text())
    assert saved["node"] == "20-reduce.php" and "total" not in saved["state"]
    assert not (root / "receipt.json").exists()
    (root / "allow").touch()
    result = subprocess.run(command, text=True, capture_output=True, timeout=5)
    assert result.returncode == 0, result.stderr
    assert (root / "map-count").read_text() == "map\n"
    assert json.loads((root / "receipt.json").read_text())["total"] == 5
    assert not checkpoint.exists()

node = (source / "workflows/hello/60-reduce.php").read_text()
assert "workflow_reduce_into(" in node and "foreach" not in node
print("PASS: reusable ordered reduce, empty/singleton sums, custom reducer, overflow, guarded publication and resume without map replay")
PYTEST
