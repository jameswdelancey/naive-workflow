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

Write demo messages to stderr using `fwrite(STDERR, ...)`. Throw exceptions
to signal errors; do not call `exit()` inside nodes.

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

Retries have no automatic limit. Nodes should manage their own retry budget
where needed and make external side effects safe to repeat. The workflow lock
remains held during the wait. Deadlines use wall-clock time, so clock changes
affect waits; this is not a precise timer.

## Conditional paths

Inside a numbered node, select another numbered node using:

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

The command returned by hello's exit node is:

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

## Tests

From the repository directory, run the bounded test suite:

    timeout 30s bash tests/retry.sh &&
    timeout 30s bash tests/status.sh &&
    timeout 40s bash tests/chain.sh &&
    timeout 50s bash tests/child.sh

Tests use temporary workflow copies rather than modifying live checkpoints.
The test harnesses require Bash, PHP and Python 3; handoff and child examples
also require the relevant pcntl functions and enabled process functions.

- `retry.sh`: both hello branches, retry status/state, a wait observation,
  completion and timestamped stderr-only demo output.
- `status.sh`: status states, invalid metadata, JSON/diagnostic separation,
  retry deadlines and the file snapshot timestamp.
- `chain.sh`: handoff ordering, same PID, completion and default stop.
  It replaces only the copied poll exit node with a finite return.
- `child.sh`: actual finite poll mode, distinct PIDs, parent continuation,
  child reaping and child-failure propagation.

These tests do not establish comprehensive signal, timeout, crash-recovery
or descendant-cleanup coverage. Add application-specific tests before using
the child pattern for a service. See `SKILL.md` for migration guidance.
