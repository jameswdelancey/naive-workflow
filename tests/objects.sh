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
    (root / "workflows/demo").mkdir(parents=True)
    (root / "workflows/invalid name").mkdir()
    (root / ".runs").mkdir()
    for name in (
        "2-work.php", "10-start.php", "030-work.php", "30-duplicate.php",
        "999999999999999999999999999999-work.php", "exit.php", "helper.php"
    ):
        (root / "workflows/demo" / name).write_text("<?php\nreturn null;\n")
    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function fails(callable $work, string $message): void
{
    try {
        $work();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected: $message");
}

$directory = __DIR__ . '/workflows/demo';
$definition = new \NaiveWorkflow\WorkflowDefinition($directory);
check($definition->steps === [
    '030-work.php', '10-start.php', '2-work.php', '30-duplicate.php',
    '999999999999999999999999999999-work.php', 'exit.php',
], 'Lexicographic discovery changed');
check($definition->resolve('0002') === 2, 'Leading zero resolution changed');
check($definition->resolve('999999999999999999999999999999') === 4,
      'Large sequence was truncated');
check($definition->positionOf('exit.php') === 5, 'Exit ordering changed');
fails(fn() => $definition->positionOf('gone.php'), 'Checkpoint node is missing: gone.php');
foreach ([30, '0030', 40] as $target) {
    fails(fn() => $definition->resolve($target), 'must identify exactly one numbered node');
}
foreach ([-1, 1.5, true, null, [], '1e2', ''] as $target) {
    fails(fn() => $definition->resolve($target), 'must be a nonnegative integer or digit string');
}
unlink($directory . '/30-duplicate.php');
fails(fn() => $definition->resolve(30), 'must identify exactly one numbered node');
$fresh = new \NaiveWorkflow\WorkflowDefinition($directory);
check($fresh->resolve(30) === 0, 'New definition did not rediscover files');

$repository = new \NaiveWorkflow\StatusRepository(__DIR__);
$formatter = new \NaiveWorkflow\StatusFormatter();
ob_start();
$report = $repository->collect();
$json = $formatter->json($report->rows);
$table = $formatter->table($report->rows);
$noise = ob_get_clean();
check($noise === '', 'Collection/rendering printed output');
check($report->exitCode() === 0 && $report->errors === [], 'Idle report failed');
check(json_decode($json, true, 512, JSON_THROW_ON_ERROR) === [
    ['workflow' => 'demo', 'status' => 'idle', 'updated_at' => null],
], 'Idle rows changed');
check(str_contains($table, 'UPDATED AT'), 'Table header missing');

$file = __DIR__ . '/.runs/demo.json';
file_put_contents($file, '{');
$bad = $repository->collect();
check($bad->exitCode() === 1 && count($bad->errors) === 1, 'Read errors lost');
check($bad->rows[0]['status'] === 'unknown', 'Malformed status looked idle');
check($bad->rows[0]['updated_at'] !== null, 'Malformed snapshot lost its mtime');
file_put_contents($file, json_encode([
    'pid' => getmypid(), 'status' => 'running', 'node' => "bad\x1b\nnode",
], JSON_THROW_ON_ERROR));
touch($file, 1700000000);
clearstatcache();
$good = $repository->collect();
check($good->exitCode() === 0 && $good->errors === [], 'Prior errors leaked');
check(strtotime($good->rows[0]['updated_at']) === 1700000000, 'Snapshot mtime changed');
check(!str_contains($formatter->table($good->rows), "\x1b"), 'Table controls not sanitized');
check(str_contains($formatter->json($good->rows), '\u001b'), 'JSON metadata changed');

$fakeRepository = new class(__DIR__) extends \NaiveWorkflow\StatusRepository {
    public function collect(): \NaiveWorkflow\StatusReport
    {
        return new \NaiveWorkflow\StatusReport(
            [['workflow' => 'injected', 'status' => 'idle', 'updated_at' => null]],
            ['injected: expected read error']
        );
    }
};
$fakeFormatter = new class extends \NaiveWorkflow\StatusFormatter {
    public function table(array $rows): string
    {
        return "custom:" . $rows[0]['workflow'] . "\n";
    }
};
$command = new \NaiveWorkflow\StatusCommand(__DIR__, $fakeRepository, $fakeFormatter);
ob_start();
$code = $command->run([]);
$output = ob_get_clean();
check($code === 1 && $output === "custom:injected\n", 'Command ignored collaborators');
''')
    result = subprocess.run(
        ["php", str(root / "check.php")], capture_output=True, text=True, timeout=10
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == "", result.stdout
    assert len(result.stderr.splitlines()) == 1, result.stderr
    assert "status: injected: expected read error" in result.stderr, result.stderr

print("PASS: workflow discovery/resolution snapshots, pure status collection/rendering, partial reports and injected command collaborators")
PYTEST
