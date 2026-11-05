<?php
// Child invocations can request one iteration; ordinary polling still self-chains.
if (getenv('POLL_ONCE') === '1') {
    return null;
}

return [PHP_BINARY, dirname(__DIR__, 2) . '/run.php', basename(__DIR__)];
