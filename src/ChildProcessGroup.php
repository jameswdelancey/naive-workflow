<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Shared lifecycle for one child or a concurrent group; no automatic retries. */
final class ChildProcessGroup
{
    /** @param list<ChildProcess> $children */
    public function run(array $children, int $timeout = 30, int $grace = 5): void
    {
        if (!$children || !array_is_list($children) || $timeout <= 0 || $grace < 0) {
            throw new \InvalidArgumentException('Invalid child timeout or termination grace');
        }
        $seen = [];
        foreach ($children as $child) {
            if (!$child instanceof ChildProcess || isset($seen[spl_object_id($child)])) {
                throw new \InvalidArgumentException('Expected distinct child process objects');
            }
            $seen[spl_object_id($child)] = true;
        }
        foreach (['pcntl_async_signals', 'pcntl_signal', 'pcntl_signal_get_handler'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException("Child workflow requires $function");
            }
        }
        $stop = false;
        $handlers = [];
        $async = pcntl_async_signals();
        try {
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                if (!pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                })) {
                    throw new \RuntimeException('Cannot install child signal handler');
                }
            }
            pcntl_async_signals(true);
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
            // Start every child before waiting for any of them.
            foreach ($children as $child) {
                if ($stop) {
                    throw new \RuntimeException('Child workflow interrupted');
                }
                $child->start();
            }
            $pending = $children;
            while ($pending) {
                if ($stop) {
                    throw new \RuntimeException('Child workflow interrupted');
                }
                foreach ($pending as $index => $child) {
                    $code = $child->collect();
                    if ($code === null) {
                        continue;
                    }
                    unset($pending[$index]);
                    if ($code !== 0) {
                        throw new \RuntimeException(
                            "Child workflow {$child->label} failed with exit code $code"
                        );
                    }
                }
                if ($pending && hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Child workflow ' . reset($pending)->label . ' timed out');
                }
                if ($pending) {
                    usleep(100_000);
                }
            }
            if ($stop) {
                throw new \RuntimeException('Child workflow interrupted');
            }
        } finally {
            try {
                // Broadcast termination before waiting: grace is shared, not per child.
                foreach ($children as $child) {
                    $child->terminate(SIGTERM);
                }
                $deadline = hrtime(true) + $grace * 1_000_000_000;
                do {
                    $running = false;
                    foreach ($children as $child) {
                        $running = $child->running() || $running;
                    }
                    if (!$running || hrtime(true) >= $deadline) {
                        break;
                    }
                    usleep(100_000);
                } while (true);
                foreach ($children as $child) {
                    $child->terminate(SIGKILL);
                }
                foreach ($children as $child) {
                    $child->close();
                }
            } finally {
                foreach ($handlers as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
                pcntl_async_signals($async);
            }
        }
    }
}
