<?php
declare(strict_types=1);

require_once __DIR__ . '/ResultNode.php';

return (new class extends \NaiveWorkflow\Hello\ResultNode {
    protected function result(): string
    {
        return 'rejected';
    }
})->run($state);
