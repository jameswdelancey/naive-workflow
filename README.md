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

## Shared logging

The runner and hello/poll demos use `workflow_log($message)` from `log.php`.
Pass an optional second argument to preserve an event's saved timestamp.
The logger escapes embedded CR/LF and writes one timestamped stderr line.
Require log.php directly for standalone nodes/tools; do not require run.php
just to load functions. Include log.php when copying the runner into fixtures.

## Diagnostic ownership

`log.php` is the sole application stderr writer.
`workflow_log_context($context, $message, $timestamp = null)` adds a consistent
prefix before sanitizing the complete line. Hello uses `hello`, poll uses
`poll`, MES uses `mes-observe`, and status uses `status`.
Saved poll timestamps are preserved; status tables/JSON remain on stdout.

MES and status call `workflow_log_context()` directly; redundant wrappers are removed.
The parallel library owns worker start/completion messages for both one and
many children; the poll wrapper does not duplicate them.
Helpers return errors without logging; the runner reports each returned failure
once after cleanup. A child and its parent may report distinct failure events.
PHP-engine warnings and external child output are outside this logger.

Application messages such as greetings, branch decisions and reduced totals
remain at their call sites rather than gaining one-purpose wrappers.

Run `timeout 30s bash tests/logging.sh` for writer ownership, sanitization,
saved timestamps, clean JSON and failure-message duplication checks.

## Diagnostic options

- `WORKFLOW_LOG_LEVEL=debug|info|warning|error`: minimum severity; default info.
  Error mode suppresses routine ticks, worker lifecycle and application messages,
  but never failures. Retry diagnostics are warnings. Unknown levels use info.
- `WORKFLOW_LOG_FORMAT=json`: newline-delimited JSON on stderr; otherwise text.
  Fields: timestamp, level, context, message, workflow, job, batch, pid, node.
  JSON preserves message newlines as escaped characters and replaces invalid UTF-8.
  Status report stdout is independent of diagnostic format.
- Existing workflow_log/workflow_log_context calls default to info. Pass a fourth
  argument to workflow_log_context to select severity, e.g.
  `workflow_log_context('fetch', $error, null, 'error')`.

The runner derives job/batch labels from its actual job directory, not inherited
labels. Text worker diagnostics include job, batch and PID; JSON always includes
PID, plus workflow/node identity when running under the runner.
Controller lifecycle messages remain in the parent's context and identify the job.
These fields are diagnostic hints, not authenticated process identities.

`workflow_error($operation, $message, $path = null)` builds an error string
without logging. File/JSON/job failures include their operation and relevant path.
Record query failures identify the table. Do not pass passwords, full DSNs or
environment dumps; connection failures report a numeric driver code.
The runner remains responsible for logging returned errors after cleanup.

Run `timeout 30s bash tests/logging-options.sh` for level/JSON/identity/error tests.

## Guarded state

The runner loads state.php. Standalone tools should require it directly.

    $error = workflow_state_set($state, 'name', 'Ada', 'add');
    $error = workflow_state_get($state, 'name', $name, 'string');
    $error = workflow_state_set($state, 'name', 'Grace');
    $error = workflow_state_remove($state, 'name');

Check each returned error; nodes return `['error' => $error]`.
Set defaults to replacing an existing key without changing its concrete type.
Adding requires explicit `add` and rejects existing keys. Removal rejects
missing keys. Nested paths use explicit lists, e.g. `['observed', $table]`;
missing/non-array parents are errors, never silently created.
Get distinguishes missing from present-null and optionally checks a type.
Errors identify the field path and types, not stored values.
For related updates, use a local copy and commit only after all checks succeed.
Do not store PHP references in state; these helpers operate on ordinary
JSON-compatible arrays, not aliases to external mutable variables.

Shipped workflows declare allowed top-level names/types in fields.php.
The runner checks restored input and node output before retry/advance,
including writes that bypass helpers. Fields may be absent until a node needs
them; use guarded reads for prerequisites. Nested invariants remain application
responsibilities. No fields.php retains open-field compatibility.
This is runtime validation, not a sandbox or immutable-state guarantee.

State cannot contain objects/resources, nonfinite numbers, invalid UTF-8 or
recursive arrays; nesting is limited to 64. Failures retain attempt input.
Nullable declarations permit null, but replacement still rejects concrete
type changes; remove then add only for intentional type transitions.
Branch loops must explicitly replace existing fields rather than re-add them.

Copy state.php into isolated runner fixtures.
Run `timeout 30s bash tests/state.sh` alongside the regression suite.

## Constructing node results

Use the procedural constructor instead of writing control arrays by hand:

    return workflow_result();                         // success: null
    return workflow_result($error);                   // null succeeds; string fails
    return workflow_result('Not ready', retry: 5);     // retry after five seconds
    return workflow_result('Try again', retry: 0);     // immediate retry

Signature: `workflow_result(?string $error = null, ?int $retry = null): ?array`.
Retry null means no retry; zero is a real retry delay. Retry requires a nonempty
error message and a nonnegative representable delay. Invalid combinations
produce a normal failure result, never an accidental retry or silent success.
Strict PHP type checking applies when called from strict-types files.

Helpers continue returning error strings or null. Nodes convert those errors
with `return workflow_result($error)`. The constructor does not log or exit.
The runner also validates returned arrays: missing/empty error messages,
unexpected keys and invalid retry types fail with workflow/node context.
Construction reduces mistakes but cannot make mutable PHP arrays opaque.
Null and implicit numbered-file success remain compatible; exit argv remains
a separate contract and is not passed through workflow_result.

The runner loads result.php; standalone nodes/tools and isolated fixtures must
load/copy it explicitly. Run `timeout 30s bash tests/results.sh`.

## Checked next-sequence selection

Use `workflow_next($sequence)` instead of directly assigning nextSequence:

    $error = workflow_next($approved ? 30 : 40);
    return workflow_result($error);

The helper returns null on success or an error string. It accepts a nonnegative
integer/digit string and requires exactly one matching numbered node in the
runner's discovery snapshot. Leading zeroes are ignored, without converting
large sequence strings to integers. Invalid requests preserve the previous
selection; they never silently clear it. Check the error immediately.

Calling the function selects the next node, not an immediate jump or return.
The runner resets selection before every attempt and applies it only after
success. Retry/failure does not apply the selected branch. exit.php cannot
select a sequence. Calling outside an active runner node returns an error.

The runner revalidates selection as a compatibility boundary for legacy direct
assignments. Shipped nodes use the helper. Routing is process-local and separate
from checkpointed application state. Copy next.php into isolated runner fixtures.
Run `timeout 30s bash tests/next.sh`.
