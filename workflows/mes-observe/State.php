<?php
declare(strict_types=1);

namespace NaiveWorkflow\MesObserve;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/common.php';

require_once __DIR__ . '/Models.php';

final class State extends \NaiveWorkflow\WorkflowState
{
    public Configuration $config;
    public int $deadline;
    public Baseline $baseline;
    public Observations $observed;
    public string $armedAt;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['config', 'deadline', 'baseline', 'observed', 'armed_at']);
        if (array_key_exists('config', $values)) {
            if (!is_array($values['config'])) {
                throw new \InvalidArgumentException('Invalid State.config');
            }
            $this->config = new Configuration($values['config']);
        }
        if (array_key_exists('deadline', $values)) {
            if (!is_int($values['deadline'])) {
                throw new \InvalidArgumentException('Invalid State.deadline');
            }
            $this->deadline = $values['deadline'];
        }
        if (array_key_exists('baseline', $values)) {
            if (!is_array($values['baseline'])) {
                throw new \InvalidArgumentException('Invalid State.baseline');
            }
            $this->baseline = new Baseline($values['baseline']);
        }
        if (array_key_exists('observed', $values)) {
            if (!is_array($values['observed'])) {
                throw new \InvalidArgumentException('Invalid State.observed');
            }
            $this->observed = new Observations($values['observed']);
        }
        if (array_key_exists('armed_at', $values)) {
            if (!is_string($values['armed_at'])) {
                throw new \InvalidArgumentException('Invalid State.armed_at');
            }
            $this->armedAt = $values['armed_at'];
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->config)) {
            $values['config'] = $this->config->toArray();
        }
        if (isset($this->deadline)) {
            $values['deadline'] = $this->deadline;
        }
        if (isset($this->baseline)) {
            $values['baseline'] = $this->baseline->toArray();
        }
        if (isset($this->observed)) {
            $values['observed'] = $this->observed->toArray();
        }
        if (isset($this->armedAt)) {
            $values['armed_at'] = $this->armedAt;
        }
        return $values;
    }

    public function __clone(): void
    {
        if (isset($this->config)) {
            $this->config = clone $this->config;
        }
        if (isset($this->baseline)) {
            $this->baseline = clone $this->baseline;
        }
        if (isset($this->observed)) {
            $this->observed = clone $this->observed;
        }
    }
}
