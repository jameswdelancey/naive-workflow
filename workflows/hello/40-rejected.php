<?php
$error = workflow_state_get($state, 'name', $name, 'string');
if ($error !== null) {
    return ['error' => $error];
}
$error = workflow_state_set($state, 'result', 'rejected', 'add');
if ($error !== null) {
    return ['error' => $error];
}
workflow_log_context('hello', "$name: rejected branch.");
return null;
