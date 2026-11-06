<?php
declare(strict_types=1);

namespace NaiveWorkflow\MesObserve;

use InvalidArgumentException;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/State.php';

/**
 * Shared observation configuration, deadline, signal scope and paced polling.
 * No automatic failure retries, database writes or child processes.
 */
abstract class ObservationNode extends \NaiveWorkflow\Node
{
    /**
     * Adapter boundary: tests and specialized observers may override this.
     * The production adapter opens a fresh read-only transaction on each call.
     */
    protected function readRecords(array $tables, string $id): array
    {
        require_once __DIR__ . '/records.php';
        return \mes_read_records($tables, $id);
    }

    protected function readSnapshot(array $tables, string $id): Baseline
    {
        $snapshot = new Baseline($this->readRecords($tables, $id));
        foreach ($tables as $table) {
            $record = $snapshot->record($table);
            if ($record !== null && $record->unitId !== $id) {
                throw new RuntimeException('Record belongs to a different manufacturing unit');
            }
        }
        return $snapshot;
    }

    protected function configuration(): Configuration
    {
        $id = getenv('MES_UNIT_ID');
        if ($id === false || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            throw new InvalidArgumentException('Set MES_UNIT_ID to a 1-64 character manufacturing unit identifier');
        }
        $integer = static function (string $name, int $default, int $maximum): int {
            $raw = getenv($name);
            if ($raw === false) {
                return $default;
            }
            if (!preg_match('/^[1-9][0-9]*$/D', $raw) || strlen($raw) > 6 ||
                (int) $raw > $maximum) {
                throw new InvalidArgumentException("Invalid $name");
            }
            return (int) $raw;
        };
        $dsn = getenv('MES_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=mes_demo;charset=utf8mb4';
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new InvalidArgumentException('MES_MYSQL_DSN must use mysql');
        }
        return new Configuration([
            'unit_id' => $id,
            'interval' => $integer('MES_POLL_SECONDS', 5, 60),
            'timeout' => $integer('MES_TIMEOUT_SECONDS', 3600, 86400),
            'database_identity' => hash('sha256', $dsn . "\0" . (getenv('MES_MYSQL_USER') ?: 'mes_observer')),
        ]);
    }

    protected function checkObservation(\NaiveWorkflow\WorkflowState $state): void
    {
        $state = State::requireState($state);
        if (!isset($state->config) || $state->config->toArray() !== $this->configuration()->toArray()) {
            throw new RuntimeException('Configuration differs from checkpoint; restore the original configuration');
        }
        if (!isset($state->deadline) || time() >= $state->deadline) {
            throw new RuntimeException('Observation deadline expired; checkpoint retained for inspection');
        }
    }

    protected function interruptible(callable $work): mixed
    {
        foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException("Requires $function");
            }
        }
        $stop = false;
        $handlers = [];
        $async = pcntl_async_signals();
        try {
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
            pcntl_async_signals(true);
            $check = static function () use (&$stop): void {
                if ($stop) {
                    throw new RuntimeException('Observation interrupted; resume by running the same workflow');
                }
            };
            $check();
            $result = $work($check);
            $check();
            return $result;
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($async);
        }
    }

    protected function poll(\NaiveWorkflow\WorkflowState $state, string $table): bool
    {
        $state = State::requireState($state);
        return $this->interruptible(function (callable $check) use ($state, $table): bool {
            $this->checkObservation($state);
            $id = $state->config->unitId;
            $current = $this->readSnapshot([$table], $id)->record($table);
            $check();
            $this->checkObservation($state);
            if (mes_changed($state->baseline->record($table)?->toArray(), $current?->toArray())) {
                $state->observed->observe($table, new Observation([
                    'record' => $current->toArray(),
                    'observed_at' => date('c'),
                ]));
                $this->log("$table: observed $id at revision {$current->rowVersion}");
                return true;
            }

            // No work is a normal branch, not an automatic failure retry.
            $until = hrtime(true) + $state->config->interval * 1_000_000_000;
            while (hrtime(true) < $until) {
                $check();
                $this->checkObservation($state);
                usleep(100_000);
            }
            $check();
            $this->checkObservation($state);
            return false;
        });
    }
}
