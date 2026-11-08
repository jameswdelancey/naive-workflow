<?php
if (!($state['child_poll_completed'] ?? false)) {
    return null;
}

// Reduce only after every mapper succeeds.
$sum = 0;
foreach ($state['child_poll_results'] as $mapped) {
    if (!is_int($mapped['result'] ?? null)) {
        return ['error' => 'Missing integer map result'];
    }
    $sum += $mapped['result'];
}
$state['child_poll_sum'] = $sum;
fwrite(STDERR, '[' . date('c') . '] ' . "Reduced child poll results: $sum.\n");
return null;
