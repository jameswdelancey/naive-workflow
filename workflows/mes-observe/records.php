<?php
declare(strict_types=1);

// Minimal read-only Active Record mapping; no third-party ORM is assumed.
abstract class MesActiveRecord
{
    protected const TABLE = '';

    final public static function find(PDO $db, string $id): ?array
    {
        // TABLE is a code-defined constant, never an environment/user identifier.
        $statement = $db->prepare(
            'SELECT unit_id, CAST(row_version AS CHAR) AS row_version, status FROM `' .
            static::TABLE . '` WHERE unit_id = :id LIMIT 1'
        );
        try {
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            mes_version($row['row_version']);
            return $row;
        } finally {
            $statement->closeCursor();
        }
    }
}

final class MesProductionOrder extends MesActiveRecord
{
    protected const TABLE = 'mes_production_orders';
}

final class MesQualityCheck extends MesActiveRecord
{
    protected const TABLE = 'mes_quality_checks';
}

function mes_read_records(array $tables, string $id): array
{
    $models = [
        'mes_production_orders' => MesProductionOrder::class,
        'mes_quality_checks' => MesQualityCheck::class,
    ];
    foreach ($tables as $table) {
        if (!isset($models[$table])) {
            throw new InvalidArgumentException('Unknown manufacturing record type');
        }
    }
    if (!extension_loaded('pdo_mysql') || !extension_loaded('mysqlnd')) {
        throw new RuntimeException('Requires pdo_mysql and mysqlnd; install php-mysql');
    }

    $previousTimeout = ini_set('mysqlnd.net_read_timeout', '5');
    if ($previousTimeout === false) {
        throw new RuntimeException('Cannot configure mysqlnd network read timeout');
    }
    $db = null;
    try {
        $db = new PDO(
            getenv('MES_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=mes_demo;charset=utf8mb4',
            getenv('MES_MYSQL_USER') ?: 'mes_observer',
            getenv('MES_MYSQL_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        // Target MySQL 8.x, not MariaDB. The account must also be SELECT-only.
        $db->exec('SET SESSION max_execution_time = 2000');
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        $db->beginTransaction();

        $records = [];
        foreach ($tables as $table) {
            $records[$table] = $models[$table]::find($db, $id);
        }
        $db->commit();
        return $records;
    } finally {
        try {
            if ($db !== null && $db->inTransaction()) {
                $db->rollBack();
            }
        } finally {
            $db = null;
            ini_set('mysqlnd.net_read_timeout', $previousTimeout);
        }
    }
}
