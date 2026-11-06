<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Default for stateless workflows. It cannot acquire application fields. */
final class EmptyWorkflowState extends WorkflowState
{
    public function __construct(array $values = [])
    {
        self::rejectUnknown($values, []);
    }

    public function toArray(): array
    {
        return [];
    }
}
