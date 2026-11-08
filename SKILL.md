---
name: migrate-php-workflow
description: Migrate scripts and services into procedural PHP workflows.
---

# Scope

Read README.md, run.php, relevant helper libraries and the application before
changing behavior. README.md documents runtime/API behavior; this file adds
migration policy. Preserve application requirements.
Use named functions, plain arrays and explicit error returns. Do not introduce
application classes, closures or exception-based control flow.

# Node contracts

Create workflows/<name>/ with simple `[a-zA-Z0-9_-]+` names and consistently
padded, unique numbered filenames. Execution order is lexicographic.

- Success/skip: `return workflow_result();`
- Helper outcome/failure: `return workflow_result($error);`
- Retry: `return workflow_result($error, retry: $seconds);`
- Exit stop: `return workflow_exit();`
- Exit handoff: `return workflow_exit('target');`

Do not hand-build node-control arrays, use bare null success returns, throw
application exceptions or exit/die inside nodes. Helpers retain string/null and
data/output-argument contracts. Check every error immediately and clean up before
returning. Retry requires a nonempty message and nonnegative delay; zero is a
real immediate retry. Constructors return results, not control transfers.
Runtime validation still protects callers that bypass constructors.

The runner loads log.php, state.php, result.php and next.php. Nodes require
map.php/reduce.php when needed. Standalone code loads dependencies directly;
requiring run.php executes a workflow.

# State and resume

Use workflow_state_get/set/remove and check errors. New keys require add;
replacement rejects absent fields and concrete type changes. Missing removals
fail. Nested paths are lists; never create parents implicitly.
Declare allowed top-level fields/types in fields.php and use guarded reads for
prerequisites. Nested domain invariants need application validation.

Keep state JSON-compatible and within nesting limit 64. No resources, objects,
references, recursive arrays, invalid UTF-8 or nonfinite numbers.
Use local copies for related updates and commit only after all checks succeed.
Validation is not a sandbox or immutable-state guarantee.

Ordinary failure retains attempt input; retry retains mutated state and its
absolute deadline. Manual runs resume checkpoints; success removes them.
External effects never roll back. Use stable idempotency keys and durable
deduplication. Store schedules/budgets/receipts outside ephemeral state.
Never rewrite or remove live checkpoints; stop, inspect and archive first.

# Plan paths

Sketch decisions before splitting code. Use workflow_next(sequence) and forward
its error with workflow_result. It validates one unique numbered target,
preserves prior selection on error and does not immediately return from the node.
The runner resets every attempt, applies only after success, and ignores retry
selections. No selection means fall-through, not completion. exit.php cannot
branch. Do not assign process-global selectors in workflow code.

Branch endpoints must skip alternatives. Every backward/self cycle needs bounds,
paced interruptible waits and stop checks. Replace existing state fields
explicitly when looping. Route normal shutdown through cleanup, then exit stop.

# Composition and handoff

workflow_exit accepts a workflow name, not an executable path. It checks target,
runner/executable support and job-mode restrictions, but only the runner performs
completion, checkpoint removal, unlocking and exec. Handoff preserves PID,
never returns and transfers no state. Post-completion launch failure does not
restore the caller checkpoint. CLI overrides are not automatically forwarded.

Managed map workers use isolated job locks/checkpoints and retain the parent's
lock. Avoid recursive/cyclic dependencies: isolated locks do not bound recursion.
Shared application resources still need synchronization.
Finite job-mode workflows must stop and cannot hand off.

# Map/reduce

Keep configuration visible in the node: child workflow, item source/defaults,
concurrency, timeout, finite environment and input/result/completion field names.
For the demo use hello/55-child-poll.php; it maps poll workers with POLL_ONCE=1.

Use workflow_map_into with named arguments for reusable list parsing, wrapping,
bounded execution and guarded publication. It accepts supplied values, never
reads application environment. Publication collisions fail before launch.
One item and many use the same implementation. Empty maps publish empty results
and a completion marker. Parent state is unchanged on expected failure.
Workers validate domain inputs; poll/30-square.php is a calculation, not a map
orchestrator.

Use workflow_map for explicit child-state arrays/custom publication and
workflow_parallel for heterogeneous child workflows.
Limits: 256 jobs, 1-32 workers, 1-3600 execution-timeout seconds, 8 MiB per result.
The monotonic deadline covers launch/queue/wait, not prior batch preparation.
Outputs preserve input order. Use inherited streams rather than unread pipes.
The helper forwards effective timezone and reaps direct children on handled
failure/stop/timeout with a shared five-second termination grace.

Use workflow_reduce_into in a later numbered node when reduction is required.
Declare results/completion/output fields, named reducer and initial accumulator.
The library owns completion checks, ordered folding and guarded publication.
Skipped maps skip reduce; empty maps publish initial; incomplete/inconsistent
maps and output collisions fail. workflow_reduce_sum checks integer result
fields and overflow.

Reducer contract: `(accumulator, item, index, &next) -> null or error string`.
Set next on success, keep reducers pure and accumulators JSON-compatible.
Expected errors do not publish partial parent state; external effects and runtime
bugs cannot be rolled back. Do not duplicate reduce loops in node files.

A reduce failure resumes checkpointed map output without map replay.
A failed/replayed map creates a fresh batch; completed effects may repeat.
Use stable application keys, not batch paths, for deduplication.
Retained artifacts need an operator cleanup policy; status.php lists ordinary
runs, not isolated jobs. Remove batches only after confirming they are unused.

# Diagnostics

Use workflow_log_context rather than repeated stderr formatting.
log.php is the sole application stderr writer. Helpers construct errors with
workflow_error(operation, message, path) and return them; the runner logs failure
after cleanup with workflow/node context. Parallel owns worker lifecycle logs;
application summaries stay local. Do not duplicate errors at every layer.

Use info for routine messages, warning for retry, error for failure.
WORKFLOW_LOG_LEVEL controls filtering; WORKFLOW_LOG_FORMAT=json selects NDJSON
on stderr without changing status stdout. Optional saved timestamps are supported.
The runner derives workflow/node/job/batch identity from execution, not inherited
labels. Do not log credentials, full DSNs or environment dumps.
External output and PHP-engine warnings are not intercepted.

# Required retry/restart policy for migrated services

This is application policy, not runner enforcement. Hello is a demonstration,
not a production retry controller.

- Share one durable budget across node retries and child restarts.
- Permit at most three automatic retries/restarts per rolling ten minutes,
  excluding the initial attempt.
- Record authorization atomically under a suitable lock before retry/replacement.
- Back off 5, 15, then 45 seconds. Honor persisted not-before times on manual runs;
  do not sleep and request the same full delay twice.
- Keep failure timestamps, consecutive failures and delays outside checkpoint
  state. Fresh runs must not reset the budget; malformed budget data fails closed.
- Reset consecutive failures only after ten healthy minutes and expire timestamps
  only after their window.
- Deny the fourth retry with an ordinary error result.
- Never automatically retry invalid configuration.
- Unexpected clean exits count as failures for servers/daemons; scheduled normal
  completion does not.
- Do not wrap the runner in an unconditional restart loop.

# Migration patterns

## Scripts

Split validation, decisions, bounded work and output into numbered nodes.
Replace exit/die and exceptions with explicit results. Keep effects repeatable.
If unsafe to refactor, invoke the original script as a managed foreground child
with proc_open argv, not shell concatenation. Check its exit code.

## Managed services and php -S

Use one controller node for a long-lived foreground/no-fork child; later nodes
cannot execute until it returns. Disable the old launcher.
Keep handles local. Named signal handlers only set flags; save/restore handlers.
Use short interruptible waits and bounded I/O. Inherit streams.

Check child liveness and bounded health probes every five seconds; allow ten
seconds startup and restart after three failed checks. Another listener answering
a port does not establish child health. Run idempotent bounded maintenance on
explicit timers. Apply the durable restart budget.

Before replacement/stopping send SIGTERM, wait up to five seconds, then SIGKILL
if needed and reap. Never launch a replacement before the previous child exits.
Use shared explicit cleanup paths for expected errors.
php -S is a development server, not production architecture.
Arbitrary descendants, runtime bugs, SIGKILL and parent crashes require external
supervision where cleanup guarantees matter.

## Cron and daemons

Persist next occurrence and last-completed event ID outside ephemeral state.
Define timezone, DST and missed-run behavior; prefer skipping missed occurrences
to catch-up bursts. Recompute wall-clock deadlines in short waits.
Use event-derived idempotency keys, advance only after success and retain the
same event on failure while applying the durable budget.

For daemons use bounded iterations or a controller. On requested stop, stop
accepting new work, finish/checkpoint bounded in-flight work, clean up and return
normally without self-chaining.

# Verify

Run the complete bounded suite in README.md. The current suite contains:
reduce.sh, map-into.sh, map.sh, exit.sh, next.sh, results.sh, state.sh, logging.sh, logging-options.sh, retry.sh, status.sh, chain.sh, child.sh, mes-observe.sh, procedural.sh, parallel.sh.

Use temporary copies, never live checkpoints or unattended infinite pollers.
Copy run.php, log.php, state.php, result.php and next.php; add map.php/parallel.php,
reduce.php and relevant workflow nodes, fields.php and helpers as needed.

Report tests actually run. Existing fixtures cover error/state contracts,
routing/exits, diagnostics, ordered map/reduce, concurrency, handled cleanup and
resume. MES fixtures use fake records, not live MySQL.

Add migration-specific tests for durable budget exhaustion across manual reruns,
malformed budgets, unhealthy children, stop during work/backoff, cron DST/missed
events and duplicate effects. Existing tests do not establish production network
behavior, SQL permissions, crash durability, descendant supervision, exactly-once
processing or application-specific idempotency.
