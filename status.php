#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

function status_read(string $file, array &$last, ?string &$updatedAt): ?string
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return workflow_error('open status', 'Cannot open status snapshot', $file);
    }
    $metadata = fstat($handle);
    $json = @stream_get_contents($handle);
    fclose($handle);
    if ($metadata === false) {
        return workflow_error('stat status', 'Cannot stat status snapshot', $file);
    }
    $updatedAt = date('c', $metadata['mtime']);
    if ($json === false) {
        return workflow_error('read status', 'Cannot read status snapshot', $file);
    }
    $saved = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE ||
        !is_array($saved) ||
        !in_array($saved['status'] ?? null, ['running', 'retrying', 'done', 'failed'], true) ||
        !is_int($saved['pid'] ?? null) || $saved['pid'] <= 0 ||
        !array_key_exists('node', $saved) ||
        ($saved['node'] !== null && !is_string($saved['node']))) {
        return workflow_error('validate status', 'Invalid status fields', $file);
    }
    foreach (['started', 'finished', 'retry_at', 'error', 'retry_error'] as $field) {
        if (array_key_exists($field, $saved) && !is_string($saved[$field])) {
            return "Invalid $field in $file";
        }
    }
    if (array_key_exists('retry_after_seconds', $saved) &&
        (!is_int($saved['retry_after_seconds']) || $saved['retry_after_seconds'] < 0)) {
        return "Invalid retry delay in $file";
    }
    $last = $saved;
    return null;
}

// Sanitize terminal output and bound column width.
function status_shorten(string $text, int $width): string
{
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    return strlen($text) > $width ? substr($text, 0, $width - 3) . '...' : $text;
}

$rows = [];
$hadErrors = false;
foreach (glob(__DIR__ . '/workflows/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
        continue;
    }
    $file = __DIR__ . "/.runs/$name.json";
    $last = [];
    $updatedAt = null;
    $status = 'idle';
    if (file_exists($file)) {
        $error = status_read($file, $last, $updatedAt);
        if ($error !== null) {
            $status = 'unknown';
            $hadErrors = true;
            workflow_log_context("status: $name", $error, null, 'error');
        } else {
            $status = $last['status'];
        }
    }
    // PID existence is only a liveness hint.
    if (in_array($status, ['running', 'retrying'], true) &&
        is_dir('/proc/self') && !is_dir('/proc/' . $last['pid'])) {
        $status = 'stopped';
    }
    $rows[] = [
        'workflow' => $name,
        'status' => $status,
        'updated_at' => $updatedAt,
    ] + $last;
}

// Keep diagnostics off stdout.
if (in_array('--json', $argv, true)) {
    $json = json_encode($rows, JSON_PRETTY_PRINT);
    if ($json === false) {
        workflow_log_context('status', workflow_error('encode report', json_last_error_msg()), null, 'error');
        echo "[]\n";
        exit(1);
    }
    echo $json, "\n";
    exit($hadErrors ? 1 : 0);
}

$format = "%-24s  %-10s  %8s  %-32s  %-25s  %-25s\n";
printf($format, 'WORKFLOW', 'STATUS', 'PID', 'NODE', 'RETRY AT', 'UPDATED AT');
foreach ($rows as $row) {
    printf(
        $format,
        status_shorten($row['workflow'], 24),
        status_shorten($row['status'], 10),
        $row['pid'] ?? '-',
        status_shorten($row['node'] ?? '-', 32),
        status_shorten($row['retry_at'] ?? '-', 25),
        status_shorten($row['updated_at'] ?? '-', 25)
    );
}
exit($hadErrors ? 1 : 0);
