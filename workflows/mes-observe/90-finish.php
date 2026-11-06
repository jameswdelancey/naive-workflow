<?php
declare(strict_types=1);

require_once __DIR__ . '/ObservationNode.php';

return (new class extends \NaiveWorkflow\MesObserve\ObservationNode {
    public function run(array &$state): mixed
    {
        foreach (['mes_production_orders', 'mes_quality_checks'] as $table) {
            if (!isset($state['observed'][$table])) {
                throw new RuntimeException("Missing observation for $table");
            }
        }

        // Successful workflows discard shared state, so retain a local receipt.
        // The runner's workflow lock serializes writers. This is not an audit log.
        $directory = $this->root . '/.runs/mes-observe';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create observation receipt directory');
        }
        $receipt = [
            'unit_id' => $state['config']['unit_id'],
            'armed_at' => $state['armed_at'],
            'baseline' => $state['baseline'],
            'observed' => $state['observed'],
            'completed_at' => date('c'),
        ];
        $temporary = tempnam($directory, '.receipt-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create observation receipt');
        }
        try {
            $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json) !== strlen($json) ||
                !rename($temporary, $directory . '/last-observation.json')) {
                throw new RuntimeException('Cannot publish observation receipt');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->log('Observation complete; no manufacturing commands or database mutations were issued');
        return null;
    }
})->run($state);
