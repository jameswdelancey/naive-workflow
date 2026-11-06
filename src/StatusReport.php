<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** One report's rows and read errors; never persisted as workflow state. */
final class StatusReport
{
    public function __construct(
        public readonly array $rows,
        public readonly array $errors
    ) {
    }

    public function exitCode(): int
    {
        return $this->errors === [] ? 0 : 1;
    }
}
