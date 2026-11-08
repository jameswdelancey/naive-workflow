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

// Shared boundary check for legacy argv and constructed handoffs.
function workflow_exit_validate(mixed $command): ?string
{
    if ($command === null) {
        return null;
    }
    if (!is_array($command) || !array_is_list($command) || !$command) {
        return 'exit.php must return workflow_exit() or a nonempty argv list';
    }
    foreach ($command as $argument) {
        if (!is_string($argument) || str_contains($argument, "\0")) {
            return 'exit.php argv must contain strings without NUL bytes';
        }
    }
    if (!str_starts_with($command[0], '/') ||
        !is_file($command[0]) || !is_executable($command[0])) {
        return 'exit.php requires an absolute executable file path';
    }
    if (!function_exists('pcntl_exec')) {
        return 'Process replacement requires pcntl_exec';
    }
    return null;
}

// Stop by default; otherwise construct a checked workflow handoff.
// This only returns a result: completion, unlocking and exec belong to the runner.
function workflow_exit(?string $workflow = null): ?array
{
    if ($workflow === null) {
        return workflow_result();
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $workflow) ||
        !is_dir(__DIR__ . '/workflows/' . $workflow)) {
        return workflow_result('Invalid exit workflow: expected an existing simple workflow name');
    }
    $runner = __DIR__ . '/run.php';
    if (!is_file($runner) || !is_readable($runner)) {
        return workflow_result('Cannot prepare workflow handoff: runner is missing or unreadable');
    }
    if (($GLOBALS['jobDir'] ?? null) !== null) {
        return workflow_result('Job workflows cannot hand off; use workflow_exit() to stop');
    }
    $command = [PHP_BINARY, $runner, $workflow];
    $error = workflow_exit_validate($command);
    return $error === null ? $command : workflow_result($error);
}
