# Naive workflow

A small PHP CLI workflow runner with sequential nodes, conditional paths,
checkpointing and retryable exceptions. Examples demonstrate both one-way
workflow handoffs and managed child workflows that return to the parent.

## Quick start

From the repository directory (for example, `cd repos/naive-workflow`):

    php run.php hello
    HELLO_APPROVED=1 php run.php hello
    POLL_ONCE=1 php run.php poll
    HELLO_CHILD_POLL=1 HELLO_POLL=0 php run.php hello
    php status.php
    php status.php --json

These examples assume optional mode variables are unset unless shown.
For continuous polling, run `POLL_ONCE=0 php run.php poll`.

Stop polling with Ctrl-C. Demo messages and runtime diagnostics go to stderr;
`status.php` writes its human-readable or JSON output to stdout.

## Demo modes

- `HELLO_APPROVED=1`: select hello's approved branch; otherwise reject.
- `HELLO_CHILD_POLL=1`: run poll as a managed child from `55-child-poll.php`.
  The child receives `POLL_ONCE=1`; hello waits and then continues.
- `HELLO_POLL=1`: hand off to poll from hello's `exit.php`. Hello does not return.
- `POLL_ONCE=1`: stop poll after its current iteration; otherwise self-chain.

Only the exact value `1` enables each option. Child execution and handoff
are independent: enabling both runs a child first and then hands off.
An inherited `POLL_ONCE=1` also makes a handoff finite.

Hello's order is greet, retry demo, choose branch, branch action, finish
message, optional child, then exit/handoff. The finish message precedes the
optional child; it does not mean the entire workflow has completed.

## Workflows and nodes

Each named subdirectory of `workflows/` is a workflow; names must match
`[a-zA-Z0-9_-]+`. Numbered PHP files execute
in lexicographic order; use zero-padded prefixes such as `10-fetch.php` and
`20-process.php`.

Nodes share a JSON-compatible `$state` array. A fresh invocation starts with
an empty array unless it resumes a checkpoint. Successful polling iterations
also start with an empty state; persist data externally if it must survive
between iterations.

Write application diagnostics through inherited `$this->log('message')`
in node subclasses; do not duplicate timestamp formatting or stderr writes.
Throw exceptions to signal errors; do not call `exit()` inside nodes.
See the OOP architecture and node adapter sections below.

An optional `exit.php` runs after the numbered nodes. Explicitly return
`null` to stop (omitting a return is not equivalent), or return a nonempty
list of strings representing argv to replace the current process:

    return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', 'hello'];

The executable path must be absolute and executable. Process replacement
requires `pcntl_exec`; it avoids accumulating processes during polling.
The exit node cannot select a sequence.

## Status and startup

Only one invocation of a workflow can run at a time. The `.runs/` directory
holds locks, the latest status and checkpoints, not execution history.
Status uses `/proc` for best-effort detection of unexpectedly stopped
processes. This is not a health check: PID reuse may hide a stopped run.
If `/proc` is unavailable, the recorded status is retained.

The table includes the saved retry deadline with its original timezone offset.
`UPDATED AT` (JSON: `updated_at`) shows the modification time of the exact
status-file snapshot read, formatted in the status command's configured local
timezone. It is not the query time or a workflow heartbeat. Contents and file
metadata are read through the same handle so atomic replacements cannot mix
snapshots. Missing timestamps display `-` in the table and `null` in JSON.
The filesystem timestamp has second-level display precision and can change
if external tools edit or touch the file; it is not a durability guarantee.
`--json` preserves the recorded metadata; `retry_after_seconds` is a saved
snapshot, not a live countdown. Missing status files report `idle`; unreadable
or malformed files report `unknown`, emit timestamped stderr diagnostics and
make the command exit nonzero while still reporting other workflows.

To start the polling example in the background from the repository directory:

    mkdir -p .runs &&
    { POLL_ONCE=0 nohup php run.php poll >> .runs/poll.log 2>&1 < /dev/null & } &&
    sleep 1 &&
    php status.php

Failed command launches are reported to stderr and exit nonzero, but the
completed workflow remains recorded as done.

## Checkpoints and automatic resume

Before each node, the runner atomically saves the node name and its input
`$state` to `.runs/<workflow>.checkpoint.json`. After an ordinary failure,
run the same workflow again to resume at that node with its saved input
state; changes made during the failed attempt are discarded.

Successful workflows remove their checkpoint. `exit.php` is checkpointed
too, but a failure to launch its returned command occurs after checkpoint
removal and does not resume `exit.php`.

Keep shared state JSON-compatible: arrays, strings, finite numbers, booleans
and null. Objects, resources and closures are not supported.

Nodes must be safe to retry. Files, network requests and other external
effects are not rolled back. A crash after an external effect but before the
next checkpoint can cause that effect to be repeated.

To restart from scratch, stop the workflow first, then remove its checkpoint:

    rm -f .runs/hello.checkpoint.json

## Retryable exceptions

A node can request another attempt by throwing `RetryableException` with a
message and a nonnegative delay in seconds:

    $state['attempts'] = ($state['attempts'] ?? 0) + 1;
    if ($state['attempts'] === 1) {
        throw new RetryableException('Not ready yet', 1);
    }

Unlike an ordinary failure, a retryable exception checkpoints the state
mutated by that attempt. The runner reports `retrying`, waits at least the
requested delay and reruns the same node. The retry deadline and state remain
in the checkpoint if the process stops; restarting resumes the wait or retries
immediately if the deadline has passed.

Retries have no automatic limit. For migrated automatic retries/restarts,
follow the durable bounded restart policy in `SKILL.md`: at most three
automatic retries/restarts per rolling ten minutes, with 5/15/45-second
backoff and metadata outside checkpoint state. This is migration policy,
not runner enforcement; hello is only a small retry demonstration.
Make external effects safe to repeat. The workflow lock remains held during
the wait. Deadlines use wall-clock time, so clock changes affect waits;
this is not a precise timer.

## Conditional paths

Inside a `Node` subclass, select a numbered node with
`$this->jump($state['approved'] ? 30 : 50);`.
Legacy procedural nodes can still use:

    global $nextSequence;
    $nextSequence = $state['approved'] ? 30 : 50;

Or assign directly:

    $GLOBALS['nextSequence'] = 50;

The target matches the leading sequence number: `30` selects
`30-process.php`. Leading zeroes are ignored. A target must match exactly one
numbered node; invalid, missing or ambiguous targets fail the current node.

The variable resets before every attempt. Leave it unset or null to continue
in filename order. Selection takes effect only after the node returns
successfully; it does not skip the remainder of the current PHP file.

After the selected node, normal sequential execution resumes unless it
selects another target. Branch endpoints must explicitly jump over unwanted
alternatives. Forward, backward and same-node jumps are supported; bound
loops yourself. There is no automatic delay or iteration limit for jumps.

## Runnable example: retry and branching

From the directory containing `repos`, run:

    php repos/naive-workflow/run.php hello
    HELLO_APPROVED=1 php repos/naive-workflow/run.php hello

Each fresh run requests a retry on the first attempt and continues on the
second using the checkpointed state. `HELLO_APPROVED=1` selects the approved
branch; otherwise, the rejected branch is selected.

Demo messages and runtime diagnostics use local timestamps with UTC offsets.
PHP's configured `date.timezone` determines local time; if it is set to UTC,
the output remains UTC. For example, to select Los Angeles explicitly:

    php -d date.timezone=America/Los_Angeles repos/naive-workflow/run.php hello

Example session with `America/Los_Angeles` configured (timestamps are
illustrative; the greeting uses the `USER` environment variable):

    jd@jd-macbookpro82:~$ php repos/naive-workflow/run.php hello
    [2026-11-04T12:00:00-08:00] Hello, jd!
    [2026-11-04T12:00:00-08:00] Retry demo: requesting retry on attempt 1.
    [2026-11-04T12:00:00-08:00] hello: 15-retry.php: retryable: Demo node is not ready yet; retrying in 1s
    [2026-11-04T12:00:02-08:00] Retry demo: continued on attempt 2.
    [2026-11-04T12:00:02-08:00] Choosing node 40.
    [2026-11-04T12:00:02-08:00] jd: rejected branch.
    [2026-11-04T12:00:02-08:00] Finished for jd: rejected.
    jd@jd-macbookpro82:~$ HELLO_APPROVED=1 php repos/naive-workflow/run.php hello
    [2026-11-04T12:00:10-08:00] Hello, jd!
    [2026-11-04T12:00:10-08:00] Retry demo: requesting retry on attempt 1.
    [2026-11-04T12:00:10-08:00] hello: 15-retry.php: retryable: Demo node is not ready yet; retrying in 1s
    [2026-11-04T12:00:12-08:00] Retry demo: continued on attempt 2.
    [2026-11-04T12:00:12-08:00] Choosing node 30.
    [2026-11-04T12:00:12-08:00] jd: approved branch.
    [2026-11-04T12:00:12-08:00] Finished for jd: approved.

## Composing workflows

Choose the mechanism based on whether control must return:

| Mechanism | Process | Parent lock | On target failure |
| --- | --- | --- | --- |
| Exit-node handoff | Same PID, process replaced | Released before handoff | Caller remains done |
| Managed child node | Separate PID, parent waits | Held while waiting | Parent node fails |

Neither mechanism transfers `$state` or creates an isolated target instance.
The target uses its own workflow lock and any existing checkpoint. There is
no cross-workflow transaction or exactly-once guarantee.

### One-way handoff

A workflow can return another runner command from `exit.php`. This replaces
the current process after successful completion; it is not a function call
and does not return to the original workflow.

From the repository directory, enable only the handoff and continuous poll:

    HELLO_CHILD_POLL=0 HELLO_POLL=1 POLL_ONCE=0 php run.php hello

Hello finishes, logs `Handing off to poll.` on stderr, and starts poll using
the same PID. Poll then repeats until stopped with Ctrl-C. Without
`HELLO_POLL=1`, hello's exit node explicitly returns null and stops.

Hello's `HandoffNode` adapter returns the equivalent of:

    return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', 'poll'];

The runner marks hello done and releases its lock before replacing itself.
Poll uses its own lock, status and checkpoint. State is not transferred:
poll starts empty unless it resumes its own existing checkpoint. Persist
shared data explicitly if needed. Environment variables are inherited;
PHP CLI `-d` options are not automatically forwarded. Configure
`date.timezone` persistently or include required options in the returned argv.

If poll cannot start, hello remains done; this is not a cross-workflow
transaction. For a call that must return, a controller must explicitly manage
a foreground child and its exit status, signals and cleanup instead.

### Child workflow as a node

Unlike an `exit.php` handoff, a managed child runs in a separate process.
The parent node waits, checks its exit status, then returns so the parent
workflow can continue. The hello example implements this in
`55-child-poll.php`, after its finish message and before its exit node.

From the repository directory:

    HELLO_CHILD_POLL=1 HELLO_POLL=0 php run.php hello

The node starts `php run.php poll` with `POLL_ONCE=1` in the child's environment.
On a fresh run, poll performs one tick and its five-second wait, then returns
null from its exit node. A resumed run continues from its saved node instead. Ordinary poll runs still repeat unless `POLL_ONCE=1` is set.
`HELLO_POLL` controls the separate one-way handoff; keep it `0` when trying
only the child example.

The child inherits stdout/stderr and receives the parent's effective PHP
timezone explicitly. Parent and child have separate state, status, locks
and checkpoints; the parent holds its lock while waiting. An existing poll
checkpoint is resumed, and an already-running poll causes the child invocation
to fail. This is not an isolated per-call workflow instance.

The node requires pcntl signal functions, imposes a 30-second execution timeout,
and treats nonzero child exits as ordinary parent-node failures. On a handled
stop or error it terminates and reaps the direct child, with a five-second
termination grace period. It restores the parent's signal handlers afterward.
SIGKILL or a parent crash cannot guarantee child cleanup; use an external
supervisor when that guarantee is required.

A parent retry may execute an already-completed child again. There is no
cross-workflow transaction or automatic state/result transfer; child effects
must be idempotent. Do not call the same workflow, or create a dependency
cycle that needs a lock held by a waiting ancestor.

## OOP architecture

`run.php` and `status.php` are thin CLI adapters. Both require `src/`.
`src/bootstrap.php` loads the runtime without executing a workflow.

- `Base::log()` is the shared timestamped stderr writer inherited by `Node`,
  `Runner` and `StatusCommand`. Use `$this->log('message')` in subclasses,
  without a timestamp prefix or trailing newline. It escapes embedded CR/LF
  and appends one newline. PHP's configured `date.timezone` controls the
  default timestamp. Poll passes its saved timestamp as the second argument.
  Status tables and JSON remain on stdout. External child output and
  PHP-engine warnings are not intercepted or reformatted by this logger.
- `Node::run(array &$state): mixed` receives checkpoint state by reference.
  Objects, process handles, connections and closures stay local to an attempt,
  never in `$state`. `enabled()` tests for the exact environment value `1`;
  `jump()` selects a sequence; `workflowCommand()` builds workflow argv.
- `Runner::run(string $name): int` returns an exit code for finite runs;
  successful handoffs replace the process and never return. Locks are released
  in `finally` on success and failure. Legacy procedural nodes still execute
  in a limited include scope without the runner's `$this` or local variables.
- `JsonFileStore` publishes runner snapshots by same-directory rename under
  the workflow lock. It can be injected into `Runner`; this is not an fsync
  durability guarantee or a transaction with external effects.
- `RetryableException` remains a global class, loaded from
  `src/RetryableException.php`. Use `\RetryableException` in namespaced code.
- `HandoffNode` has a final `run()` method. Override `workflow()`,
  `shouldHandoff()`, `message()` and, if needed, `forwardTimezone()`.
  It returns argv or null; only the runner performs process replacement.
  Hello and poll do not forward timezone overrides by default.
- `ManagedChildWorkflowNode` owns the foreground-child lifecycle. Override
  `workflow()`, `shouldRun()`, `environment()`, `timeoutSeconds()`,
  `terminationGraceSeconds()`, `completed()` or `completionMessage()` rather
  than duplicating signal, timeout and reaping code. Defaults are a 30-second
  timeout and five-second termination grace. It forwards the effective PHP
  timezone and inherits output streams. It has no automatic failure retries,
  health supervision or descendant-cleanup guarantee.

Hello and poll use OOP node adapters. Hello's workflow-local `ResultNode`
shares branch behavior, and `ChildPollNode` configures the managed poll child.
Poll still uses a five-second sleep and optional self-handoff; it is not a
production scheduler or graceful-shutdown controller.

Mes-observe uses workflow-local `ObservationNode` and `PollRecordNode`.
Production and quality subclasses supply their table and self-branch sequence.
The protected `readRecords()` hook delegates to the existing read-only PDO
adapter; decimal revision helpers remain in `common.php`. Configuration,
deadline and signal checks surround reads. Unchanged records use paced
branches, not failure retries. Read/configuration failures stop immediately.
Its receipt uses a unique temporary file, complete-write check and cleanup.
See `workflows/mes-observe/README.md` before adapting its observe-only behavior.

## Node adapters

Use a small numbered adapter for new nodes; for example:

    <?php
    declare(strict_types=1);

    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    return (new class extends \NaiveWorkflow\Node {
        public function run(array &$state): mixed
        {
            $state['visits'] = ($state['visits'] ?? 0) + 1;
            $this->log('Visit ' . $state['visits']);
            return null;
        }
    })->run($state);

Anonymous subclasses can be included again during retries or jumps. Put named
classes in non-numbered helper files and load them with `require_once`.
Adapters must return `run()`'s result, especially for `exit.php`. Returning
null from a numbered node means ordinary success/fall-through, not workflow
completion. Keep common execution in base classes and override only the
configuration or hooks that differ. Preserve checkpoint filenames and state
schemas when refactoring workflows that may have saved runs.

## Tests

Run the bounded regression suite from the repository directory:

    timeout 15s bash tests/logging.sh &&
    timeout 30s bash tests/status.sh &&
    timeout 30s bash tests/runner.sh &&
    timeout 30s bash tests/oop.sh &&
    timeout 15s bash tests/templates.sh &&
    timeout 30s bash tests/retry.sh &&
    timeout 40s bash tests/chain.sh &&
    timeout 50s bash tests/child.sh &&
    timeout 20s bash tests/mes-oop.sh &&
    timeout 40s bash tests/mes-observe.sh

Tests use isolated temporary copies. Copy `src/` alongside either CLI entry
point and copy the required workflow directories, including non-numbered
helper files. Never delete live checkpoints or run an unattended infinite
poller for a test. Harnesses require Bash, PHP and Python 3; child/handoff
tests require enabled process and pcntl functions. MES signal checks also
use the PHP POSIX extension. Fake MES adapters do not require a live database.

- `logging.sh`: shared base logger, single application stderr writer,
  non-UTC timestamps, escaped multiline diagnostics and clean status JSON.
- `status.sh`: status states, validation, retry deadlines and snapshot mtime.
- `runner.sh`: reusable runner, legacy include scope, failure checkpoints,
  lock release/contention and injected storage failures.
- `oop.sh`: state references, ordinary resume versus retry mutations,
  selector reset, branching and child configuration hooks.
- `templates.sh`: shared result actions, repeated inclusion, exact mode flags
  and handoff argv configuration without process replacement.
- `retry.sh`: both hello branches, retry state/status, observed wait,
  final cleanup and timestamped stderr-only hello/poll output.
- `chain.sh`: actual handoff ordering, same PID, completion and default stop;
  only the copied poll exit node is replaced to make the fixture finite.
- `child.sh`: real finite poll child, distinct PIDs, parent continuation,
  reaping and child-failure propagation.
- `mes-oop.sh`: polling hooks, paced branches, revision validation,
  read/configuration/deadline failures and scoped signal restoration.
- `mes-observe.sh`: fake-adapter inserts/updates, sequential observations,
  large revisions, deadline persistence, SIGTERM/resume and receipt creation.

These tests do not establish live MySQL connectivity, SQL permissions,
network timeout behavior, comprehensive child signal/timeout or descendant
cleanup, crash durability, durable retry budgets, or cron/DST correctness.
Add application-specific coverage and report which tests actually ran.

See `SKILL.md` for migration policy, controller patterns and additional
application-specific verification requirements.
