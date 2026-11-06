<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RuntimeException;

/** Shared foreground-child lifecycle with workflow-specific configuration hooks. */
abstract class ManagedChildWorkflowNode extends Node
{
    abstract protected function workflow(): string;

    protected function shouldRun(): bool
    {
        return true;
    }

    protected function environment(): array
    {
        return [];
    }

    protected function timeoutSeconds(): int
    {
        return 30;
    }

    protected function terminationGraceSeconds(): int
    {
        return 5;
    }

    protected function completed(\NaiveWorkflow\WorkflowState $state): void
    {
    }

    protected function completionMessage(): string
    {
        return 'Child workflow ' . $this->workflow() . ' completed.';
    }

    final public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        if (!$this->shouldRun()) {
            return null;
        }

        $workflow = $this->workflow();
        $this->workflowCommand($workflow);
        $timeout = $this->timeoutSeconds();
        $grace = $this->terminationGraceSeconds();
        if ($timeout <= 0 || $grace < 0) {
            throw new \InvalidArgumentException('Invalid child timeout or termination grace');
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

        try {
            // Handlers only request a stop. Resource cleanup happens in finally.
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
            pcntl_async_signals(true);

            // Inherit the environment and apply child-only overrides.
            // Forward the parent's effective timezone explicitly to the new PHP process.
            $env = array_replace(getenv(), $this->environment());
            $command = $this->workflowCommand($workflow, true);
            $this->log("Starting child workflow $workflow.");
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => STDOUT,
                2 => STDERR,
            ], $pipes, $this->root, $env);
            if (!is_resource($process)) {
                throw new RuntimeException("Cannot start child workflow $workflow");
            }

            // Inherit output streams instead of creating pipes that could fill and deadlock.
            // Use a monotonic deadline so wall-clock adjustments do not extend the timeout.
            $deadline = hrtime(true) + $timeout * 1_000_000_000;
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
                    throw new RuntimeException("Child workflow $workflow timed out");
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
                throw new RuntimeException("Child workflow $workflow failed with exit code $exitCode");
            }
            $this->completed($state);
            $this->log($this->completionMessage());
        } finally {
            // Stop and reap an unfinished child before leaving this node on any exception.
            // This handles this demo's direct child, not arbitrary descendant process trees.
            if (is_resource($process)) {
                $info = proc_get_status($process);
                if ($info['running']) {
                    proc_terminate($process, SIGTERM);
                    $deadline = hrtime(true) + $grace * 1_000_000_000;
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
        return null;
    }
}
