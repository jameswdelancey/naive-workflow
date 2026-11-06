<?php
declare(strict_types=1);

require_once __DIR__ . '/State.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\Hello\State::requireState($state);
        $state->name = getenv('USER') ?: 'world';
        $this->log("Hello, {$state->name}!");
        return null;
    }
})->run($state);
