<?php
declare(strict_types=1);

require_once __DIR__ . '/parallel.php';
require_once __DIR__ . '/state.php';

// Explicit child workflow and inputs; never read application configuration here.
// Return ordered final child states, not mutations to the parent's state.
function workflow_map(
    string $workflow,
    array $inputs,
    int $concurrency,
    int $timeout,
    ?array &$results = null,
    array $environment = []
): ?string {
    $results = [];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $workflow) ||
        !is_dir(__DIR__ . '/workflows/' . $workflow)) {
        return 'Map workflow must name an existing workflow';
    }
    if (!array_is_list($inputs) || count($inputs) > 256) {
        return 'Map inputs must be an ordered list of at most 256 state arrays';
    }
    $jobs = [];
    foreach ($inputs as $index => $input) {
        if (!is_array($input)) {
            return "Map input $index must be a child state array";
        }
        $jobs[] = [
            'workflow' => $workflow,
            'state' => $input,
            'env' => $environment,
        ];
    }
    // One input follows exactly the same lifecycle as many inputs.
    return workflow_parallel($jobs, $concurrency, $timeout, $results);
}

// Map scalar/structured items through a named child input field.
// Accept raw JSON/decimal configuration, but never read environment variables.
function workflow_map_into(
    array &$state,
    string $workflow,
    mixed $items,
    mixed $concurrency,
    int $timeout,
    array $environment,
    string $inputField,
    string $resultsField,
    string $completedField
): ?string {
    if ($inputField === '' || $resultsField === '' || $completedField === '' ||
        $resultsField === $completedField) {
        return 'Map field names must be nonempty; results and completion fields must differ';
    }
    // Fail before launching children if publication would overwrite prior state.
    foreach ([$resultsField, $completedField] as $field) {
        if (array_key_exists($field, $state)) {
            return workflow_state_label([$field]) . ' already exists; map publication requires new fields';
        }
    }
    if (is_string($items)) {
        $raw = trim($items);
        $items = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !str_starts_with($raw, '[')) {
            return 'Map items must be a JSON list or a PHP list';
        }
    }
    if (!is_array($items) || !array_is_list($items) || count($items) > 256) {
        return 'Map items must be an ordered list of at most 256 values';
    }
    if (is_string($concurrency) && preg_match('/^[1-9][0-9]?$/D', $concurrency)) {
        $concurrency = (int) $concurrency;
    }
    if (!is_int($concurrency) || $concurrency < 1 || $concurrency > 32) {
        return 'Map concurrency must be an integer between 1 and 32';
    }
    $inputs = [];
    foreach ($items as $index => $item) {
        $error = workflow_state_value($item, "Map item $index");
        if ($error !== null) {
            return $error;
        }
        $inputs[] = [$inputField => $item];
    }

    $results = [];
    $error = workflow_map(
        $workflow, $inputs, $concurrency, $timeout, $results, $environment
    );
    if ($error !== null) {
        return $error;
    }

    // Publish the complete map and its marker as one parent-state update.
    $updated = $state;
    $error = workflow_state_set($updated, $resultsField, $results, 'add');
    if ($error === null) {
        $error = workflow_state_set($updated, $completedField, true, 'add');
    }
    if ($error === null) {
        $state = $updated;
    }
    return $error;
}
