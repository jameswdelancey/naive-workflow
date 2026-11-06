#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
from datetime import datetime
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
production = [source / "run.php", source / "status.php"]
production += list((source / "src").rglob("*.php"))
production += list((source / "workflows").rglob("*.php"))
writers = [
    path.relative_to(source).as_posix() for path in production
    if re.search(r"fwrite\s*\(\s*STDERR", path.read_text())
]
assert writers == ["src/Base.php"], writers

with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    shutil.copytree(source / "src", root / "src")
    shutil.copytree(source / "workflows/poll", root / "workflows/poll")
    for filename in ("run.php", "status.php"):
        shutil.copy2(source / filename, root / filename)
    (root / "probe.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$logger = new class extends \NaiveWorkflow\Base {
    public function emit(string $message): void
    {
        $this->log($message);
    }
};
$logger->emit("first\nsecond\rthird");

foreach ([
    \NaiveWorkflow\Node::class,
    \NaiveWorkflow\Runner::class,
    \NaiveWorkflow\StatusCommand::class,
] as $class) {
    $method = new ReflectionMethod($class, 'log');
    if ($method->getDeclaringClass()->getName() !== \NaiveWorkflow\Base::class) {
        throw new RuntimeException("Logger not inherited by $class");
    }
}

$state = [];
require __DIR__ . '/workflows/poll/10-tick.php';
echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
''')

    def run(script, *args):
        return subprocess.run(
            ["php", "-d", "date.timezone=Asia/Kolkata",
             str(root / script), *args],
            capture_output=True, text=True, timeout=5,
        )

    pattern = re.compile(
        r"^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30)\] (.*)$"
    )

    def messages(result):
        lines = result.stderr.splitlines()
        assert lines, result
        parsed = []
        for line in lines:
            match = pattern.fullmatch(line)
            assert match, line
            datetime.fromisoformat(match[1])
            parsed.append(match[2])
        return parsed

    result = run("probe.php")
    assert result.returncode == 0, result.stderr
    assert messages(result) == [r"first\nsecond\rthird", "polling"], result.stderr
    state = json.loads(result.stdout)
    assert result.stderr.splitlines()[1] == f'[{state["time"]}] polling'

    result = run("run.php")
    assert result.returncode == 1 and result.stdout == "", result
    assert messages(result) == ["Usage: php run.php <workflow>"]

    demo = root / "workflows/demo"
    demo.mkdir()
    (demo / "10-fail.php").write_text(
        '<?php\nthrow new RuntimeException("first\\nsecond\\rthird");\n'
    )
    result = run("run.php", "demo")
    assert result.returncode == 1 and result.stdout == "", result
    assert messages(result) == [r"demo: first\nsecond\rthird"]

    # Logging escapes line breaks without changing persisted error metadata.
    saved = json.loads((root / ".runs/demo.json").read_text())
    assert saved["error"] == "first\nsecond\rthird"

    (root / ".runs/demo.json").write_text("{")
    result = run("status.php", "--json")
    assert result.returncode == 1, result
    assert messages(result)[0].startswith("status: demo:")
    rows = json.loads(result.stdout)
    assert next(row for row in rows if row["workflow"] == "demo")["status"] == "unknown"

print("PASS: single inherited stderr logger, local timestamps, escaped multiline diagnostics, saved poll timestamp and clean status JSON")
PYTEST
