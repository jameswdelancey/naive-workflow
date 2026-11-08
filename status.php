#!/usr/bin/env php
<?php
declare(strict_types=1);

function status_diagnose(string $message): void
{
    $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $message);
    fwrite(STDERR, '[' . date('c') . '] ' . "status: $message\n");
}

// Read contents and mtime from one snapshot.
function status_read(string $file, array &$last, ?string &$updatedAt): ?string
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return "Cannot open $file";
    }
    $metadata = fstat($handle);
    $json = @stream_get_contents($handle);
    fclose($handle);
    if ($metadata === false) {
        return "Cannot stat $file";
    }
    $updatedAt = date('c', $metadata['mtime']);
    if ($json === false) {
        return "Cannot read $file";
    }
    $saved = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE ||
        !is_array($saved) ||
        !in_array($saved['status'] ?? null, ['running', 'retrying', 'done', 'failed'], true) ||
        !is_int($saved['pid'] ?? null) || $saved['pid'] <= 0 ||
        !array_key_exists('node', $saved) ||
        ($saved['node'] !== null && !is_string($saved['node']))) {
        return "Invalid status fields in $file";
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
            status_diagnose("$name: $error");
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
        status_diagnose('Cannot encode report: ' . json_last_error_msg());
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
