<?php
declare(strict_types=1);

namespace NaiveWorkflow;

use RuntimeException;

/** Immutable discovery snapshot and sequence resolution; no execution or I/O writes. */
final class WorkflowDefinition
{
    public readonly array $steps;

    public function __construct(string $directory)
    {
        // Discover numbered nodes and sort lexicographically, not numerically.
        $nodes = glob("$directory/[0-9]*.php") ?: [];
        sort($nodes, SORT_STRING);
        $steps = array_map('basename', $nodes);
        // Run the optional process-handoff node after the numbered nodes.
        if (is_file("$directory/exit.php")) {
            $steps[] = 'exit.php';
        }
        $this->steps = $steps;
    }

    public function positionOf(string $node): int
    {
        $position = array_search($node, $this->steps, true);
        if ($position === false) {
            throw new RuntimeException("Checkpoint node is missing: $node");
        }
        return $position;
    }

    public function resolve(mixed $target): int
    {
        // Accept nonnegative integers or digit strings, not arbitrary filenames.
        if ((!is_int($target) && !is_string($target)) ||
            !preg_match('/^[0-9]+$/D', (string) $target)) {
            throw new RuntimeException('Jump target must be a nonnegative integer or digit string');
        }

        // Compare digit strings without integer conversion; leading zeroes do not matter.
        $normalize = static fn(string $value): string => ltrim($value, '0') ?: '0';
        $matches = [];
        foreach ($this->steps as $index => $candidate) {
            if (preg_match('/^([0-9]+)/', $candidate, $match) &&
                $normalize($match[1]) === $normalize((string) $target)) {
                $matches[] = $index;
            }
        }
        // Missing or ambiguous sequence numbers are errors, not fall-through.
        if (count($matches) !== 1) {
            throw new RuntimeException("Jump target $target must identify exactly one numbered node");
        }
        return $matches[0];
    }
}
