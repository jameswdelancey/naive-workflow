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

with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    source = Path(sys.argv[1])
    shutil.copytree(source / "src", root / "src")
    shutil.copy2(source / "run.php", root / "run.php")
    (root / ".runs").mkdir()
    for folder in ("hello", "poll", "mes-observe"):
        directory = root / "workflows" / folder
        directory.mkdir(parents=True)
        if folder == "hello":
            shutil.copy2(source / "workflows/hello/PollJoinResult.php", directory / "PollJoinResult.php")
        if folder == "mes-observe":
            for filename in ("Models.php", "common.php"):
                shutil.copy2(source / "workflows" / folder / filename, directory / filename)
        for filename in ("State.php", "state.php"):
            shutil.copy2(source / "workflows" / folder / filename, directory / filename)
        namespace = {"hello": "Hello", "poll": "Poll", "mes-observe": "MesObserve"}[folder]
        values, expression, expected = {
            "hello": ({"name": "typed", "retry_demo_attempts": 4},
                      "$state->retryDemoAttempts", {"name": "typed", "retry_demo_attempts": 5}),
            "poll": ({"time": "saved"}, "$state->time", {"time": "updated"}),
            "mes-observe": ({"deadline": 4}, "$state->deadline", {"deadline": 5}),
        }[folder]
        update = f"{expression} = 'updated';" if folder == "poll" else f"{expression}++;"
        (directory / "10-check.php").write_text(
            "<?php\ndeclare(strict_types=1);\n"
            f"if (!$state instanceof \\NaiveWorkflow\\{namespace}\\State) "
            "{ throw new RuntimeException('Wrong state class'); }\n"
            + update + "\n"
            "file_put_contents(dirname(__DIR__, 2) . '/observed.json', json_encode($state));\n"
        )
        checkpoint = root / f".runs/{folder}.checkpoint.json"
        checkpoint.write_text(json.dumps({"node": "10-check.php", "state": values}))
        result = subprocess.run(
            ["php", str(root / "run.php"), folder],
            capture_output=True, text=True, timeout=5,
        )
        assert result.returncode == 0 and result.stdout == "", result.stderr
        assert not checkpoint.exists()
        assert json.loads((root / "observed.json").read_text()) == expected
        assert json.loads((root / f".runs/{folder}.json").read_text())["status"] == "done"


    plain = root / "workflows/stateless"
    plain.mkdir()
    (plain / "10-check.php").write_text(
        "<?php\nfile_put_contents(dirname(__DIR__, 2) . '/should-not-run', 'bad');\n"
    )
    checkpoint = root / ".runs/stateless.checkpoint.json"
    checkpoint.write_text(json.dumps({"node": "10-check.php", "state": {"injected": 1}}))
    before = checkpoint.read_bytes()
    result = subprocess.run(
        ["php", str(root / "run.php"), "stateless"],
        capture_output=True, text=True, timeout=5,
    )
    assert result.returncode == 1 and result.stdout == "", result
    assert "Unknown state field" in result.stderr, result.stderr
    assert checkpoint.read_bytes() == before
    assert not (root / "should-not-run").exists()

    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}

require __DIR__ . '/workflows/hello/State.php';
require __DIR__ . '/workflows/poll/State.php';
$hello = new \NaiveWorkflow\Hello\State(['name' => 'typed', 'approved' => false]);
$poll = new \NaiveWorkflow\Poll\State();
check(!$hello instanceof ArrayAccess && !$poll instanceof ArrayAccess, 'Typed states still accept keys');
check($hello->toArray() === ['name' => 'typed', 'approved' => false], 'Absent/false fields changed');
$copy = clone $hello;
$copy->name = 'copy';
check($hello->name === 'typed', 'Clone changed original');
foreach ([
    static function () use ($hello): void { $hello->unknown = 1; },
    static function () use ($hello): void { $hello['unknown'] = 1; },
    static function () use ($hello): void { $hello->approved = 'true'; },
    static function () use ($hello): void { $hello->retryDemoAttempts = 1.5; },
    static function (): void { new \NaiveWorkflow\Hello\State(['unknown' => 1]); },
    static function (): void { new \NaiveWorkflow\Hello\State(['approved' => 1]); },
    static function (): void { new \NaiveWorkflow\Hello\State(['name' => null]); },
    static function (): void { new \NaiveWorkflow\Poll\State(['time' => []]); },
    static function () use ($poll): void { \NaiveWorkflow\Hello\State::requireState($poll); },
] as $bad) {
    $rejected = false;
    try { $bad(); } catch (Throwable $expected) { $rejected = true; }
    check($rejected, 'Invalid typed state operation accepted');
}
foreach ([$hello, $poll] as $typed) {
    foreach ((new ReflectionObject($typed))->getProperties() as $property) {
        check($property->hasType() && (string) $property->getType() !== 'array',
              'Typed workflow still has a property bag');
    }
}


require __DIR__ . '/workflows/mes-observe/State.php';
$mesValues = [
    'config' => [
        'unit_id' => 'UNIT-TEST', 'interval' => 1, 'timeout' => 30,
        'database_identity' => str_repeat('a', 64),
    ],
    'deadline' => 123,
    'baseline' => [
        'mes_production_orders' => null,
        'mes_quality_checks' => [
            'unit_id' => 'UNIT-TEST', 'row_version' => '9007199254740993', 'status' => 'existing',
        ],
    ],
    'observed' => [],
    'armed_at' => 'saved',
];
$mes = new \NaiveWorkflow\MesObserve\State($mesValues);
check($mes->toArray() === $mesValues, 'MES null/absent fields or large versions changed');
$mesCopy = clone $mes;
$mesCopy->config->interval = 2;
$mesCopy->baseline->quality->status = 'changed';
check($mes->config->interval === 1 && $mes->baseline->quality->status === 'existing',
      'MES nested clone shares state');
foreach ([
    static function () use ($mes): void { $mes->unexpected = true; },
    static function () use ($mes): void { $mes->config->interval = '1'; },
    static function () use ($mes): void { $mes->config->unexpected = 1; },
    static function () use ($mes): void { $mes->baseline->quality->rowVersion = 1; },
    static function () use ($mes): void { $mes->observed->production = []; },
    static function () use ($mesValues): void {
        new \NaiveWorkflow\MesObserve\State($mesValues + ['unexpected' => 1]);
    },
    static function () use ($mesValues): void {
        $mesValues['config']['extra'] = true;
        new \NaiveWorkflow\MesObserve\State($mesValues);
    },
    static function () use ($mesValues): void {
        $mesValues['baseline']['mes_quality_checks']['row_version'] = 9007199254740993;
        new \NaiveWorkflow\MesObserve\State($mesValues);
    },
    static function () use ($mesValues): void {
        $mesValues['observed']['unknown_table'] = [];
        new \NaiveWorkflow\MesObserve\State($mesValues);
    },
] as $bad) {
    $rejected = false;
    try { $bad(); } catch (Throwable $expected) { $rejected = true; }
    check($rejected, 'Invalid nested MES state accepted');
}
foreach ([
    $mes, $mes->config, $mes->baseline, $mes->baseline->quality, $mes->observed,
    new \NaiveWorkflow\MesObserve\Observation([
        'record' => $mesValues['baseline']['mes_quality_checks'], 'observed_at' => 'saved',
    ]),
] as $typed) {
    check(!$typed instanceof ArrayAccess, 'MES object still supports dynamic keys');
    foreach ((new ReflectionObject($typed))->getProperties() as $property) {
        check($property->hasType() && (string) $property->getType() !== 'array',
              'MES object has an untyped property or array bag');
    }
}


$empty = new \NaiveWorkflow\EmptyWorkflowState();
check($empty->toArray() === [], 'Empty state is not empty');
check(!$empty instanceof ArrayAccess, 'Fallback still supports array keys');
foreach ([
    static function () use ($empty): void { $empty->newField = 1; },
    static function () use ($empty): void { $empty['newField'] = 1; },
    static function (): void { new \NaiveWorkflow\EmptyWorkflowState(['newField' => 1]); },
] as $bad) {
    $rejected = false;
    try { $bad(); } catch (Throwable $expected) { $rejected = true; }
    check($rejected, 'Stateless fallback accepted application data');
}
$status = \NaiveWorkflow\WorkflowStatus::start();
$status->selectNode('15-retry.php');
$status->retryError("not ready\nagain");
$status->retrying(time() + 2);
$saved = $status->toArray();
check($saved['status'] === 'retrying' && $saved['retry_after_seconds'] >= 1, 'Retry status lost');
check(\NaiveWorkflow\WorkflowStatus::fromArray($saved)->toArray() === $saved,
      'Status round trip changed');
$status->running();
check(!isset($status->toArray()['retry_error'], $status->toArray()['retry_at']),
      'Retry diagnostics not cleared');
$status->complete();
check($status->toArray()['status'] === 'done' && isset($status->toArray()['finished']),
      'Completion missing');
$failed = \NaiveWorkflow\WorkflowStatus::start();
$failed->fail(new RuntimeException("first\nsecond"));
check($failed->toArray()['error'] === "first\nsecond", 'Error metadata modified');
check($saved['status'] === 'retrying', 'Status snapshot mutated');

check($status->execution() === \NaiveWorkflow\ExecutionStatus::Done, 'Status is not enum-backed');
check($status->pid() === getmypid() && $status->node() === '15-retry.php',
      'Typed status accessors failed');
foreach ([
    static function () use ($status): void { $status->unexpected = 1; },
    static function () use ($status): void { $status->pid = 'invalid'; },
    static function () use ($status): void { unset($status->node); },
    static function () use ($status): void { $status->retrying('123'); },
    static function () use ($status): void { $status->selectNode(123); },
    static function () use ($saved): void {
        \NaiveWorkflow\WorkflowStatus::fromArray($saved + ['unexpected' => 1]);
    },
    static function () use ($saved): void {
        \NaiveWorkflow\WorkflowStatus::fromArray(array_replace($saved, ['retry_at' => null]));
    },
] as $invalidOperation) {
    $rejected = false;
    try {
        $invalidOperation();
    } catch (LogicException | RuntimeException | TypeError $expected) {
        $rejected = true;
    }
    check($rejected, 'Invalid status operation was accepted');
}
$reflection = new ReflectionClass(\NaiveWorkflow\WorkflowStatus::class);
foreach ($reflection->getProperties() as $property) {
    check($property->hasType(), 'Untyped status property');
    check((string) $property->getType() !== 'array', 'Status still contains a property bag');
}

''')
    result = subprocess.run(["php", str(root / "check.php")],
                            capture_output=True, text=True, timeout=5)
    assert result.returncode == 0 and result.stdout == result.stderr == "", result
print("PASS: workflow-specific state classes, old checkpoint resume, nested mutation, detached snapshots, JSON validation and status transitions")
PYTEST
