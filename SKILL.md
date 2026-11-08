---
name: migrate-php-workflow
description: Migrate scripts and services into procedural PHP workflows.
---

# Contract

Read README.md, run.php and the application before changing behavior.
Use named functions, plain arrays, explicit error returns and ordinary control
flow. Do not introduce application classes, closures or exception control flow.

Numbered nodes return null for success, `['error' => $message]` for failure,
or `['retry' => $seconds, 'error' => $message]` for retry.
Functions return errors explicitly; check them immediately.
Release resources on a shared cleanup path before returning an error or retry.
Never call exit/die inside a node.

Use JSON-compatible `$state`, private local handles, paths relative to __DIR__,
timestamped stderr diagnostics and deliberate stdout data output.

# Plan paths

Use small numbered nodes and `$GLOBALS['nextSequence']` for real alternatives.
A target must identify one leading filename sequence number.
Selection takes effect only after successful return, resets every attempt,
and is ignored when retrying. No selection means fall-through, not completion.
Branch endpoints must jump over unwanted alternatives.
Every cycle needs bounded work, an interruptible wait and a shutdown check.
Route normal stops to cleanup, and make exit.php return null after stopping.

Before each attempt, the runner checkpoints input state. Ordinary errors retain
that input; retries preserve mutated state and a retry deadline. Neither rolls
back external effects. Use idempotency keys for repeatable work.

# Bounded retry/restart policy for migrations

This is application policy, not built into the runner.

- Share one durable budget across retries and child restarts.
- Allow at most three automatic retries/restarts in a rolling ten-minute
  window, excluding the initial attempt.
- Persist authorization before returning a retry or launching a replacement.
- Back off 5, 15, then 45 seconds; honor persisted not-before times on reruns.
- Store failure timestamps and consecutive failures outside checkpoint state,
  atomically and under a suitable lock. Fresh runs must not reset the budget.
- Reset consecutive failures only after ten healthy minutes. Expire timestamps
  only after their window. Fail closed on malformed budget data.
- Deny the fourth retry with an ordinary error result.
- Never automatically retry invalid configuration.
- Unexpected clean exits count as failures for servers/daemons; normal scheduled
  completions do not.
- Do not wrap the runner in an unconditional restart loop.

# Scripts

Split validation, decisions, work and output into numbered nodes.
Replace exit/die and exceptions with explicit results.
Keep operations bounded and external effects safe to repeat.
If unsafe to refactor, run the original script as a managed foreground child
using proc_open with an argv list, not shell concatenation.

# Managed services and php -S

Use one controller node for a long-lived child; later nodes cannot run until
the controller returns. Keep process handles local, never in `$state`.

Use named signal handlers that only set a stop flag. Save/restore process-global
handlers. Check stop requests during short interruptible waits.
Inherit output rather than creating unread pipes.

Track child liveness and bounded health probes every five seconds.
Allow ten seconds for startup; restart after three failed checks.
A different process answering a port is not proof the managed child is healthy.
Run bounded, idempotent maintenance on explicit timers.

Before replacement/stopping, send SIGTERM, wait up to five seconds, then SIGKILL
if needed and reap with proc_close. Use an explicit cleanup path on all expected
error returns. Never launch a replacement before the old child exits.
Apply the shared durable restart budget.

Disable old launchers and require foreground/no-fork operation.
php -S is a development server, not a production serving architecture.
Runtime bugs, SIGKILL, parent crashes and descendant process trees need external
supervision if cleanup must be guaranteed.

# Cron and daemons

Persist next occurrence and last-completed event ID outside ephemeral state.
Define timezone, DST and missed-run behavior; prefer skipping missed occurrences
rather than catch-up bursts. Recompute wall-clock deadlines during short waits.
Use an event-derived idempotency key. Advance only after successful completion.
Keep the same event on failure and apply the durable retry budget.

For daemons, implement one bounded iteration per node or use a controller.
Stop accepting new work on a requested stop, finish/checkpoint bounded work,
clean up and return normally without self-chaining.

# Composition

exit.php returns null or a nonempty argv list with an absolute executable path.
A handoff completes the caller, releases its lock and replaces the process with
pcntl_exec. It never returns or transfers state. Forward required PHP CLI settings
explicitly. Launch failure does not restore the completed caller checkpoint.

A managed child retains the parent lock, uses the target's normal lock/checkpoint
and returns only after its exit code is checked. Avoid self-calls and dependency
cycles. Child success is not transactionally linked to the parent's checkpoint;
parent replay may repeat child effects.

See hello/55-child-poll.php for finite child execution and explicit cleanup.
Enable HELLO_CHILD_POLL=1 HELLO_POLL=0. HELLO_POLL independently controls handoff.

# Verify

Run the bounded test commands in README.md using temporary workflow copies.
Never run an unattended infinite poller or alter live checkpoints for tests.
Add application-specific tests for failure/input restoration, retry-state
preservation, retry interruption/deadline resumption, lock contention, durable
budget exhaustion across manual reruns, malformed metadata, stop handling,
unhealthy children, cron DST/missed events and duplicate-effect prevention.
Report tests actually executed and distinguish fake adapters from live systems.

# Parallel map/reduce

Require parallel.php and call workflow_parallel inside a controller node.
Jobs specify workflow, explicit JSON-compatible state and optional environment.
Check its error before storing results. Results preserve input order.
Use a subsequent numbered reduce node to checkpoint successful map output.

Each job uses isolated runtime files and a finite workflow; poll requires
POLL_ONCE=1. Exec handoffs are rejected in job mode. Bound concurrency, total
jobs and the whole batch timeout. On handled errors/stops, terminate and reap
all active siblings before returning. Parent crashes and descendant cleanup
still require external supervision when guarantees matter.

A failed/replayed map starts a new batch and may repeat completed jobs.
Use stable application idempotency keys, not generated batch paths.
Isolated workflow locks do not synchronize shared application resources.

Retained batch artifacts require an operator cleanup policy.
status.php covers ordinary runs; inspect job runtime JSON separately.
Run tests/parallel.sh for bounded concurrency, ordered reduction, isolation,
failure/stop/timeout cleanup and manual resume.

# Reusable child polling

Require child-poll.php and call workflow_child_poll($state) for the poll demo.
It returns an error string or null and uses workflow_parallel even for one job.
Keep workflow nodes thin: optional enable check, library call, error conversion.
Do not duplicate input validation, process launching, waiting or cleanup there.
For non-poll workflows, use workflow_parallel directly with explicit job inputs.

# Shared diagnostics

Use workflow_log($message), not repeated timestamp formatting and stderr writes.
The runner loads log.php. Standalone nodes/tools should require_once log.php
directly. An optional timestamp argument preserves a saved event time.
Copy log.php alongside run.php in isolated fixtures.

# Diagnostic ownership

Use workflow_log_context($context, $message) for contextual diagnostics.
log.php owns the sole stderr writer; MES/status use direct context calls.
The parallel controller owns worker lifecycle messages for every group size;
do not duplicate them in workflow nodes or poll wrappers.
Return helper errors without logging; the runner reports failures after cleanup.
Child and parent failures are distinct diagnostic events.
Keep application message text local rather than adding trivial wrappers.
Run tests/logging.sh after changing diagnostics or runtime-copy fixtures.

# Diagnostic levels and identity

Routine messages default to info; retries use warning; failures must use error.
WORKFLOW_LOG_LEVEL controls filtering; WORKFLOW_LOG_FORMAT=json emits NDJSON to
stderr without changing report stdout. The runner supplies workflow/node and
isolated job/batch identity; never infer identity from inherited environment labels.
Use workflow_error(operation, message, path) at failure boundaries, then return
the error without logging. Avoid secrets in diagnostic strings and JSON fields.
Run tests/logging-options.sh alongside tests/logging.sh.

# Guarded state

Use workflow_state_get/set/remove from state.php and check returned errors.
New fields require add; replacement rejects missing fields and type changes.
Missing removal is an error. Use explicit nested path lists; never invent parents.
Declare top-level names/types in fields.php; the runner validates input/output.
Guard reads for prerequisites and validate nested application invariants.
Use local copies for multi-field commits. Avoid references, resources and objects.
For intentional loops, replace existing fields explicitly.
Run tests/state.sh and copy state.php alongside the runner in fixtures.
