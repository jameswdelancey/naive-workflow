<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Closed, typed execution metadata. Arrays exist only at serialization boundaries. */
final class WorkflowStatus implements \JsonSerializable
{
    private ?string $started = null;
    private ?string $finished = null;
    private ?string $retryAt = null;
    private ?int $retryAfterSeconds = null;
    private ?string $error = null;
    private ?string $retryError = null;

    private function __construct(
        private readonly int $pid,
        private ExecutionStatus $status,
        private ?string $node
    ) {
        if ($pid <= 0) {
            throw new \InvalidArgumentException('Status PID must be positive');
        }
    }

    public static function start(): self
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Cannot determine process ID');
        }
        $status = new self($pid, ExecutionStatus::Running, null);
        $status->started = date('c');
        return $status;
    }

    public static function fromArray(mixed $saved, string $file = 'status'): self
    {
        if (!is_array($saved) ||
            !is_int($saved['pid'] ?? null) || $saved['pid'] <= 0 ||
            !is_string($saved['status'] ?? null) ||
            !array_key_exists('node', $saved) ||
            ($saved['node'] !== null && !is_string($saved['node']))) {
            throw new \RuntimeException("Invalid status fields in $file");
        }

        $execution = ExecutionStatus::tryFrom($saved['status']);
        if ($execution === null) {
            throw new \RuntimeException("Invalid status fields in $file");
        }

        // updated_at is a known, non-authoritative presentation field.
        // StatusRepository always obtains its value from the file snapshot.
        $allowed = [
            'pid', 'status', 'node', 'started', 'finished', 'retry_at',
            'retry_after_seconds', 'error', 'retry_error', 'updated_at',
        ];
        foreach (array_keys($saved) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new \RuntimeException("Unknown status field in $file");
            }
        }

        $result = new self($saved['pid'], $execution, $saved['node']);
        $result->started = self::optionalString($saved, 'started', $file);
        $result->finished = self::optionalString($saved, 'finished', $file);
        $result->retryAt = self::optionalString($saved, 'retry_at', $file);
        $result->error = self::optionalString($saved, 'error', $file);
        $result->retryError = self::optionalString($saved, 'retry_error', $file);
        if (array_key_exists('retry_after_seconds', $saved)) {
            if (!is_int($saved['retry_after_seconds']) || $saved['retry_after_seconds'] < 0) {
                throw new \RuntimeException("Invalid retry delay in $file");
            }
            $result->retryAfterSeconds = $saved['retry_after_seconds'];
        }
        return $result;
    }

    private static function optionalString(array $saved, string $field, string $file): ?string
    {
        if (!array_key_exists($field, $saved)) {
            return null;
        }
        if (!is_string($saved[$field])) {
            throw new \RuntimeException("Invalid $field in $file");
        }
        return $saved[$field];
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function execution(): ExecutionStatus
    {
        return $this->status;
    }

    public function node(): ?string
    {
        return $this->node;
    }

    public function selectNode(string $node): void
    {
        $this->node = $node;
    }

    public function retrying(int $deadline): void
    {
        if ($deadline < 0) {
            throw new \InvalidArgumentException('Invalid retry deadline');
        }
        $this->status = ExecutionStatus::Retrying;
        $this->retryAfterSeconds = max(0, $deadline - time());
        $this->retryAt = date('c', $deadline);
    }

    public function retryError(string $message): void
    {
        $this->retryError = $message;
    }

    public function running(): void
    {
        $this->status = ExecutionStatus::Running;
        $this->retryAfterSeconds = null;
        $this->retryAt = null;
        $this->retryError = null;
    }

    public function complete(): void
    {
        $this->status = ExecutionStatus::Done;
        $this->finished = date('c');
    }

    public function fail(\Throwable $error): void
    {
        $this->status = ExecutionStatus::Failed;
        $this->error = $error->getMessage();
        $this->finished = date('c');
    }

    public function toArray(): array
    {
        // node is required even when null; optional absent fields stay absent.
        return [
            'pid' => $this->pid,
            'status' => $this->status->value,
            'node' => $this->node,
        ] + array_filter([
            'started' => $this->started,
            'finished' => $this->finished,
            'retry_at' => $this->retryAt,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'error' => $this->error,
            'retry_error' => $this->retryError,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __set(string $name, mixed $value): never
    {
        throw new \LogicException("Status properties cannot be assigned externally: $name");
    }

    public function __get(string $name): never
    {
        throw new \LogicException("Status properties cannot be read directly: $name");
    }

    public function __unset(string $name): never
    {
        throw new \LogicException("Status properties cannot be removed: $name");
    }
}
