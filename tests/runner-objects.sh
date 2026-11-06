#!/usr/bin/env bash
set -euo pipefail
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
    shutil.copytree(source / "src", root / "src")
    shutil.copy2(source / "tests/fixtures/TestState.php", root / "TestState.php")
    shutil.copy2(source / "run.php", root / "run.php")
    (root / ".runs").mkdir()
    (root / "workflows/demo").mkdir(parents=True)
    (root / "workflows/demo/10-work.php").write_text("<?php\nreturn null;\n")

    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/TestState.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function fails(callable $work, string $expected): void
{
    try {
        $work();
    } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $expected), $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected failure: $expected");
}

$path = __DIR__ . '/.runs/test.lock';
$first = new \NaiveWorkflow\WorkflowLock($path);
$second = new \NaiveWorkflow\WorkflowLock($path);
check($first->acquire(), 'Initial lock failed');
fails(fn() => $first->acquire(), 'already held');
check(!$second->acquire(), 'Contention accepted');
$second->release();
check(!$second->acquire(), 'Contender released the owner lock');
$first->release();
$first->release();
check($second->acquire(), 'Released lock unavailable');
unset($second);
check($first->acquire(), 'Destructor did not release lock');
$first->release();

$path = __DIR__ . '/.runs/test.checkpoint.json';
$store = new class extends \NaiveWorkflow\JsonFileStore {
    public int $writes = 0;
    public bool $fail = false;
    public function write(string $path, array $data): void
    {
        $this->writes++;
        if ($this->fail) {
            throw new RuntimeException('Injected checkpoint failure');
        }
        parent::write($path, $data);
    }
};
$checkpoint = new \NaiveWorkflow\CheckpointRepository($path, $store);
check($checkpoint->load() === null, 'Missing checkpoint did not start fresh');
$checkpoint->save('10-work.php', new TestState(['attempts' => 1]), 123);
$saved = $checkpoint->load();
check($saved === [
    'node' => '10-work.php', 'state' => ['attempts' => 1], 'retry_at' => 123,
], 'Checkpoint schema changed');
check($checkpoint->retryDeadline($saved) === 123, 'Retry deadline lost');
$checkpoint->save('10-work.php', new TestState(['attempts' => 2]));
$saved = $checkpoint->load();
check(!array_key_exists('retry_at', $saved), 'Stale retry deadline persisted');
check($checkpoint->retryDeadline($saved) === null, 'Missing deadline changed');
$store->fail = true;
fails(fn() => $checkpoint->save('20-work.php', new TestState()), 'Injected checkpoint failure');
check($checkpoint->load() === $saved, 'Failed write damaged checkpoint');
check($store->writes === 3, 'Injected store was bypassed');
foreach ([-1, '123', 1.5, false, []] as $invalid) {
    fails(fn() => $checkpoint->retryDeadline(['retry_at' => $invalid]),
          'Invalid checkpoint retry deadline');
}
check($checkpoint->retryDeadline(['retry_at' => 0]) === 0, 'Zero deadline rejected');
check($checkpoint->retryDeadline(['retry_at' => null]) === null, 'Null deadline rejected');
foreach (['null', '[]', '{"node":3,"state":[]}', '{"node":"10-work.php","state":null}'] as $invalid) {
    file_put_contents($path, $invalid);
    fails(fn() => $checkpoint->load(), 'Invalid checkpoint');
}
file_put_contents($path, '{');
fails(fn() => $checkpoint->load(), 'Syntax error');
$checkpoint->remove();
$checkpoint->remove();
check($checkpoint->load() === null, 'Checkpoint removal failed');

$command = new \NaiveWorkflow\HandoffCommand([PHP_BINARY, '-r', 'echo "not executed";']);
check($command->executable() === PHP_BINARY, 'Wrong executable');
foreach ([null, 1, [], [PHP_BINARY, 1], ['php'], [''], ['argv' => PHP_BINARY]] as $invalid) {
    fails(fn() => new \NaiveWorkflow\HandoffCommand($invalid),
          'exit.php must return null or an argv array');
}
''')
    result = subprocess.run(
        ["php", str(root / "check.php")],
        capture_output=True, text=True, timeout=10,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == result.stderr == "", result

    checkpoint = root / ".runs/demo.checkpoint.json"
    cases = [
        ({"node": "missing.php", "state": {}, "retry_at": -1},
         "Checkpoint node is missing: missing.php", "missing.php"),
        ({"node": "10-work.php", "state": {}, "retry_at": -1},
         "Invalid checkpoint retry deadline", "10-work.php"),
        ({"node": 4, "state": {}}, "Invalid checkpoint", None),
    ]
    for saved, message, node in cases:
        checkpoint.write_text(json.dumps(saved))
        before = checkpoint.read_bytes()
        result = subprocess.run(
            ["php", str(root / "run.php"), "demo"],
            capture_output=True, text=True, timeout=5,
        )
        assert result.returncode == 1 and result.stdout == "", result
        assert message in result.stderr, result.stderr
        status = json.loads((root / ".runs/demo.json").read_text())
        assert status["status"] == "failed" and status["node"] == node, status
        assert checkpoint.read_bytes() == before
    checkpoint.unlink()

    # Real, finite process replacement verifies ordering without an infinite poller.
    (root / "workflows/demo/exit.php").write_text(
        "<?php\nreturn [PHP_BINARY, dirname(__DIR__, 2) . '/target.php'];\n"
    )
    (root / "target.php").write_text(r'''<?php
$status = json_decode(file_get_contents(__DIR__ . '/.runs/demo.json'), true);
if ($status['status'] !== 'done' || $status['pid'] !== getmypid() ||
    is_file(__DIR__ . '/.runs/demo.checkpoint.json')) {
    throw new RuntimeException('Handoff preceded completion');
}
$handle = fopen(__DIR__ . '/.runs/demo.lock', 'c');
if (!flock($handle, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Handoff retained the workflow lock');
}
flock($handle, LOCK_UN);
fclose($handle);
file_put_contents(__DIR__ . '/handoff-complete', 'yes');
''')
    result = subprocess.run(
        ["php", str(root / "run.php"), "demo"],
        capture_output=True, text=True, timeout=5,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == result.stderr == "", result
    assert (root / "handoff-complete").read_text() == "yes"

print("PASS: lock ownership, checkpoint schema/storage/errors, resume validation order and finite handoff after completion/unlock")
PYTEST
