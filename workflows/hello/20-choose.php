<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

return (new class extends \NaiveWorkflow\Node {
    public function run(array &$state): mixed
    {
        $state['approved'] = $this->enabled('HELLO_APPROVED');
        $sequence = $state['approved'] ? 30 : 40;
        $this->jump($sequence);
        $this->log("Choosing node $sequence.");
        return null;
    }
})->run($state);
