<?php
if (!array_key_exists('child_poll_completed', $state)) {
    return workflow_result();
}
$error = workflow_state_get($state, 'child_poll_completed', $completed, 'bool');
if ($error !== null) {
    return workflow_result($error);
}
if (!$completed) {
    return workflow_result();
}
$error = workflow_state_get($state, 'child_poll_results', $results, 'array');
if ($error !== null) {
    return workflow_result($error);
}
$sum = 0;
foreach ($results as $index => $mapped) {
    if (!is_array($mapped)) {
        return workflow_result("Map result $index must be an array");
    }
    $error = workflow_state_get($mapped, 'result', $value, 'int');
    if ($error !== null) {
        return workflow_result("Map result $index: $error");
    }
    $sum += $value;
}
$error = workflow_state_set($state, 'child_poll_sum', $sum, 'add');
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('hello', "Reduced child poll results: $sum.");
return workflow_result();
