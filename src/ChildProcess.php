<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** One direct managed child. Handles remain local, never in checkpoint state. */
final class ChildProcess
{
    private mixed $process = null;
    private ?int $pid = null;

    public function __construct(
        public readonly string $label,
        private readonly array $command,
        private readonly string $directory,
        private readonly array $environment
    ) {
        if (!$command || !array_is_list($command) ||
            count(array_filter($command, 'is_string')) !== count($command) ||
            !str_starts_with($command[0], '/') || !is_executable($command[0]) ||
            !is_dir($directory)) {
            throw new \InvalidArgumentException('Invalid child process configuration');
        }
    }

    public function start(): void
    {
        if ($this->pid !== null || is_resource($this->process)) {
            throw new \LogicException('Child process cannot be restarted');
        }
        $this->process = proc_open($this->command, [
            0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
        ], $pipes, $this->directory, $this->environment);
        if (!is_resource($this->process)) {
            throw new \RuntimeException("Cannot start child workflow {$this->label}");
        }
        $this->pid = proc_get_status($this->process)['pid'];
    }

    public function pid(): int
    {
        return $this->pid ?? throw new \LogicException('Child has not started');
    }

    public function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /** Null means still running; otherwise reap and return the observed exit code. */
    public function collect(): ?int
    {
        if (!is_resource($this->process)) {
            throw new \LogicException('Child has no process handle');
        }
        $info = proc_get_status($this->process);
        if ($info['running']) {
            return null;
        }
        $closed = proc_close($this->process);
        $this->process = null;
        return $info['exitcode'] < 0 ? $closed : $info['exitcode'];
    }

    public function terminate(int $signal): void
    {
        if ($this->running()) {
            proc_terminate($this->process, $signal);
        }
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}
