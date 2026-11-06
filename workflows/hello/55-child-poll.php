<?php
declare(strict_types=1);

require_once __DIR__ . '/ChildPollNode.php';

return (new \NaiveWorkflow\Hello\ChildPollNode())->run($state);
