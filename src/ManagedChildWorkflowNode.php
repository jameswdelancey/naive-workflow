<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RuntimeException;

/** Shared foreground-child lifecycle with workflow-specific configuration hooks. */
abstract class ManagedChildWorkflowNode extends Node
{
    abstract protected function workflow(): string;

    protected function shouldRun(): bool
    {
        return true;
    }

    protected function environment(): array
    {
        return [];
    }

    protected function timeoutSeconds(): int
    {
        return 30;
    }

    protected function terminationGraceSeconds(): int
    {
        return 5;
    }

    protected function completed(\NaiveWorkflow\WorkflowState $state): void
    {
    }

    protected function completionMessage(): string
    {
        return 'Child workflow ' . $this->workflow() . ' completed.';
    }

    final public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        if (!$this->shouldRun()) {
            return null;
        }

        $workflow = $this->workflow();
        $this->workflowCommand($workflow);
        $timeout = $this->timeoutSeconds();
        $grace = $this->terminationGraceSeconds();
        if ($timeout <= 0 || $grace < 0) {
            throw new \InvalidArgumentException('Invalid child timeout or termination grace');
        }

        $child = new ChildProcess(
            $workflow,
            $this->workflowCommand($workflow, true),
            $this->root,
            array_replace(getenv(), $this->environment())
        );
        $this->log("Starting child workflow $workflow.");
        (new ChildProcessGroup())->run([$child], $timeout, $grace);
        $this->completed($state);
        $this->log($this->completionMessage());
        return null;
    }
}
