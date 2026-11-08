<?php
declare(strict_types=1);

require_once __DIR__ . '/state.php';

// Fold ordered map outputs; publish only after every reducer call succeeds.
// Reducer: (accumulator, item, index, &nextAccumulator) -> error string or null.
function workflow_reduce_into(
    array &$state,
    string $resultsField,
    string $completedField,
    string $outputField,
    callable $reducer,
    mixed $initial
): ?string {
    if ($resultsField === '' || $completedField === '' || $outputField === '' ||
        count(array_unique([$resultsField, $completedField, $outputField])) !== 3) {
        return 'Reduce field names must be nonempty and distinct';
    }

    // A skipped map has neither results nor a completion marker.
    if (!array_key_exists($completedField, $state)) {
        return array_key_exists($resultsField, $state)
            ? 'Reduce requires a map completion marker for existing results'
            : null;
    }
    $error = workflow_state_get($state, $completedField, $completed, 'bool');
    if ($error !== null) {
        return $error;
    }
    if (!$completed) {
        return 'Reduce cannot consume an incomplete map';
    }
    $error = workflow_state_get($state, $resultsField, $results, 'array');
    if ($error !== null) {
        return $error;
    }
    if (!array_is_list($results)) {
        return 'Reduce results must be an ordered list';
    }
    if (array_key_exists($outputField, $state)) {
        return workflow_state_label([$outputField]) . ' already exists; reduce publication requires a new field';
    }
    $error = workflow_state_value($initial, 'Reduce initial accumulator');
    if ($error !== null) {
        return $error;
    }

    $accumulator = $initial;
    foreach ($results as $index => $item) {
        $next = null;
        $error = $reducer($accumulator, $item, $index, $next);
        if ($error !== null) {
            return is_string($error) && trim($error) !== ''
                ? "Reduce item $index: $error"
                : "Reduce item $index: reducer must return null or a nonempty error string";
        }
        $error = workflow_state_value($next, "Reduce item $index accumulator");
        if ($error !== null) {
            return $error;
        }
        $accumulator = $next;
    }

    // No state changes or logging until the complete fold succeeds.
    return workflow_state_set($state, $outputField, $accumulator, 'add');
}

// Reusable integer sum for worker outputs containing an integer result field.
function workflow_reduce_sum(
    mixed $accumulator,
    mixed $item,
    int $index,
    mixed &$next
): ?string {
    $next = null;
    if (!is_int($accumulator)) {
        return 'Sum accumulator must be int; got ' . get_debug_type($accumulator);
    }
    if (!is_array($item)) {
        return 'Sum worker output must be array; got ' . get_debug_type($item);
    }
    $error = workflow_state_get($item, 'result', $value, 'int');
    if ($error !== null) {
        return $error;
    }
    if (($value > 0 && $accumulator > PHP_INT_MAX - $value) ||
        ($value < 0 && $accumulator < PHP_INT_MIN - $value)) {
        return 'Integer sum overflow; reduce result cannot be represented as int';
    }
    $next = $accumulator + $value;
    return null;
}
