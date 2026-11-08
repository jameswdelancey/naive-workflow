<?php
declare(strict_types=1);

// Resolve without integer conversion; leading zeroes do not matter.
function workflow_next_resolve(mixed $sequence, array $steps, ?int &$position): ?string
{
    $position = null;
    if ((!is_int($sequence) && !is_string($sequence)) ||
        !preg_match('/^[0-9]+$/D', (string) $sequence)) {
        return 'nextSequence must be a nonnegative integer or digit string';
    }
    $normalized = ltrim((string) $sequence, '0');
    $matches = [];
    foreach ($steps as $index => $candidate) {
        if (is_string($candidate) && preg_match('/^([0-9]+)/', $candidate, $match) &&
            ltrim($match[1], '0') === $normalized) {
            $matches[] = $index;
        }
    }
    if (count($matches) !== 1) {
        return "nextSequence $sequence must identify exactly one numbered node";
    }
    $position = $matches[0];
    return null;
}

// Select only after validation. A failed request preserves the previous choice.
// Selection does not return from the caller or execute the target immediately.
function workflow_next(mixed $sequence): ?string
{
    $routing = $GLOBALS['workflowRouting'] ?? null;
    if (!is_array($routing) || !is_array($routing['steps'] ?? null) ||
        !is_string($routing['node'] ?? null)) {
        return 'workflow_next requires an active runner node';
    }
    if ($routing['node'] === 'exit.php') {
        return 'nextSequence cannot be set by exit.php';
    }
    $position = null;
    $error = workflow_next_resolve($sequence, $routing['steps'], $position);
    if ($error !== null) {
        return $error;
    }
    $GLOBALS['nextSequence'] = $sequence;
    return null;
}
