<?php
// Child jobs stop; ordinary polling replaces itself after completion.
return workflow_exit(getenv('POLL_ONCE') === '1' ? null : 'poll');
