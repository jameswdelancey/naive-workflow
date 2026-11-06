#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

exit((new \NaiveWorkflow\Runner(__DIR__))->run($argv[1] ?? ''));
