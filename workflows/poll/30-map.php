<?php
// Ordinary polling has no map input.
if (!array_key_exists('item', $state)) {
    return null;
}
$item = $state['item'];
if (!is_int($item) || $item < -1_000_000 || $item > 1_000_000) {
    return ['error' => 'Map item must be an integer between -1000000 and 1000000'];
}
$state['result'] = $item * $item;
return null;
