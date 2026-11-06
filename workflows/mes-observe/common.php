<?php
declare(strict_types=1);

// Decimal revision helpers shared with the read-only record adapter.
function mes_version(string $version): string
{
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $version)) {
        throw new RuntimeException('Expected a positive, monotonically increasing decimal row_version');
    }
    return $version;
}

function mes_compare(string $left, string $right): int
{
    mes_version($left);
    mes_version($right);
    return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
}

function mes_changed(?array $baseline, ?array $current): bool
{
    if ($current === null) {
        return false;
    }
    mes_version($current['row_version']);
    if ($baseline === null) {
        return true;
    }
    $comparison = mes_compare($current['row_version'], $baseline['row_version']);
    if ($comparison < 0) {
        throw new RuntimeException('row_version regressed; identifiers and revision counters must not be reset');
    }
    return $comparison > 0;
}
