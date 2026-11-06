<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Shared diagnostic output for nodes, the runner and status reporting. */
abstract class Base
{
    /**
     * Emit one timestamped stderr line. Escape embedded newlines so messages
     * cannot create unprefixed lines. An event may supply its saved timestamp.
     */
    protected function log(string $message, ?string $timestamp = null): void
    {
        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], $message);
        $timestamp = str_replace(
            ["\r", "\n"], ['\\r', '\\n'], $timestamp ?? date('c')
        );
        fwrite(STDERR, '[' . $timestamp . '] ' . $message . "\n");
    }
}
