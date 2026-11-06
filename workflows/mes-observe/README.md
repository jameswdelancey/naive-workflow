# Manufacturing execution observer

A finite, hypothetical MES backend workflow. It observes one manufacturing
unit, issues no machine commands, and never inserts or updates database rows.

## Sequence

1. `10-configure`: validate configuration and save a bounded observation deadline.
2. `15-baseline`: capture both records in one read-only InnoDB transaction.
3. `20-production-order`: poll the production order for this exact `unit_id`.
   Complete only after an insert or a higher `row_version` than the baseline.
4. `30-quality-check`: poll the quality table for the same exact `unit_id`.
   Complete only after an insert or a higher version than its baseline.
5. `90-finish`: atomically publish a local receipt; `exit.php` stops.

Absent or unchanged records follow a paced self-branch. They do not consume
failure retries. Each query uses a new connection/transaction so it can see
new commits. Database/configuration failures stop immediately, without
automatic retries or restarts.

Both baselines are captured at workflow start: a quality change that happens
while waiting for production is accepted when node 30 runs. This avoids losing
that change between nodes. It does NOT prove that the quality write happened
after the production write. Enforcing event ordering needs producer-owned event
sequence identifiers or an outbox, not unrelated table revision counters.

## Record contract

`schema.sql` describes two hypothetical tables with one active row per unit.
`records.php` supplies minimal read-only Active Record-style models over PDO;
it does not assume an existing ORM dependency.

Producers must atomically increase `row_version` on EVERY relevant upsert,
including updates that leave status unchanged. Versions are compared as decimal
strings to avoid floating-point or integer-overflow problems. Versions must
never reset and unit identifiers must not be recycled. A detected version
regression fails explicitly. Deletion alone is not an upsert.

Existing rows do not count as new events. A missing baseline followed by a
visible row counts as an insert. A changed row counts as an update regardless
of status; this observer does not require "released" or "passed".

This is current-row polling, not CDC: multiple writes can collapse into one
observation, and insert/delete activity wholly between polls can be missed.
An upsert before the initial baseline snapshot is not observed.

## Setup and execution

Requires PHP CLI with pcntl, pdo_mysql and mysqlnd, and MySQL 8.x.
On Devuan, the PDO MySQL driver can be installed with `sudo apt-get install php-mysql`.
No package installation, schema application, grants, or live database execution
is performed by the accompanying tests.

Have a DBA provision the schema and a dedicated account with SELECT privileges
only on these two tables. Use a TLS-configured DSN/deployment for remote databases.
The session is additionally read-only. Producer credentials must not be used.

From the repository root:

    export MES_UNIT_ID=UNIT-00042
    export MES_MYSQL_DSN='mysql:host=127.0.0.1;dbname=mes_demo;charset=utf8mb4'
    export MES_MYSQL_USER=mes_observer
    read -r -s -p 'MySQL password: ' MES_MYSQL_PASSWORD; printf '\n'
    export MES_MYSQL_PASSWORD
    MES_POLL_SECONDS=5 MES_TIMEOUT_SECONDS=3600 php run.php mes-observe

Trigger hypothetical upserts separately using a producer account, after the
"Baselines captured" diagnostic appears. Example statements are comments in
`schema.sql`, not part of the observer.

Defaults: five-second polls and a one-hour observation deadline.
Supported ranges: poll 1-60 seconds; timeout 1-86400 seconds.
PDO connection timeout is five seconds, mysqlnd read timeout five seconds,
and MySQL SELECT execution limit two seconds. These are operation timeouts,
not a hard end-to-end network or DNS deadline.

Diagnostics use inherited `Base::log()` and go to timestamped stderr.
Check progress with `php status.php --json`.
Completion overwrites `.runs/mes-observe/last-observation.json`; it is a latest
receipt, not durable event history, and is not transactionally coupled to the
runner checkpoint. Atomic rename does not guarantee survival of power loss.

## Resume and stop

The runner permits one active instance of this workflow, not one per unit.
Do not use this single workflow concurrently for different units.

Ctrl-C, SIGTERM and SIGHUP interrupt waits and retain the current checkpoint as
an ordinary failure; blocking database calls may delay handling until their
timeouts. No child process is created. Rerun with the same configuration to
resume. The original deadline and baseline are retained after polling begins.
Passwords may rotate without changing the connection identity.

The deadline uses wall-clock time and is not extended by manual reruns. Once
expired, inspect/archive the checkpoint before deliberately starting a fresh
observation. Never remove a checkpoint while its workflow is running.

A crash before the baseline node's successful result is checkpointed may
recapture the baseline on resume and miss intervening writes. A crash after
a node observes a change can replay that read. This is observe-only, not an
exactly-once event-processing system.

## Tests

Run `timeout 40s bash tests/mes-observe.sh`.
Tests use an isolated runner copy and a deterministic fake record adapter.
They cover preexisting rows, inserts, unchanged rows, sequential completion,
large revision values, deadline persistence, interruption/resume, stderr-only
output and receipt creation. They do not establish live MySQL connectivity,
SQL permissions, network timeout behavior or production CDC guarantees.

## OOP structure

Numbered files now invoke node subclasses while keeping the same filenames,
checkpoint state and sequence numbers. `ObservationNode` centralizes
configuration validation, deadline checks, scoped signal handling and paced
record polling. `PollRecordNode` supplies the final polling execution method;
production-order and quality-check subclasses override only the table and
self-branch sequence.

The protected `readRecords()` method is the adapter boundary for specialized
subclasses and isolated tests. Its default implementation delegates to the
existing read-only PDO adapter. Configuration, deadline and signal checks
surround reads just as before. The record models remain unchanged; decimal
revision validation and comparison remain in `common.php`.

Receipt publication retains its unique temporary file, complete-write check
and cleanup. This iteration does not change SQL, credentials, transaction
semantics, observation ordering, resume behavior or failure retry policy.

Additional isolated checks:

    timeout 20s bash tests/mes-oop.sh

They cover overridden record reads, paced unchanged branches, successful
observations, version regressions, read failures, configuration and deadline
guards, and restoration of signal handlers/async mode after success, failure
and a handled SIGTERM. No database connection is made.
