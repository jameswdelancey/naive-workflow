<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\HandoffNode {
    protected function shouldHandoff(\NaiveWorkflow\WorkflowState $state): bool
    {
        return $this->enabled('HELLO_POLL');
    }

    protected function workflow(): string
    {
        return 'poll';
    }

    protected function message(): ?string
    {
        return 'Handing off to poll.';
    }
})->run($state);
