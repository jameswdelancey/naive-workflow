<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/log.php';
require_once dirname(__DIR__, 2) . '/state.php';

function mes_integer(string $name, int $default, int $maximum, ?string &$error): ?int
{
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }
    if (!preg_match('/^[1-9][0-9]*$/D', $raw) ||
        strlen($raw) > 6 || (int) $raw > $maximum) {
        $error = "Invalid $name";
        return null;
    }
    return (int) $raw;
}

// Capture configuration for resume checks.
function mes_config(?string &$error = null): ?array
{
    $error = null;
    $id = getenv('MES_UNIT_ID');
    if ($id === false || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
        $error = 'Set MES_UNIT_ID to a 1-64 character manufacturing unit identifier';
        return null;
    }
    $interval = mes_integer('MES_POLL_SECONDS', 5, 60, $error);
    if ($error !== null) {
        return null;
    }
    $timeout = mes_integer('MES_TIMEOUT_SECONDS', 3600, 86400, $error);
    if ($error !== null) {
        return null;
    }
    $dsn = getenv('MES_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=mes_demo;charset=utf8mb4';
    if (!str_starts_with($dsn, 'mysql:')) {
        $error = 'MES_MYSQL_DSN must use mysql';
        return null;
    }
    $identity = $dsn . "\0" . (getenv('MES_MYSQL_USER') ?: 'mes_observer');
    foreach (['MES_MYSQL_SSL_CA', 'MES_MYSQL_SSL_CERT', 'MES_MYSQL_SSL_KEY'] as $name) {
        $identity .= "\0" . (getenv($name) ?: '');
    }
    return [
        'unit_id' => $id,
        'interval' => $interval,
        'timeout' => $timeout,
        'database_identity' => hash('sha256', $identity),
    ];
}

// Reject configuration changes and expired observations.
function mes_check(array $state): ?string
{
    $config = mes_config($error);
    if ($error !== null) {
        return $error;
    }
    if (($state['config'] ?? null) !== $config) {
        return 'Configuration differs from checkpoint; restore the original configuration';
    }
    if (!is_int($state['deadline'] ?? null) || time() >= $state['deadline']) {
        return 'Observation deadline expired; checkpoint retained for inspection';
    }
    return null;
}

function mes_version(string $version): bool
{
    return preg_match('/^[1-9][0-9]{0,19}$/D', $version) === 1;
}

// Compare decimal revisions without integer conversion.
function mes_compare(string $left, string $right): int
{
    return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
}

// Accept inserts or newer revisions; reject regressions.
function mes_changed(?array $baseline, ?array $current, ?string &$error = null): bool
{
    $error = null;
    if ($current === null) {
        return false;
    }
    if (!is_string($current['row_version'] ?? null) ||
        !mes_version($current['row_version']) ||
        ($baseline !== null &&
            (!is_string($baseline['row_version'] ?? null) ||
             !mes_version($baseline['row_version'])))) {
        $error = 'Expected a positive, monotonically increasing decimal row_version';
        return false;
    }
    if ($baseline === null) {
        return true;
    }
    $comparison = mes_compare($current['row_version'], $baseline['row_version']);
    if ($comparison < 0) {
        $error = 'row_version regressed; identifiers and revision counters must not be reset';
        return false;
    }
    return $comparison > 0;
}

// Defer interruption handling to normal control flow.
function mes_signal(int $signal): void
{
    $GLOBALS['mesStop'] = true;
}

// Save handlers before installing observation handlers.
function mes_begin(array &$handlers, bool &$async): ?string
{
    $handlers = [];
    $async = false;
    foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
        if (!function_exists($function)) {
            return "Requires $function";
        }
    }
    $GLOBALS['mesStop'] = false;
    $async = pcntl_async_signals();
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        $handlers[$signal] = pcntl_signal_get_handler($signal);
        if (!pcntl_signal($signal, 'mes_signal')) {
            return 'Cannot install observation signal handler';
        }
    }
    pcntl_async_signals(true);
    return null;
}

// Restore process-global signal settings.
function mes_end(array $handlers, bool $async): void
{
    foreach ($handlers as $signal => $handler) {
        pcntl_signal($signal, $handler);
    }
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals($async);
    }
}

function mes_check_work(array $state): ?string
{
    if ($GLOBALS['mesStop'] ?? false) {
        return 'Observation interrupted; resume by running the same workflow';
    }
    return mes_check($state);
}

// Capture both baselines in one database snapshot.
function mes_baseline(array &$state): ?string
{
    require_once __DIR__ . '/records.php';
    $handlers = [];
    $async = false;
    $error = mes_begin($handlers, $async);
    if ($error === null) {
        $error = mes_check_work($state);
    }
    if ($error === null) {
        $records = mes_read_records(
            ['mes_production_orders', 'mes_quality_checks'],
            $state['config']['unit_id'],
            $error
        );
        if ($error === null && !is_array($records)) {
            $error = 'Cannot read baseline records';
        }
    }
    if ($error === null) {
        $error = mes_check_work($state);
    }
    if ($error === null) {
        $updated = $state;
        $error = workflow_state_set($updated, 'baseline', $records, 'add');
        if ($error === null) {
            $error = workflow_state_set($updated, 'armed_at', date('c'), 'add');
        }
        if ($error === null) {
            $state = $updated;
            workflow_log_context('mes-observe', 'Baselines captured; waiting for a production-order upsert, then a quality-check upsert');
        }
    }
    mes_end($handlers, $async);
    return $error;
}

// Observe once; pace unchanged records with a self-branch.
function mes_poll(array &$state, string $table, int $sequence): ?string
{
    require_once __DIR__ . '/records.php';
    $handlers = [];
    $async = false;
    $error = mes_begin($handlers, $async);
    if ($error === null) {
        $error = mes_check_work($state);
    }
    if ($error === null && !array_key_exists($table, $state['baseline'] ?? [])) {
        $error = "Missing baseline for $table";
    }
    if ($error === null) {
        $id = $state['config']['unit_id'];
        $records = mes_read_records([$table], $id, $error);
        if ($error === null &&
            (!is_array($records) || !array_key_exists($table, $records))) {
            $error = "Missing record result for $table";
        }
    }
    if ($error === null) {
        $error = mes_check_work($state);
    }
    if ($error === null) {
        $current = $records[$table];
        $changed = mes_changed($state['baseline'][$table], $current, $error);
    }
    if ($error === null && $changed) {
        $error = workflow_state_set($state, ['observed', $table], [
            'record' => $current,
            'observed_at' => date('c'),
        ], 'add');
        if ($error === null) {
            workflow_log_context('mes-observe', "$table: observed $id at revision {$current['row_version']}");
        }
    } elseif ($error === null) {
        $until = hrtime(true) + $state['config']['interval'] * 1_000_000_000;
        while (hrtime(true) < $until) {
            $error = mes_check_work($state);
            if ($error !== null) {
                break;
            }
            usleep(100_000);
        }
        if ($error === null) {
            $error = mes_check_work($state);
        }
        if ($error === null) {
            $GLOBALS['nextSequence'] = $sequence;
        }
    }
    if ($error === null) {
        $error = mes_check_work($state);
    }
    mes_end($handlers, $async);
    return $error;
}
