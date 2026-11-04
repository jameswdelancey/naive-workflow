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

## Conditional paths

Inside a numbered node:

    global $nextSequence;
    $nextSequence = $state['approved'] ? 30 : 50;

Or assign directly:

    $GLOBALS['nextSequence'] = 50;

The target matches the leading sequence number: 30 selects 30-process.php.
Leading zeroes are ignored. A target must match exactly one numbered node;
invalid, missing or ambiguous targets fail the current node.

The variable resets before every node. Leave it unset or null to continue
in filename order. Selection takes effect only after the node returns
successfully. It does not skip the remainder of the current PHP file.

After the selected node, normal sequential execution resumes unless it
selects another target. Branch endpoints must explicitly jump over any
unwanted alternative branches.

Forward, backward and same-node jumps are supported. Bound loops yourself:
there is no automatic delay, iteration limit or restart budget for jumps.
Shared state survives jumps. The selected next node and its input state are
checkpointed; restarting after its failure resumes that node.

exit.php cannot select a sequence. It retains its command-return convention.

## Runnable example: retry and branching

Run these commands from the directory containing `repos`. The hello workflow requests a retry on its first attempt, waits at least one second, then continues on its second attempt using the checkpointed state. Each fresh run repeats this demonstration. Set `HELLO_APPROVED=1` to select the approved branch; otherwise, the rejected branch is selected.

Example session (the greeting uses your `USER` environment variable):

    jd@jd-macbookpro82:~$ php repos/naive-workflow/run.php hello
    Hello, jd!
    Retry demo: requesting retry on attempt 1.
    hello: 15-retry.php: retryable: Demo node is not ready yet; retrying in 1s
    Retry demo: continued on attempt 2.
    Choosing node 40.
    jd: rejected branch.
    Finished for jd: rejected.
    jd@jd-macbookpro82:~$ HELLO_APPROVED=1 php repos/naive-workflow/run.php hello
    Hello, jd!
    Retry demo: requesting retry on attempt 1.
    hello: 15-retry.php: retryable: Demo node is not ready yet; retrying in 1s
    Retry demo: continued on attempt 2.
    Choosing node 30.
    jd: approved branch.
    Finished for jd: approved.

Run the automated test for retry status, checkpointed state, the wait, and both branches:

    bash repos/naive-workflow/tests/retry.sh
