<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once __DIR__ . '/State.php';

/** Shared hello branch action; subclasses supply only the differing values. */
abstract class ResultNode extends \NaiveWorkflow\Node
{
    abstract protected function result(): string;

    public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $state = \NaiveWorkflow\Hello\State::requireState($state);
        $state->result = $this->result();
        $this->log("{$state->name}: {$state->result} branch.");
        return null;
    }
}
