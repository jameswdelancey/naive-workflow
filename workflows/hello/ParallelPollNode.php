<?php
declare(strict_types=1);

namespace NaiveWorkflow\Hello;

require_once __DIR__ . '/State.php';

/** Start two isolated instances of the real poll workflow, then join tick results. */
class ParallelPollNode extends \NaiveWorkflow\Node
{
    protected function timeoutSeconds(): int
    {
        return 30;
    }

    protected function terminationGraceSeconds(): int
    {
        return 5;
    }

    private function result(string $runtime, \NaiveWorkflow\ChildProcess $child): PollChildResult
    {
        $status = \NaiveWorkflow\WorkflowStatus::fromArray(
            json_decode(file_get_contents($runtime . '/poll.json'), true, 512, JSON_THROW_ON_ERROR)
        );
        $saved = json_decode(file_get_contents($runtime . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($status->execution() !== \NaiveWorkflow\ExecutionStatus::Done ||
            $status->pid() !== $child->pid() ||
            !is_array($saved) || array_keys($saved) !== ['pid', 'state'] ||
            ($saved['pid'] ?? null) !== $child->pid() ||
            !is_array($saved['state'] ?? null) ||
            array_keys($saved['state']) !== ['time']) {
            throw new \RuntimeException('Invalid or incomplete isolated poll result');
        }
        return new PollChildResult(['pid' => $saved['pid'], 'time' => $saved['state']['time']]);
    }

    final public function run(\NaiveWorkflow\WorkflowState $state): mixed
    {
        $mode = getenv('HELLO_CHILD_POLL');
        if ($mode !== false && !$this->enabled('HELLO_CHILD_POLL')) {
            return null;
        }
        $state = State::requireState($state);
        $timeout = $this->timeoutSeconds();
        $grace = $this->terminationGraceSeconds();
        if ($timeout <= 0 || $grace < 0) {
            throw new \InvalidArgumentException('Invalid child timeout or termination grace');
        }

        // Unique per attempt: never reuse a result from a prior failed parent attempt.
        $attempt = bin2hex(random_bytes(16));
        $base = $this->root . '/.runs/hello';
        $directory = $base . '/parallel-' . $attempt;
        if (!mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create parallel poll runtime');
        }
        $children = [];
        foreach (['left', 'right'] as $slot) {
            $runtime = $directory . '/' . $slot;
            if (!mkdir($runtime, 0700)) {
                throw new \RuntimeException('Cannot create child runtime');
            }
            $children[] = new \NaiveWorkflow\ChildProcess(
                'poll-' . $slot,
                [
                    PHP_BINARY, '-d', 'date.timezone=' . date_default_timezone_get(),
                    $this->root . '/child.php', 'poll', $runtime,
                ],
                $this->root,
                array_replace(getenv(), ['POLL_ONCE' => '1'])
            );
        }

        $this->log('Starting parallel poll children: left and right.');
        (new \NaiveWorkflow\ChildProcessGroup())->run($children, $timeout, $grace);
        $joined = new PollJoinResult([
            'attempt' => $attempt,
            'left' => $this->result($directory . '/left', $children[0])->toArray(),
            'right' => $this->result($directory . '/right', $children[1])->toArray(),
        ]);
        // Publish only a complete pair. The parent workflow lock serializes writers.
        (new \NaiveWorkflow\JsonFileStore())->write($base . '/last-poll-join.json', $joined->toArray());
        $state->pollJoin = $joined;
        $this->log("Joined parallel poll results: left={$joined->left->time}, right={$joined->right->time}.");
        return null;
    }
}
