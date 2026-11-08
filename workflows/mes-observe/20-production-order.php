<?php
require_once __DIR__ . '/common.php';

$error = mes_poll($state, 'mes_production_orders', 20);
return workflow_result($error);
