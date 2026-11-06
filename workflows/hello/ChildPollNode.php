<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

final class ChildPollNode extends \NaiveWorkflow\ManagedChildWorkflowNode
{
    protected function shouldRun(): bool
    {
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

    protected function completed(array &$state): void
    {
        $state['child_poll_completed'] = true;
    }

    protected function completionMessage(): string
    {
        return 'Child workflow poll completed; continuing hello.';
    }
}
