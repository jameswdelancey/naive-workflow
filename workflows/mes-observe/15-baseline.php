<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\MesObserve\State::requireState($state);
        $this->interruptible(function (callable $check) use ($state): void {
            $this->checkObservation($state);
            // Both baselines use the same InnoDB consistent-read transaction.
            $state->baseline = $this->readSnapshot(
                ['mes_production_orders', 'mes_quality_checks'],
                $state->config->unitId
            );
            $check();
            $this->checkObservation($state);
            $state->armedAt = date('c');
            $this->log('Baselines captured; waiting for a production-order upsert, then a quality-check upsert');
        });
        return null;
    }
})->run($state);
