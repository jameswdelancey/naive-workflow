<?php
if (!array_key_exists('item', $state)) {
    return null;
}
$error = workflow_state_get($state, 'item', $item, 'int');
if ($error !== null) {
    return ['error' => $error];
}
if ($item < -1_000_000 || $item > 1_000_000) {
    return ['error' => 'Map item must be between -1000000 and 1000000'];
}
$error = workflow_state_set($state, 'result', $item * $item, 'add');
return $error === null ? null : ['error' => $error];
