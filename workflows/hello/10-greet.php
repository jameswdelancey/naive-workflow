<?php
$state['name'] = getenv('USER') ?: 'world';
fwrite(STDERR, '[' . date('c') . '] ' . "Hello, {$state['name']}!\n");
