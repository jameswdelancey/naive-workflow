#!/usr/bin/env php
<?php
declare(strict_types=1);

function workflow_log(string $message): void
{
    $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $message);
    fwrite(STDERR, '[' . date('c') . '] ' . $message . "\n");
}

// Return an error string, or null on success.
function workflow_write(string $path, array $data): ?string
{
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) {
        return 'Cannot encode checkpoint/status: ' . json_last_error_msg();
    }
    $json .= "\n";
    if (@file_put_contents("$path.tmp", $json) !== strlen($json)) {
        @unlink("$path.tmp");
        return "Cannot write $path";
    }
    if (!@rename("$path.tmp", $path)) {
        @unlink("$path.tmp");
        return "Cannot publish $path";
    }
    return null;
}

// Publish current status.
function workflow_save(): ?string
{
    global $runs, $name, $status;
    return workflow_write("$runs/$name.json", $status);
}

// A named function limits the node's scope without using a closure.
function workflow_include(string $file, array &$state)
{
    return require $file;
}

// Release the workflow lock.
function workflow_release(): void
{
    global $lock;
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $lock = null;
}

// Record failure; retain the checkpoint.
function workflow_fail(string $message): void
{
    global $name, $status, $workflowFinished;
    $status['status'] = 'failed';
    $status['error'] = $message;
    $status['finished'] = date('c');
    $saveError = workflow_save();
    workflow_log("$name: $message");
    if ($saveError !== null) {
        workflow_log("$name: $saveError");
    }
    $workflowFinished = true;
    workflow_release();
    exit(1);
}

// Runtime bugs are not retry requests. Preserve the existing checkpoint and
// make a best-effort failed status if PHP terminates without a normal return.
function workflow_shutdown(): void
{
    global $workflowFinished, $status, $name;
    if ($workflowFinished || !is_array($status)) {
        return;
    }
    $last = error_get_last();
    $message = $last['message'] ?? 'Node terminated without returning';
    $status['status'] = 'failed';
    $status['error'] = $message;
    $status['finished'] = date('c');
    workflow_save();
    workflow_log("$name: $message");
    workflow_release();
}

// Ignore leading zeroes in branch targets.
function workflow_normalize(string $number): string
{
    $number = ltrim($number, '0');
    return $number === '' ? '0' : $number;
}

$root = __DIR__;
$name = $argv[1] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name) ||
    !is_dir("$root/workflows/$name")) {
    workflow_log('Usage: php run.php <workflow>');
    exit(1);
}

$dir = "$root/workflows/$name";
// Job mode gives each worker an isolated runtime directory.
$jobDir = null;
if (count($argv) !== 2) {
    if (count($argv) !== 4 || $argv[2] !== '--job-dir' ||
        !str_starts_with($argv[3], '/') || !is_dir($argv[3])) {
        workflow_log('Usage: php run.php <workflow> [--job-dir <absolute-directory>]');
        exit(1);
    }
    $jobDir = realpath($argv[3]);
    if ($jobDir === false) {
        workflow_log('Cannot resolve job directory');
        exit(1);
    }
}
$runs = $jobDir === null ? "$root/.runs" : "$jobDir/runtime";
if (!is_dir($runs) && !@mkdir($runs, 0700, true) && !is_dir($runs)) {
    workflow_log("Cannot create $runs");
    exit(1);
}

// Allow one active runner per workflow.
$lock = @fopen("$runs/$name.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    workflow_log("$name: already running or cannot acquire lock");
    exit(1);
}

$checkpoint = "$runs/$name.checkpoint.json";
$status = [
    'pid' => getmypid(),
    'status' => 'running',
    'node' => null,
    'started' => date('c'),
];
$workflowFinished = false;
register_shutdown_function('workflow_shutdown');

$nodes = glob("$dir/[0-9]*.php") ?: [];
sort($nodes, SORT_STRING);
$steps = [];
foreach ($nodes as $file) {
    if (is_file($file)) {
        $steps[] = basename($file);
    }
}
if (is_file("$dir/exit.php")) {
    $steps[] = 'exit.php';
}

$state = [];
$position = 0;
$retryAt = null;
$next = null;

// Restore the saved node, state and retry deadline.
if (is_file($checkpoint)) {
    $json = @file_get_contents($checkpoint);
    if ($json === false) {
        workflow_fail('Cannot read checkpoint');
    }
    $saved = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE ||
        !is_array($saved) ||
        !is_string($saved['node'] ?? null) ||
        !is_array($saved['state'] ?? null)) {
        workflow_fail('Invalid checkpoint');
    }
    $status['node'] = $saved['node'];
    $position = array_search($saved['node'], $steps, true);
    if ($position === false) {
        workflow_fail("Checkpoint node is missing: {$saved['node']}");
    }
    $state = $saved['state'];
    $retryAt = $saved['retry_at'] ?? null;
    if ($retryAt !== null && (!is_int($retryAt) || $retryAt < 0)) {
        workflow_fail('Invalid checkpoint retry deadline');
    }
    workflow_log("$name: resuming {$saved['node']}");
}

if ($jobDir !== null) {
    // Exit code and output must both belong to this invocation.
    if (file_exists("$jobDir/output.json") && !@unlink("$jobDir/output.json")) {
        workflow_fail('Cannot remove stale job output');
    }
    if (!is_file($checkpoint)) {
        $input = @file_get_contents("$jobDir/input.json");
        if ($input === false) {
            workflow_fail('Cannot read job input');
        }
        $state = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($state)) {
            workflow_fail('Invalid job input');
        }
    }
}

$error = workflow_save();
if ($error !== null) {
    workflow_fail($error);
}

while ($position < count($steps)) {
    $node = $steps[$position];
    $status['node'] = $node;

    while (true) {
        if ($retryAt !== null) {
            $status['status'] = 'retrying';
            $status['retry_after_seconds'] = max(0, $retryAt - time());
            $status['retry_at'] = date('c', $retryAt);
            $error = workflow_save();
            if ($error !== null) {
                workflow_fail($error);
            }
            while (($remaining = $retryAt - time()) > 0) {
                sleep($remaining);
            }
            $retryAt = null;
        }

        $status['status'] = 'running';
        unset($status['retry_after_seconds'], $status['retry_at'], $status['retry_error']);
        $error = workflow_save();
        if ($error !== null) {
            workflow_fail($error);
        }

        $GLOBALS['nextSequence'] = null;
        $error = workflow_write($checkpoint, ['node' => $node, 'state' => $state]);
        if ($error !== null) {
            workflow_fail($error);
        }

        // Run with shared state and an explicit result.
        $result = workflow_include("$dir/$node", $state);

        // Retries preserve mutations; failures preserve attempt input.
        if (is_array($result) && array_key_exists('retry', $result)) {
            $delay = $result['retry'];
            $message = $result['error'] ?? 'Retry requested';
            if (!is_int($delay) || $delay < 0 ||
                $delay > PHP_INT_MAX - time() - 1 ||
                !is_string($message)) {
                workflow_fail('Invalid retry result');
            }
            $retryAt = $delay === 0 ? time() : (int) ceil(microtime(true) + $delay);
            $error = workflow_write($checkpoint, [
                'node' => $node,
                'state' => $state,
                'retry_at' => $retryAt,
            ]);
            if ($error !== null) {
                workflow_fail($error);
            }
            $status['retry_error'] = $message;
            workflow_log("$name: $node: retry requested: $message; retrying in {$delay}s");
            continue;
        }

        if (is_array($result) && array_key_exists('error', $result)) {
            if (!is_string($result['error'])) {
                workflow_fail('Invalid error result');
            }
            workflow_fail($result['error']);
        }

        if ($node === 'exit.php') {
            $next = $result;
            if ($next !== null) {
                if ($jobDir !== null) {
                    workflow_fail('Job workflows cannot hand off; return null from exit.php');
                }
                if (!is_array($next) || !array_is_list($next) || !$next) {
                    workflow_fail('exit.php must return null or an argv array');
                }
                foreach ($next as $argument) {
                    if (!is_string($argument) || str_contains($argument, "\0")) {
                        workflow_fail('exit.php argv must contain strings without NUL bytes');
                    }
                }
                if (!str_starts_with($next[0], '/') || !is_executable($next[0])) {
                    workflow_fail('exit.php requires an absolute executable path');
                }
                if (!function_exists('pcntl_exec')) {
                    workflow_fail('Process replacement requires pcntl_exec');
                }
            }
        } elseif ($result !== null && $result !== 1) {
            workflow_fail('Node must return null, an error result, or a retry result');
        }
        break;
    }

    // Resolve branches only after success.
    $nextPosition = $position + 1;
    $target = $GLOBALS['nextSequence'] ?? null;
    if ($target !== null) {
        if ($node === 'exit.php') {
            workflow_fail('nextSequence cannot be set by exit.php');
        }
        if ((!is_int($target) && !is_string($target)) ||
            !preg_match('/^[0-9]+$/D', (string) $target)) {
            workflow_fail('nextSequence must be a nonnegative integer or digit string');
        }
        $matches = [];
        foreach ($steps as $index => $candidate) {
            if (preg_match('/^([0-9]+)/', $candidate, $match) &&
                workflow_normalize($match[1]) === workflow_normalize((string) $target)) {
                $matches[] = $index;
            }
        }
        if (count($matches) !== 1) {
            workflow_fail("nextSequence $target must identify exactly one numbered node");
        }
        $nextPosition = $matches[0];
    }

    // Checkpoint the next node before advancing.
    if (isset($steps[$nextPosition])) {
        $error = workflow_write($checkpoint, [
            'node' => $steps[$nextPosition],
            'state' => $state,
        ]);
        if ($error !== null) {
            workflow_fail($error);
        }
    }
    $position = $nextPosition;
}

// Publish final job state before removing its checkpoint.
if ($jobDir !== null) {
    $error = workflow_write("$jobDir/output.json", $state);
    if ($error !== null) {
        workflow_fail($error);
    }
}

// Discard resumable state on completion.
if (is_file($checkpoint) && !@unlink($checkpoint)) {
    workflow_fail('Cannot remove checkpoint');
}
$status['status'] = 'done';
$status['finished'] = date('c');
$error = workflow_save();
if ($error !== null) {
    workflow_fail($error);
}
$workflowFinished = true;
workflow_release();

if ($next !== null) {
    // Replace this process after releasing its lock.
    pcntl_exec($next[0], array_slice($next, 1));
    workflow_log("$name: cannot execute {$next[0]}");
    exit(1);
}
