<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(array &$state): mixed
    {
        $state['name'] = getenv('USER') ?: 'world';
        $this->log("Hello, {$state['name']}!");
        return null;
    }
})->run($state);
