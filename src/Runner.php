<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RetryableException;
use RuntimeException;
use Throwable;

/**
 * Executes legacy script adapters and OOP adapters under the same checkpoint
 * contract. Runtime objects never become part of workflow state.
 */
class Runner extends Base
{
    protected readonly string $root;
    protected readonly JsonFileStore $store;

    public function __construct(?string $root = null, ?JsonFileStore $store = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__));
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Invalid workflow repository root');
        }
        $this->root = $resolved;
        $this->store = $store ?? new JsonFileStore();
    }

    /**
     * Preserve the original isolated include scope: scripts receive state,
     * not the Runner's local variables or $this.
     */
    protected function executeNode(string $file, \NaiveWorkflow\WorkflowState $state): mixed
    {
        return (static function (string $file, \NaiveWorkflow\WorkflowState $state): mixed {
            return require $file;
        })($file, $state);
    }

    /** Non-numbered state.php optionally returns a WorkflowState subclass name. */
    protected function createState(string $directory, array $values): WorkflowState
    {
        $file = $directory . '/state.php';
        $class = is_file($file)
            ? (static function (string $file): mixed { return require $file; })($file)
            : EmptyWorkflowState::class;
        if (!is_string($class) || !is_a($class, WorkflowState::class, true)) {
            throw new RuntimeException('state.php must return a WorkflowState class name');
        }
        return new $class($values);
    }

    protected function runtimeDirectory(): string
    {
        return $this->root . '/.runs';
    }

    /** Called before checkpoint removal; a failure retains resumable state. */
    protected function completed(WorkflowState $state): void
    {
    }

    /** Returns a CLI exit code; a successful process handoff never returns. */
    public function run(string $name): int
    {
        $root = $this->root;
        // Accept a simple existing workflow name; reject path separators and traversal.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name) || !is_dir("$root/workflows/$name")) {
            $this->log("Usage: php run.php <workflow>");
            return 1;
        }

        // Store checkpoints, status and locks in a private runtime directory.
        $dir = "$root/workflows/$name";
        $runs = $this->runtimeDirectory();
        if (!is_dir($runs) && !mkdir($runs, 0700, true) && !is_dir($runs)) {
            return 1;
        }

        // Hold a nonblocking exclusive lock throughout execution, including retry waits.
        $lock = new WorkflowLock("$runs/$name.lock");
        if (!$lock->acquire()) {
            $this->log("$name: already running or cannot acquire lock");
            return 1;
        }

        try {
            $write = [$this->store, 'write'];
            $include = fn(string $file, \NaiveWorkflow\WorkflowState $state): mixed => $this->executeNode($file, $state);

            // Checkpoints control resumption; status metadata describes the latest invocation.
            $checkpoint = new CheckpointRepository("$runs/$name.checkpoint.json", $this->store);
            $status = WorkflowStatus::start();
            $save = static function () use ($status, $write, $runs, $name): void {
                $write("$runs/$name.json", $status->toArray());
            };

            // Start fresh unless a checkpoint restores state and a retry deadline.
            $state = null;
            $next = null;
            $retryAt = null;
            try {
                $definition = new WorkflowDefinition($dir);
                $steps = $definition->steps;

                // Resume the saved node rather than replaying earlier completed nodes.
                $start = 0;
                $saved = $checkpoint->load();
                if ($saved !== null) {
                    $status->selectNode($saved['node']);
                    $start = $definition->positionOf($saved['node']);

                    // Retry deadlines are absolute Unix timestamps, independent of display timezone.
                    $retryAt = $checkpoint->retryDeadline($saved);
                    $this->log("$name: resuming {$saved['node']}");
                }

                $state = $this->createState($dir, $saved['state'] ?? []);
                $save();
                // Execute nodes in order unless a successful node chooses a different position.
                for ($position = $start; $position < count($steps); $position++) {
                    $node = $steps[$position];
                    $status->selectNode($node);
                    $save();

                    // Repeat only this node on retryable exceptions; there is no built-in retry budget.
                    while (true) {
                        // Publish the waiting state and honor a new or restored deadline.
                        if ($retryAt !== null) {
                            $status->retrying($retryAt);
                            $save();

                            // Preserve the deadline across interruptions and restarts.
                            // Recheck after interrupted sleeps; wall-clock changes also affect this wait.
                            while (($remaining = $retryAt - time()) > 0) {
                                sleep($remaining);
                            }
                            $retryAt = null;
                        }

                        // Clear retry diagnostics before each attempt; branch results are local.
                        $status->running();
                        $save();

                        // Ordinary failures retain the input state of this attempt.
                        $checkpoint->save($node, $state);
                        try {
                            // A normal return ends the retry loop; ordinary exceptions propagate outward.
                            $result = $include("$dir/$node", $state);
                            break;
                        } catch (RetryableException $retry) {
                            // Unlike an ordinary failure, a retry preserves mutations made to state.
                            // Round upward so the requested delay is never shortened.
                            $retryAt = $retry->retryAfterSeconds === 0
                                ? time()
                                : (int) ceil(microtime(true) + $retry->retryAfterSeconds);
                            $checkpoint->save($node, $state, $retryAt);
                            $status->retryError($retry->getMessage());
                            $this->log(
                                "$name: $node: retryable: {$retry->getMessage()}; " .
                                "retrying in {$retry->retryAfterSeconds}s"
                            );
                        }
                    }

                    // One branch API: a successfully returned Jump result.
                    if ($result instanceof Jump) {
                        if ($node === 'exit.php') {
                            throw new RuntimeException('exit.php cannot return a Jump');
                        }
                        $position = $definition->resolve($result->sequence) - 1;
                    } elseif ($node === 'exit.php') {
                        $next = $result === null ? null : new HandoffCommand($result);
                    }

                    // Persist the selected next node and its input state before continuing.
                    if (isset($steps[$position + 1])) {
                        $checkpoint->save($steps[$position + 1], $state);
                    }
                }

                // Successful completion removes resumable state before publishing done.
                $this->completed($state);
                $checkpoint->remove();
                $status->complete();
                $save();
            } catch (Throwable $error) {
                // Report failure without deleting any remaining checkpoint; external effects stay applied.
                $status->fail($error);
                try {
                    $save();
                } catch (Throwable $ignored) {
                    // Do not let a second status-write failure hide the original error.
                }
                $this->log("$name: {$error->getMessage()}");
                return 1;
            }
        } finally {
            // Release on success and failure, including repeated in-process invocations.
            // A self-handoff must acquire its own lock after this one is released.
            $lock->release();
        }

        // Replace this process without spawning a child. Success never returns here.
        // If replacement fails, the workflow is already done and its checkpoint is gone.
        if ($next !== null) {
            $next->execute();
            $this->log("$name: cannot execute {$next->executable()}");
            return 1;
        }
        return 0;
    }
}
