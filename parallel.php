<?php
declare(strict_types=1);

function workflow_parallel_signal(int $signal): void
{
    $GLOBALS['workflowParallelStop'] = true;
}

// Terminate siblings together; reap every direct child.
function workflow_parallel_cleanup(array &$active): void
{
    foreach ($active as $process) {
        $info = proc_get_status($process);
        if ($info['running']) {
            proc_terminate($process, SIGTERM);
        }
    }
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        $running = false;
        foreach ($active as $process) {
            $info = proc_get_status($process);
            $running = $running || $info['running'];
        }
        if ($running) {
            usleep(50_000);
        }
    } while ($running && hrtime(true) < $deadline);
    foreach ($active as $process) {
        $info = proc_get_status($process);
        if ($info['running']) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);
    }
    $active = [];
}

// Jobs contain workflow, input state and optional environment overrides.
// Results preserve input order. An error exposes no partial results.
function workflow_parallel(
    array $jobs,
    int $concurrency,
    int $timeout,
    ?array &$results = null
): ?string {
    global $runs, $name;
    $results = [];
    if (!array_is_list($jobs) || count($jobs) > 256 ||
        $concurrency < 1 || $concurrency > 32 || $timeout < 1 || $timeout > 3600) {
        return 'Invalid parallel job count, concurrency or timeout';
    }
    foreach (['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate',
              'pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
        if (!function_exists($function)) {
            return "Parallel workflows require $function";
        }
    }
    foreach ($jobs as $job) {
        if (!is_array($job) || !is_string($job['workflow'] ?? null) ||
            !preg_match('/^[a-zA-Z0-9_-]+$/D', $job['workflow']) ||
            !is_dir(__DIR__ . '/workflows/' . $job['workflow']) ||
            !is_array($job['state'] ?? null) ||
            !is_array($job['env'] ?? [])) {
            return 'Invalid parallel job';
        }
        foreach ($job['env'] ?? [] as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) ||
                !is_string($value) || str_contains($value, "\0")) {
                return 'Invalid parallel job environment';
            }
        }
    }
    if (!$jobs) {
        return null;
    }

    // Retain artifacts for inspection; never reuse another batch.
    $base = "$runs/$name/parallel";
    if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
        return 'Cannot create parallel runtime directory';
    }
    $batch = $base . '/batch-' . uniqid('', false);
    if (!@mkdir($batch, 0700)) {
        return 'Cannot create parallel batch';
    }
    foreach ($jobs as $index => $job) {
        if (!@mkdir("$batch/job-$index", 0700)) {
            return "Cannot create parallel job $index";
        }
        $error = workflow_write("$batch/job-$index/input.json", $job['state']);
        if ($error !== null) {
            return $error;
        }
    }
    workflow_log("$name: parallel batch $batch");

    $GLOBALS['workflowParallelStop'] = false;
    $async = pcntl_async_signals();
    $handlers = [];
    $error = null;
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        $handlers[$signal] = pcntl_signal_get_handler($signal);
        if (!pcntl_signal($signal, 'workflow_parallel_signal')) {
            $error = 'Cannot install parallel signal handler';
            break;
        }
    }
    pcntl_async_signals(true);
    $active = [];
    $mapped = [];
    $next = 0;
    // One deadline covers queueing, startup and work.
    $deadline = hrtime(true) + $timeout * 1_000_000_000;
    while ($error === null && ($active || $next < count($jobs))) {
        if ($GLOBALS['workflowParallelStop']) {
            $error = 'Parallel workflows interrupted';
            break;
        }
        if (hrtime(true) >= $deadline) {
            $error = 'Parallel workflows timed out';
            break;
        }

        // Reap finished workers before filling free slots.
        foreach ($active as $index => $process) {
            $info = proc_get_status($process);
            if ($info['running']) {
                continue;
            }
            $code = $info['exitcode'];
            $closed = proc_close($process);
            unset($active[$index]);
            if ($code < 0) {
                $code = $closed;
            }
            if ($code !== 0) {
                $error = "Child workflow {$jobs[$index]['workflow']} failed with exit code $code (job $index)";
                break;
            }
            $json = @file_get_contents("$batch/job-$index/output.json", false, null, 0, 8_388_609);
            if ($json === false || strlen($json) > 8_388_608) {
                $error = "Missing or oversized output from job $index";
                break;
            }
            $value = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) {
                $error = "Invalid output from job $index";
                break;
            }
            $mapped[$index] = $value;
        }
        if ($error !== null) {
            break;
        }

        // Bound active workers, not just launch rate.
        while ($next < count($jobs) && count($active) < $concurrency) {
            if ($GLOBALS['workflowParallelStop'] || hrtime(true) >= $deadline) {
                break;
            }
            $job = $jobs[$next];
            $env = getenv();
            foreach ($job['env'] ?? [] as $key => $value) {
                $env[$key] = $value;
            }
            $command = [
                PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(),
                __DIR__ . '/run.php', $job['workflow'],
                '--job-dir', "$batch/job-$next",
            ];
            $process = @proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => STDOUT,
                2 => STDERR,
            ], $pipes, __DIR__, $env);
            if (!is_resource($process)) {
                $error = "Cannot start parallel job $next";
                break;
            }
            $active[$next] = $process;
            $next++;
        }
        if ($error === null && ($active || $next < count($jobs))) {
            usleep(50_000);
        }
    }

    // All expected errors share cleanup and handler restoration.
    workflow_parallel_cleanup($active);
    if ($error === null && $GLOBALS['workflowParallelStop']) {
        $error = 'Parallel workflows interrupted';
    }
    foreach ($handlers as $signal => $handler) {
        pcntl_signal($signal, $handler);
    }
    pcntl_async_signals($async);
    if ($error !== null) {
        return "$error; batch retained at $batch";
    }
    ksort($mapped, SORT_NUMERIC);
    $results = array_values($mapped);
    return null;
}
