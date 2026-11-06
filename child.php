#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

// Internal finite-child entry point. The parent creates a fresh runtime directory.
if (count($argv) !== 3) {
    exit(1);
}
exit((new \NaiveWorkflow\IsolatedRunner(__DIR__, $argv[2]))->run($argv[1]));
