<?php
// Opt in: this node waits for one poll iteration, then hello continues.
if (getenv('HELLO_CHILD_POLL') !== '1') {
    return;
}

// Child management needs explicit signal handling; the runner does not supply it.
foreach (['pcntl_async_signals', 'pcntl_signal', 'pcntl_signal_get_handler'] as $function) {
    if (!function_exists($function)) {
        throw new RuntimeException("Child workflow requires $function");
    }
}

$process = null;
$stop = false;
$handlers = [];
$async = pcntl_async_signals();
$log = static function (string $message): void {
    fwrite(STDERR, '[' . date('c') . '] ' . $message . "\n");
};

try {
    // Handlers only request a stop. Resource cleanup happens in finally.
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        $handlers[$signal] = pcntl_signal_get_handler($signal);
        pcntl_signal($signal, static function () use (&$stop): void {
            $stop = true;
        });
    }
    pcntl_async_signals(true);

    // Inherit the environment, but make this invocation finite.
    // Forward the parent's effective timezone explicitly to the new PHP process.
    $env = getenv();
    $env['POLL_ONCE'] = '1';
    $command = [
        PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(),
        dirname(__DIR__, 2) . '/run.php', 'poll',
    ];
    $log('Starting child workflow poll.');
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start child workflow poll');
    }

    // Inherit output streams instead of creating pipes that could fill and deadlock.
    // Use a monotonic deadline so wall-clock adjustments do not extend the timeout.
    $deadline = hrtime(true) + 30_000_000_000;
    while (true) {
        if ($stop) {
            throw new RuntimeException('Child workflow interrupted');
        }
        $info = proc_get_status($process);
        if (!$info['running']) {
            $exitCode = $info['exitcode'];
            break;
        }
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Child workflow poll timed out');
        }
        usleep(100_000);
    }

    // Keep the observed exit code: proc_close may not return it on every PHP version.
    $closedCode = proc_close($process);
    $process = null;
    if ($exitCode < 0) {
        $exitCode = $closedCode;
    }
    if ($exitCode !== 0) {
        throw new RuntimeException("Child workflow poll failed with exit code $exitCode");
    }
    $state['child_poll_completed'] = true;
    $log('Child workflow poll completed; continuing hello.');
} finally {
    // Stop and reap an unfinished child before leaving this node on any exception.
    // This handles this demo's direct child, not arbitrary descendant process trees.
    if (is_resource($process)) {
        $info = proc_get_status($process);
        if ($info['running']) {
            proc_terminate($process, SIGTERM);
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                usleep(100_000);
                $info = proc_get_status($process);
            } while ($info['running'] && hrtime(true) < $deadline);
            if ($info['running']) {
                proc_terminate($process, SIGKILL);
            }
        }
        proc_close($process);
    }

    // Signal configuration is process-global: restore it before the next node runs.
    foreach ($handlers as $signal => $handler) {
        pcntl_signal($signal, $handler);
    }
    pcntl_async_signals($async);
}
