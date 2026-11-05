---
name: migrate-php-workflow
description: Migrate PHP scripts, development servers, cron jobs and daemons into sequential PHP workflows.
---

# Scope and runtime contract

Use this skill when migrating scripts or services into this repository's
workflow format. Read `README.md`, `run.php` and the source being migrated
before changing behavior. Preserve existing application requirements.

Distinguish implemented runner behavior from migration policy: retry budgets,
child supervision, scheduling and graceful shutdown below must be implemented
by the workflow; the runner does not supply them.

# Conventions

- Create `workflows/<name>/`; names must match `[a-zA-Z0-9_-]+`.
  Numbered nodes run in lexicographic order, not numeric order. Use consistently
  padded prefixes and unique leading sequence numbers.
- Nodes share $state; use JSON-compatible values. Resolve paths via __DIR__.
- Write demo messages and runtime diagnostics to stderr with `fwrite(STDERR, ...)`;
  use `fwrite(STDERR, '[' . date('c') . '] ' . "message\n");`.
  PHP's configured `date.timezone` determines the timezone, not necessarily
  the OS timezone. Preserve stdout for deliberate data output;
  `status.php` writes its table or JSON to stdout.
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

# Retryable node exceptions

- Throw `new RetryableException('Not ready', 5)` to retry the same node
  after at least five seconds; the delay is a nonnegative integer in seconds.
- On a retryable exception, the runner checkpoints the mutated `$state` and
  retry deadline, reports `retrying`, waits, and reruns the node. A restart
  resumes the saved wait or retries immediately if the deadline has passed.
- Ordinary failures instead retain the input state for the failed attempt and
  stop the run. `$nextSequence` takes effect only after successful return and
  is reset on each attempt.
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

Run `bash tests/retry.sh` from the repository. It checks hello's two branches,
retry status, checkpointed retry state, a wait observation, final cleanup,
timestamp formatting and stderr-only demo output (including the poll node).
It does not establish full interruption, supervision or scheduling coverage.

Add migration-specific tests for:

- Ordinary failure restoring attempt input versus retry preserving mutated state.
- Interruption during a retry wait and resumption of its persisted deadline.
- Retry-budget exhaustion across manual reruns and malformed budget files.
- Unhealthy/immediately exiting children and SIGTERM during work/backoff.
- Cron missed events, DST behavior and duplicate-event suppression.
- A non-UTC configured timezone, valid JSON status output and lock contention.

Use temporary workflow copies and bounded test timeouts. Do not run an
unbounded poller or delete live checkpoints as part of tests. Confirm no
surviving child and no rapid restart loop. Report which tests actually ran.

# Conditional paths

- In a numbered node, use `global $nextSequence; $nextSequence = 30;`
  or `$GLOBALS['nextSequence'] = $condition ? 30 : 50;`.
- Targets must be nonnegative integers or digit strings and match one unique
  leading filename number; leading zeroes are ignored.
- Selection applies after successful return. Unset/null means sequential.
- The selector resets each attempt; state persists. Branch endpoints must jump
  over unwanted alternatives. Missing/ambiguous targets fail the current node.
- Backward/self jumps need explicit bounds and interruptible waits; they do
  not provide retry backoff. exit.php cannot select a sequence.

# Plan conditional paths before migrating

- First identify decisions: work available, not due yet, healthy/unhealthy,
  maintenance due, retry allowed/exhausted, and shutdown requested.
- Sketch numbered nodes and branch destinations before moving code. Prefer
  small decision nodes over deeply nested conditionals.
- Use `$GLOBALS['nextSequence'] = $condition ? 30 : 50;` for real alternatives.
  No assignment means fall-through, NOT workflow completion.
- End each alternative with an explicit jump to the common continuation;
  otherwise it may fall through into the other branch.
- Reserve a final numbered cleanup node. Route requested stops there; set
  `$state['stop'] = true` and make exit.php return null when it is set.
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
  retry and stop decisions. nextSequence cannot transfer control until it returns.

## Branch tests

Exercise both sides, no-work, not-due, maintenance-due, retry exhaustion and
shutdown. Verify unused alternatives never run, loops wait, and failure/resume
preserves the selected path. Inject failures around external effects to verify
application-level idempotency; checkpoints alone cannot prevent repetition.

# Calling another workflow

- For a one-way handoff after successful completion, return
  `[PHP_BINARY, dirname(__DIR__, 2).'/run.php', 'poll']` from `exit.php`.
  This uses process replacement, not recursion or a child process; it never
  returns to the calling workflow.
- The hello demo enables this with `HELLO_POLL=1`. Leave the option unset
  or set it to `0` for a finite hello run. Poll itself repeats until stopped.
- The caller is marked done and its lock released before handoff. The target
  acquires its own lock and resumes its own checkpoint if present. No `$state`
  is transferred. A target failure does not roll back the completed caller.
- Environment variables survive replacement; command-line PHP configuration
  overrides do not unless explicitly included in the returned argv.
- If execution must return to the caller, use a managed foreground child
  inside a controller with bounded waits, exit-status checks and signal/cleanup
  handling. Do not wait on a child that needs the caller's held workflow lock.
- Run `bash tests/chain.sh` for the bounded hello-to-poll integration test.
  It uses isolated copies and disables only the copied poll self-chain.
  Do not run the production infinite poller as an unattended test.

# Child workflows as nodes

- Use `workflows/hello/55-child-poll.php` as the managed-child example.
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
- Run `bash tests/child.sh`; it covers the real finite child mode, separate
  PIDs, parent continuation, reaping and failure propagation. Add dedicated
  signal/timeout tests before relying on this pattern for a production service.
