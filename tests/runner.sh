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
    shutil.copy2(source / "run.php", root / "run.php")
    shutil.copytree(source / "src", root / "src")
    shutil.copy2(source / "tests/fixtures/TestState.php", root / "TestState.php")
    for name in ("legacy", "failure", "scope", "invalidexit", "storage"):
        (root / "workflows" / name).mkdir(parents=True)
        (root / "workflows" / name / "state.php").write_text(
            "<?php\nrequire_once dirname(__DIR__, 2) . '/TestState.php';\nreturn TestState::class;\n"
        )

    (root / "workflows/legacy/10-work.php").write_text("""<?php
$state->value = ($state->value ?? 0) + 1;
if ($state->value !== 1) {
    throw new RuntimeException('State leaked across runs');
}
""")
    (root / "workflows/legacy/exit.php").write_text("<?php\nreturn null;\n")
    (root / "workflows/failure/10-work.php").write_text("""<?php
$state->discarded = true;
throw new RuntimeException('Expected node failure');
""")
    (root / "workflows/scope/10-work.php").write_text("""<?php
if (isset($this) || isset($root) || isset($lock) || isset($status)) {
    throw new RuntimeException('Runner scope leaked into a script');
}
$state->isolated = true;
""")
    (root / "workflows/invalidexit/exit.php").write_text("<?php\n// Missing explicit return.\n")
    (root / "workflows/storage/10-work.php").write_text("<?php\n$state->ok = true;\n")

    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function unlocked(string $name): void
{
    $handle = fopen(__DIR__ . "/.runs/$name.lock", 'c');
    check(is_resource($handle), 'Cannot open lock');
    try {
        check(flock($handle, LOCK_EX | LOCK_NB), "Leaked lock: $name");
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

$runner = new \NaiveWorkflow\Runner(__DIR__);
check($runner->run('../invalid') === 1, 'Invalid workflow accepted');
check($runner->run('legacy') === 0, 'Legacy run failed');
unlocked('legacy');
check($runner->run('legacy') === 0, 'Runner cannot be reused');
unlocked('legacy');

check($runner->run('failure') === 1, 'Node error did not propagate');
unlocked('failure');
$saved = json_decode(
    file_get_contents(__DIR__ . '/.runs/failure.checkpoint.json'),
    true, 512, JSON_THROW_ON_ERROR
);
check($saved['state'] === [], 'Ordinary failure saved mutated state');

check($runner->run('scope') === 0, 'Script scope changed');
unlocked('scope');
check($runner->run('invalidexit') === 1, 'Missing exit return accepted');
unlocked('invalidexit');

$handle = fopen(__DIR__ . '/.runs/legacy.lock', 'c');
check(flock($handle, LOCK_EX | LOCK_NB), 'Cannot prepare contention');
try {
    check($runner->run('legacy') === 1, 'Contended lock accepted');
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
check($runner->run('legacy') === 0, 'Runner failed after contention');

$store = new class extends \NaiveWorkflow\JsonFileStore {
    public int $writes = 0;

    public function write(string $path, array $data): void
    {
        $this->writes++;
        if (str_ends_with($path, '.checkpoint.json')) {
            throw new RuntimeException('Injected checkpoint write failure');
        }
        parent::write($path, $data);
    }
};
$custom = new \NaiveWorkflow\Runner(__DIR__, $store);
check($custom->run('storage') === 1, 'Storage failure was ignored');
check($store->writes >= 2, 'Injected store was not used');
unlocked('storage');
check($runner->run('storage') === 0, 'Cannot run after storage failure');

$retry = new RetryableException('compatible', 0);
check($retry->retryAfterSeconds === 0, 'Retry exception compatibility changed');
try {
    new RetryableException('invalid', -1);
    throw new RuntimeException('Negative retry delay accepted');
} catch (InvalidArgumentException $expected) {
}
''')
    result = subprocess.run(
        ["php", str(root / "check.php")],
        text=True, capture_output=True, timeout=10,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == "", result.stdout
    assert "Warning:" not in result.stderr and "Fatal error:" not in result.stderr, result.stderr
    for expected in (
        "Usage: php run.php",
        "Expected node failure",
        "exit.php must return null",
        "already running or cannot acquire lock",
        "Injected checkpoint write failure",
    ):
        assert expected in result.stderr, (expected, result.stderr)
    for name in ("legacy", "scope", "storage"):
        status = json.loads((root / f".runs/{name}.json").read_text())
        assert status["status"] == "done", status
        assert not (root / f".runs/{name}.checkpoint.json").exists()

print("PASS: reusable OOP runner, legacy scope, failure checkpoints, lock release/contention, storage injection and retry compatibility")
PYTEST
