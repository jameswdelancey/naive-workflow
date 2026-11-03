# Naive workflow

    php run.php hello
    php run.php poll
    php status.php
    php status.php --json

Stop polling with Ctrl-C.

Each workflows/ subdirectory is a workflow.
Numbered PHP files execute sequentially in lexicographic order.
Use zero-padded prefixes such as 10-fetch.php and 20-process.php.

Nodes share $state, an array reset at the start of each invocation.
Persist data explicitly when needed across polling iterations.
Write results to stdout and diagnostics to stderr.
Throw exceptions to fail; do not call exit() inside nodes.

Optional exit.php runs after successful nodes.
Return null to stop, or an argv array to replace the process:

    return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', 'hello'];

The executable must have an absolute path. Process replacement requires
pcntl_exec and avoids accumulating processes during polling.

Only one invocation per workflow runs at a time.
.runs/ holds locks and latest status, not execution history.
Unexpected termination can leave stale metadata; status uses /proc for
best-effort detection. Failed command launches report to stderr and exit
nonzero, but the completed workflow remains recorded as done.

## Startup example

    { nohup php run.php poll >> .runs/poll.log 2>&1 < /dev/null & } &&
    sleep 1 &&
    php status.php

## Automatic resume

Run the same command again after a failure:

    php run.php hello

Before each node, the runner atomically saves its filename and input $state
in .runs/<workflow>.checkpoint.json. The next invocation automatically
resumes there, skipping earlier nodes and restoring that input state.
Changes made to $state inside the failed node are discarded.

This overrides the earlier empty-state rule when a checkpoint exists.
Successful workflows remove their checkpoint, so successful polling
iterations still start fresh. exit.php is also checkpointed.

Keep shared state JSON-compatible: arrays, strings, finite numbers,
booleans and null. Objects, resources and closures are not supported.

Nodes must be safe to retry. Files, network requests and other external
effects are not rolled back. A crash after an external effect but before
the next checkpoint can cause that effect to be repeated.

To restart from scratch, stop the workflow first, then remove its checkpoint:

    rm -f .runs/hello.checkpoint.json

Checkpoints only apply to executions using this updated runner.
Existing failures without a checkpoint cannot restore their previous state.
Failure to launch the returned exit command occurs after checkpoint removal
and does not resume exit.php.
