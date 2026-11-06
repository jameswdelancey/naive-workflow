<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Only state is checkpointed; node instances remain local to an attempt. */
abstract class Node extends Base
{
    protected readonly string $root;

    public function __construct(?string $root = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__));
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Invalid workflow repository root');
        }
        $this->root = $resolved;
    }

    abstract public function run(\NaiveWorkflow\WorkflowState $state): mixed;

    protected function enabled(string $variable): bool
    {
        return getenv($variable) === '1';
    }

    protected function workflowCommand(string $workflow, bool $forwardTimezone = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $workflow)) {
            throw new \InvalidArgumentException('Invalid workflow name');
        }
        $command = [PHP_BINARY];
        if ($forwardTimezone) {
            $command[] = '-d';
            $command[] = 'date.timezone=' . date_default_timezone_get();
        }
        $command[] = $this->root . '/run.php';
        $command[] = $workflow;
        return $command;
    }
}
