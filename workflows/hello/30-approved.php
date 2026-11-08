<?php
$error = workflow_state_get($state, 'name', $name, 'string');
if ($error !== null) {
    return workflow_result($error);
}
$error = workflow_state_set($state, 'result', 'approved', 'add');
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('hello', "$name: approved branch.");
// Skip the rejected branch only after this node succeeds.
return workflow_result(workflow_next(50));
