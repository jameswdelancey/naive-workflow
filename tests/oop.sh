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
    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)

    def node(filename, body):
        (workflow / filename).write_text(
            "<?php\n"
            "require_once dirname(__DIR__, 2) . '/src/bootstrap.php';\n"
            "return (new class extends \\NaiveWorkflow\\Node {\n"
            "public function run(array &$state): mixed {\n"
            + body + "\n}\n})->run($state);\n"
        )

    node("10-start.php", "$state['value'] = 7; return null;")
    node("20-work.php", r"""
        $state['value']++;
        if (!is_file($this->root . '/allow')) {
            throw new \RuntimeException('ordinary failure');
        }
        if (!isset($state['retried'])) {
            $state['retried'] = true;
            $this->jump(999);
            throw new \RetryableException('retry with mutation', 0);
        }
        // Fall through: the unsuccessful attempt's jump must be reset.
        return null;
    """)
    node("30-choose.php", "$this->jump('0050'); return null;")
    node("40-unused.php", r"throw new \RuntimeException('Unused branch ran');")
    node("50-finish.php", r"""
        if ($state !== ['value' => 9, 'retried' => true]) {
            throw new \RuntimeException('Unexpected resumed state');
        }
        $this->log('OOP state and branch verified.');
        return null;
    """)
    node("exit.php", "return null;")

    def run():
        return subprocess.run(
            ["php", "-d", "date.timezone=America/Los_Angeles",
             str(root / "run.php"), "demo"],
            capture_output=True, text=True, timeout=10,
        )

    result = run()
    assert result.returncode == 1 and result.stdout == "", result
    checkpoint = root / ".runs/demo.checkpoint.json"
    saved = json.loads(checkpoint.read_text())
    assert saved == {"node": "20-work.php", "state": {"value": 7}}, saved

    (root / "allow").touch()
    result = run()
    assert result.returncode == 0 and result.stdout == "", result.stderr
    assert "OOP state and branch verified." in result.stderr
    assert "retry with mutation" in result.stderr
    assert not checkpoint.exists()
    assert json.loads((root / ".runs/demo.json").read_text())["status"] == "done"

    (root / "contracts.php").write_text(r'''<?php
require __DIR__ . '/src/bootstrap.php';

$state = ['unchanged' => true];
$disabled = new class extends \NaiveWorkflow\ManagedChildWorkflowNode {
    protected function shouldRun(): bool { return false; }
    protected function workflow(): string {
        throw new \RuntimeException('Disabled node resolved its target');
    }
};
if ($disabled->run($state) !== null || $state !== ['unchanged' => true]) {
    throw new \RuntimeException('Disabled node changed state');
}

$invalid = new class extends \NaiveWorkflow\ManagedChildWorkflowNode {
    protected function workflow(): string { return 'demo'; }
    protected function timeoutSeconds(): int { return 0; }
};
try {
    $invalid->run($state);
    throw new \RuntimeException('Invalid timeout was accepted');
} catch (\InvalidArgumentException $expected) {
}

$command = new class extends \NaiveWorkflow\Node {
    public function run(array &$state): mixed {
        return $this->workflowCommand('demo', true);
    }
};
$expected = [
    PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(),
    __DIR__ . '/run.php', 'demo',
];
if ($command->run($state) !== $expected) {
    throw new \RuntimeException('Incorrect child command');
}
''')
    result = subprocess.run(
        ["php", str(root / "contracts.php")],
        capture_output=True, text=True, timeout=5,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == result.stderr == "", result

print("PASS: OOP state references, ordinary resume, retry mutations, selector reset, branching and child hooks")
PYTEST
