<?php
declare(strict_types=1);

function mes_log(string $message): void
{
    fwrite(STDERR, '[' . date('c') . '] ' . $message . "\n");
}

function mes_config(): array
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
    return [
        'unit_id' => $id,
        'interval' => $integer('MES_POLL_SECONDS', 5, 60),
        'timeout' => $integer('MES_TIMEOUT_SECONDS', 3600, 86400),
        'database_identity' => hash('sha256', $dsn . "\0" . (getenv('MES_MYSQL_USER') ?: 'mes_observer')),
    ];
}

function mes_check(array $state): void
{
    if (($state['config'] ?? null) !== mes_config()) {
        throw new RuntimeException('Configuration differs from checkpoint; restore the original configuration');
    }
    if (!is_int($state['deadline'] ?? null) || time() >= $state['deadline']) {
        throw new RuntimeException('Observation deadline expired; checkpoint retained for inspection');
    }
}

function mes_version(string $version): string
{
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $version)) {
        throw new RuntimeException('Expected a positive, monotonically increasing decimal row_version');
    }
    return $version;
}

function mes_compare(string $left, string $right): int
{
    mes_version($left);
    mes_version($right);
    return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
}

function mes_changed(?array $baseline, ?array $current): bool
{
    if ($current === null) {
        return false;
    }
    mes_version($current['row_version']);
    if ($baseline === null) {
        return true;
    }
    $comparison = mes_compare($current['row_version'], $baseline['row_version']);
    if ($comparison < 0) {
        throw new RuntimeException('row_version regressed; identifiers and revision counters must not be reset');
    }
    return $comparison > 0;
}

// Signal handlers are scoped to database work and interruptible waits.
// Interruption is an ordinary failure, retaining the runner's checkpoint.
function mes_interruptible(callable $work): void
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
        $work($check);
        $check();
    } finally {
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        pcntl_async_signals($async);
    }
}

function mes_poll(array &$state, string $table, int $sequence): void
{
    require_once __DIR__ . '/records.php';
    mes_interruptible(static function (callable $check) use (&$state, $table, $sequence): void {
        mes_check($state);
        $id = $state['config']['unit_id'];
        $current = mes_read_records([$table], $id)[$table];
        $check();
        mes_check($state);
        if (mes_changed($state['baseline'][$table], $current)) {
            $state['observed'][$table] = [
                'record' => $current,
                'observed_at' => date('c'),
            ];
            mes_log("$table: observed $id at revision {$current['row_version']}");
            return;
        }

        // No work is a normal branch, not an automatic failure retry.
        $until = hrtime(true) + $state['config']['interval'] * 1_000_000_000;
        while (hrtime(true) < $until) {
            $check();
            mes_check($state);
            usleep(100_000);
        }
        $check();
        mes_check($state);
        $GLOBALS['nextSequence'] = $sequence;
    });
}
