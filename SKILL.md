---
name: migrate-php-workflow
description: Migrate PHP scripts, development servers, cron jobs and daemons into sequential PHP workflows.
---

# Conventions

- Create workflows/<name>/ with zero-padded numbered PHP nodes.
- Nodes share $state; use JSON-compatible values. Resolve paths via __DIR__.
- Send results to stdout, diagnostics to stderr; throw on failure, never exit().
- A failed run stops. Starting it again retries the checkpointed node with its
  original input state. Make side effects idempotent.
- Successful runs discard checkpoints and state. Store durable scheduling and
  restart metadata separately under .runs/<name>/.
- exit.php returns null or an argv array with an absolute executable.
  Self-chain: [PHP_BINARY, dirname(__DIR__, 2).'/run.php', basename(__DIR__)].
- Never recursively launch runners or background unmanaged children.
- The runner does not supervise, retry, forward signals or clean up children.
  Implement those responsibilities in a controller node when needed.

# Restart policy: required for every automatic retry

- Persist failure timestamps, consecutive failures and not-before time outside
  $state; checkpoints must not reset the budget.
- Allow at most three automatic restarts within a rolling ten-minute window.
  Record each restart before launching; the fourth stops with an exception.
- Back off 5, 15, then 45 seconds. Honor persisted delays after manual restarts.
- Reset the consecutive-failure counter only after ten healthy minutes; retain
  timestamps until they expire. Malformed budget data fails closed.
- Treat unexpected clean exits as failures for servers and daemons.
- Normal scheduled completions are not failures. Never automatically retry
  invalid configuration. Do not wrap the runner in an unconditional restart loop.
- Catch failures inside the controller when retrying; an uncaught exception
  stops the runner. Cap retries even when an external scheduler starts it again.

# Existing PHP script

1. Split setup, work and output into numbered nodes; replace globals with $state.
2. Replace exit/die with exceptions or normal returns; keep configuration explicit.
3. Keep non-JSON resources local to a node. Add timeouts to external operations.
4. If refactoring is unsafe, invoke the script as a managed foreground child
   with proc_open using an argv array; check its exit status.
5. For automatic retries, use a controller node and the restart policy above.
   Otherwise leave failures stopped for an explicit rerun.

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

Test node failure/resume, restored state, unhealthy child, immediate child exit,
restart-budget exhaustion across runner restarts, cron missed events, and
SIGTERM during work/backoff. Confirm no surviving child and no rapid restart loop.
 
# Conditional paths

- In a numbered node, use `global $nextSequence; $nextSequence = 30;`
  or `$GLOBALS['nextSequence'] = $condition ? 30 : 50;`.
- Targets match one unique leading filename number; leading zeroes are ignored.
- Selection applies after successful return. Unset/null means sequential.
- The selector resets each node; state persists. Branch endpoints must jump
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
preserves the selected path without repeating completed external effects.
