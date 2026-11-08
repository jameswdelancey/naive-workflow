<?php
require_once dirname(__DIR__, 2) . '/reduce.php';

// Reduce the preceding map's outputs; the library owns checks and publication.
$error = workflow_reduce_into(
    state: $state,
    resultsField: 'child_poll_results',
    completedField: 'child_poll_completed',
    outputField: 'child_poll_sum',
    reducer: 'workflow_reduce_sum',
    initial: 0
);
if ($error !== null) {
    return workflow_result($error);
}
if (array_key_exists('child_poll_sum', $state)) {
    $error = workflow_state_get($state, 'child_poll_sum', $sum, 'int');
    if ($error !== null) {
        return workflow_result($error);
    }
    workflow_log_context('hello', "Reduced child poll results: $sum.");
}
return workflow_result();
