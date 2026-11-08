<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/log.php';

// Procedural mysqli API. Database handles remain local and never enter state.
function mes_read_records(array $tables, string $id, ?string &$error = null): ?array
{
    $error = null;
    foreach ($tables as $table) {
        if (!in_array($table, ['mes_production_orders', 'mes_quality_checks'], true)) {
            $error = 'Unknown manufacturing record type';
            return null;
        }
    }
    if (!extension_loaded('mysqli') || !extension_loaded('mysqlnd')) {
        $error = 'Requires mysqli and mysqlnd; install php-mysql';
        return null;
    }

    $dsn = getenv('MES_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=mes_demo;charset=utf8mb4';
    if (!str_starts_with($dsn, 'mysql:')) {
        $error = 'MES_MYSQL_DSN must use mysql';
        return null;
    }
    // Accept only supported connection fields.
    $options = ['host' => '127.0.0.1', 'dbname' => 'mes_demo', 'charset' => 'utf8mb4', 'port' => '3306'];
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if ($part === '') {
            continue;
        }
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2 ||
            !in_array($pair[0], ['host', 'dbname', 'charset', 'port', 'unix_socket'], true) ||
            str_contains($pair[1], "\0")) {
            $error = 'Unsupported MES_MYSQL_DSN field';
            return null;
        }
        $options[$pair[0]] = $pair[1];
    }
    if (!preg_match('/^[0-9]{1,5}$/D', $options['port']) ||
        (int) $options['port'] < 1 || (int) $options['port'] > 65535 ||
        str_starts_with($options['host'], 'p:')) {
        $error = 'Invalid MySQL port or persistent host';
        return null;
    }

    // This CLI adapter deliberately keeps exception reporting disabled.
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    if ($db === false) {
        $error = 'Cannot initialize MySQL';
        return null;
    }
    if (!mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 5) ||
        !mysqli_options($db, MYSQLI_OPT_READ_TIMEOUT, 5)) {
        mysqli_close($db);
        $error = 'Cannot configure MySQL timeouts';
        return null;
    }

    // A configured CA enables verified TLS.
    $ca = getenv('MES_MYSQL_SSL_CA') ?: null;
    $cert = getenv('MES_MYSQL_SSL_CERT') ?: null;
    $key = getenv('MES_MYSQL_SSL_KEY') ?: null;
    $flags = 0;
    if (($cert !== null || $key !== null) && $ca === null) {
        mysqli_close($db);
        $error = 'Client TLS credentials require MES_MYSQL_SSL_CA';
        return null;
    }
    if ($ca !== null) {
        if (!mysqli_ssl_set($db, $key, $cert, $ca, null, null) ||
            !mysqli_options($db, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true)) {
            mysqli_close($db);
            $error = 'Cannot configure verified MySQL TLS';
            return null;
        }
        $flags = MYSQLI_CLIENT_SSL;
    }

    if (!@mysqli_real_connect(
        $db,
        $options['host'],
        getenv('MES_MYSQL_USER') ?: 'mes_observer',
        getenv('MES_MYSQL_PASSWORD') ?: '',
        $options['dbname'],
        (int) $options['port'],
        $options['unix_socket'] ?? null,
        $flags
    )) {
        $error = workflow_error('connect MySQL', 'MySQL connection failed (code ' . mysqli_connect_errno() . ')');
        mysqli_close($db);
        return null;
    }

    if (!mysqli_set_charset($db, $options['charset'])) {
        $error = workflow_error('set MySQL charset', mysqli_error($db));
    }
    // Share one read-only snapshot across requested tables.
    $transaction = false;
    if ($error === null) {
        foreach ([
            'SET SESSION max_execution_time = 2000',
            'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'SET SESSION TRANSACTION READ ONLY',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
        ] as $sql) {
            if (!mysqli_query($db, $sql)) {
                $error = workflow_error('start MySQL snapshot', mysqli_error($db));
                break;
            }
        }
        $transaction = $error === null;
    }

    $records = [];
    if ($error === null) {
        foreach ($tables as $table) {
            // Allowlist table names; bind the unit identifier.
            $statement = mysqli_prepare(
                $db,
                'SELECT unit_id, CAST(row_version AS CHAR) AS row_version, status FROM `' .
                $table . '` WHERE unit_id = ? LIMIT 1'
            );
            if ($statement === false) {
                $error = workflow_error("prepare record read ($table)", mysqli_error($db));
                break;
            }
            if (!mysqli_stmt_bind_param($statement, 's', $id) ||
                !mysqli_stmt_execute($statement)) {
                $error = workflow_error("execute record read ($table)", mysqli_stmt_error($statement));
                mysqli_stmt_close($statement);
                break;
            }
            $result = mysqli_stmt_get_result($statement);
            if ($result === false) {
                $error = workflow_error("fetch record ($table)", mysqli_stmt_error($statement));
                mysqli_stmt_close($statement);
                break;
            }
            $row = mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            mysqli_stmt_close($statement);
            if ($row === false) {
                $error = 'Cannot fetch record row';
                break;
            }
            if ($row !== null &&
                (!is_string($row['row_version'] ?? null) || !mes_version($row['row_version']))) {
                $error = 'Expected a positive, monotonically increasing decimal row_version';
                break;
            }
            $records[$table] = $row;
        }
    }

    if ($error === null && !mysqli_commit($db)) {
        $error = workflow_error('commit MySQL snapshot', mysqli_error($db));
    }
    // Roll back failed reads before closing.
    if ($error !== null && $transaction) {
        mysqli_rollback($db);
    }
    mysqli_close($db);
    return $error === null ? $records : null;
}
