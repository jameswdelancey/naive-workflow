<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/**
 * Publish complete JSON snapshots via same-directory rename.
 * Writers must hold the workflow lock. This is not an fsync guarantee.
 */
class JsonFileStore
{
    public function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents("$path.tmp", "$json\n") === false ||
            !rename("$path.tmp", $path)) {
            throw new \RuntimeException("Cannot write $path");
        }
    }
}
