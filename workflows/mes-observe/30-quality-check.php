<?php
declare(strict_types=1);

require_once __DIR__ . '/PollRecordNode.php';

return (new class extends \NaiveWorkflow\MesObserve\PollRecordNode {
    protected function table(): string
    {
        return 'mes_quality_checks';
    }

    protected function sequence(): int
    {
        return 30;
    }
})->run($state);
