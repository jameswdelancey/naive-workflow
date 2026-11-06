#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile

with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    source = Path(sys.argv[1])
    shutil.copy2(source / "run.php", root / "run.php")
    shutil.copy2(source / "child.php", root / "child.php")
    shutil.copytree(source / "src", root / "src")
    for name in ("hello", "poll"):
        shutil.copytree(source / f"workflows/{name}", root / f"workflows/{name}")
    env = dict(os.environ, USER="child-test", HELLO_APPROVED="1",
               POLL_ONCE="0")
    # Exercise plain hello defaults, regardless of the invoking shell's flags.
    env.pop("HELLO_CHILD_POLL", None)
    env.pop("HELLO_POLL", None)

    def run():
        # A separate process group lets the test clean up both runners on timeout.
        p = subprocess.Popen(
            ["php", str(root / "run.php"), "hello"], env=env,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, start_new_session=True,
        )
        try:
            out, err = p.communicate(timeout=20)
            return p.returncode, out, err
        finally:
            try:
                os.killpg(p.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            p.wait()

    code, out, err = run()
    sys.stderr.write(err)
    assert code == 0 and out == "", (code, out, err)
    assert err.count(" polling\n") == 3
    assert err.index("Starting child workflow poll.") < err.index(" polling\n")
    assert err.index(" polling\n") < err.index("Child workflow poll completed; continuing hello.")

    assert "Joined parallel poll results:" in err
    joined = json.loads((root / ".runs/hello/last-poll-join.json").read_text())
    assert joined["left"]["pid"] != joined["right"]["pid"]
    assert all(not Path(f'/proc/{joined[slot]["pid"]}').exists() for slot in ("left", "right"))

    hello = json.loads((root / ".runs/hello.json").read_text())
    poll = json.loads((root / ".runs/poll.json").read_text())
    assert hello["status"] == poll["status"] == "done"
    assert hello["node"] == poll["node"] == "exit.php"
    assert hello["pid"] != poll["pid"]
    assert not (root / ".runs/hello.checkpoint.json").exists()
    assert not (root / ".runs/poll.checkpoint.json").exists()
    assert not Path(f'/proc/{poll["pid"]}').exists()

    # Explicit opt-out must not start or modify poll.
    env["HELLO_CHILD_POLL"] = "0"
    code, out, err = run()
    assert code == 0 and out == "", (code, out, err)
    assert "Starting child workflow" not in err and " polling\n" not in err
    assert json.loads((root / ".runs/poll.json").read_text()) == poll
    env.pop("HELLO_CHILD_POLL")

    # A child failure must fail the parent node, not silently continue.
    (root / "workflows/poll/10-tick.php").write_text(
        "<?php\nthrow new RuntimeException('Injected child failure');\n"
    )
    code, out, err = run()
    assert code != 0 and out == ""
    assert "Child workflow poll failed with exit code 1" in err
    hello = json.loads((root / ".runs/hello.json").read_text())
    checkpoint = json.loads((root / ".runs/hello.checkpoint.json").read_text())
    assert hello["status"] == "failed" and hello["node"] == "55-child-poll.php"
    assert checkpoint["node"] == "55-child-poll.php"

print("PASS: default hello runs finite child; explicit opt-out, distinct PID, continuation, cleanup and failure propagation")
PYTEST
