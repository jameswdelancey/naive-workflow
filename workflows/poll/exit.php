<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\HandoffNode {
    protected function shouldHandoff(array $state): bool
    {
        return !$this->enabled('POLL_ONCE');
    }

    protected function workflow(): string
    {
        return basename(__DIR__);
    }
})->run($state);
