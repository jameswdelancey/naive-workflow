<?php
// Preserve the attempt count across retries.
$state['retry_demo_attempts'] = ($state['retry_demo_attempts'] ?? 0) + 1;

if ($state['retry_demo_attempts'] === 1) {
    fwrite(STDERR, '[' . date('c') . '] ' . "Retry demo: requesting retry on attempt 1.\n");
    return ['retry' => 1, 'error' => 'Demo node is not ready yet'];
}

fwrite(STDERR, '[' . date('c') . '] ' . "Retry demo: continued on attempt {$state['retry_demo_attempts']}.\n");
return null;
