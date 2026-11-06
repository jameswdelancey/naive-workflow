<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

/** Explicit test-only schema; not loaded by the production bootstrap. */
final class TestState extends \NaiveWorkflow\WorkflowState
{
    public int $value;
    public bool $retried;
    public bool $discarded;
    public bool $isolated;
    public bool $ok;
    public bool $unchanged;
    public int $attempts;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['value', 'retried', 'discarded', 'isolated', 'ok', 'unchanged', 'attempts']);
        if (array_key_exists('value', $values)) {
            if (!is_int($values['value'])) {
                throw new \InvalidArgumentException('Invalid test field: value');
            }
            $this->value = $values['value'];
        }
        if (array_key_exists('retried', $values)) {
            if (!is_bool($values['retried'])) {
                throw new \InvalidArgumentException('Invalid test field: retried');
            }
            $this->retried = $values['retried'];
        }
        if (array_key_exists('discarded', $values)) {
            if (!is_bool($values['discarded'])) {
                throw new \InvalidArgumentException('Invalid test field: discarded');
            }
            $this->discarded = $values['discarded'];
        }
        if (array_key_exists('isolated', $values)) {
            if (!is_bool($values['isolated'])) {
                throw new \InvalidArgumentException('Invalid test field: isolated');
            }
            $this->isolated = $values['isolated'];
        }
        if (array_key_exists('ok', $values)) {
            if (!is_bool($values['ok'])) {
                throw new \InvalidArgumentException('Invalid test field: ok');
            }
            $this->ok = $values['ok'];
        }
        if (array_key_exists('unchanged', $values)) {
            if (!is_bool($values['unchanged'])) {
                throw new \InvalidArgumentException('Invalid test field: unchanged');
            }
            $this->unchanged = $values['unchanged'];
        }
        if (array_key_exists('attempts', $values)) {
            if (!is_int($values['attempts'])) {
                throw new \InvalidArgumentException('Invalid test field: attempts');
            }
            $this->attempts = $values['attempts'];
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->value)) {
            $values['value'] = $this->value;
        }
        if (isset($this->retried)) {
            $values['retried'] = $this->retried;
        }
        if (isset($this->discarded)) {
            $values['discarded'] = $this->discarded;
        }
        if (isset($this->isolated)) {
            $values['isolated'] = $this->isolated;
        }
        if (isset($this->ok)) {
            $values['ok'] = $this->ok;
        }
        if (isset($this->unchanged)) {
            $values['unchanged'] = $this->unchanged;
        }
        if (isset($this->attempts)) {
            $values['attempts'] = $this->attempts;
        }
        return $values;
    }
}
