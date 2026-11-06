<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RuntimeException;
use Throwable;

/**
 * Reads/validates status snapshots without printing.
 * File contents and modification time are obtained from the same handle.
 */
class StatusRepository
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

    public function collect(): StatusReport
    {
        // Resolve paths independently of the caller's working directory.
        $rows = [];
        $errors = [];
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

                    $last = WorkflowStatus::fromArray($saved, $file)->toArray();
                    $status = $last['status'];
                } catch (Throwable $error) {
                    // Continue reporting other workflows, but signal incomplete results to callers.
                    $status = 'unknown';
                    $errors[] = "$name: {$error->getMessage()}";
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
        return new StatusReport($rows, $errors);
    }
}
