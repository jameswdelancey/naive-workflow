<?php
declare(strict_types=1);

require_once __DIR__ . '/ParallelPollNode.php';

return (new \NaiveWorkflow\Hello\ParallelPollNode())->run($state);
