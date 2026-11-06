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
for directory in ("src", "workflows"):
    for file in (source / directory).rglob("*.php"):
        text = file.read_text()
        for removed in ("nextSequence", "function jump(", "->jump(", "function sequence("):
            assert removed not in text, (file, removed)

with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    shutil.copytree(source / "src", root / "src")
    shutil.copy2(source / "run.php", root / "run.php")
    workflow = root / "workflows/demo"
    workflow.mkdir(parents=True)

    def node(name, body):
        (workflow / name).write_text("<?php\ndeclare(strict_types=1);\n" + body + "\n")

    def run():
        return subprocess.run(
            ["php", str(root / "run.php"), "demo"],
            capture_output=True, text=True, timeout=5,
        )

    node("10-start.php", r"""
        // An unreturned object has no routing effect.
        \NaiveWorkflow\Jump::to(999);
        return null;
    """)
    node("20-choose.php", r"return \NaiveWorkflow\Jump::to('0040');")
    node("30-unused.php", "throw new RuntimeException('Wrong branch');")
    node("40-finish.php", "return null;")
    node("exit.php", "return null;")
    result = run()
    assert result.returncode == 0 and result.stdout == result.stderr == "", result

    node("20-choose.php", r"return \NaiveWorkflow\Jump::to(999);")
    result = run()
    assert result.returncode == 1 and "must identify exactly one numbered node" in result.stderr
    saved = json.loads((root / ".runs/demo.checkpoint.json").read_text())
    assert saved["node"] == "20-choose.php", saved
    node("20-choose.php", r"return \NaiveWorkflow\Jump::to(40);")
    result = run()
    assert result.returncode == 0, result.stderr

    node("exit.php", r"return \NaiveWorkflow\Jump::to(10);")
    result = run()
    assert result.returncode == 1 and "exit.php cannot return a Jump" in result.stderr
    assert json.loads((root / ".runs/demo.checkpoint.json").read_text())["node"] == "exit.php"

print("PASS: one returned-Jump routing API, ignored unreturned results, target failure/resume and exit-node rejection")
PYTEST
