<?php
require_once __DIR__ . '/common.php';

$state['config'] = mes_config();
$state['deadline'] = time() + $state['config']['timeout'];
$state['observed'] = [];
mes_log('Observing manufacturing unit ' . $state['config']['unit_id'] . '; database writes disabled');
