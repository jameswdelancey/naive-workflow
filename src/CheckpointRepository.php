<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/**
 * Owns checkpoint persistence, not execution policy.
 * Callers must hold the workflow lock. The on-disk schema stays unchanged.
 */
class CheckpointRepository
{
    public function __construct(
        private readonly string $path,
        private readonly JsonFileStore $store
    ) {
    }

    /** Read structural fields; the runner resolves the node before the deadline. */
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $saved = json_decode(
            file_get_contents($this->path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($saved) ||
            !is_string($saved['node'] ?? null) ||
            !is_array($saved['state'] ?? null)) {
            throw new \RuntimeException('Invalid checkpoint');
        }
        return $saved;
    }

    public function retryDeadline(array $saved): ?int
    {
        $retryAt = $saved['retry_at'] ?? null;
        if ($retryAt !== null && (!is_int($retryAt) || $retryAt < 0)) {
            throw new \RuntimeException('Invalid checkpoint retry deadline');
        }
        return $retryAt;
    }

    public function save(string $node, WorkflowState $state, ?int $retryAt = null): void
    {
        if ($retryAt !== null && $retryAt < 0) {
            throw new \InvalidArgumentException('Invalid checkpoint retry deadline');
        }
        $data = ['node' => $node, 'state' => $state->toArray()];
        if ($retryAt !== null) {
            $data['retry_at'] = $retryAt;
        }
        $this->store->write($this->path, $data);
    }

    public function remove(): void
    {
        if (is_file($this->path) && !unlink($this->path)) {
            throw new \RuntimeException('Cannot remove checkpoint');
        }
    }
}
