<?php
$error = workflow_state_get($state, 'name', $name, 'string');
if ($error !== null) {
    return ['error' => $error];
}
$error = workflow_state_get($state, 'result', $result, 'string');
if ($error !== null) {
    return ['error' => $error];
}
workflow_log_context('hello', "Finished for $name: $result.");
return null;
