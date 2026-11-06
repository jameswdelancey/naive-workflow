<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\MesObserve\State::requireState($state);
        $state->config = $this->configuration();
        $state->deadline = time() + $state->config->timeout;
        $state->observed = new \NaiveWorkflow\MesObserve\Observations();
        $this->log('Observing manufacturing unit ' . $state->config->unitId . '; database writes disabled');
        return null;
    }
})->run($state);
