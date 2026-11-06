<?php
declare(strict_types=1);

namespace NaiveWorkflow\MesObserve;

require_once __DIR__ . '/ObservationNode.php';

/** Only the table and paced self-branch differ between observation stages. */
abstract class PollRecordNode extends ObservationNode
{
    abstract protected function table(): string;

    abstract protected function sequence(): int;

    final public function run(array &$state): mixed
    {
        $this->poll($state, $this->table(), $this->sequence());
        return null;
    }
}
