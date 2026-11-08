<?php
// Opt in to process replacement; ordinary hello runs still finish and stop.
if (getenv('HELLO_POLL') !== '1') {
    return null;
}

// The runner completes hello, releases its lock, then replaces itself with poll.
// This is a handoff, not a function call: execution never returns to hello.
workflow_log_context('hello', "Handing off to poll.");
return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', 'poll'];
