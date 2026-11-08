<?php
require_once __DIR__ . '/common.php';

$config = mes_config($error);
if ($error !== null) {
    return ['error' => $error];
}
$updated = $state;
foreach (['config' => $config, 'deadline' => time() + $config['timeout'], 'observed' => []] as $key => $value) {
    $error = workflow_state_set($updated, $key, $value, 'add');
    if ($error !== null) {
        return ['error' => $error];
    }
}
$state = $updated;
workflow_log_context('mes-observe', 'Observing manufacturing unit ' . $config['unit_id'] . '; database writes disabled');
return null;
