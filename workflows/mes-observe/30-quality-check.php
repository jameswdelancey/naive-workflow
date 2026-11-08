<?php
require_once __DIR__ . '/common.php';

$error = mes_poll($state, 'mes_quality_checks', 30);
return $error === null ? null : ['error' => $error];
