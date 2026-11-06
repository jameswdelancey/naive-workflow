<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** An explicit successful-node result, never process-global mutable state. */
final class Jump
{
    private function __construct(public readonly string $sequence)
    {
    }

    public static function to(int|string $sequence): self
    {
        if (!preg_match('/^[0-9]+$/D', (string) $sequence)) {
            throw new \InvalidArgumentException('Jump target must be a nonnegative integer or digit string');
        }
        return new self(ltrim((string) $sequence, '0') ?: '0');
    }
}
