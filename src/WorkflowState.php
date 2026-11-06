<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** State contract; concrete workflows declare their own fields and JSON mapping. */
abstract class WorkflowState implements \JsonSerializable
{
    abstract public function __construct(array $values = []);

    abstract public function toArray(): array;

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    final public function __set(string $name, mixed $value): never
    {
        throw new \LogicException("Unknown or inaccessible state field: $name");
    }

    final public function __get(string $name): never
    {
        throw new \LogicException("Unknown or uninitialized state field: $name");
    }

    final public function __unset(string $name): never
    {
        throw new \LogicException("Unknown or inaccessible state field: $name");
    }

    /** Narrow the shared node contract without relying on disabled assertions. */
    final public static function requireState(WorkflowState $state): static
    {
        if (!$state instanceof static) {
            throw new \InvalidArgumentException('Expected state of type ' . static::class);
        }
        return $state;
    }

    final protected static function rejectUnknown(array $values, array $allowed): void
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown state field: ' . (string) $key);
            }
        }
    }
}
