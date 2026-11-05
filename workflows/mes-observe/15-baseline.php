<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/records.php';

mes_interruptible(static function (callable $check) use (&$state): void {
    mes_check($state);
    // Both baselines use the same InnoDB consistent-read transaction.
    $state['baseline'] = mes_read_records(
        ['mes_production_orders', 'mes_quality_checks'],
        $state['config']['unit_id']
    );
    $check();
    mes_check($state);
    $state['armed_at'] = date('c');
    mes_log('Baselines captured; waiting for a production-order upsert, then a quality-check upsert');
});
