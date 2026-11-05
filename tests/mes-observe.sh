#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
temporary=$(mktemp -d)
pid=
cleanup() {
    if [[ -n "$pid" ]]; then
        kill -TERM "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
    rm -rf -- "$temporary"
}
trap cleanup EXIT
mkdir -p "$temporary/workflows"
cp "$root/run.php" "$temporary/run.php"
cp -R "$root/workflows/mes-observe" "$temporary/workflows/"
cat > "$temporary/workflows/mes-observe/records.php" <<'PHP'
<?php
function mes_read_records(array $tables, string $id): array
{
    static $calls = [];
    $mode = getenv('MES_TEST_MODE');
    $baseline = count($tables) === 2;
    $result = [];
    foreach ($tables as $table) {
        $calls[$table] = ($calls[$table] ?? 0) + 1;
        file_put_contents(
            dirname(__DIR__, 2) . '/queries.log',
            ($baseline ? 'baseline ' : 'poll ') . $table . "\n",
            FILE_APPEND
        );
        $changed = !$baseline && $mode !== 'wait' &&
            ($mode === 'resume' || $calls[$table] >= 3);
        $version = $changed ? '9007199254740993' : '9007199254740992';
        $result[$table] = $mode === 'insert' && !$changed ? null : [
            'unit_id' => $id,
            'row_version' => $version,
            'status' => $changed ? 'updated' : 'existing',
        ];
    }
    return $result;
}
PHP
export MES_UNIT_ID=UNIT-TEST MES_POLL_SECONDS=1 MES_TIMEOUT_SECONDS=20
export MES_MYSQL_DSN='mysql:host=fixture;dbname=fixture;charset=utf8mb4'
export MES_MYSQL_USER=fixture MES_MYSQL_PASSWORD=
cd "$temporary"

for mode in update insert; do
    rm -rf .runs
    : > queries.log
    MES_TEST_MODE="$mode" timeout 8s php run.php mes-observe >out 2>err
    test ! -s out
    test ! -e .runs/mes-observe.checkpoint.json
    python3 - "$mode" <<'PYTEST'
import json, pathlib, sys
receipt = json.loads(pathlib.Path(".runs/mes-observe/last-observation.json").read_text())
status = json.loads(pathlib.Path(".runs/mes-observe.json").read_text())
assert status["status"] == "done"
assert receipt["unit_id"] == "UNIT-TEST"
assert len(receipt["observed"]) == 2
for table, observation in receipt["observed"].items():
    assert observation["record"]["row_version"] == "9007199254740993"
    assert (receipt["baseline"][table] is None) == (sys.argv[1] == "insert")
queries = pathlib.Path("queries.log").read_text().splitlines()[2:]
assert queries == [
    "poll mes_production_orders", "poll mes_production_orders",
    "poll mes_quality_checks", "poll mes_quality_checks",
], queries
PYTEST
    printf 'PASS: %s, unchanged branches, ordered nodes, receipt, large revisions\n' "$mode"
done

rm -rf .runs
MES_TEST_MODE=wait MES_TIMEOUT_SECONDS=1 timeout 5s php run.php mes-observe >out 2>err && {
    printf 'Expected timeout failure\n' >&2
    exit 1
}
grep -q 'Observation deadline expired' err
cp .runs/mes-observe.checkpoint.json saved-checkpoint.json
MES_TEST_MODE=wait MES_TIMEOUT_SECONDS=1 timeout 5s php run.php mes-observe >out 2>err && {
    printf 'Expired observation unexpectedly resumed\n' >&2
    exit 1
}
cmp saved-checkpoint.json .runs/mes-observe.checkpoint.json
printf 'PASS: timeout and unchanged deadline across manual resume\n'

rm -rf .runs
: > queries.log
MES_TEST_MODE=wait php run.php mes-observe >out 2>err &
pid=$!
ready=0
for ((attempt=0; attempt<100; attempt++)); do
    if grep -q '^poll mes_production_orders$' queries.log; then
        ready=1
        break
    fi
    sleep 0.05
done
test "$ready" = 1
kill -TERM "$pid"
if wait "$pid"; then
    printf 'Interrupted workflow unexpectedly succeeded\n' >&2
    exit 1
fi
pid=
test ! -s out
grep -q 'Observation interrupted' err
python3 - <<'PYTEST'
import json, pathlib
checkpoint = json.loads(pathlib.Path(".runs/mes-observe.checkpoint.json").read_text())
assert checkpoint["node"] == "20-production-order.php"
assert not checkpoint["state"]["observed"]
assert "poll mes_quality_checks" not in pathlib.Path("queries.log").read_text()
PYTEST
MES_TEST_MODE=resume timeout 5s php run.php mes-observe >out 2>err
test ! -s out
test ! -e .runs/mes-observe.checkpoint.json
test -s .runs/mes-observe/last-observation.json
printf 'PASS: SIGTERM preserves checkpoint; manual resume completes\n'
printf 'Live MySQL integration was not run; tests used an isolated fake adapter.\n'
