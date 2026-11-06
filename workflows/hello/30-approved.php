<?php
declare(strict_types=1);

require_once __DIR__ . '/ResultNode.php';

return (new class extends \NaiveWorkflow\Hello\ResultNode {
    protected function result(): string
    {
        return 'approved';
    }

    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        parent::run($state);
        return \NaiveWorkflow\Jump::to(50);
    }
})->run($state);
