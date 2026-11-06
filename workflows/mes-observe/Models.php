<?php
declare(strict_types=1);

namespace NaiveWorkflow\MesObserve;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/common.php';

final class Configuration extends \NaiveWorkflow\WorkflowState
{
    public string $unitId;
    public int $interval;
    public int $timeout;
    public string $databaseIdentity;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['unit_id', 'interval', 'timeout', 'database_identity']);
        if (!array_key_exists('unit_id', $values)) {
            throw new \InvalidArgumentException('Missing Configuration.unit_id');
        }
        if (array_key_exists('unit_id', $values)) {
            if (!is_string($values['unit_id'])) {
                throw new \InvalidArgumentException('Invalid Configuration.unit_id');
            }
            $this->unitId = $values['unit_id'];
        }
        if (!array_key_exists('interval', $values)) {
            throw new \InvalidArgumentException('Missing Configuration.interval');
        }
        if (array_key_exists('interval', $values)) {
            if (!is_int($values['interval'])) {
                throw new \InvalidArgumentException('Invalid Configuration.interval');
            }
            $this->interval = $values['interval'];
        }
        if (!array_key_exists('timeout', $values)) {
            throw new \InvalidArgumentException('Missing Configuration.timeout');
        }
        if (array_key_exists('timeout', $values)) {
            if (!is_int($values['timeout'])) {
                throw new \InvalidArgumentException('Invalid Configuration.timeout');
            }
            $this->timeout = $values['timeout'];
        }
        if (!array_key_exists('database_identity', $values)) {
            throw new \InvalidArgumentException('Missing Configuration.database_identity');
        }
        if (array_key_exists('database_identity', $values)) {
            if (!is_string($values['database_identity'])) {
                throw new \InvalidArgumentException('Invalid Configuration.database_identity');
            }
            $this->databaseIdentity = $values['database_identity'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $this->unitId) ||
            $this->interval < 1 || $this->interval > 60 ||
            $this->timeout < 1 || $this->timeout > 86400 ||
            !preg_match('/^[a-f0-9]{64}$/D', $this->databaseIdentity)) {
            throw new \InvalidArgumentException('Invalid MES configuration values');
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->unitId)) {
            $values['unit_id'] = $this->unitId;
        }
        if (isset($this->interval)) {
            $values['interval'] = $this->interval;
        }
        if (isset($this->timeout)) {
            $values['timeout'] = $this->timeout;
        }
        if (isset($this->databaseIdentity)) {
            $values['database_identity'] = $this->databaseIdentity;
        }
        return $values;
    }
}

final class Record extends \NaiveWorkflow\WorkflowState
{
    public string $unitId;
    public string $rowVersion;
    public string $status;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['unit_id', 'row_version', 'status']);
        if (!array_key_exists('unit_id', $values)) {
            throw new \InvalidArgumentException('Missing Record.unit_id');
        }
        if (array_key_exists('unit_id', $values)) {
            if (!is_string($values['unit_id'])) {
                throw new \InvalidArgumentException('Invalid Record.unit_id');
            }
            $this->unitId = $values['unit_id'];
        }
        if (!array_key_exists('row_version', $values)) {
            throw new \InvalidArgumentException('Missing Record.row_version');
        }
        if (array_key_exists('row_version', $values)) {
            if (!is_string($values['row_version'])) {
                throw new \InvalidArgumentException('Invalid Record.row_version');
            }
            $this->rowVersion = $values['row_version'];
        }
        if (!array_key_exists('status', $values)) {
            throw new \InvalidArgumentException('Missing Record.status');
        }
        if (array_key_exists('status', $values)) {
            if (!is_string($values['status'])) {
                throw new \InvalidArgumentException('Invalid Record.status');
            }
            $this->status = $values['status'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $this->unitId)) {
            throw new \InvalidArgumentException('Invalid MES record unit');
        }
        \mes_version($this->rowVersion);
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->unitId)) {
            $values['unit_id'] = $this->unitId;
        }
        if (isset($this->rowVersion)) {
            $values['row_version'] = $this->rowVersion;
        }
        if (isset($this->status)) {
            $values['status'] = $this->status;
        }
        return $values;
    }
}

final class Observation extends \NaiveWorkflow\WorkflowState
{
    public Record $record;
    public string $observedAt;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['record', 'observed_at']);
        if (!array_key_exists('record', $values)) {
            throw new \InvalidArgumentException('Missing Observation.record');
        }
        if (array_key_exists('record', $values)) {
            if (!is_array($values['record'])) {
                throw new \InvalidArgumentException('Invalid Observation.record');
            }
            $this->record = new Record($values['record']);
        }
        if (!array_key_exists('observed_at', $values)) {
            throw new \InvalidArgumentException('Missing Observation.observed_at');
        }
        if (array_key_exists('observed_at', $values)) {
            if (!is_string($values['observed_at'])) {
                throw new \InvalidArgumentException('Invalid Observation.observed_at');
            }
            $this->observedAt = $values['observed_at'];
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->record)) {
            $values['record'] = $this->record->toArray();
        }
        if (isset($this->observedAt)) {
            $values['observed_at'] = $this->observedAt;
        }
        return $values;
    }

    public function __clone(): void
    {
        if (isset($this->record)) {
            $this->record = clone $this->record;
        }
    }
}

final class Baseline extends \NaiveWorkflow\WorkflowState
{
    public ?Record $production;
    public ?Record $quality;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['mes_production_orders', 'mes_quality_checks']);
        if (array_key_exists('mes_production_orders', $values)) {
            if ($values['mes_production_orders'] !== null && !is_array($values['mes_production_orders'])) {
                throw new \InvalidArgumentException('Invalid Baseline.mes_production_orders');
            }
            $this->production = $values['mes_production_orders'] === null ? null : new Record($values['mes_production_orders']);
        }
        if (array_key_exists('mes_quality_checks', $values)) {
            if ($values['mes_quality_checks'] !== null && !is_array($values['mes_quality_checks'])) {
                throw new \InvalidArgumentException('Invalid Baseline.mes_quality_checks');
            }
            $this->quality = $values['mes_quality_checks'] === null ? null : new Record($values['mes_quality_checks']);
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (array_key_exists('production', get_object_vars($this))) {
            $values['mes_production_orders'] = $this->production?->toArray();
        }
        if (array_key_exists('quality', get_object_vars($this))) {
            $values['mes_quality_checks'] = $this->quality?->toArray();
        }
        return $values;
    }

    public function __clone(): void
    {
        if (isset($this->production)) {
            $this->production = clone $this->production;
        }
        if (isset($this->quality)) {
            $this->quality = clone $this->quality;
        }
    }

    public function record(string $table): ?Record
    {
        return match ($table) {
            'mes_production_orders' => $this->production,
            'mes_quality_checks' => $this->quality,
            default => throw new \InvalidArgumentException('Unknown manufacturing record type'),
        };
    }
}

final class Observations extends \NaiveWorkflow\WorkflowState
{
    public Observation $production;
    public Observation $quality;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['mes_production_orders', 'mes_quality_checks']);
        if (array_key_exists('mes_production_orders', $values)) {
            if (!is_array($values['mes_production_orders'])) {
                throw new \InvalidArgumentException('Invalid Observations.mes_production_orders');
            }
            $this->production = new Observation($values['mes_production_orders']);
        }
        if (array_key_exists('mes_quality_checks', $values)) {
            if (!is_array($values['mes_quality_checks'])) {
                throw new \InvalidArgumentException('Invalid Observations.mes_quality_checks');
            }
            $this->quality = new Observation($values['mes_quality_checks']);
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->production)) {
            $values['mes_production_orders'] = $this->production->toArray();
        }
        if (isset($this->quality)) {
            $values['mes_quality_checks'] = $this->quality->toArray();
        }
        return $values;
    }

    public function __clone(): void
    {
        if (isset($this->production)) {
            $this->production = clone $this->production;
        }
        if (isset($this->quality)) {
            $this->quality = clone $this->quality;
        }
    }

    public function record(string $table): ?Observation
    {
        return match ($table) {
            'mes_production_orders' => $this->production ?? null,
            'mes_quality_checks' => $this->quality ?? null,
            default => throw new \InvalidArgumentException('Unknown manufacturing record type'),
        };
    }

    public function observe(string $table, Observation $observation): void
    {
        match ($table) {
            'mes_production_orders' => $this->production = $observation,
            'mes_quality_checks' => $this->quality = $observation,
            default => throw new \InvalidArgumentException('Unknown manufacturing record type'),
        };
    }
}
