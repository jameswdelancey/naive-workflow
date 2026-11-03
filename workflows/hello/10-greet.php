<?php
$state['name'] = getenv('USER') ?: 'world';
echo "Hello, {$state['name']}!\n";
