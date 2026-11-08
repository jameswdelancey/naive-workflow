# Naive workflow

A procedural PHP CLI workflow runner: named functions, arrays, explicit error
returns and ordinary control flow. No application classes, closures, or
exception-based control flow.

## Run

    php run.php hello
    HELLO_APPROVED=1 php run.php hello
    POLL_ONCE=1 php run.php poll
    HELLO_CHILD_POLL=1 HELLO_POLL=0 php run.php hello
    php status.php
    php status.php --json

Only the exact value `1` enables a demo option. `HELLO_POLL=1` enables a
one-way handoff to poll. Poll self-chains unless `POLL_ONCE=1`; stop continuous
polling with Ctrl-C. Child execution and handoff are independent options.

Diagnostics use timestamped stderr. Status reports use stdout. PHP's configured
timezone controls timestamps; use `php -d date.timezone=America/Los_Angeles ...`
when an explicit timezone is needed.

## Node contract

Create `workflows/<name>/`, where names match `[a-zA-Z0-9_-]+`.
Numbered PHP files execute in lexicographic order. Use consistently padded,
unique leading sequence numbers.

Nodes share `$state`, which must contain JSON-compatible data only. Keep
database/process handles local to a node.

A numbered node returns:

    return null;                                 // success
    return ['error' => 'Invalid configuration'];  // stop, exit code 1
    return ['retry' => 5, 'error' => 'Not ready']; // retry this node

For compatibility, reaching the end of a numbered file without a return
also succeeds. Other numbered-node return values are rejected.

A failure retains the checkpoint containing the attempt's INPUT state.
A retry checkpoints the attempt's MUTATED state plus an absolute retry
deadline, waits while holding the workflow lock, then reruns the same node.
The retry delay must be a nonnegative integer number of seconds.

Retry state and deadlines survive manual restarts. Deadlines use wall-clock
time, so clock changes affect waits. There is no built-in retry budget.
Use durable, bounded retry authorization for production work.

Functions follow the same explicit-error convention: return an error string
or null; functions returning data may accept a final by-reference `$error`
argument. Check errors immediately and clean up resources before returning.
Do not throw exceptions or call exit/die from a node.

Unexpected PHP runtime errors are bugs, not retry requests. A shutdown handler
makes a best-effort failed status and preserves the existing checkpoint; it
does not guarantee node-resource cleanup or graceful signal handling.

## Conditional paths

    $GLOBALS['nextSequence'] = $state['approved'] ? 30 : 40;
    return null;

A target must match exactly one numbered file's leading sequence number.
Leading zeroes are ignored. Missing/ambiguous/invalid targets fail.
No selection means sequential execution.

Selection applies after successful return and resets before each attempt.
A retry ignores the attempted branch. Branch endpoints must explicitly jump
over alternatives. Backward/self jumps require bounds, waits and shutdown
checks; they are not a retry policy.

## Checkpoints and locks

`.runs/` contains private workflow locks, checkpoints and latest status.
Ordinary invocations use one nonblocking exclusive lock per workflow; job
invocations use isolated locks. Locks remain held during
retry waits and time spent waiting for a managed child.

Before each attempt, the runner atomically publishes the node and input state.
After success, it checkpoints the selected next node and its input state.
A manual rerun resumes the checkpoint. Successful completion removes it.

Atomic rename prevents partial JSON snapshots; it is not an fsync durability
guarantee. External effects are not rolled back, and a crash can replay them.
Require idempotency: this is not exactly-once execution.

To start fresh, first stop the workflow, inspect/archive its checkpoint, then
deliberately remove that checkpoint. Never remove a live workflow's checkpoint.

## Exit and composition

An optional `exit.php` explicitly returns null to stop, or an argv list:

    return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', 'poll'];

Its first argument must be an absolute executable path. A successful handoff
uses `pcntl_exec`: same PID, no accumulating child processes, no return to the
caller. The runner marks the caller done and releases its lock first.
A failed launch exits nonzero but does not restore the completed checkpoint.
`exit.php` cannot select a sequence. Error/retry results are also supported.

`HELLO_CHILD_POLL=1` runs a bounded parallel map followed by reduce:

    HELLO_CHILD_POLL=1 HELLO_POLL=0 \
      HELLO_CHILD_ITEMS='[3,1,2,4]' HELLO_CHILD_CONCURRENCY=2 php run.php hello

Each item runs an isolated finite poll workflow. `30-map.php` squares the input;
hello's `60-reduce.php` sums the results: 30 for this example.
Defaults: `[1]`, four concurrent workers, a 30-second whole-map timeout.
An empty map reduces to zero. Items must be integers from -1000000 to 1000000;
at most 256 items and 1-32 concurrent workers are accepted.

### Child-poll library

`child-poll.php` owns poll input validation, job construction, the parallel
controller call and successful result collection. A single item uses exactly
the same path as multiple items; there is no separate singular child launcher.

The optional workflow node is only an enable check and library call:

    if (getenv('HELLO_CHILD_POLL') !== '1') {
        return null;
    }
    require_once dirname(__DIR__, 2) . '/child-poll.php';
    $error = workflow_child_poll($state);
    return $error === null ? null : ['error' => $error];

Other runner nodes can require the same library and call
`workflow_child_poll($state)` directly, without copying child management code.
The library uses `HELLO_CHILD_ITEMS` and `HELLO_CHILD_CONCURRENCY`, defaults
to one item, and stores `child_poll_results` and `child_poll_completed` only
after success. Reduction remains a separate application node.

### Procedural parallel API

Require `parallel.php` inside a runner node:

    $jobs = [
        ['workflow' => 'poll', 'state' => ['item' => 3],
         'env' => ['POLL_ONCE' => '1']],
        ['workflow' => 'poll', 'state' => ['item' => 4],
         'env' => ['POLL_ONCE' => '1']],
    ];
    $results = [];
    $error = workflow_parallel($jobs, 2, 30, $results);
    if ($error !== null) {
        return ['error' => $error];
    }
    $state['mapped'] = $results;
    return null;

Jobs may select different workflows. Results are final child states in input
order, not completion order. The helper returns an error string or null and
exposes no partial results on error. Limits: 256 jobs, 32 active workers,
3600 seconds including queueing/startup, 8 MiB per result.
State must be JSON-compatible. Child stdout/stderr are inherited, not used
as a result channel. The parent's effective timezone is forwarded explicitly.

Each job owns an isolated lock, checkpoint, status and result under
`.runs/<parent>/parallel/batch-*/job-<index>/`. Ordinary workflow locks remain
unchanged; parallel poll jobs do not contend with each other or a normal poll.
The parent retains its lock. Inputs are explicit rather than inherited state.

`php run.php <workflow> --job-dir <absolute-existing-directory>` enables job
mode. The caller writes input.json there before launching. A fresh invocation
loads input; an existing checkpoint resumes saved state. Successful completion
atomically writes final state to output.json. Consumers must check both exit
code zero and valid output. Stale output is removed after acquiring the lock.
Job workflows must stop: exec handoffs from exit.php are rejected.

On child failure, timeout, SIGINT, SIGTERM or SIGHUP, stop launching, terminate
all active siblings, allow a shared five-second grace period, then kill/reap
unfinished direct children. Restore signal handlers before returning.
SIGKILL, parent crashes, runtime bugs and arbitrary descendants still require
external supervision where cleanup must be guaranteed.

Batch input/output/runtime files are retained for inspection. There is no
automatic retention cleanup; remove old batches only when unused.
`status.php` reports ordinary runs; inspect each job's runtime JSON directly.

Reduce only after every mapper succeeds. Results are checkpointed as input
to the separate reduce node, so reduce failures need not rerun the map.
A failed/replayed map starts a new batch and may repeat completed child effects;
successful jobs from failed batches are not automatically reused. Use stable
application idempotency keys and durable deduplication. This is not exactly-once
execution, a distributed transaction, or shared-resource synchronization.
Avoid recursive/cyclic calls: isolated locks do not prevent unbounded recursion.

## Status

Missing status files report idle. Malformed/unreadable files report unknown
and produce a nonzero command exit while other workflows are still reported.
`/proc` provides best-effort stopped-process detection, not a health check;
PID reuse may hide a stopped process.

`RETRY AT` preserves the recorded deadline. `retry_after_seconds` is a saved
snapshot, not a live countdown. `UPDATED AT` / `updated_at` is the modification
time of the exact open status-file snapshot, not query time or a heartbeat.
Human output is sanitized and width-bounded; JSON preserves recorded metadata.

## Examples

Hello: greet, one explicit retry, select approved/rejected branch, finish
message, optional managed child, exit/handoff. The finish message precedes the
optional child and is not the workflow completion status.

MES observer: see `workflows/mes-observe/README.md`. Its record adapter uses
the procedural mysqli API, not PDO or Active Record classes.

## Tests

Run from this directory:

    timeout 30s bash tests/retry.sh &&
    timeout 30s bash tests/status.sh &&
    timeout 40s bash tests/chain.sh &&
    timeout 50s bash tests/child.sh &&
    timeout 30s bash tests/mes-observe.sh &&
    timeout 30s bash tests/procedural.sh &&
    timeout 60s bash tests/parallel.sh

Tests use temporary copies and fake MES records, never live checkpoints or
a live database. Bash, Python 3 and PHP CLI are required; process composition
also requires pcntl and enabled process functions. Live MySQL, production
network behavior, comprehensive signal/timeout recovery, descendant cleanup
and exactly-once effects are not established by these tests.
