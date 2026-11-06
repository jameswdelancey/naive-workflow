---
name: migrate-php-workflow
description: Migrate PHP scripts, development servers, cron jobs and daemons into sequential PHP workflows.
---

# Scope and runtime contract

Use this skill when migrating scripts or services into this repository's
workflow format. Read `README.md`, `run.php`, `src/Runner.php`,
`src/Node.php`, `src/Base.php` and the source being migrated before changing
behavior. Read the relevant base classes and workflow README as well.
Preserve existing application requirements.

Distinguish implemented runner behavior from migration policy: retry budgets,
child supervision, scheduling and graceful shutdown below must be implemented
by workflow/controller nodes; the runner does not supply them. Reuse the
managed-child base class for its implemented lifecycle, but do not mistake
it for a health supervisor or durable restart controller.

# Conventions

- Create `workflows/<name>/`; names must match `[a-zA-Z0-9_-]+`.
  Numbered nodes run in lexicographic order, not numeric order. Use consistently
  padded prefixes and unique leading sequence numbers.
- Nodes share a `WorkflowState` object; use JSON-compatible values. Resolve
  adapter paths via `__DIR__` and repository paths via `$this->root` in nodes.
- Use inherited `$this->log('message')` for application diagnostics. Do not
  add timestamps/newlines or duplicate stderr writers. `Base::log()` uses
  PHP's configured timezone and escapes embedded CR/LF. Preserve stdout
  for deliberate data; `status.php` writes its table or JSON there.
  External child output and PHP-engine warnings remain outside this logger.
- Throw on failure; never call `exit()` inside a node.
- An ordinary uncaught exception stops the run. Restarting restores the input
  of the failed attempt, not necessarily the input of the node's first attempt.
  Retryable exceptions preserve mutated state instead. Neither mechanism
  rolls back external effects or guarantees exactly-once execution.
- Successful runs discard checkpoints and state. Store durable scheduling and
  restart metadata separately under .runs/<name>/.
- `exit.php` must explicitly return `null` or a nonempty list of strings whose
  first element is an absolute executable path. Omitting a return is not null.
  Self-chain: `[PHP_BINARY, dirname(__DIR__, 2).'/run.php', basename(__DIR__)]`.
  Process replacement requires `pcntl_exec`. PHP CLI `-d` overrides are not
  automatically forwarded; use persistent configuration or explicit argv.
  A launch failure after completion does not restore the removed checkpoint.
- Never recursively launch runners or background unmanaged children.
- The runner retries a node only when it throws `RetryableException`; it does
  not automatically restart failed workflows or supervise, forward signals to,
  or clean up children. Implement child management in a controller node.

# OOP architecture

`run.php` and `status.php` are thin CLI adapters. Both require `src/`.
`src/bootstrap.php` loads the runtime without executing a workflow.

- `Base::log()` is the shared timestamped stderr writer inherited by `Node`,
  `Runner` and `StatusCommand`. Use `$this->log('message')` in subclasses,
  without a timestamp prefix or trailing newline. It escapes embedded CR/LF
  and appends one newline. PHP's configured `date.timezone` controls the
  default timestamp. Poll passes its saved timestamp as the second argument.
  Status tables and JSON remain on stdout. External child output and
  PHP-engine warnings are not intercepted or reformatted by this logger.
- `Node::run(WorkflowState $state): mixed` receives shared mutable state.
  Objects, process handles, connections and closures stay local to an attempt,
  never in `$state`. `enabled()` tests for the exact environment value `1`;
  `Jump::to()` creates a returned branch result; `workflowCommand()` builds workflow argv.
- `Runner::run(string $name): int` returns an exit code for finite runs;
  successful handoffs replace the process and never return. Locks are released
  in `finally` on success and failure. Procedural nodes still execute
  in a limited include scope without the runner's `$this` or local variables.
  They now receive a state object: typed workflows require named properties;
  array-only
  type hints, array functions and whole-state replacement must be migrated.
- `WorkflowState` is an abstract serialization contract that rejects unknown
  properties. All shipped workflows declare typed fields in their `State` classes;
  their nodes use named properties, not runtime-inserted keys. Constructors
  explicitly validate known checkpoint fields without scalar coercion.
  Missing fields retain their absence in checkpoints; false values are kept.
- `State::requireState()` checks and narrows the shared node argument before
  workflow-specific properties are used. Non-numbered `state.php` returns the
  class used to hydrate checkpoints. Use `clone` for independent state copies.
- MES state and its nested configuration, records, baselines and observations
  now use declared typed fields. The record adapter's arrays are validated and
  converted immediately at the read boundary. Fixed production/quality slots
  replace runtime-inserted table keys inside state. JSON field names are retained.
- Workflows without `state.php` use `EmptyWorkflowState`, which rejects all
  application fields. Stateful workflows must declare their schema; there is
  no array-backed fallback or `ArrayAccess` state implementation.
  Checkpoint writes require a `WorkflowState` object. JSON arrays exist at
  serialization boundaries, not as an extensible in-memory state bag.
- Declare `strict_types=1` in node and model files. PHP checks assignments to
  declared properties, and hydration validates input types and unknown keys.
  This is a closed runtime state schema, not a claim of whole-program static
  verification or of application-level invariants beyond the checks shown.
- `WorkflowStatus` owns execution metadata in declared typed fields, with
  `ExecutionStatus` enum values for running/retrying/done/failed. It rejects
  external property creation and unknown input fields. The known `updated_at`
  input field is ignored; the report uses the snapshot file's mtime instead.
  Status report rows remain presentation arrays; serialization retains the
  documented status fields, not arbitrary extra metadata.
- `WorkflowLock` owns the nonblocking lock handle; `Runner` explicitly releases
  it in `finally` before any handoff. Release is idempotent; destruction is a
  fallback, not a replacement for explicit cleanup.
- `CheckpointRepository` loads, saves and removes checkpoints through the
  runner's injected `JsonFileStore`. The runner still chooses when to save
  attempt input versus retry mutations. Resume resolves the saved node before
  validating its retry deadline, preserving failure status and diagnostics.
- `HandoffCommand` validates argv and process-replacement support before
  completion. Its execution occurs only after checkpoint removal, done status
  publication and lock release. It does not launch a child or restore state
  on a post-completion launch failure.
- `WorkflowDefinition` owns the discovered step snapshot, checkpoint-node
  lookup and sequence resolution. It preserves lexicographic ordering and
  compares digit strings without integer overflow. It does not execute nodes.
- `StatusRepository::collect()` returns a `StatusReport` containing rows and
  read errors without printing; snapshot contents and mtime still use one
  handle. `StatusFormatter` returns table/JSON strings without printing.
  `StatusCommand` accepts optional repository/formatter collaborators and
  handles only diagnostic logging, output selection and the report exit code.
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

Mes-observe uses workflow-local `ObservationNode`.
Production and quality nodes supply the table and return their self-branch result.
The protected `readRecords()` hook delegates to the existing read-only PDO
adapter; decimal revision helpers remain in `common.php`. Configuration,
deadline and signal checks surround reads. Unchanged records use paced
branches, not failure retries. Read/configuration failures stop immediately.
Its receipt uses a unique temporary file, complete-write check and cleanup.
See `workflows/mes-observe/README.md` before adapting its observe-only behavior.

# Node adapters

A stateless numbered adapter can use the default `EmptyWorkflowState`:

    <?php
    declare(strict_types=1);

    require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

    return (new class extends \NaiveWorkflow\Node {
        public function run(\NaiveWorkflow\WorkflowState $state): mixed
        {
            $this->log('Visited this node');
            return null;
        }
    })->run($state);

For stateful nodes, define typed fields and explicit hydration/serialization
in a workflow-local `State` subclass. Return its class name from `state.php`,
load the class with `require_once`, and narrow the argument using
`State::requireState($state)` before accessing its properties. See hello and
MES for scalar and nested examples. Declare any stop/retry/schedule fields
before using them; arbitrary runtime field insertion is rejected.

Anonymous subclasses can be included again during retries or jumps. Put named
classes in non-numbered helper files and load them with `require_once`.
Adapters must return `run()`'s result, especially for `exit.php`. Returning
null from a numbered node means ordinary success/fall-through, not workflow
completion. Keep common execution in base classes and override only the
configuration or hooks that differ. Preserve checkpoint filenames and state
schemas when refactoring workflows that may have saved runs.

# Retryable node exceptions

- Throw `new RetryableException('Not ready', 5)` to retry the same node
  after at least five seconds; the delay is a nonnegative integer in seconds.
- On a retryable exception, the runner checkpoints the mutated `$state` and
  retry deadline, reports `retrying`, waits, and reruns the node. A restart
  resumes the saved wait or retries immediately if the deadline has passed.
- Ordinary failures instead retain the input state for the failed attempt and
  stop the run. A returned Jump takes effect only after successful return;
  failed attempts do not transfer a branch result.
- There is no built-in retry limit. Apply the durable bounded policy below;
  checkpointed state alone cannot enforce a budget across fresh runs. Treat
  side effects as potentially repeated and avoid retrying invalid config.
- Release resources and terminate/reap managed children in `finally` before
  allowing a retryable exception to escape. The runner holds the workflow
  lock during retry waits; another invocation cannot bypass the wait.
- The persisted deadline uses wall-clock time. Clock changes affect waits;
  do not treat this mechanism as a precise timer or a graceful shutdown loop.

# Restart policy: required for migrated automatic retries

This is migration policy, not built-in runner enforcement. The hello example
is a small retry demonstration, not a production restart controller.
Apply one durable budget to both node retries and child restarts; nesting
controllers must not multiply the allowed attempts.

- Persist failure timestamps, consecutive failures and not-before time outside
  `$state`, using atomic writes. Validate stored data; protect shared metadata
  from concurrent writers. Checkpoints and manual reruns must not reset the budget.
- Allow at most three automatic retries/restarts within a rolling ten-minute
  window, excluding the initial attempt. Record each authorization before
  throwing `RetryableException` or launching a replacement. Deny the fourth
  with an ordinary exception. Check the budget again on manual invocation.
- Back off 5, 15, then 45 seconds. Honor persisted delays after manual restarts;
  do not both sleep and request the same full delay through `RetryableException`.
- Reset the consecutive-failure counter only after ten healthy minutes; retain
  timestamps until they expire. Malformed budget data fails closed.
- Treat unexpected clean exits as failures for servers and daemons.
- Normal scheduled completions are not failures. Never automatically retry
  invalid configuration. Do not wrap the runner in an unconditional restart loop.
- Catch failures inside a controller when it manages child retries. An uncaught
  ordinary exception stops the runner; `RetryableException` requests a node
  retry instead. Cap retries even when an external scheduler starts it again.

# Existing PHP script

1. Split setup, work and output into numbered nodes; replace globals with $state.
2. Replace exit/die with exceptions or normal returns; keep configuration explicit.
3. Keep non-JSON resources local to a node. Add timeouts to external operations.
4. If refactoring is unsafe, invoke the script as a managed foreground child
   with proc_open using an argv array; check its exit status.
5. For automatic retries, use a controller or a bounded `RetryableException`
   policy as appropriate. Otherwise leave failures stopped for an explicit rerun.

# php -S server

1. Use one long-lived controller node. Start a foreground child:
   [PHP_BINARY, '-S', '127.0.0.1:8080', '-t', $absoluteDocumentRoot].
   Add an absolute router path if needed; do not use a shell or "&".
2. Keep the process handle in that node, not $state. Inherit stdout/stderr
   rather than leaving unread pipes. Verify required pcntl functions first.
3. Enable pcntl_async_signals(true). SIGINT/SIGTERM/SIGHUP handlers only set a
   stop flag. Use interruptible waits; all child cleanup belongs in finally.
4. Check child liveness and a bounded HTTP health request every five seconds.
   Allow ten seconds for startup; restart after three consecutive failed checks.
   Confirm the child is alive too: another listener must not count as success.
5. Run bounded, idempotent maintenance on a timer in the controller.
   Do not expect later nodes to run while this node is serving.
6. Before restarting or stopping, send SIGTERM, wait up to five seconds, then
   SIGKILL if necessary; reap the child with proc_close. Never start a replacement
   until the old process has exited. Apply the restart policy.
7. On a requested stop, clean up and return normally; do not self-chain.
   Arrange shutdown of descendants too if the application spawns any.
8. php -S is a development server, not a production serving architecture.
   SIGKILL cannot be trapped; use an external service supervisor if parent
   crashes must also guarantee child cleanup.

# Cron-style job

1. Persist the next occurrence and last completed event identifier.
   Calculate occurrences in an explicit timezone; define DST and missed-run
   behavior. Prefer skipping missed occurrences rather than catch-up bursts.
2. Sleep until the next occurrence in interruptible slices of at most one
   second, recomputing wall-clock time. Handle stop signals as above.
3. Run work with an idempotency key derived from its scheduled occurrence.
   After success, persist completion and calculate a strictly future occurrence.
4. On failure, keep that event identifier and apply the bounded retry policy.
   Once exhausted, throw; do not advance silently or retry on every invocation.
5. Loop inside a controller, or self-chain only after normal completion.
   Keep the schedule durable: checkpoint replay must not execute a completed
   event again. Disable the old cron entry before enabling this workflow.

# Existing daemon

1. Disable its old launcher; configure foreground/no-fork mode.
2. Either move one bounded work iteration into a controller loop or manage the
   existing executable with proc_open. Never persist process handles in $state.
3. Use the server pattern for signal handling, child cleanup, health checks,
   maintenance, backoff and restart limits.
4. Use bounded I/O and interruptible idle waits. On requested shutdown, stop
   accepting work, finish or checkpoint bounded in-flight work, clean up, return.
5. Do not self-chain after a stop request. Ensure retries cannot duplicate work.

# Verify

Run the bounded regression suite from the repository directory:

    timeout 15s bash tests/logging.sh &&
    timeout 30s bash tests/status.sh &&
    timeout 30s bash tests/runner.sh &&
    timeout 20s bash tests/objects.sh &&
    timeout 20s bash tests/runner-objects.sh &&
    timeout 20s bash tests/state-status.sh &&
    timeout 30s bash tests/oop.sh &&
    timeout 15s bash tests/templates.sh &&
    timeout 15s bash tests/branch-results.sh &&
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
- `objects.sh`: discovery snapshots, sequence resolution, pure status
  collection/rendering, partial reports and injected command collaborators.
- `runner-objects.sh`: lock ownership, checkpoint persistence and validation,
  injected storage errors, resume validation order and completion-before-handoff.
- `state-status.sh`: workflow-specific state classes, legacy checkpoint
  hydration, nested updates, detached snapshots and status transitions.
- `oop.sh`: state references, ordinary resume versus retry mutations,
  discarded retry results, branching and child configuration hooks.
- `templates.sh`: shared result actions, repeated inclusion, exact mode flags
  and handoff argv configuration without process replacement.
- `branch-results.sh`: the single returned-Jump API, target failure/resume
  and rejection of exit-node jumps.
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

Add migration-specific tests for:

- Interruption during a retry wait and resumption of the persisted deadline.
- Retry-budget exhaustion across manual reruns and malformed budget files.
- Unhealthy/immediately exiting children and SIGTERM during work/backoff.
- Cron missed events, DST behavior and duplicate-event suppression.
- Application-level idempotency around external effects and checkpoint replay.

Retain the existing ordinary-failure/retry, non-UTC logging, JSON status and
lock-contention regression checks. Confirm no surviving managed child and no
rapid restart loop. Do not infer untested production guarantees from fixtures.

# Conditional paths

- Use only `return \NaiveWorkflow\Jump::to($sequence);` from numbered nodes.
  The PHP adapter must return the node result. Null means fall-through.
- Targets are nonnegative integers or digit strings matching one unique
  leading filename number; leading zeroes are ignored.
- The runner applies a returned Jump only after successful node execution.
  Missing/ambiguous targets fail the node. Exceptions discard the result.
- Returning ends the method: perform required work before the return and
  mandatory cleanup in finally. A Jump is not a retry request.
- Bound and pace backward/self branches. exit.php cannot return a Jump.
- Do not use globals, a jump setter or a next-sequence hook as alternatives.

# Plan conditional paths before migrating

- First identify decisions: work available, not due yet, healthy/unhealthy,
  maintenance due, retry allowed/exhausted, and shutdown requested.
- Sketch numbered nodes and branch destinations before moving code. Prefer
  small decision nodes over deeply nested conditionals.
- Use `return \NaiveWorkflow\Jump::to($condition ? 30 : 50);` for alternatives.
  Returning null means fall-through, NOT workflow completion.
- End each alternative with an explicit jump to the common continuation;
  otherwise it may fall through into the other branch.
- Reserve a final numbered cleanup node. Route requested stops there; set
  `$state->stop = true` and make exit.php return null when it is set.
- Exceptions skip later nodes: mandatory resource cleanup still needs finally.
  A jump takes effect only after successful node return.
- Checkpoint replay may revisit a decision or action. Re-evaluate volatile
  conditions, persist retry/schedule metadata, and make effects idempotent.
- Every cycle needs bounded work, an interruptible wait, a shutdown check and,
  for failures, the durable restart budget. Jumps are not a retry policy.

## Migration patterns

- Script: validate -> decide -> process OR skip -> common finish.
  Process must jump past skip. Expected no-work is a branch, not an exception.
- Cron: determine due -> wait OR execute -> persist next event -> determine due.
  Wait jumps back to determine due. Execution failure branches to bounded
  retry/backoff or throws on exhaustion; it must not advance the event.
- Daemon implemented in PHP: check stop/work -> wait OR one work iteration ->
  check stop/work; route shutdown to cleanup. Keep resources local to a node.
- Managed php -S or external daemon: branch between preflight/start/skip and
  after-controller outcomes, but retain child launch, health/maintenance
  decisions, bounded restarts and finally cleanup inside one controller node.
  Live process handles cannot cross checkpointed nodes through $state.
- Inside that controller, use ordinary PHP conditionals for health, maintenance,
  retry and stop decisions. a Jump cannot transfer control until the node returns.

## Branch tests

Exercise both sides, no-work, not-due, maintenance-due, retry exhaustion and
shutdown. Verify unused alternatives never run, loops wait, and failure/resume
preserves the selected path. Inject failures around external effects to verify
application-level idempotency; checkpoints alone cannot prevent repetition.

# Calling another workflow

- Prefer a `HandoffNode` subclass in `exit.php` and return its `run()` result.
  For a legacy one-way handoff after successful completion, return
  `[PHP_BINARY, dirname(__DIR__, 2).'/run.php', 'poll']` from `exit.php`.
  This uses process replacement, not recursion or a child process; it never
  returns to the calling workflow.
- The hello demo enables this with `HELLO_POLL=1`. Leave the option unset
  or set it to `0` for a finite hello run. Poll repeats unless `POLL_ONCE=1`;
  that inherited setting also makes a handoff finite. Only the exact value
  `1` enables these options.
- The caller is marked done and its lock released before handoff. The target
  acquires its own lock and resumes its own checkpoint if present. No `$state`
  is transferred. A target failure does not roll back the completed caller.
- Environment variables survive replacement; command-line PHP configuration
  overrides do not unless explicitly included in the returned argv.
- If execution must return to the caller, use a managed foreground child
  inside a controller with bounded waits, exit-status checks and signal/cleanup
  handling. Do not wait on a child that needs the caller's held workflow lock.
- Run `timeout 40s bash tests/chain.sh` for the bounded hello-to-poll integration test.
  It uses isolated copies and disables only the copied poll self-chain.
  Do not run the production infinite poller as an unattended test.

# Child workflows as nodes

- Use `workflows/hello/55-child-poll.php`, `ChildPollNode.php` and
  `src/ManagedChildWorkflowNode.php` as the managed-child example.
  Override configuration/completion hooks rather than copying the lifecycle.
  Enable it with `HELLO_CHILD_POLL=1 HELLO_POLL=0`; the latter disables
  the independent exit-node handoff.
- Use an argv array with `proc_open`, not shell command concatenation.
  Pass `POLL_ONCE=1` only in the child environment to make poll finite.
  Forward required PHP configuration explicitly, inherit output streams,
  and keep process handles local to the node rather than in `$state`.
- Wait with a monotonic timeout; check the child exit code and throw an
  ordinary exception on failure. Release/reap children in `finally` and
  restore process-global signal handlers before continuing.
- A child uses its normal workflow lock and checkpoint, not a new instance.
  The parent retains its own lock. Avoid self-calls and dependency cycles.
- Successful child completion is not transactionally linked to the parent's
  checkpoint. Parent retries may replay child effects; require idempotency.
  This demo propagates errors rather than automatically retrying children.
- Cleanup covers the direct child on handled stops/errors, not arbitrary
  descendants, SIGKILL or parent crashes. Use a supervisor where needed.
- Run `timeout 50s bash tests/child.sh`; it covers the real finite child mode, separate
  PIDs, parent continuation, reaping and failure propagation. Add dedicated
  signal/timeout tests before relying on this pattern for a production service.
