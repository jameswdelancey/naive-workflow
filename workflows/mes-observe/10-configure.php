<?php
require_once __DIR__ . '/common.php';

$config = mes_config($error);
if ($error !== null) {
    return ['error' => $error];
}
$state['config'] = $config;
$state['deadline'] = time() + $config['timeout'];
$state['observed'] = [];
mes_log('Observing manufacturing unit ' . $config['unit_id'] . '; database writes disabled');
return null;
