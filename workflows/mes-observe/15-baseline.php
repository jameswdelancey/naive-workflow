<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(array &$state): mixed
    {
        $this->interruptible(function (callable $check) use (&$state): void {
            $this->checkObservation($state);
            // Both baselines use the same InnoDB consistent-read transaction.
            $state['baseline'] = $this->readRecords(
                ['mes_production_orders', 'mes_quality_checks'],
                $state['config']['unit_id']
            );
            $check();
            $this->checkObservation($state);
            $state['armed_at'] = date('c');
            $this->log('Baselines captured; waiting for a production-order upsert, then a quality-check upsert');
        });
        return null;
    }
})->run($state);
