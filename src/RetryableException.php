<?php
declare(strict_types=1);

/**
 * Request another attempt of the current node after a delay.
 * Mutations to $state are checkpointed and passed to the next attempt.
 * Nodes must make external side effects safe to repeat.
 */
final class RetryableException extends RuntimeException
{
    public readonly int $retryAfterSeconds;

    public function __construct(
        string $message,
        int $retryAfterSeconds = 1,
        ?Throwable $previous = null
    ) {
        // Reject invalid delays before creating the retry request.
        if ($retryAfterSeconds < 0) {
            throw new InvalidArgumentException('Retry delay cannot be negative');
        }
        $this->retryAfterSeconds = $retryAfterSeconds;
        parent::__construct($message, 0, $previous);
    }
}
