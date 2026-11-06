<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

/** Shared hello branch action; subclasses supply only the differing values. */
abstract class ResultNode extends \NaiveWorkflow\Node
{
    abstract protected function result(): string;

    protected function nextSequence(): int|string|null
    {
        return null;
    }

    final public function run(array &$state): mixed
    {
        $state['result'] = $this->result();
        $this->log("{$state['name']}: {$state['result']} branch.");
        $sequence = $this->nextSequence();
        if ($sequence !== null) {
            $this->jump($sequence);
        }
        return null;
    }
}
