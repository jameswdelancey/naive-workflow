<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** Fixed hello schema. Uninitialized fields retain checkpoint absence semantics. */
final class State extends \NaiveWorkflow\WorkflowState
{
    public string $name;
    public int $retryDemoAttempts;
    public bool $approved;
    public string $result;
    public bool $childPollCompleted;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, [
            'name', 'retry_demo_attempts', 'approved', 'result', 'child_poll_completed',
        ]);
        if (array_key_exists('name', $values)) {
            if (!is_string($values['name'])) {
                throw new \InvalidArgumentException('Invalid hello name');
            }
            $this->name = $values['name'];
        }
        if (array_key_exists('retry_demo_attempts', $values)) {
            if (!is_int($values['retry_demo_attempts']) || $values['retry_demo_attempts'] < 0) {
                throw new \InvalidArgumentException('Invalid hello retry attempts');
            }
            $this->retryDemoAttempts = $values['retry_demo_attempts'];
        }
        if (array_key_exists('approved', $values)) {
            if (!is_bool($values['approved'])) {
                throw new \InvalidArgumentException('Invalid hello approval');
            }
            $this->approved = $values['approved'];
        }
        if (array_key_exists('result', $values)) {
            if (!is_string($values['result']) ||
                !in_array($values['result'], ['approved', 'rejected'], true)) {
                throw new \InvalidArgumentException('Invalid hello result');
            }
            $this->result = $values['result'];
        }
        if (array_key_exists('child_poll_completed', $values)) {
            if (!is_bool($values['child_poll_completed'])) {
                throw new \InvalidArgumentException('Invalid hello child completion');
            }
            $this->childPollCompleted = $values['child_poll_completed'];
        }
    }

    public function toArray(): array
    {
        $values = [];
        if (isset($this->name)) {
            $values['name'] = $this->name;
        }
        if (isset($this->retryDemoAttempts)) {
            $values['retry_demo_attempts'] = $this->retryDemoAttempts;
        }
        if (isset($this->approved)) {
            $values['approved'] = $this->approved;
        }
        if (isset($this->result)) {
            $values['result'] = $this->result;
        }
        if (isset($this->childPollCompleted)) {
            $values['child_poll_completed'] = $this->childPollCompleted;
        }
        return $values;
    }
}
