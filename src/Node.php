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

    abstract public function run(array &$state): mixed;

    protected function enabled(string $variable): bool
    {
        return getenv($variable) === '1';
    }

    protected function jump(int|string $sequence): void
    {
        if (!preg_match('/^[0-9]+$/D', (string) $sequence)) {
            throw new \InvalidArgumentException(
                'nextSequence must be a nonnegative integer or digit string'
            );
        }
        // The runner validates the target and applies it only after success.
        $GLOBALS['nextSequence'] = $sequence;
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
