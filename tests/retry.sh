#!/usr/bin/env bash
set -euo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
pid=""

cleanup() {
    if [[ -n "$pid" ]]; then
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
    rm -rf -- "$tmp"
}
trap cleanup EXIT

mkdir -p "$tmp/workflows"
cp -- "$root/run.php" "$root/status.php" "$tmp/"
cp -R -- "$root/workflows/hello" "$tmp/workflows/"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    cat "$tmp/"*.log >&2 2>/dev/null || true
    exit 1
}

check_completed() {
    local branch="$1"
    local log="$tmp/$branch.log"

    [[ ! -s "$tmp/$branch.stdout" ]] ||
        fail "$branch: demo output leaked to stdout"

    if grep -Ev '^\[[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\] ' "$log"; then
        fail "$branch: output is missing a uniform local timestamp"
    fi

    [[ "$(grep -Fc 'Retry demo: requesting retry on attempt 1.' "$log")" == 1 ]] ||
        fail "$branch: first attempt was not executed exactly once"
    [[ "$(grep -Fc 'Retry demo: continued on attempt 2.' "$log")" == 1 ]] ||
        fail "$branch: second attempt did not receive updated state"
    grep -Fq 'retrying in 1s' "$log" || fail "$branch: missing retry diagnostic"
    grep -Fq "Finished for retry-test: $branch." "$log" ||
        fail "$branch: workflow did not finish correctly"
    [[ ! -e "$tmp/.runs/hello.checkpoint.json" ]] ||
        fail "$branch: checkpoint was not removed"

    php -r '
        $s = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (($s["status"] ?? null) !== "done" ||
            ($s["node"] ?? null) !== "50-finish.php" ||
            isset($s["retry_at"]) ||
            isset($s["retry_after_seconds"]) ||
            isset($s["retry_error"])) {
            exit(1);
        }
    ' "$tmp/.runs/hello.json" || fail "$branch: invalid final status"
}

USER=retry-test HELLO_APPROVED=0 php "$tmp/run.php" hello >"$tmp/rejected.stdout" 2>"$tmp/rejected.log" &
pid=$!

observed=0
for ((i = 0; i < 100; i++)); do
    if php -r '
        $path = $argv[1] . "/.runs/";
        $s = json_decode(@file_get_contents($path . "hello.json") ?: "{}", true);
        $c = json_decode(@file_get_contents($path . "hello.checkpoint.json") ?: "{}", true);
        if (($s["status"] ?? null) !== "retrying" ||
            ($s["node"] ?? null) !== "15-retry.php" ||
            ($s["retry_after_seconds"] ?? 0) < 1 ||
            ($s["retry_error"] ?? null) !== "Demo node is not ready yet" ||
            ($c["node"] ?? null) !== "15-retry.php" ||
            ($c["state"]["retry_demo_attempts"] ?? null) !== 1 ||
            !is_int($c["retry_at"] ?? null) ||
            $c["retry_at"] <= time()) {
            exit(1);
        }
    ' "$tmp"; then
        observed=1
        break
    fi
    sleep 0.02
done
[[ "$observed" == 1 ]] || fail "retrying status and checkpoint were not observed"

php "$tmp/status.php" --json >"$tmp/status.json"
php -r '
    $rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    exit(($rows[0]["status"] ?? null) === "retrying" ? 0 : 1);
' "$tmp/status.json" || fail "status.php did not report retrying"

sleep 0.2
! grep -Fq 'Retry demo: continued' "$tmp/rejected.log" ||
    fail "node retried before its wait elapsed"

wait "$pid" || fail "rejected workflow failed"
pid=""
check_completed rejected

USER=retry-test HELLO_APPROVED=1 php "$tmp/run.php" hello >"$tmp/approved.stdout" 2>"$tmp/approved.log" ||
    fail "approved workflow failed"
check_completed approved

printf 'PASS: retry status, checkpointed state, node wait, second attempt, and both hello branches\n'

php -r '$state = []; require $argv[1];' \
    "$root/workflows/poll/10-tick.php" \
    >"$tmp/poll.stdout" 2>"$tmp/poll.log" ||
    fail "poll node failed"

[[ ! -s "$tmp/poll.stdout" ]] || fail "poll output leaked to stdout"
[[ "$(wc -l < "$tmp/poll.log")" == 1 ]] || fail "unexpected poll output"
grep -Eq '^\[[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\] polling$' \
    "$tmp/poll.log" || fail "poll stderr timestamp missing"

printf 'PASS: hello and poll messages use timestamped stderr; stdout remains empty\n'
