<?php
// Map configuration stays visible; validation and publication are shared.
if (getenv('HELLO_CHILD_POLL') !== '1') {
    return workflow_result();
}
require_once dirname(__DIR__, 2) . '/map.php';

$items = getenv('HELLO_CHILD_ITEMS');
$concurrency = getenv('HELLO_CHILD_CONCURRENCY');

return workflow_result(workflow_map_into(
    state: $state,
    workflow: 'poll',
    items: $items === false ? [1] : $items,
    concurrency: $concurrency === false ? 4 : $concurrency,
    timeout: 30,
    environment: ['POLL_ONCE' => '1'],
    inputField: 'item',
    resultsField: 'child_poll_results',
    completedField: 'child_poll_completed'
));
