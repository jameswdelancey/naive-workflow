#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = __DIR__;
$name = $argv[1] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name) || !is_dir("$root/workflows/$name")) {
    fwrite(STDERR, "Usage: php run.php <workflow>\n");
    exit(1);
}

$dir = "$root/workflows/$name";
$runs = "$root/.runs";
if (!is_dir($runs) && !mkdir($runs, 0700, true) && !is_dir($runs)) {
    exit(1);
}

$lock = fopen("$runs/$name.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "$name: already running or cannot acquire lock\n");
    exit(1);
}

$write = static function (string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents("$path.tmp", "$json\n") === false ||
        !rename("$path.tmp", $path)) {
        throw new RuntimeException("Cannot write $path");
    }
};
$include = static function (string $file, array &$state): mixed {
    return require $file;
};

$checkpoint = "$runs/$name.checkpoint.json";
$status = [
    'pid' => getmypid(),
    'status' => 'running',
    'node' => null,
    'started' => gmdate('c'),
];
$save = static function () use (&$status, $write, $runs, $name): void {
    $write("$runs/$name.json", $status);
};

$state = [];
$next = null;
try {
    $nodes = glob("$dir/[0-9]*.php") ?: [];
    sort($nodes, SORT_STRING);
    $steps = array_map('basename', $nodes);
    if (is_file("$dir/exit.php")) {
        $steps[] = 'exit.php';
    }

    $start = 0;
    if (is_file($checkpoint)) {
        $saved = json_decode(
            file_get_contents($checkpoint),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($saved) ||
            !is_string($saved['node'] ?? null) ||
            !is_array($saved['state'] ?? null)) {
            throw new RuntimeException('Invalid checkpoint');
        }
        $status['node'] = $saved['node'];
        $start = array_search($saved['node'], $steps, true);
        if ($start === false) {
            throw new RuntimeException("Checkpoint node is missing: {$saved['node']}");
        }
        $state = $saved['state'];
        fwrite(STDERR, "$name: resuming {$saved['node']}\n");
    }

    $save();
    foreach (array_slice($steps, $start) as $node) {
        $status['node'] = $node;
        $save();

        // Save input state before execution: retry this node on failure.
        $write($checkpoint, ['node' => $node, 'state' => $state]);
        $result = $include("$dir/$node", $state);

        if ($node === 'exit.php') {
            $next = $result;
            if ($next !== null) {
                if (!is_array($next) || !array_is_list($next) || !$next ||
                    count(array_filter($next, 'is_string')) !== count($next) ||
                    !str_starts_with($next[0], '/') || !is_executable($next[0])) {
                    throw new RuntimeException(
                        'exit.php must return null or an argv array with an absolute executable path'
                    );
                }
                if (!function_exists('pcntl_exec')) {
                    throw new RuntimeException('Process replacement requires pcntl_exec');
                }
            }
        }
    }

    if (is_file($checkpoint) && !unlink($checkpoint)) {
        throw new RuntimeException('Cannot remove checkpoint');
    }
    $status['status'] = 'done';
    $status['finished'] = gmdate('c');
    $save();
} catch (Throwable $error) {
    $status['status'] = 'failed';
    $status['error'] = $error->getMessage();
    $status['finished'] = gmdate('c');
    try {
        $save();
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "$name: {$error->getMessage()}\n");
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);

if ($next !== null) {
    pcntl_exec($next[0], array_slice($next, 1));
    fwrite(STDERR, "$name: cannot execute {$next[0]}\n");
    exit(1);
}
