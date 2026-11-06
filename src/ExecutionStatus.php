<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Persisted execution states; idle/unknown/stopped are report-only states. */
enum ExecutionStatus: string
{
    case Running = 'running';
    case Retrying = 'retrying';
    case Done = 'done';
    case Failed = 'failed';
}
