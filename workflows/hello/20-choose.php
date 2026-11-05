<?php
global $nextSequence;
$state['approved'] = getenv('HELLO_APPROVED') === '1';
$nextSequence = $state['approved'] ? 30 : 40;
fwrite(STDERR, '[' . date('c') . '] ' . "Choosing node $nextSequence.\n");
