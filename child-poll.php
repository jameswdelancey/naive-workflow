<?php
declare(strict_types=1);

require_once __DIR__ . '/parallel.php';

// One item and many items use the same parallel controller.
// Return an error string; commit mapped state only after all jobs succeed.
function workflow_child_poll(array &$state): ?string
{
    // Map explicit inputs to isolated, finite poll jobs.
    $raw = getenv('HELLO_CHILD_ITEMS');
    $items = json_decode($raw === false ? '[1]' : $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($items) ||
        !array_is_list($items) || count($items) > 256) {
        return 'HELLO_CHILD_ITEMS must be a JSON list of at most 256 integers';
    }
    $jobs = [];
    foreach ($items as $item) {
        if (!is_int($item) || $item < -1_000_000 || $item > 1_000_000) {
            return 'Child items must be integers between -1000000 and 1000000';
        }
        $jobs[] = [
            'workflow' => 'poll',
            'state' => ['item' => $item],
            'env' => ['POLL_ONCE' => '1'],
        ];
    }
    $raw = getenv('HELLO_CHILD_CONCURRENCY');
    if ($raw !== false && (!preg_match('/^[1-9][0-9]?$/D', $raw) || (int) $raw > 32)) {
        return 'HELLO_CHILD_CONCURRENCY must be between 1 and 32';
    }
    $concurrency = $raw === false ? 4 : (int) $raw;

    fwrite(STDERR, '[' . date('c') . '] ' . "Starting child workflow poll.\n");
    $results = [];
    $error = workflow_parallel($jobs, $concurrency, 30, $results);
    if ($error !== null) {
        return $error;
    }
    $state['child_poll_results'] = $results;
    $state['child_poll_completed'] = true;
    fwrite(STDERR, '[' . date('c') . '] ' . "Child workflow poll completed; continuing hello.\n");
    return null;
}
