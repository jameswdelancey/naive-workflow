<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** A finite child invocation with a caller-owned private runtime directory. */
final class IsolatedRunner extends Runner
{
    private readonly string $runtime;

    public function __construct(string $root, string $runtime)
    {
        parent::__construct($root);
        $resolved = realpath($runtime);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Invalid isolated runtime directory');
        }
        $this->runtime = $resolved;
    }

    protected function runtimeDirectory(): string
    {
        return $this->runtime;
    }

    protected function completed(WorkflowState $state): void
    {
        $this->store->write($this->runtime . '/result.json', [
            'pid' => getmypid(), 'state' => $state->toArray(),
        ]);
    }
}
