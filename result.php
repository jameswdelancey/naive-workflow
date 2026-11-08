<?php
declare(strict_types=1);

// Construct node results in one place; zero means immediate retry, not failure.
function workflow_result(?string $error = null, ?int $retry = null): ?array
{
    if ($retry !== null && ($retry < 0 || $retry > PHP_INT_MAX - time() - 1)) {
        return ['error' => 'Invalid retry result: delay must be a nonnegative, representable number of seconds'];
    }
    if ($error === null) {
        return $retry === null
            ? null
            : ['error' => 'Invalid retry result: retry requires an error message'];
    }
    if (trim($error) === '') {
        return ['error' => 'Invalid error result: error message must not be empty'];
    }
    return $retry === null
        ? ['error' => $error]
        : ['retry' => $retry, 'error' => $error];
}

// Validate at the boundary too: callers can bypass the constructor.
function workflow_result_validate(mixed $result, bool $exitNode = false): ?string
{
    if ($result === null || (!$exitNode && $result === 1)) {
        return null;
    }
    // Exit argv receives separate executable/argument validation in the runner.
    if ($exitNode && is_array($result) && array_is_list($result)) {
        return null;
    }
    if (!is_array($result)) {
        return 'Invalid node result: use workflow_result($error, $retry); got ' .
            get_debug_type($result);
    }
    if (!is_string($result['error'] ?? null) || trim($result['error']) === '') {
        return array_key_exists('retry', $result)
            ? 'Invalid retry result: a nonempty error message is required'
            : 'Invalid error result: a nonempty error message is required';
    }
    $retry = array_key_exists('retry', $result);
    foreach ($result as $key => $value) {
        if ($key !== 'error' && $key !== 'retry') {
            return 'Invalid node result: unexpected field; use workflow_result($error, $retry)';
        }
    }
    if ($retry && (!is_int($result['retry']) || $result['retry'] < 0 ||
                  $result['retry'] > PHP_INT_MAX - time() - 1)) {
        return 'Invalid retry result: delay must be a nonnegative, representable integer';
    }
    return null;
}
