<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** The actual tick result from one successfully reaped poll child. */
final class PollChildResult extends \NaiveWorkflow\WorkflowState
{
    public readonly int $pid;
    public readonly string $time;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['pid', 'time']);
        if (!is_int($values['pid'] ?? null) || $values['pid'] <= 0 ||
            !is_string($values['time'] ?? null) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $values['time'])) {
            throw new \InvalidArgumentException('Invalid poll child result');
        }
        $this->pid = $values['pid'];
        $this->time = $values['time'];
    }

    public function toArray(): array
    {
        return ['pid' => $this->pid, 'time' => $this->time];
    }
}

/** Fixed left/right slots; no dynamically inserted result keys. */
final class PollJoinResult extends \NaiveWorkflow\WorkflowState
{
    public readonly string $attempt;
    public readonly PollChildResult $left;
    public readonly PollChildResult $right;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['attempt', 'left', 'right']);
        if (!is_string($values['attempt'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}$/D', $values['attempt']) ||
            !is_array($values['left'] ?? null) || !is_array($values['right'] ?? null)) {
            throw new \InvalidArgumentException('Invalid poll join result');
        }
        $this->attempt = $values['attempt'];
        $this->left = new PollChildResult($values['left']);
        $this->right = new PollChildResult($values['right']);
        if ($this->left->pid === $this->right->pid) {
            throw new \InvalidArgumentException('Poll children must have distinct PIDs');
        }
    }

    public function toArray(): array
    {
        return [
            'attempt' => $this->attempt,
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
        ];
    }
}
