<?php
$state['result'] = 'rejected';
fwrite(STDERR, '[' . date('c') . '] ' . "{$state['name']}: rejected branch.\n");
