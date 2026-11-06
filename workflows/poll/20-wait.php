<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(array &$state): mixed
    {
        sleep(5);
        return null;
    }
})->run($state);
