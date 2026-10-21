<?php

// A benchmark result is PHP-serialized; matching arbitrary text in it can
// mistake query text or server metadata for the tested engine version.
if ($argc !== 9 || !is_file($argv[1])) {
    exit(1);
}

[, $file, $test, $type, $memory, $limited, $version, $hash, $phase] = $argv;
if (!in_array($phase, ['initial', 'retest'], true)) {
    exit(1);
}

$result = @unserialize(file_get_contents($file), ['allowed_classes' => false]);
if (!is_array($result) || !isset($result['info']) || !is_array($result['info'])) {
    exit(1);
}

$queries = $result['queries'] ?? null;
$expectedRetest = $phase === 'retest';
if (!is_array($queries) || !$queries) {
    exit(1);
}
foreach ($queries as $query) {
    if (!is_array($query) || ($query['retest'] ?? null) !== $expectedRetest
        || !isset($query['result']) || !is_array($query['result'])
        || array_key_exists('error', $query['result'])
        || !isset($query['times']) || !is_array($query['times']) || !$query['times']) {
        exit(1);
    }
    foreach ($query['times'] as $time) {
        if ((!is_int($time) && !is_float($time)) || !is_finite($time) || $time < 0) {
            exit(1);
        }
    }
}

$matches = ($result['testName'] ?? null) === $test
    && ($result['engine'] ?? null) === 'manticoresearch'
    && ($result['type'] ?? null) === ltrim($type . '_' . $version, '_')
    && (string) ($result['memory'] ?? '') === $memory
    && ($result['limited'] ?? null) === (int) ($limited === 'true')
    && ($result['info']['version'] ?? null) === $version
    && isset($result['info']['manticoreCommit'])
    && is_string($result['info']['manticoreCommit'])
    && explode('_', $result['info']['manticoreCommit'], 2)[0] === $hash;
exit($matches ? 0 : 1);
