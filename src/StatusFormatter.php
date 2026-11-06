<?php
declare(strict_types=1);

namespace NaiveWorkflow;

/** Pure rendering: callers choose the destination for the returned text. */
class StatusFormatter
{
    public function json(array $rows): string
    {
        return json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }

    public function table(array $rows): string
    {
        // Sanitize terminal control characters and bound widths in the human-readable report.
        $shorten = static function (string $text, int $width): string {
            $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
            return strlen($text) > $width ? substr($text, 0, $width - 3) . '...' : $text;
        };

        // Show the saved retry deadline, not a stale delay presented as a live countdown.
        // UPDATED AT identifies when the file snapshot was last written, in local time.
        $format = "%-24s  %-10s  %8s  %-32s  %-25s  %-25s\n";
        $output = sprintf($format, 'WORKFLOW', 'STATUS', 'PID', 'NODE', 'RETRY AT', 'UPDATED AT');
        foreach ($rows as $row) {
            $output .= sprintf(
                $format,
                $shorten($row['workflow'], 24),
                $shorten($row['status'], 10),
                $row['pid'] ?? '-',
                $shorten($row['node'] ?? '-', 32),
                $shorten($row['retry_at'] ?? '-', 25),
                $shorten($row['updated_at'] ?? '-', 25)
            );
        }
        return $output;
    }
}
