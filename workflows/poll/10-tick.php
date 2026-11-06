<?php
declare(strict_types=1);

require_once __DIR__ . '/State.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\Poll\State::requireState($state);
        $state->time = date('c');
        $this->log('polling', $state->time);
        return null;
    }
})->run($state);
