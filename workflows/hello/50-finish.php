<?php
$error = workflow_state_get($state, 'name', $name, 'string');
if ($error !== null) {
    return workflow_result($error);
}
$error = workflow_state_get($state, 'result', $result, 'string');
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('hello', "Finished for $name: $result.");
return workflow_result();
