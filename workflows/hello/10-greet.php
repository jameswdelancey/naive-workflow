<?php
$name = getenv('USER') ?: 'world';
$error = workflow_state_set($state, 'name', $name, 'add');
if ($error !== null) {
    return workflow_result($error);
}
workflow_log_context('hello', "Hello, $name!");
return null;
