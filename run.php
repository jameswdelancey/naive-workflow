#!/usr/bin/env php
<?php
// Use strict scalar argument checking for calls made from this file.
declare(strict_types=1);

/**
 * Request another attempt of the current node after a delay.
 * Mutations to $state are checkpointed and passed to the next attempt.
 * Nodes must make external side effects safe to repeat.
 */
final class RetryableException extends RuntimeException
{
    public readonly int $retryAfterSeconds;

    public function __construct(
        string $message,
        int $retryAfterSeconds = 1,
        ?Throwable $previous = null
    ) {
        // Reject invalid delays before creating the retry request.
        if ($retryAfterSeconds < 0) {
            throw new InvalidArgumentException('Retry delay cannot be negative');
        }
        $this->retryAfterSeconds = $retryAfterSeconds;
        parent::__construct($message, 0, $previous);
    }
}

// Resolve paths relative to the runner, not the caller's working directory.
$root = __DIR__;
$name = $argv[1] ?? '';
// Accept a simple existing workflow name; reject path separators and traversal.
if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name) || !is_dir("$root/workflows/$name")) {
    fwrite(STDERR, '[' . date('c') . '] ' . "Usage: php run.php <workflow>\n");
    exit(1);
}

// Store checkpoints, status and locks in a private runtime directory.
$dir = "$root/workflows/$name";
$runs = "$root/.runs";
if (!is_dir($runs) && !mkdir($runs, 0700, true) && !is_dir($runs)) {
    exit(1);
}

// Hold a nonblocking exclusive lock throughout execution, including retry waits.
$lock = fopen("$runs/$name.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, '[' . date('c') . '] ' . "$name: already running or cannot acquire lock\n");
    exit(1);
}

// Publish JSON via a same-directory rename so readers see a complete snapshot.
// This is not an fsync durability guarantee or a transaction with external effects.
$write = static function (string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents("$path.tmp", "$json\n") === false ||
        !rename("$path.tmp", $path)) {
        throw new RuntimeException("Cannot write $path");
    }
};
// Limit the node's local scope and share state by reference; this is not a sandbox.
$include = static function (string $file, array &$state): mixed {
    return require $file;
};

// Checkpoints control resumption; status metadata describes the latest invocation.
$checkpoint = "$runs/$name.checkpoint.json";
$status = [
    'pid' => getmypid(),
    'status' => 'running',
    'node' => null,
    'started' => date('c'),
];
// Capture status by reference so every save publishes its current contents.
$save = static function () use (&$status, $write, $runs, $name): void {
    $write("$runs/$name.json", $status);
};

// Start fresh unless a checkpoint restores state and a retry deadline.
$state = [];
$next = null;
$retryAt = null;
try {
    // Discover numbered nodes and sort lexicographically, not numerically.
    $nodes = glob("$dir/[0-9]*.php") ?: [];
    sort($nodes, SORT_STRING);
    $steps = array_map('basename', $nodes);
    // Run the optional process-handoff node after the numbered nodes.
    if (is_file("$dir/exit.php")) {
        $steps[] = 'exit.php';
    }

    // Resume the saved node rather than replaying earlier completed nodes.
    $start = 0;
    if (is_file($checkpoint)) {
        $saved = json_decode(
            file_get_contents($checkpoint),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        // Reject malformed checkpoint data instead of silently starting over.
        if (!is_array($saved) ||
            !is_string($saved['node'] ?? null) ||
            !is_array($saved['state'] ?? null)) {
            throw new RuntimeException('Invalid checkpoint');
        }
        $status['node'] = $saved['node'];
        $start = array_search($saved['node'], $steps, true);
        // Refuse to resume if workflow edits removed or renamed the saved node.
        if ($start === false) {
            throw new RuntimeException("Checkpoint node is missing: {$saved['node']}");
        }
        $state = $saved['state'];
        // Retry deadlines are absolute Unix timestamps, independent of display timezone.
        $retryAt = $saved['retry_at'] ?? null;
        if ($retryAt !== null && (!is_int($retryAt) || $retryAt < 0)) {
            throw new RuntimeException('Invalid checkpoint retry deadline');
        }
        fwrite(STDERR, '[' . date('c') . '] ' . "$name: resuming {$saved['node']}\n");
    }

    $save();
    // Execute nodes in order unless a successful node chooses a different position.
    for ($position = $start; $position < count($steps); $position++) {
        $node = $steps[$position];
        $GLOBALS['nextSequence'] = null;
        $status['node'] = $node;
        $save();

        // Repeat only this node on retryable exceptions; there is no built-in retry budget.
        while (true) {
            // Publish the waiting state and honor a new or restored deadline.
            if ($retryAt !== null) {
                $status['status'] = 'retrying';
                $status['retry_after_seconds'] = max(0, $retryAt - time());
                $status['retry_at'] = date('c', $retryAt);
                $save();

                // Preserve the deadline across interruptions and restarts.
                // Recheck after interrupted sleeps; wall-clock changes also affect this wait.
                while (($remaining = $retryAt - time()) > 0) {
                    sleep($remaining);
                }
                $retryAt = null;
            }

            // Clear retry diagnostics and branch selection before each attempt.
            $status['status'] = 'running';
            unset(
                $status['retry_after_seconds'],
                $status['retry_at'],
                $status['retry_error']
            );
            $save();
            $GLOBALS['nextSequence'] = null;

            // Ordinary failures retain the input state of this attempt.
            $write($checkpoint, ['node' => $node, 'state' => $state]);
            try {
                // A normal return ends the retry loop; ordinary exceptions propagate outward.
                $result = $include("$dir/$node", $state);
                break;
            } catch (RetryableException $retry) {
                // Unlike an ordinary failure, a retry preserves mutations made to state.
                // Round upward so the requested delay is never shortened.
                $retryAt = $retry->retryAfterSeconds === 0
                    ? time()
                    : (int) ceil(microtime(true) + $retry->retryAfterSeconds);
                $write($checkpoint, [
                    'node' => $node,
                    'state' => $state,
                    'retry_at' => $retryAt,
                ]);
                $status['retry_error'] = $retry->getMessage();
                fwrite(
                    STDERR,
                    '[' . date('c') . '] ' . "$name: $node: retryable: {$retry->getMessage()}; " .
                    "retrying in {$retry->retryAfterSeconds}s\n"
                );
            }
        }

        // Only exit.php's return value is interpreted as a process-replacement command.
        if ($node === 'exit.php') {
            $next = $result;
            // Null means stop; otherwise require a nonempty string argv list and executable.
            if ($next !== null) {
                if (!is_array($next) || !array_is_list($next) || !$next ||
                    count(array_filter($next, 'is_string')) !== count($next) ||
                    !str_starts_with($next[0], '/') || !is_executable($next[0])) {
                    throw new RuntimeException(
                        'exit.php must return null or an argv array with an absolute executable path'
                    );
                }
                // Check process-replacement support before marking the workflow complete.
                if (!function_exists('pcntl_exec')) {
                    throw new RuntimeException('Process replacement requires pcntl_exec');
                }
            }
        }

        // Apply branching only after successful execution, never after a retry request.
        $target = $GLOBALS['nextSequence'] ?? null;
        if ($target !== null) {
            // The final handoff node cannot jump back into the numbered workflow.
            if ($node === 'exit.php') {
                throw new RuntimeException('nextSequence cannot be set by exit.php');
            }
            // Accept nonnegative integers or digit strings, not arbitrary filenames.
            if ((!is_int($target) && !is_string($target)) ||
                !preg_match('/^[0-9]+$/D', (string) $target)) {
                throw new RuntimeException('nextSequence must be a nonnegative integer or digit string');
            }

            // Compare digit strings without integer conversion; leading zeroes do not matter.
            $normalize = static fn(string $value): string => ltrim($value, '0') ?: '0';
            $matches = [];
            foreach ($steps as $index => $candidate) {
                if (preg_match('/^([0-9]+)/', $candidate, $match) &&
                    $normalize($match[1]) === $normalize((string) $target)) {
                    $matches[] = $index;
                }
            }
            // Missing or ambiguous sequence numbers are errors, not fall-through.
            if (count($matches) !== 1) {
                throw new RuntimeException("nextSequence $target must identify exactly one numbered node");
            }
            // Compensate for the for-loop increment so the next iteration lands on the target.
            $position = $matches[0] - 1;
        }

        // Persist the selected next node and its input state before continuing.
        if (isset($steps[$position + 1])) {
            $write($checkpoint, [
                'node' => $steps[$position + 1],
                'state' => $state,
            ]);
        }
    }

    // Successful completion removes resumable state before publishing done.
    if (is_file($checkpoint) && !unlink($checkpoint)) {
        throw new RuntimeException('Cannot remove checkpoint');
    }
    $status['status'] = 'done';
    $status['finished'] = date('c');
    $save();
} catch (Throwable $error) {
    // Report failure without deleting any remaining checkpoint; external effects stay applied.
    $status['status'] = 'failed';
    $status['error'] = $error->getMessage();
    $status['finished'] = date('c');
    try {
        $save();
    } catch (Throwable $ignored) {
        // Do not let a second status-write failure hide the original error.
    }
    fwrite(STDERR, '[' . date('c') . '] ' . "$name: {$error->getMessage()}\n");
    exit(1);
}

// Release the lock before handing off, allowing a self-chained workflow to acquire it.
flock($lock, LOCK_UN);
fclose($lock);

// Replace this process without spawning a child. Success never returns here.
// If replacement fails, the workflow is already done and its checkpoint is gone.
if ($next !== null) {
    pcntl_exec($next[0], array_slice($next, 1));
    fwrite(STDERR, '[' . date('c') . '] ' . "$name: cannot execute {$next[0]}\n");
    exit(1);
}
