<?php
// Stop unless a one-way workflow handoff is explicitly enabled.
if (getenv('HELLO_POLL') !== '1') {
    return workflow_exit();
}

$handoff = workflow_exit('poll');
if (is_array($handoff) && array_is_list($handoff)) {
    workflow_log_context('hello', 'Handing off to poll.');
}
return $handoff;
