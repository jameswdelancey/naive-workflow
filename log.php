<?php
declare(strict_types=1);

// Return contextual errors without emitting diagnostics.
function workflow_error(string $operation, string $message, ?string $path = null): string
{
    return "$operation: $message" . ($path === null ? '' : " [path=$path]");
}

// Keep routine messages optional, but never suppress failures.
function workflow_log(
    string $message,
    ?string $timestamp = null,
    string $level = 'info',
    string $context = ''
): void {
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    if (!isset($levels[$level])) {
        $level = 'error';
    }
    $minimum = getenv('WORKFLOW_LOG_LEVEL') ?: 'info';
    if (!isset($levels[$minimum])) {
        $minimum = 'info';
    }
    if ($levels[$level] < $levels[$minimum]) {
        return;
    }

    $timestamp = $timestamp ?? date('c');
    $identity = $GLOBALS['workflowLogIdentity'] ?? [];
    $pid = getmypid();
    if (getenv('WORKFLOW_LOG_FORMAT') === 'json') {
        // JSON escapes controls; invalid UTF-8 cannot break the result stream.
        $record = [
            'timestamp' => $timestamp,
            'level' => $level,
            'context' => $context,
            'message' => $message,
            'workflow' => $identity['workflow'] ?? null,
            'job' => $identity['job'] ?? null,
            'batch' => $identity['batch'] ?? null,
            'pid' => $pid,
            'node' => $identity['node'] ?? null,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = '{"level":"error","message":"Cannot encode diagnostic"}';
        }
    } else {
        // Preserve ordinary text output; identify concurrent workers.
        $prefix = '';
        if (isset($identity['job'])) {
            $prefix = '[job=' . $identity['job'] . ' batch=' .
                $identity['batch'] . " pid=$pid] ";
        }
        $body = $context === '' ? $message : "$context: $message";
        $line = '[' . $timestamp . '] ' . $prefix . $body;
        $line = str_replace(["\r", "\n"], ['\\r', '\\n'], $line);
    }
    fwrite(STDERR, $line . "\n");
}

function workflow_log_context(
    string $context,
    string $message,
    ?string $timestamp = null,
    string $level = 'info'
): void {
    workflow_log($message, $timestamp, $level, $context);
}
