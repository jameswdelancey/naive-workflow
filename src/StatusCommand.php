<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RuntimeException;
use Throwable;

/** Status data remains on stdout; diagnostics use the shared base logger. */
class StatusCommand extends Base
{
    protected readonly string $root;

    public function __construct(?string $root = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__));
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Invalid workflow repository root');
        }
        $this->root = $resolved;
    }

    public function run(array $argv): int
    {
        // Resolve paths independently of the caller's working directory.
        $rows = [];
        $hadErrors = false;
        foreach (glob($this->root . '/workflows/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);

            // Match the runner's workflow-name rules; unrelated directories are not workflows.
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
                continue;
            }

            // No status file means no recorded run. An invalid file must not look idle.
            $file = $this->root . "/.runs/$name.json";
            $last = [];
            $updatedAt = null;
            $status = 'idle';
            if (file_exists($file)) {
                try {
                    // Read contents and modification time from the same open file.
                    // The runner publishes by rename: an open handle keeps this snapshot
                    // consistent even if a newer status replaces the path during our read.
                    $handle = @fopen($file, 'rb');
                    if ($handle === false) {
                        throw new RuntimeException("Cannot open $file");
                    }
                    try {
                        $metadata = fstat($handle);
                        if ($metadata === false) {
                            throw new RuntimeException("Cannot stat $file");
                        }
                        // This is the snapshot's file modification time, not the time of this query.
                        $updatedAt = date('c', $metadata['mtime']);
                        $json = @stream_get_contents($handle);
                        if ($json === false) {
                            throw new RuntimeException("Cannot read $file");
                        }
                    } finally {
                        // Close even when reading fails; status reporting must not leak handles.
                        fclose($handle);
                    }
                    $saved = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                    // Validate fields used in rendering and PID checks before trusting their types.
                    if (!is_array($saved) ||
                        !in_array($saved['status'] ?? null, ['running', 'retrying', 'done', 'failed'], true) ||
                        !is_int($saved['pid'] ?? null) || $saved['pid'] <= 0 ||
                        !array_key_exists('node', $saved) ||
                        ($saved['node'] !== null && !is_string($saved['node']))) {
                        throw new RuntimeException("Invalid status fields in $file");
                    }

                    // Timestamps are recorded by the runner in its configured local timezone.
                    // Preserve them rather than relabeling them with the reader's timezone.
                    foreach (['started', 'finished', 'retry_at', 'error', 'retry_error'] as $field) {
                        if (array_key_exists($field, $saved) && !is_string($saved[$field])) {
                            throw new RuntimeException("Invalid $field in $file");
                        }
                    }
                    if (array_key_exists('retry_after_seconds', $saved) &&
                        (!is_int($saved['retry_after_seconds']) || $saved['retry_after_seconds'] < 0)) {
                        throw new RuntimeException("Invalid retry delay in $file");
                    }
                    $last = $saved;
                    $status = $last['status'];
                } catch (Throwable $error) {
                    // Continue reporting other workflows, but signal incomplete results to callers.
                    $status = 'unknown';
                    $hadErrors = true;
                    $this->log("status: $name: {$error->getMessage()}");
                }
            }

            // Check both active states. /proc is only a best-effort liveness check:
            // PID reuse can hide a stopped workflow, and this is not a health check.
            // If /proc is unavailable, preserve the recorded status rather than guessing.
            if (in_array($status, ['running', 'retrying'], true) &&
                is_dir('/proc/self') && !is_dir('/proc/' . $last['pid'])) {
                $status = 'stopped';
            }

            // Computed identity/status take precedence; preserve remaining metadata for JSON.
            $rows[] = [
                'workflow' => $name,
                'status' => $status,
                'updated_at' => $updatedAt,
            ] + $last;
        }

        // JSON remains a clean array on stdout, including when diagnostics go to stderr.
        if (in_array('--json', $argv, true)) {
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
            return $hadErrors ? 1 : 0;
        }

        // Sanitize terminal control characters and bound widths in the human-readable report.
        $shorten = static function (string $text, int $width): string {
            $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
            return strlen($text) > $width ? substr($text, 0, $width - 3) . '...' : $text;
        };

        // Show the saved retry deadline, not a stale delay presented as a live countdown.
        // UPDATED AT identifies when the file snapshot was last written, in local time.
        $format = "%-24s  %-10s  %8s  %-32s  %-25s  %-25s\n";
        printf($format, 'WORKFLOW', 'STATUS', 'PID', 'NODE', 'RETRY AT', 'UPDATED AT');
        foreach ($rows as $row) {
            printf(
                $format,
                $shorten($row['workflow'], 24),
                $shorten($row['status'], 10),
                $row['pid'] ?? '-',
                $shorten($row['node'] ?? '-', 32),
                $shorten($row['retry_at'] ?? '-', 25),
                $shorten($row['updated_at'] ?? '-', 25)
            );
        }

        // A partial report is still useful, but automation must be able to detect read errors.
        return $hadErrors ? 1 : 0;
    }
}
