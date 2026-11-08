<?php
declare(strict_types=1);

// Explicit key lists avoid ambiguous dotted paths.
function workflow_state_keys(string|array $path, ?string &$error): array
{
    $error = null;
    $keys = is_string($path) ? [$path] : $path;
    if (!$keys || !array_is_list($keys)) {
        $error = 'State path must be a nonempty list of keys';
        return [];
    }
    foreach ($keys as $key) {
        if ((!is_string($key) && !is_int($key)) || $key === '') {
            $error = 'State path keys must be nonempty strings or integers';
            return [];
        }
    }
    return $keys;
}

function workflow_state_label(array $keys): string
{
    $label = 'state';
    foreach ($keys as $key) {
        $label .= '[' . json_encode($key, JSON_INVALID_UTF8_SUBSTITUTE) . ']';
    }
    return $label;
}

function workflow_state_type(mixed $value, string $type): bool
{
    if (str_starts_with($type, '?')) {
        return $value === null || workflow_state_type($value, substr($type, 1));
    }
    switch ($type) {
        case 'string': return is_string($value);
        case 'int': return is_int($value);
        case 'float': return is_float($value) && is_finite($value);
        case 'bool': return is_bool($value);
        case 'array': return is_array($value);
        case 'null': return $value === null;
    }
    return false;
}

// Check before publishing checkpoints or accepting helper writes.
function workflow_state_value(mixed $value, string $label = 'state', int $depth = 0): ?string
{
    if ($depth > 64) {
        return "$label exceeds nesting limit 64; check for recursion";
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $error = workflow_state_value(
                $item, $label . substr(workflow_state_label([$key]), 5), $depth + 1
            );
            if ($error !== null) {
                return $error;
            }
        }
    } elseif ($value !== null && !is_string($value) && !is_bool($value) &&
              !is_int($value) && !is_float($value)) {
        return "$label must be JSON-compatible; got " . get_debug_type($value);
    }
    if (json_encode($value) === false) {
        return "$label cannot be checkpointed: " . json_last_error_msg();
    }
    return null;
}

// Missing is an error; a present null is a real value.
function workflow_state_get(
    array $state,
    string|array $path,
    mixed &$value,
    ?string $type = null
): ?string {
    $value = null;
    $keys = workflow_state_keys($path, $error);
    if ($error !== null) {
        return $error;
    }
    if ($type !== null &&
        !preg_match('/^\??(string|int|float|bool|array|null)$/D', $type)) {
        return "Unknown state type: $type";
    }
    $current = $state;
    $visited = [];
    foreach ($keys as $key) {
        if (!is_array($current)) {
            return workflow_state_label($visited) . ' must be an array; got ' .
                get_debug_type($current);
        }
        $visited[] = $key;
        if (!array_key_exists($key, $current)) {
            return workflow_state_label($visited) . ' is missing';
        }
        $current = $current[$key];
    }
    if ($type !== null && !workflow_state_type($current, $type)) {
        return workflow_state_label($keys) . " must be $type; got " . get_debug_type($current);
    }
    $value = $current;
    return null;
}

// Rebuild a path without creating missing parents.
function workflow_state_edit(
    array $state,
    array $keys,
    mixed $value,
    string $mode,
    string $label,
    ?string &$error
): array {
    $key = array_shift($keys);
    $exists = array_key_exists($key, $state);
    if ($keys) {
        if (!$exists || !is_array($state[$key])) {
            $error = "$label has a missing or non-array parent";
            return $state;
        }
        $child = workflow_state_edit($state[$key], $keys, $value, $mode, $label, $error);
        if ($error === null) {
            $state[$key] = $child;
        }
        return $state;
    }
    if ($mode === 'add' && $exists) {
        $error = "$label already exists; use replace";
    } elseif ($mode !== 'add' && !$exists) {
        $error = "$label is missing" . ($mode === 'replace' ? '; use add for a new field' : '');
    } elseif ($mode === 'replace' &&
              get_debug_type($state[$key]) !== get_debug_type($value)) {
        $error = "$label cannot change type from " . get_debug_type($state[$key]) .
            ' to ' . get_debug_type($value);
    } elseif ($mode === 'remove') {
        unset($state[$key]);
    } else {
        $state[$key] = $value;
    }
    return $state;
}

// Replacement is the safe default; additions must be intentional.
function workflow_state_set(
    array &$state,
    string|array $path,
    mixed $value,
    string $mode = 'replace'
): ?string {
    $keys = workflow_state_keys($path, $error);
    if ($error !== null) {
        return $error;
    }
    if (!in_array($mode, ['add', 'replace'], true)) {
        return 'State write mode must be add or replace';
    }
    $label = workflow_state_label($keys);
    $error = workflow_state_value($value, $label);
    if ($error !== null) {
        return $error;
    }
    $updated = workflow_state_edit($state, $keys, $value, $mode, $label, $error);
    if ($error === null) {
        $state = $updated;
    }
    return $error;
}

// A misspelled removal must not silently succeed.
function workflow_state_remove(array &$state, string|array $path): ?string
{
    $keys = workflow_state_keys($path, $error);
    if ($error !== null) {
        return $error;
    }
    $updated = workflow_state_edit(
        $state, $keys, null, 'remove', workflow_state_label($keys), $error
    );
    if ($error === null) {
        $state = $updated;
    }
    return $error;
}

// Field declarations constrain top-level names/types, not domain invariants.
function workflow_state_validate(mixed $state, ?array $fields = null): ?string
{
    if (!is_array($state)) {
        return 'Workflow state must remain an array; got ' . get_debug_type($state);
    }
    $error = workflow_state_value($state);
    if ($error !== null || $fields === null) {
        return $error;
    }
    foreach ($fields as $key => $type) {
        if (!is_string($key) || !is_string($type) ||
            !preg_match('/^\??(string|int|float|bool|array|null)$/D', $type)) {
            return 'fields.php must declare string field names and supported type names';
        }
    }
    foreach ($state as $key => $value) {
        $label = workflow_state_label([$key]);
        if (!array_key_exists($key, $fields)) {
            return "$label is not declared in fields.php; check for a misspelled field";
        }
        if (!workflow_state_type($value, $fields[$key])) {
            return "$label must be {$fields[$key]}; got " . get_debug_type($value);
        }
    }
    return null;
}
