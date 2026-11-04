<?php
$state['retry_demo_attempts'] = ($state['retry_demo_attempts'] ?? 0) + 1;

if ($state['retry_demo_attempts'] === 1) {
    echo "Retry demo: requesting retry on attempt 1.\n";
    throw new RetryableException('Demo node is not ready yet', 1);
}

echo "Retry demo: continued on attempt {$state['retry_demo_attempts']}.\n";
