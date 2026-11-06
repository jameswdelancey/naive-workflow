<?php
declare(strict_types=1);

require_once __DIR__ . '/State.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\Hello\State::requireState($state);
        $state->retryDemoAttempts = ($state->retryDemoAttempts ?? 0) + 1;
        if ($state->retryDemoAttempts === 1) {
            $this->log('Retry demo: requesting retry on attempt 1.');
            throw new \RetryableException('Demo node is not ready yet', 1);
        }
        $this->log("Retry demo: continued on attempt {$state->retryDemoAttempts}.");
        return null;
    }
})->run($state);
