<?php
require_once __DIR__ . '/common.php';

$error = mes_baseline($state);
return $error === null ? null : ['error' => $error];
