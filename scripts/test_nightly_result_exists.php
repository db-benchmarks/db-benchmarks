<?php

$dir = sys_get_temp_dir() . '/nightly-result-fixture-' . getmypid();
mkdir($dir, 0700);
$version = 'test-kit-perf_improvements-26102015-5ba603a55';
$base = [
    'testName' => 'hn_small', 'engine' => 'manticoresearch', 'type' => $version,
    'memory' => '1024', 'limited' => 0,
    'info' => ['version' => $version, 'manticoreCommit' => '5ba60_ab123'],
    'queries' => [
        ['result' => [['count(*)' => 1]], 'times' => [100, 110], 'retest' => false],
        ['result' => [['count(*)' => 2]], 'times' => [120], 'retest' => false],
    ],
];
$name = "$dir/hn_small_manticoresearch__1024";
$check = static function (bool $expected, string $ver, string $retest = 'false', string $limited = 'false', string $type = '') use ($dir): void {
    $args = [$dir, 'hn_small', 'manticoresearch', $type, '1024', $limited, $retest, $ver, '5ba60', '2'];
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/nightly_result_exists.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd, $output, $status);
    if (($status === 0) !== $expected) {
        throw new RuntimeException("Expected " . ($expected ? 'match' : 'miss') . " for $cmd (exit $status)");
    }
};
try {
    $check(false, $version);
    file_put_contents($name, serialize($base));
    $check(true, $version);
    $short = $base;
    array_pop($short['queries']);
    file_put_contents($name, serialize($short));
    $check(false, $version);
    file_put_contents($name, serialize($base));
    $check(false, 'test-kit-perf_improvements-26102015-deadbeef0');
    $wrongCommit = $base;
    $wrongCommit['info']['manticoreCommit'] = 'other_ab123';
    file_put_contents($name, serialize($wrongCommit));
    $check(false, $version);
    file_put_contents($name, serialize($base));
    $check(false, $version, 'true');
    $check(false, $version, 'false', 'true');
    $bad = $base;
    $bad['testName'] = 'other';
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad['testName'] = 'hn_small';
    $bad['type'] = 'columnar_' . $version;
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad['type'] = $version;
    $bad['memory'] = '110000';
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad['memory'] = '1024';
    $bad['queries'][1]['retest'] = true;
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad = $base;
    $bad['queries'][1]['result'] = ['error' => 'query failed'];
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad['queries'][1] = $base['queries'][1];
    $bad['queries'][1]['times'] = [];
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    $bad['queries'][1]['times'] = [0];
    file_put_contents($name, serialize($bad));
    $check(false, $version);
    // A valid retest must not stand in for the initial phase.
    $retest = $base;
    foreach ($retest['queries'] as &$query) {
        $query['retest'] = true;
    }
    unset($query);
    file_put_contents($name . '_retest', serialize($retest));
    $check(true, $version, 'true');
    $check(false, $version);
    unlink($name . '_retest');
    // Legacy release results use the same serialized layout, including typed
    // and limited variants: keep recognizing their exact phase and filename.
    $typedName = "$dir/hn_small_manticoresearch_columnar_tuned_1024_limited";
    $typed = $base;
    $typed['type'] = 'columnar_tuned_' . $version;
    $typed['limited'] = 1;
    file_put_contents($typedName, serialize($typed));
    $check(true, $version, 'false', 'true', 'columnar_tuned');
    $check(false, $version, 'false', 'false', 'columnar_tuned');
    foreach ($typed['queries'] as &$query) {
        $query['retest'] = true;
    }
    unset($query);
    file_put_contents($typedName . '_retest', serialize($typed));
    $check(true, $version, 'true', 'true', 'columnar_tuned');
    unlink($typedName . '_retest');
    unlink($typedName);
    file_put_contents($name, 'not serialized');
    $check(false, $version);
    $release = $base;
    $release['info']['version'] = '14.0.0';
    $release['type'] = '14.0.0';
    file_put_contents($name, serialize($release));
    $check(true, '14.0.0');
    $release['info']['manticoreCommit'] = 'other_ab123';
    file_put_contents($name, serialize($release));
    $check(false, '14.0.0');
    echo "nightly result matcher: OK\n";
} finally {
    if (isset($typedName)) {
        foreach ([$typedName, $typedName . '_retest'] as $file) {
            if (is_file($file)) unlink($file);
        }
    }
    unlink($name);
    rmdir($dir);
}
