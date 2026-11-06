#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    shutil.copytree(source / "src", root / "src")
    for name in ("hello", "poll"):
        shutil.copytree(source / "workflows" / name, root / "workflows" / name)
    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invoke(string $path, array &$state): mixed
{
    return require $path;
}

$state = ['name' => 'template-test', 'preserved' => ['value' => 1]];
foreach (['approved' => 50, 'rejected' => null] as $branch => $target) {
    $file = $branch === 'approved' ? '30-approved.php' : '40-rejected.php';
    // Repeated inclusion must not redeclare named classes.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $GLOBALS['nextSequence'] = null;
        check(invoke(__DIR__ . "/workflows/hello/$file", $state) === null,
              'Branch returned an unexpected value');
        check($state['result'] === $branch, 'Incorrect branch result');
        check($GLOBALS['nextSequence'] === $target, 'Incorrect continuation');
        check($state['preserved'] === ['value' => 1], 'Unrelated state changed');
    }
}

foreach ([null, '0', 'true', '01', '1'] as $value) {
    putenv($value === null ? 'HELLO_POLL' : "HELLO_POLL=$value");
    putenv($value === null ? 'POLL_ONCE' : "POLL_ONCE=$value");
    $before = $state;
    $GLOBALS['nextSequence'] = null;
    $command = [PHP_BINARY, __DIR__ . '/run.php', 'poll'];
    $hello = invoke(__DIR__ . '/workflows/hello/exit.php', $state);
    $poll = invoke(__DIR__ . '/workflows/poll/exit.php', $state);
    check($hello === ($value === '1' ? $command : null), 'Hello flag changed');
    check($poll === ($value === '1' ? null : $command), 'Poll flag changed');
    check($state === $before, 'Handoff changed state');
    check($GLOBALS['nextSequence'] === null, 'Handoff selected a sequence');
}

$disabled = new class extends \NaiveWorkflow\HandoffNode {
    protected function shouldHandoff(array $state): bool { return false; }
    protected function workflow(): string {
        throw new RuntimeException('Disabled handoff resolved target');
    }
};
check($disabled->run($state) === null, 'Disabled handoff did not stop');

$forwarding = new class extends \NaiveWorkflow\HandoffNode {
    protected function workflow(): string { return 'poll'; }
    protected function forwardTimezone(): bool { return true; }
};
check($forwarding->run($state) === [
    PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(),
    __DIR__ . '/run.php', 'poll',
], 'Timezone forwarding failed');

$invalid = new class extends \NaiveWorkflow\HandoffNode {
    protected function workflow(): string { return '../poll'; }
};
try {
    $invalid->run($state);
    throw new RuntimeException('Invalid target accepted');
} catch (InvalidArgumentException $expected) {
}
''')
    result = subprocess.run(
        ["php", "-d", "date.timezone=America/Los_Angeles", str(root / "check.php")],
        capture_output=True, text=True, timeout=5,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == "", result.stdout
    assert "Warning:" not in result.stderr and "Fatal error:" not in result.stderr
    assert result.stderr.count("approved branch.") == 2, result.stderr
    assert result.stderr.count("rejected branch.") == 2, result.stderr
    assert result.stderr.count("Handing off to poll.") == 1, result.stderr

print("PASS: shared branch actions, repeated inclusion, exact mode flags and handoff configuration without process replacement")
PYTEST
