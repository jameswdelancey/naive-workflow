<?php
require_once __DIR__ . '/common.php';

foreach (['mes_production_orders', 'mes_quality_checks'] as $table) {
    if (!isset($state['observed'][$table])) {
        return ['error' => "Missing observation for $table"];
    }
}
$directory = dirname(__DIR__, 2) . '/.runs/mes-observe';
if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
    return ['error' => 'Cannot create observation receipt directory'];
}
// Retain a receipt after workflow state is discarded.
$receipt = [
    'unit_id' => $state['config']['unit_id'],
    'armed_at' => $state['armed_at'],
    'baseline' => $state['baseline'],
    'observed' => $state['observed'],
    'completed_at' => date('c'),
];
$json = json_encode($receipt, JSON_PRETTY_PRINT);
if ($json === false) {
    return ['error' => 'Cannot encode observation receipt: ' . json_last_error_msg()];
}
$json .= "\n";
// Publish the receipt with a same-directory rename.
$temporary = @tempnam($directory, '.receipt-');
if ($temporary === false) {
    return ['error' => 'Cannot create observation receipt'];
}
$error = null;
if (@file_put_contents($temporary, $json) !== strlen($json) ||
    !@rename($temporary, $directory . '/last-observation.json')) {
    $error = 'Cannot publish observation receipt';
}
if (is_file($temporary)) {
    @unlink($temporary);
}
if ($error !== null) {
    return ['error' => $error];
}
mes_log('Observation complete; no manufacturing commands or database mutations were issued');
return null;
