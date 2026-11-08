<?php
require_once dirname(__DIR__, 2) . '/log.php';
require_once dirname(__DIR__, 2) . '/state.php';
require_once dirname(__DIR__, 2) . '/result.php';

$time = date('c');
$error = workflow_state_set($state, 'time', $time, 'add');
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('poll', 'polling', $time);
return workflow_result();
