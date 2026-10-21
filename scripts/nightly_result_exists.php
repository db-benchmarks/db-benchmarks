<?php

// A result file is not a completed benchmark merely because it exists: the
// harness also saves failed and partially completed query runs.
if ($argc !== 11) {
    fwrite(STDERR, "Usage: nightly_result_exists.php dir test engine type memory limited retest version hash query_count\n");
    exit(2);
}

[, $dir, $test, $engine, $type, $memory, $limited, $retest, $version, $hash, $queryCount] = $argv;
if (!is_dir($dir)) {
    exit(1);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $name = $file->getBasename();
    $suffix = $limited === 'true' ? '_limited' : '';
    $suffix .= $retest === 'true' ? '_retest' : '';
    if ($name !== "{$test}_{$engine}_{$type}_{$memory}{$suffix}") {
        continue;
    }
    $result = @unserialize(file_get_contents($file->getPathname()), ['allowed_classes' => false]);
    if (!is_array($result) || ($result['testName'] ?? null) !== $test ||
        ($result['engine'] ?? null) !== $engine ||
        (string) ($result['memory'] ?? '') !== $memory ||
        (bool) ($result['limited'] ?? false) !== ($limited === 'true') ||
        ($result['info']['version'] ?? null) !== $version ||
        ($result['type'] ?? null) !== ltrim("{$type}_{$version}", '_')) {
        continue;
    }
    // Check the engine's reported commit as well as the benchmark version.
    if (!str_starts_with($result['info']['manticoreCommit'] ?? '', $hash)) {
        continue;
    }
    $queries = $result['queries'] ?? null;
    if (!is_array($queries) || $queries === [] || count($queries) !== (int) $queryCount) {
        continue;
    }
    foreach ($queries as $query) {
        if (!is_array($query) || (bool) ($query['retest'] ?? false) !== ($retest === 'true') ||
            !isset($query['result']) || !empty($query['result']['error']) ||
            !isset($query['times']) || !is_array($query['times']) || $query['times'] === []) {
            continue 2;
        }
        foreach ($query['times'] as $time) {
            if (!is_numeric($time) || $time <= 0) {
                continue 3;
            }
        }
    }
    exit(0);
}
exit(1);
