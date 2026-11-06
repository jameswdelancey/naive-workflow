<?php
declare(strict_types=1);

namespace NaiveWorkflow\Poll;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

final class State extends \NaiveWorkflow\WorkflowState
{
    public string $time;

    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, ['time']);
        if (array_key_exists('time', $values)) {
            if (!is_string($values['time'])) {
                throw new \InvalidArgumentException('Invalid poll timestamp');
            }
            $this->time = $values['time'];
        }
    }

    public function toArray(): array
    {
        return isset($this->time) ? ['time' => $this->time] : [];
    }
}
