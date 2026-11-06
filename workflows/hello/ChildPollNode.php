<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once __DIR__ . '/State.php';

final class ChildPollNode extends \NaiveWorkflow\ManagedChildWorkflowNode
{
    protected function shouldRun(): bool
    {
        // A normal hello run includes the finite child; explicit 0 skips it.
        $mode = getenv('HELLO_CHILD_POLL');
        if ($mode === false) {
            return true;
        }
        return $this->enabled('HELLO_CHILD_POLL');
    }

    protected function workflow(): string
    {
        return 'poll';
    }

    protected function environment(): array
    {
        return ['POLL_ONCE' => '1'];
    }

    protected function completed(\NaiveWorkflow\WorkflowState $state): void
    {
        $state = \NaiveWorkflow\Hello\State::requireState($state);
        $state->childPollCompleted = true;
    }

    protected function completionMessage(): string
    {
        return 'Child workflow poll completed; continuing hello.';
    }
}
