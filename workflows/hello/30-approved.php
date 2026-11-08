<?php
global $nextSequence;
$state['result'] = 'approved';
fwrite(STDERR, '[' . date('c') . '] ' . "{$state['name']}: approved branch.\n");
// Skip the rejected branch.
$nextSequence = 50;
