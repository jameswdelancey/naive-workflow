<?php
if (getenv('HELLO_CHILD_POLL') !== '1') {
    return null;
}
require_once dirname(__DIR__, 2) . '/child-poll.php';

$error = workflow_child_poll($state);
return $error === null ? null : ['error' => $error];
