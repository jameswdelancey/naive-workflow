<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        return $this->poll($state, 'mes_production_orders')
            ? null
            : \NaiveWorkflow\Jump::to(20);
    }
})->run($state);
