<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(array &$state): mixed
    {
        $state['config'] = $this->configuration();
        $state['deadline'] = time() + $state['config']['timeout'];
        $state['observed'] = [];
        $this->log('Observing manufacturing unit ' . $state['config']['unit_id'] . '; database writes disabled');
        return null;
    }
})->run($state);
