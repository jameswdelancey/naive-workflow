# Naive workflow

A procedural PHP CLI workflow runner with sequential nodes, checked routing,
checkpoints, explicit retries, workflow handoffs and bounded parallel map/reduce.
Application code uses named functions, arrays and explicit error returns.

## Quick start

Run from the repository directory:

    php run.php hello
    HELLO_APPROVED=1 php run.php hello
    POLL_ONCE=1 php run.php poll
    HELLO_CHILD_POLL=1 HELLO_POLL=0 \
      HELLO_CHILD_ITEMS='[3,1,2,4]' HELLO_CHILD_CONCURRENCY=2 php run.php hello
    php status.php
    php status.php --json

Only the exact value `1` enables an option:

| Variable | Behavior |
| --- | --- |
| HELLO_APPROVED | Select the approved rather than rejected branch. |
| HELLO_CHILD_POLL | Enable the map/reduce demo; disabled by default. |
| HELLO_POLL | Enable a one-way exit handoff to poll. |
| POLL_ONCE | Stop poll after its current iteration instead of self-handoff. |

Map execution and exit handoff are independent. An inherited POLL_ONCE=1 also
makes an exit handoff finite. Continuous poll can be stopped with Ctrl-C.

Hello runs greet, retry demonstration, decision, selected branch, finish
message, optional map, reduce, then exit. Its finish message precedes child
work and does not mean the workflow has completed. The example above squares
3, 1, 2 and 4 in workers and reduces their results to 30.

## Runtime files

| File | Responsibility |
| --- | --- |
| run.php | CLI execution, locks, checkpoints, status, retry and handoff. |
| status.php | Ordinary workflow status reports. |
| result.php | workflow_result, workflow_exit and boundary validation. |
| state.php | Guarded state access and validation. |
| next.php | Checked next-node selection. |
| log.php | Contextual diagnostics and error-string construction. |
| map.php | Generic map adaptation and optional parent-state publication. |
| parallel.php | Isolated child lifecycle, bounded concurrency and ordered results. |
| reduce.php | Ordered reduction and guarded output publication. |

The runner loads log.php, state.php, result.php and next.php. Nodes require
map.php or reduce.php when needed. Do not require run.php merely to load helpers:
it executes a workflow. Standalone tools must explicitly load their dependencies.

## Nodes and results

Create `workflows/<name>/`; names match `[a-zA-Z0-9_-]+`. Numbered PHP files run
in lexicographic order. Use consistently padded, unique leading numbers.
Resolve paths via __DIR__ and keep resource handles local to each node.

All explicit numbered-node outcomes use:

    return workflow_result();                       // success, including skips
    return workflow_result($error);                 // null succeeds; string fails
    return workflow_result('Not ready', retry: 5);   // retry after a delay
    return workflow_result('Try again', retry: 0);   // immediate retry

The signature is `workflow_result(?string $error = null, ?int $retry = null): ?array`.
Retry requires a nonempty error message and nonnegative representable delay.
Invalid combinations produce a failure result. The runner also rejects malformed
control arrays. Strict argument checking depends on the caller's strict_types.

Do not hand-build node-control arrays, use bare null success returns, throw
application exceptions or call exit/die in nodes. Helpers retain their own
data/error contracts: generally null on success, an error string on failure,
with output arguments where needed. Check errors immediately and convert them
with workflow_result only at the node boundary. Cleanup precedes return.

## Guarded state

Nodes share a JSON-compatible `$state` array:

    $error = workflow_state_set($state, 'name', 'Ada', 'add');
    if ($error !== null) {
        return workflow_result($error);
    }
    $error = workflow_state_get($state, 'name', $name, 'string');
    return workflow_result($error);

- Set defaults to replacing an existing field without changing its concrete type.
- Explicit `add` rejects existing fields; remove rejects missing fields.
- Nested paths are key lists such as `['observed', $table]`, not dotted strings.
- Missing/non-array parents are errors; helpers never silently create them.
- Get distinguishes missing from present-null and optionally checks a type.
- For related updates, use a local copy and commit only after every check succeeds.

Use `workflow_state_remove($state, $path)` for deliberate removal.
Errors identify paths/types rather than stored values. Do not store references,
objects, resources, recursive arrays, nonfinite numbers or invalid UTF-8.
Validation limits nesting to 64. Helpers operate on ordinary value arrays,
not aliases to external mutable variables.

Shipped workflows declare allowed top-level names/types in fields.php.
The runner validates restored input and node output before retry/advance.
Absent fields are allowed until a guarded read requires them. Supported types
are string, int, float, bool, array and null, with an optional `?` prefix.
Nullable schemas do not allow a replacement to change an existing concrete type.
Workflows without fields.php retain open-field compatibility and JSON checks.
Nested invariants remain application responsibilities; this is not a sandbox.

## Checked routing

    return workflow_result(workflow_next($approved ? 30 : 40));

workflow_next accepts a nonnegative integer/digit string identifying exactly
one numbered node. Leading zeroes are ignored without integer conversion.
Invalid requests return an error and preserve the previous selection.

Selection does not immediately return from the current node. The runner resets
it before each attempt, applies it only after success, and ignores it on retry
or failure. No selection means fall-through, not completion.
Branch endpoints must skip unwanted alternatives. Bound and pace backward/self
branches and check shutdown requests. exit.php cannot select a sequence.

## Checkpoints, retries and completion

Ordinary invocations hold one nonblocking exclusive lock per workflow, including
retry waits and child execution. `.runs/` stores locks, latest status and
checkpoints, not execution history.

Before each attempt the runner publishes its node and input state. After
success it checkpoints the selected next node and its input. Ordinary failure
retains attempt input; retry checkpoints mutated state and an absolute deadline.
Manual invocation resumes the checkpoint and saved wait. Deadlines use wall-clock
time, so clock changes affect retry waits. There is no automatic retry budget.

Completion removes the checkpoint and discards shared state. Store durable
schedules, budgets, receipts and deduplication metadata separately.
Atomic rename prevents partial JSON snapshots; it is not fsync durability.
External effects are not rolled back and can repeat after crashes or replay.
Use application idempotency keys; this is not exactly-once processing.

Unexpected PHP runtime errors are bugs, not retries. Shutdown reporting is
best-effort and cannot guarantee resource cleanup or graceful signal handling.
To start fresh, first stop the workflow and inspect/archive its checkpoint.
Never remove or rewrite a live checkpoint.

## Checked exits

All shipped exit.php files use:

    return workflow_exit();        // stop
    return workflow_exit('poll');  // handoff to an existing workflow name

The argument is a workflow name, not an executable path.
The helper checks the target name/directory, readable runner, absolute executable
PHP file, pcntl_exec support and job-mode restrictions. It returns a canonical
failure result on invalid preparation; stopping does not require pcntl_exec.
Exit nodes may forward workflow_result errors/retries during preparation.

The helper does not log, spawn or execute. After successful completion the runner
removes the checkpoint, publishes done, releases the lock and replaces the process
with pcntl_exec. Successful handoff keeps the PID and never returns to the caller.
No state is transferred. A target uses its own checkpoint and lock.
A launch failure after completion cannot restore the removed checkpoint.
CLI `-d` settings are not automatically forwarded by workflow_exit.

Do not construct workflow argv or call pcntl_exec inside new nodes.
Finite job-mode workflows must stop and cannot hand off.

## Map and reduce

### Visible map configuration

`workflows/hello/55-child-poll.php` declares the child, inputs and policies:

    return workflow_result(workflow_map_into(
        state: $state,
        workflow: 'poll',
        items: [3, 1, 2, 4],
        concurrency: 2,
        timeout: 30,
        environment: ['POLL_ONCE' => '1'],
        inputField: 'item',
        resultsField: 'child_poll_results',
        completedField: 'child_poll_completed'
    ));

The actual node reads HELLO_CHILD_ITEMS (default `[1]`) and
HELLO_CHILD_CONCURRENCY (default 4). Application settings stay at the call site;
map.php never reads environment variables.

workflow_map_into accepts a PHP list or raw JSON list and integer/canonical
decimal-string concurrency. It checks publication collisions before launching,
wraps each item in the named input field, maps the children and publishes results
and the completion marker together on success. Expected errors leave parent
state unchanged. Empty input publishes an empty list and true marker.
Worker domain validation stays in the worker; poll accepts integers from
-1000000 to 1000000.

One child is simply a map with one input. The same parallel lifecycle handles
single and multiple children. Parent state, explicit child inputs and returned
child states are distinct.

### Lower-level APIs and isolated execution

Use `workflow_map($workflow, $inputs, $concurrency, $timeout, $results, $environment)`
for explicit child-state arrays and custom publication. It does not mutate
parent state. Use workflow_parallel for heterogeneous jobs:

    $jobs = [
        ['workflow' => 'poll', 'state' => ['item' => 3],
         'env' => ['POLL_ONCE' => '1']],
    ];
    $error = workflow_parallel($jobs, 2, 30, $results);
    return workflow_result($error);

Both return null/error strings and ordered final child states in the output
argument, with no partial results exposed on expected failure.
Limits: 256 jobs, 1-32 concurrent workers, 1-3600 timeout seconds and 8 MiB per
collected result. The monotonic execution deadline covers launching, queueing and
waiting; batch-file preparation occurs before that deadline is established.
Child stdout/stderr are inherited, not the result channel.
The parallel helper forwards the effective PHP timezone explicitly.

Each job uses private input.json, output.json and runtime lock/checkpoint/status
files under `.runs/<parent>/parallel/batch-*/job-<index>/`; nested jobs use their
parent's runtime namespace. Ordinary and isolated instances do not share locks.
Application files/databases still require their own concurrency safety.

`php run.php <workflow> --job-dir <absolute-existing-directory>` runs an isolated
job. The caller prepares input.json; a checkpoint resumes saved state instead.
Consumers require both exit code zero and valid output.json. Stale output is
removed after lock acquisition during startup processing. Job workflows cannot
exec-handoff.

On handled error, timeout, SIGINT, SIGTERM or SIGHUP, the controller stops
launching, terminates active siblings together, allows a shared five-second
grace, then kills/reaps survivors and restores signal settings.
This covers direct children, not arbitrary descendants, SIGKILL or parent crashes.
Use external supervision where stronger guarantees are needed.

Batch artifacts are retained; there is no automatic retention cleanup.
Remove them only when unused. status.php lists ordinary runs, not isolated jobs.

### Reusable reduction

`workflows/poll/30-square.php` calculates one item; it is not another map
controller. `workflows/hello/60-reduce.php` configures:

    return workflow_result(workflow_reduce_into(
        state: $state,
        resultsField: 'child_poll_results',
        completedField: 'child_poll_completed',
        outputField: 'child_poll_sum',
        reducer: 'workflow_reduce_sum',
        initial: 0
    ));

The library checks map completion, folds outputs in input order, validates
accumulators and publishes only the final value. Missing/inconsistent markers,
incomplete maps, unordered outputs and output-field collisions are errors.
No marker/results means a skipped map and skipped reduction.
An empty map publishes the initial value.

Named reducers use `(accumulator, item, index, &next) -> null or error string`.
Set next on success. Keep reducers pure and accumulators JSON-compatible;
external effects cannot be rolled back. workflow_reduce_sum requires integer
result fields and rejects integer overflow.

Map failure stops before reduce. A separate reduce node checkpoints map output,
so a reduce failure can resume without rerunning successful map work.
A failed/replayed map creates a fresh batch and may repeat completed workers.
Use stable application idempotency keys, not generated batch names.
A separate reduce is useful here, not mandatory for all child invocations.

## Diagnostics and status

log.php owns the sole application stderr writer:

    workflow_log_context('fetch', 'Request started');
    workflow_log_context('fetch', $error, null, 'error');

The optional timestamp preserves saved event time. Routine messages default to
info, retries use warning, failures use error. Helpers return errors without
logging; the runner reports them after cleanup with workflow/node context.
Parallel owns worker lifecycle messages; nodes own application summaries.
`workflow_error($operation, $message, $path = null)` constructs contextual error
strings without logging. Avoid passwords, full DSNs and environment dumps.

- WORKFLOW_LOG_LEVEL: debug, info, warning or error; default/unknown is info.
- WORKFLOW_LOG_FORMAT=json: NDJSON on stderr; other values select text.
- JSON fields: timestamp, level, context, message, workflow, job, batch, pid, node.
- Text escapes CR/LF; JSON escapes controls and replaces invalid UTF-8.
- The runner derives job/batch labels from its invocation; worker text includes
  job, batch and PID. These are diagnostics, not authenticated identities.

PHP-engine warnings and external child output are outside this formatting.
Status tables/JSON stay on stdout regardless of diagnostic format.
PHP's configured timezone controls default timestamps.

Missing ordinary status files report idle. Invalid/unreadable files report
unknown, log diagnostics and cause nonzero exit while retaining partial reports.
/proc provides best-effort stopped detection, not health or PID-reuse protection.
RETRY AT is the saved deadline; retry_after_seconds is not a live countdown.
UPDATED AT / updated_at is the exact open snapshot's mtime, not query time,
a heartbeat or a durability guarantee. Human output is sanitized/width-bounded.

## MES example

See workflows/mes-observe/README.md before adapting the observe-only manufacturing
example. Its procedural mysqli adapter does not issue manufacturing commands
or application database writes. SQL permissions, TLS and producer revision
contracts remain deployment responsibilities.

## Tests

Run from the repository directory:

    timeout 30s bash tests/reduce.sh &&
    timeout 30s bash tests/map-into.sh &&
    timeout 30s bash tests/map.sh &&
    timeout 30s bash tests/exit.sh &&
    timeout 30s bash tests/next.sh &&
    timeout 30s bash tests/results.sh &&
    timeout 30s bash tests/state.sh &&
    timeout 30s bash tests/logging.sh &&
    timeout 30s bash tests/logging-options.sh &&
    timeout 30s bash tests/retry.sh &&
    timeout 30s bash tests/status.sh &&
    timeout 40s bash tests/chain.sh &&
    timeout 50s bash tests/child.sh &&
    timeout 30s bash tests/mes-observe.sh &&
    timeout 30s bash tests/procedural.sh &&
    timeout 60s bash tests/parallel.sh

Tests use temporary copies and fake MES records, not live checkpoints/database.
Harnesses need Bash, Python 3 and PHP CLI; process tests need pcntl and enabled
process functions. Copy run.php, log.php, state.php, result.php and next.php into
runner fixtures; add map.php/parallel.php and reduce.php when used, plus workflow
nodes, fields.php and helper files.

Coverage includes guarded state, result/routing/exit validation, logging,
map/reduce publication, ordered results, bounded concurrency, failure/stop/timeout
cleanup, checkpoint resume and finite process replacement.
These tests do not establish live MySQL connectivity/permissions, production
network behavior, crash durability, descendant cleanup, durable retry budgets,
cron/DST correctness or exactly-once effects. Report tests actually run.

Production migration policy, including bounded retry/restart budgets, is in
SKILL.md; it is not automatically enforced by this runner.
