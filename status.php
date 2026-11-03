#!/usr/bin/env php
<?php
declare(strict_types=1);

$rows = [];
foreach (glob(__DIR__ . '/workflows/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $name = basename($dir);
    $file = __DIR__ . "/.runs/$name.json";
    $last = is_file($file) ? json_decode(file_get_contents($file), true) : [];
    $last = is_array($last) ? $last : [];
    $status = $last['status'] ?? 'idle';
    if ($status === 'running' && !is_dir('/proc/' . ($last['pid'] ?? 0))) {
        $status = 'stopped';
    }
    $rows[] = ['workflow' => $name, 'status' => $status] + $last;
}

if (in_array('--json', $argv, true)) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit;
}

$shorten = static function (string $text, int $width): string {
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    return strlen($text) > $width ? substr($text, 0, $width - 3) . '...' : $text;
};

$format = "%-24s  %-10s  %8s  %-32s\n";
printf($format, 'WORKFLOW', 'STATUS', 'PID', 'NODE');
foreach ($rows as $row) {
    printf(
        $format,
        $shorten($row['workflow'], 24),
        $shorten($row['status'], 10),
        $row['pid'] ?? '-',
        $shorten($row['node'] ?? '-', 32)
    );
}
