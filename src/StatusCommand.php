<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Coordinates collection, diagnostics and rendering; reports stay on stdout. */
class StatusCommand extends Base
{
    protected readonly string $root;
    protected readonly StatusRepository $repository;
    protected readonly StatusFormatter $formatter;

    public function __construct(
        ?string $root = null,
        ?StatusRepository $repository = null,
        ?StatusFormatter $formatter = null
    ) {
        $resolved = realpath($root ?? dirname(__DIR__));
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Invalid workflow repository root');
        }
        $this->root = $resolved;
        $this->repository = $repository ?? new StatusRepository($this->root);
        $this->formatter = $formatter ?? new StatusFormatter();
    }

    public function run(array $argv): int
    {
        $report = $this->repository->collect();
        foreach ($report->errors as $error) {
            $this->log("status: $error");
        }
        echo in_array('--json', $argv, true)
            ? $this->formatter->json($report->rows)
            : $this->formatter->table($report->rows);
        return $report->exitCode();
    }
}
