#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$root" <<'PYTEST'
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

source = Path(sys.argv[1])
with tempfile.TemporaryDirectory() as temporary:
    root = Path(temporary)
    shutil.copytree(source / "src", root / "src")
    shutil.copytree(source / "workflows/mes-observe", root / "workflows/mes-observe")
    (root / "check.php").write_text(r'''<?php
declare(strict_types=1);
require __DIR__ . '/workflows/mes-observe/ObservationNode.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function failure(callable $work, string $expected): void
{
    try {
        $work();
    } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $expected), $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected failure: $expected");
}

final class TestObserver extends \NaiveWorkflow\MesObserve\ObservationNode
{
    public array $calls = [];
    public ?array $record = null;
    public bool $failRead = false;
    public bool $interrupt = false;

    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        return $this->poll($state, 'mes_production_orders')
            ? null : \NaiveWorkflow\Jump::to(20);
    }

    public function initialState(): \NaiveWorkflow\WorkflowState
    {
        return new \NaiveWorkflow\MesObserve\State([
            'config' => $this->configuration()->toArray(),
            'deadline' => time() + 30,
            'baseline' => ['mes_production_orders' => [
                'unit_id' => 'UNIT-TEST',
                'row_version' => '9007199254740992',
                'status' => 'existing',
            ]],
            'observed' => [],
        ]);
    }

    protected function readRecords(array $tables, string $id): array
    {
        $this->calls[] = [$tables, $id];
        if ($this->failRead) {
            throw new RuntimeException('Injected read failure');
        }
        if ($this->interrupt) {
            posix_kill(getmypid(), SIGTERM);
        }
        return [$tables[0] => $this->record];
    }
}

$observer = new TestObserver(__DIR__);
$async = pcntl_async_signals();
$original = [];
$handler = static function (): void {};
foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
    $original[$signal] = pcntl_signal_get_handler($signal);
    pcntl_signal($signal, $handler);
}
pcntl_async_signals(false);
try {
    foreach (['unchanged', 'changed', 'regression', 'read-failure', 'interrupted'] as $mode) {
        $state = $observer->initialState();
        $before = $state->toArray();
        $observer->record = $state->baseline->production->toArray();
        $observer->failRead = $mode === 'read-failure';
        $observer->interrupt = $mode === 'interrupted';

        if ($mode === 'changed') {
            $observer->record['row_version'] = '9007199254740993';
        } elseif ($mode === 'regression') {
            $observer->record['row_version'] = '9007199254740991';
        }

        $start = hrtime(true);
        if (in_array($mode, ['regression', 'read-failure', 'interrupted'], true)) {
            $expected = [
                'regression' => 'row_version regressed',
                'read-failure' => 'Injected read failure',
                'interrupted' => 'Observation interrupted',
            ][$mode];
            failure(function () use ($observer, &$state): void {
                $observer->run($state);
            }, $expected);
            check($state->toArray() === $before, 'Failed poll mutated state');
        } else {
            $result = $observer->run($state);
            if ($mode === 'unchanged') {
                check($result instanceof \NaiveWorkflow\Jump && $result->sequence === '20', 'Missing self-branch');
                check(hrtime(true) - $start >= 1_000_000_000, 'Unchanged poll did not wait');
                check($state->toArray() === $before, 'Unchanged poll mutated state');
            } else {
                check($result === null, 'Changed poll did not continue');
                check($state->observed->production->record->toArray() === $observer->record,
                      'Changed record not preserved');
            }
        }

        check(pcntl_async_signals() === false, 'Async signal setting leaked');
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            check(pcntl_signal_get_handler($signal) === $handler, 'Signal handler leaked');
        }
    }

    check(count($observer->calls) === 5, 'Unexpected query count');
    foreach ($observer->calls as [$tables, $id]) {
        check($tables === ['mes_production_orders'] && $id === 'UNIT-TEST',
              'Wrong table or manufacturing unit');
    }

    $state = $observer->initialState();
    $observer->interrupt = false;
    putenv('MES_UNIT_ID=OTHER-UNIT');
    failure(function () use ($observer, &$state): void {
        $observer->run($state);
    }, 'Configuration differs from checkpoint');
    check(count($observer->calls) === 5, 'Changed configuration queried records');
    putenv('MES_UNIT_ID=UNIT-TEST');

    $state->deadline = time() - 1;
    failure(function () use ($observer, &$state): void {
        $observer->run($state);
    }, 'Observation deadline expired');
    check(count($observer->calls) === 5, 'Expired observation queried records');

    check(mes_changed(null, ['row_version' => '1']), 'Insert not recognized');
    check(!mes_changed(['row_version' => '1'], null), 'Deletion treated as upsert');
    check(mes_compare('18446744073709551615', '9007199254740993') > 0,
          'Large decimal comparison failed');
    foreach (['0', '-1', '01', '1e3', '1.0', ''] as $invalid) {
        failure(static function () use ($invalid): void {
            mes_version($invalid);
        }, 'Expected a positive');
    }
} finally {
    foreach ($original as $signal => $previous) {
        pcntl_signal($signal, $previous);
    }
    pcntl_async_signals($async);
}
''')
    env = dict(os.environ, MES_UNIT_ID="UNIT-TEST", MES_POLL_SECONDS="1",
               MES_TIMEOUT_SECONDS="30", MES_MYSQL_DSN="mysql:host=fixture;dbname=fixture",
               MES_MYSQL_USER="fixture", MES_MYSQL_PASSWORD="")
    result = subprocess.run(
        ["php", str(root / "check.php")], env=env,
        text=True, capture_output=True, timeout=10,
    )
    assert result.returncode == 0, result.stderr
    assert result.stdout == "", result.stdout
    assert "Warning:" not in result.stderr and "Fatal error:" not in result.stderr, result.stderr

print("PASS: MES polling hooks, paced branches, large revisions, read failures, configuration/deadline guards and signal restoration")
print("Live MySQL integration was not run.")
PYTEST
