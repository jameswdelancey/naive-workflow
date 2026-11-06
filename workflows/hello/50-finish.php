<?php
declare(strict_types=1);

require_once __DIR__ . '/State.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\Hello\State::requireState($state);
        $this->log("Finished for {$state->name}: {$state->result}.");
        return null;
    }
})->run($state);
