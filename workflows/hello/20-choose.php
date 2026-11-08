<?php
global $nextSequence;
$approved = getenv('HELLO_APPROVED') === '1';
$error = workflow_state_set($state, 'approved', $approved, 'add');
if ($error !== null) {
    return ['error' => $error];
}
$nextSequence = $approved ? 30 : 40;
workflow_log_context('hello', "Choosing node $nextSequence.");
return null;
