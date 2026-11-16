<?php

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/engine.php';
require_once __DIR__ . '/../plugins/manticoresearch.php';
require_once __DIR__ . '/../plugins/mysql.php';

function check($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "$label: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$parse = new ReflectionMethod(engine::class, 'parseMysqlError');
foreach ([new manticoresearch('rowwise'), new mysql('')] as $engine) {
    $error = $parse->invoke($engine, 1064, 2006, "unknown local table(s) 'hn_small_vector'");
    check($error['error'], true, 'query failure is fatal');
    check($error['message'], "MySQL error 1064: unknown local table(s) 'hn_small_vector'", 'SQL error is reported');
    check($parse->invoke($engine, 0, 2006, ''), false, 'successful query');

    $connection = new class {
        public int $errno = 1064;
        public string $error = "unknown local table(s) 'hn_small_vector'";
        public function query(string $query): bool { return false; }
    };
    $property = new ReflectionProperty($engine, 'mysql');
    $property->setValue($engine, $connection);
    $result = (new ReflectionMethod($engine, 'testOnce'))->invoke($engine, 'select count(*) from hn_small_vector');
    check($result['error'], true, 'testOnce rejects missing table');
    check($result['message'], "MySQL error 1064: unknown local table(s) 'hn_small_vector'", 'testOnce reports server error');
}
engine::$commandLineArguments = ['query_timeout' => 15];
$timeout = $parse->invoke(new manticoresearch('rowwise'), 2006, 2006, 'server has gone away');
check($timeout['type'], 'timeout', 'timeout classification');
check($timeout['message'], 'Operation timed out after 15 seconds with 0 bytes received', 'timeout message');

$startupLog = [
    'starting daemon ...',
    'WARNING: table hn_small_vector: prealloc: meta is v.25, binary is v.24 - NOT SERVING',
    'accepting connections',
];
check(manticoresearch::findNotServingError($startupLog), $startupLog[1], 'incompatible table is detected');
check(manticoresearch::findNotServingError(['accepting connections']), null, 'healthy startup');

class ManticoreStartupProbe extends manticoresearch {
    public ?string $startupProblem = null;
    public bool $ready = true;
    protected function startupError(): ?string { return $this->startupProblem; }
    protected function canConnect() { return $this->ready; }
    public function startForTest(): float { return $this->start(false, false, true); }
}

$bin = sys_get_temp_dir() . '/benchmark-startup-' . bin2hex(random_bytes(5));
mkdir($bin);
file_put_contents($bin . '/docker', "#!/bin/sh\ncase \"\$1\" in\n  compose) exit 0;;\n  inspect) if [ \"\$BENCHMARK_FAKE_INSPECT_FAIL\" = 1 ]; then exit 1; fi; case \"\$3\" in --format=*) echo '2026-11-12T00:00:13Z';; *) echo '[{\"State\":{\"Status\":\"running\"}}]' ;; esac;;\n  logs) if [ \"\$BENCHMARK_FAKE_LOG_FAIL\" = 1 ]; then exit 1; fi; printf '%s\\n' \"\$BENCHMARK_FAKE_DOCKER_LOG\";;\nesac\n");
chmod($bin . '/docker', 0755);
$oldPath = getenv('PATH');
putenv('PATH=' . $bin . ':' . $oldPath);
try {
    $realProbe = new manticoresearch('rowwise');
    $startupError = new ReflectionMethod($realProbe, 'startupError');
    putenv('BENCHMARK_FAKE_DOCKER_LOG=' . $startupLog[1]);
    check($startupError->invoke($realProbe), $startupLog[1], 'real log probe catches incompatible table');
    putenv('BENCHMARK_FAKE_DOCKER_LOG=accepting connections');
    check($startupError->invoke($realProbe), null, 'real log probe accepts healthy table');
    putenv('BENCHMARK_FAKE_INSPECT_FAIL=1');
    check($startupError->invoke($realProbe), 'Cannot inspect Manticore container startup time', 'inspect failure is fatal');
    putenv('BENCHMARK_FAKE_INSPECT_FAIL');
    putenv('BENCHMARK_FAKE_LOG_FAIL=1');
    check($startupError->invoke($realProbe), 'Cannot read Manticore container startup logs', 'log read failure is fatal');
    putenv('BENCHMARK_FAKE_LOG_FAIL');

    $probe = new ManticoreStartupProbe('rowwise');
    engine::$commandLineArguments['test'] = 'hn_small_vector';
    engine::$commandLineArguments['probe_timeout'] = 1;
    $probe->startupProblem = $startupLog[1];
    try {
        $probe->startForTest();
        fwrite(STDERR, "startup accepted a NOT SERVING table\n");
        exit(1);
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'v.25, binary is v.24 - NOT SERVING'), true, 'startup fails with diagnostic');
    }
    $probe->ready = false;
    try {
        $probe->startForTest();
        fwrite(STDERR, "startup waited for SQL despite NOT SERVING\n");
        exit(1);
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'NOT SERVING'), true, 'startup fails before SQL readiness');
    }
    $probe->startupProblem = null;
    try {
        $probe->startForTest();
        fwrite(STDERR, "startup accepted unavailable SQL\n");
        exit(1);
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'did not accept SQL connections'), true, 'startup times out if SQL never becomes ready');
    }
    $probe->startupProblem = 'Cannot read Manticore container startup logs';
    $probe->ready = true;
    try {
        $probe->startForTest();
        fwrite(STDERR, "startup accepted unreadable Docker logs\n");
        exit(1);
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'Cannot read Manticore container startup logs'), true, 'startup fails closed on log error');
    }
    $probe->startupProblem = null;
    check($probe->startForTest(), 0.0, 'healthy startup continues');
} finally {
    putenv('BENCHMARK_FAKE_DOCKER_LOG');
    putenv('BENCHMARK_FAKE_INSPECT_FAIL');
    putenv('BENCHMARK_FAKE_LOG_FAIL');
    putenv('PATH=' . $oldPath);
    unlink($bin . '/docker');
    rmdir($bin);
}

echo "PASS: MySQL errors retain their diagnostic text and fail the query\n";
