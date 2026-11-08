<?php
$attempts = 0;
$exists = array_key_exists('retry_demo_attempts', $state);
if ($exists) {
    $error = workflow_state_get($state, 'retry_demo_attempts', $attempts, 'int');
    if ($error !== null) {
        return workflow_result($error);
    }
}
if ($attempts < 0 || $attempts === PHP_INT_MAX) {
    return workflow_result('Retry attempt counter is out of range');
}
$attempts++;
$error = workflow_state_set(
    $state, 'retry_demo_attempts', $attempts, $exists ? 'replace' : 'add'
);
if ($error !== null) {
    return workflow_result($error);
}
if ($attempts === 1) {
    workflow_log_context('hello', 'Retry demo: requesting retry on attempt 1.');
    return workflow_result('Demo node is not ready yet', retry: 1);
}
workflow_log_context('hello', "Retry demo: continued on attempt $attempts.");
return null;
