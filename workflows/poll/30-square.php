<?php
// Worker calculation only; the parent owns map orchestration.
if (!array_key_exists('item', $state)) {
    return workflow_result();
}
$error = workflow_state_get($state, 'item', $item, 'int');
if ($error !== null) {
    return workflow_result($error);
}
if ($item < -1_000_000 || $item > 1_000_000) {
    return workflow_result('Map item must be between -1000000 and 1000000');
}
$error = workflow_state_set($state, 'result', $item * $item, 'add');
return workflow_result($error);
