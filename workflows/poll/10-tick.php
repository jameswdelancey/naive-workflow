<?php
require_once dirname(__DIR__, 2) . '/log.php';
require_once dirname(__DIR__, 2) . '/state.php';

$time = date('c');
$error = workflow_state_set($state, 'time', $time, 'add');
if ($error !== null) {
    return ['error' => $error];
}
workflow_log_context('poll', 'polling', $time);
return null;
