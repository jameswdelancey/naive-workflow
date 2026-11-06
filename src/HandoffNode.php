<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/**
 * Builds an exit-node handoff; process replacement remains the runner's job.
 * Subclasses supply the target, enable condition and optional message.
 */
abstract class HandoffNode extends Node
{
    abstract protected function workflow(): string;

    protected function shouldHandoff(array $state): bool
    {
        return true;
    }

    protected function forwardTimezone(): bool
    {
        return false;
    }

    protected function message(): ?string
    {
        return null;
    }

    final public function run(array &$state): mixed
    {
        if (!$this->shouldHandoff($state)) {
            return null;
        }

        $command = $this->workflowCommand($this->workflow(), $this->forwardTimezone());
        $message = $this->message();
        if ($message !== null) {
            $this->log($message);
        }
        return $command;
    }
}
