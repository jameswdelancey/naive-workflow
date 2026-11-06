<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Owns one nonblocking exclusive workflow lock, including its file handle. */
final class WorkflowLock
{
    private mixed $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    private function __clone(): void
    {
    }

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            throw new \LogicException('Workflow lock is already held by this object');
        }
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        try {
            flock($this->handle, LOCK_UN);
        } finally {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
