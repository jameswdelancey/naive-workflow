<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/**
 * Validated process-replacement argv. Construct before completion; execute
 * only after checkpoint removal, done status publication and lock release.
 */
final class HandoffCommand
{
    private readonly array $argv;

    public function __construct(mixed $argv)
    {
        if (!is_array($argv) || !array_is_list($argv) || !$argv ||
            count(array_filter($argv, 'is_string')) !== count($argv) ||
            !str_starts_with($argv[0], '/') || !is_executable($argv[0])) {
            throw new \RuntimeException(
                'exit.php must return null or an argv array with an absolute executable path'
            );
        }
        if (!function_exists('pcntl_exec')) {
            throw new \RuntimeException('Process replacement requires pcntl_exec');
        }
        $this->argv = $argv;
    }

    public function executable(): string
    {
        return $this->argv[0];
    }

    public function execute(): void
    {
        // Success never returns. The caller reports a returned launch failure.
        pcntl_exec($this->argv[0], array_slice($this->argv, 1));
    }
}
