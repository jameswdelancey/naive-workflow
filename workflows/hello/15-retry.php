<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(array &$state): mixed
    {
        $state['retry_demo_attempts'] = ($state['retry_demo_attempts'] ?? 0) + 1;
        if ($state['retry_demo_attempts'] === 1) {
            $this->log('Retry demo: requesting retry on attempt 1.');
            throw new \RetryableException('Demo node is not ready yet', 1);
        }
        $this->log("Retry demo: continued on attempt {$state['retry_demo_attempts']}.");
        return null;
    }
})->run($state);
