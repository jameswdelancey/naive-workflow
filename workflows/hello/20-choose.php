<?php
$approved = getenv('HELLO_APPROVED') === '1';
$error = workflow_state_set($state, 'approved', $approved, 'add');
if ($error !== null) {
    return workflow_result($error);
}
$sequence = $approved ? 30 : 40;
$error = workflow_next($sequence);
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('hello', "Choosing node $sequence.");
return workflow_result();
