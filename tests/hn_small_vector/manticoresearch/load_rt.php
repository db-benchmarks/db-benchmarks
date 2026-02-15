<?php

declare(strict_types=1);

final class HnSmallVectorRtLoader
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 9306;
    private const EXPECTED_COLUMNS = 10;
    private const DEFAULT_MAX_ID_FALLBACK = 1_200_000;
    private const DEFAULT_MODEL = 'sentence-transformers/all-MiniLM-L6-v2';
    private const DEFAULT_MAX_SQL_BYTES = 200_000; // keep small: auto-embeddings can make first batch very slow
    private const DEFAULT_MAX_ROWS_PER_BATCH = 200;
    private const DEFAULT_QUERY_TIMEOUT_SEC = 600;
    private const DEFAULT_CONNECT_TIMEOUT_SEC = 10;

    private static string $existingIdBitset = '';
    private static int $existingMaxId = 0;

    public static function main(array $argv): int
    {
        $options = getopt('', [
            'test:',
            'csv::',
            'host::',
            'port::',
            'batch_bytes::',
            'batch_rows::',
            'query_timeout::',
            'max_rows::',
            'workers::',
            'skip_ddl',
            'skip_existing',
            'no_skip_existing',
            'max_id::',
            'mode::',
            'total_workers::',
            'worker_id::'
        ]);
        $test = $options['test'] ?? getenv('test') ?: '';
        if ($test === '') {
            fwrite(STDOUT, "Missing --test (or env test)\n");
            return 2;
        }

        $rootDir = dirname(__DIR__); // tests/<test>
        $csvPath = $options['csv'] ?? ($rootDir . '/data/data.csv');
        $csvReal = realpath($csvPath);
        if ($csvReal === false || !is_file($csvReal)) {
            fwrite(STDOUT, "ERROR: CSV not found: $csvPath\n");
            return 2;
        }

        $host = (string)($options['host'] ?? getenv('MANTICORE_HOST') ?: self::DEFAULT_HOST);
        $port = (int)($options['port'] ?? getenv('MANTICORE_MYSQL_PORT') ?: self::DEFAULT_PORT);
        $maxSqlBytes = (int)($options['batch_bytes'] ?? self::DEFAULT_MAX_SQL_BYTES);
        $maxRowsPerBatch = (int)($options['batch_rows'] ?? self::DEFAULT_MAX_ROWS_PER_BATCH);
        $queryTimeoutSec = (int)($options['query_timeout'] ?? getenv('MANTICORE_QUERY_TIMEOUT') ?: self::DEFAULT_QUERY_TIMEOUT_SEC);
        $maxRowsTotal = (int)($options['max_rows'] ?? 0);
        $workers = (int)($options['workers'] ?? getenv('MANTICORE_WORKERS') ?: 1);
        $skipDdl = array_key_exists('skip_ddl', $options);
        $skipExisting = !array_key_exists('no_skip_existing', $options);
        $maxId = (int)($options['max_id'] ?? getenv('MANTICORE_MAX_ID') ?: 0);
        $mode = (string)($options['mode'] ?? 'insert');
        $totalWorkersOpt = isset($options['total_workers']) ? (int)$options['total_workers'] : null;
        $workerIdOpt = isset($options['worker_id']) ? (int)$options['worker_id'] : null;
        if ($maxSqlBytes < 100_000) {
            fwrite(STDOUT, "batch_bytes too small: $maxSqlBytes\n");
            return 2;
        }
        if ($maxRowsPerBatch < 1) {
            fwrite(STDOUT, "batch_rows too small: $maxRowsPerBatch\n");
            return 2;
        }
        if ($queryTimeoutSec < 1) {
            fwrite(STDOUT, "query_timeout too small: $queryTimeoutSec\n");
            return 2;
        }
        if ($maxRowsTotal < 0) {
            fwrite(STDOUT, "max_rows too small: $maxRowsTotal\n");
            return 2;
        }
        if ($workers < 1) {
            fwrite(STDOUT, "workers too small: $workers\n");
            return 2;
        }
        if ($mode !== 'insert' && $mode !== 'replace') {
            fwrite(STDOUT, "mode must be insert|replace, got: $mode\n");
            return 2;
        }
        if ($skipExisting && $maxId < 1) {
            $maxId = self::detectMaxIdFromCsv($csvReal);
            if ($maxId < 1) {
                $maxId = self::DEFAULT_MAX_ID_FALLBACK;
                fwrite(STDOUT, "WARNING: couldn't detect max id from CSV, falling back to max_id=$maxId\n");
            } else {
                fwrite(STDOUT, "Detected max_id from CSV: $maxId\n");
            }
        }
        if ($workerIdOpt !== null) {
            if ($totalWorkersOpt === null || $totalWorkersOpt < 1) {
                fwrite(STDOUT, "worker_id requires --total_workers\n");
                return 2;
            }
            if ($workerIdOpt < 0 || $workerIdOpt >= $totalWorkersOpt) {
                fwrite(STDOUT, "worker_id out of range: $workerIdOpt for total_workers=$totalWorkersOpt\n");
                return 2;
            }
        }
        fwrite(STDOUT, "Starting load: test=$test host=$host port=$port workers=$workers csv=$csvReal\n");
        fflush(STDOUT);

        $mysql = new mysqli();
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_SEC);
        $mysql->options(MYSQLI_OPT_READ_TIMEOUT, $queryTimeoutSec);
        @ini_set('mysqlnd.net_read_timeout', (string)$queryTimeoutSec);
        fwrite(STDOUT, "Connecting to Manticore MySQL at $host:$port (timeout=" . self::DEFAULT_CONNECT_TIMEOUT_SEC . "s)\n");
        @$mysql->real_connect($host, '', '', '', $port);
        if ($mysql->connect_error) {
            fwrite(STDOUT, "ERROR: MySQL connect error: {$mysql->connect_error}\n");
            return 1;
        }
        fwrite(STDOUT, "Connected.\n");

        if (!$skipDdl && $workerIdOpt === null) {
            self::exec($mysql, "DROP TABLE IF EXISTS `$test`", 'drop');

            $create = self::createTableSql($test);
            self::exec($mysql, $create, 'create');
            self::debugAfterCreate($mysql, $test);
        } elseif ($workerIdOpt === null) {
            fwrite(STDOUT, "Skipping DDL as requested (--skip_ddl)\n");
        }

        if ($skipExisting) {
            self::existingInit($mysql, $test, $maxId);
        }

        if ($workerIdOpt !== null) {
            $totalWorkers = (int)$totalWorkersOpt;
            $workerId = (int)$workerIdOpt;
            fwrite(STDOUT, "Single-worker mode: worker_id=$workerId total_workers=$totalWorkers mode=$mode skip_ddl=" . ($skipDdl ? 'yes' : 'no') . "\n");
            fflush(STDOUT);
            $result = self::loadPartition(
                $test,
                $csvReal,
                $host,
                $port,
                $maxSqlBytes,
                $maxRowsPerBatch,
                $queryTimeoutSec,
                $maxRowsTotal,
                $totalWorkers,
                $workerId,
                $mode,
                $skipExisting
            );
            return $result['exitCode'];
        }

        if ($workers === 1) {
            $result = self::loadPartition(
                $test,
                $csvReal,
                $host,
                $port,
                $maxSqlBytes,
                $maxRowsPerBatch,
                $queryTimeoutSec,
                $maxRowsTotal,
                1,
                0,
                $mode,
                $skipExisting
            );
            return $result['exitCode'];
        }

        fwrite(STDOUT, "Loading $csvReal into RT table `$test` with $workers workers (auto-embeddings from `comment_text`)\n");
        fflush(STDOUT);
        fwrite(STDOUT, "Forking $workers workers\n");
        fflush(STDOUT);

        $pids = [];
        for ($workerId = 0; $workerId < $workers; $workerId++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                fwrite(STDOUT, "ERROR: Failed to fork worker $workerId\n");
                return 1;
            }
            if ($pid === 0) {
                $res = self::loadPartition(
                    $test,
                    $csvReal,
                    $host,
                    $port,
                    $maxSqlBytes,
                $maxRowsPerBatch,
                $queryTimeoutSec,
                $maxRowsTotal,
                $workers,
                $workerId,
                $mode,
                $skipExisting
            );
            exit($res['exitCode']);
        }
            $pids[$pid] = $workerId;
        }

        $exitCode = 0;
        foreach ($pids as $pid => $workerId) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status)) {
                $code = pcntl_wexitstatus($status);
                if ($code !== 0) {
                    fwrite(STDOUT, "ERROR: Worker $workerId exited with code $code\n");
                    $exitCode = 1;
                }
            } else {
                fwrite(STDOUT, "ERROR: Worker $workerId terminated abnormally\n");
                $exitCode = 1;
            }
        }

        if ($exitCode === 0) {
            fwrite(STDOUT, "Done. All workers completed.\n");
        }

        return $exitCode;
    }

    private static function createTableSql(string $test): string
    {
        $model = self::DEFAULT_MODEL;

        return "CREATE TABLE `$test` ("
            . "`id` bigint, "
            . "`story_id` bigint, "
            . "`story_text` text, "
            . "`story_author` string, "
            . "`comment_id` bigint, "
            . "`comment_text` text, "
            . "`comment_author` string, "
            . "`comment_ranking` int, "
            . "`author_comment_count` int, "
            . "`story_comment_count` int, "
            . "`comment_embedding` float_vector "
            . "KNN_TYPE='hnsw' "
            . "HNSW_SIMILARITY='l2' "
            . "MODEL_NAME='$model' "
            . "FROM='comment_text'"
            . ") min_infix_len='2'";
    }

    private static function rowToSqlValues(mysqli $mysql, array $row): string
    {
        [
            $id,
            $storyId,
            $storyText,
            $storyAuthor,
            $commentId,
            $commentText,
            $commentAuthor,
            $commentRanking,
            $authorCommentCount,
            $storyCommentCount
        ] = $row;

        $id = (int)$id;
        $storyId = (int)$storyId;
        $commentId = (int)$commentId;
        $commentRanking = (int)$commentRanking;
        $authorCommentCount = (int)$authorCommentCount;
        $storyCommentCount = (int)$storyCommentCount;

        $storyTextEsc = self::q($mysql, (string)$storyText);
        $storyAuthorEsc = self::q($mysql, (string)$storyAuthor);
        $commentTextEsc = self::q($mysql, (string)$commentText);
        $commentAuthorEsc = self::q($mysql, (string)$commentAuthor);

        return "($id,$storyId,$storyTextEsc,$storyAuthorEsc,$commentId,$commentTextEsc,$commentAuthorEsc,$commentRanking,$authorCommentCount,$storyCommentCount)";
    }

    private static function q(mysqli $mysql, string $value): string
    {
        return "'" . $mysql->real_escape_string($value) . "'";
    }

    private static function exec(mysqli $mysql, string $sql, string $context): void
    {
        $ok = $mysql->query($sql);
        if ($ok === false) {
            $err = $mysql->error ?: 'unknown error';
            $errno = $mysql->errno;
            $sqlstate = @$mysql->sqlstate ?: '';
            $sqlPrefix = substr(preg_replace('/\\s+/', ' ', trim($sql)), 0, 200);
            fwrite(STDOUT, "ERROR: SQL failed ($context) (errno=$errno sqlstate=$sqlstate): $err\n");
            fwrite(STDOUT, "ERROR: SQL prefix: $sqlPrefix\n");
            fflush(STDOUT);
            exit(1);
        }
    }

    private static function debugAfterCreate(mysqli $mysql, string $test): void
    {
        fwrite(STDOUT, "Debug: created table `$test`, checking visibility...\n");

        $res = $mysql->query('SHOW TABLES');
        if ($res === false) {
            $err = $mysql->error ?: 'unknown error';
            fwrite(STDOUT, "WARNING: SHOW TABLES failed: $err\n");
            return;
        }

        $tables = [];
        while ($row = $res->fetch_row()) {
            if (isset($row[0]) && $row[0] !== '') {
                $tables[] = $row[0];
            }
            if (count($tables) >= 50) break;
        }
        $res->free();

        if (count($tables) === 0) {
            fwrite(STDOUT, "Debug: SHOW TABLES returned 0 rows\n");
            return;
        }

        $has = in_array($test, $tables, true) ? 'yes' : 'no';
        fwrite(STDOUT, "Debug: SHOW TABLES rows=" . count($tables) . " contains `$test`=$has\n");
    }

    private static function existingInit(mysqli $mysql, string $test, int $maxId): void
    {
        $probe = $mysql->query("SELECT id FROM `$test` LIMIT 1 OPTION max_matches=1");
        if ($probe === false) {
            $err = $mysql->error ?: 'unknown error';
            fwrite(STDOUT, "ERROR: failed to probe existing ids: $err\n");
            exit(1);
        }
        if ($probe->fetch_row() === null) {
            $probe->free();
            fwrite(STDOUT, "Resume: table is empty, nothing to skip\n");
            fflush(STDOUT);
            self::$existingIdBitset = '';
            self::$existingMaxId = 0;
            return;
        }
        $probe->free();

        self::$existingMaxId = $maxId;
        $bytes = intdiv(($maxId + 8), 8);
        self::$existingIdBitset = str_repeat("\0", $bytes);

        fwrite(STDOUT, "Resume: loading existing ids into bitset (max_id=$maxId)\n");
        fflush(STDOUT);

        $rangeSize = 50_000;
        $limit = $rangeSize;
        $totalMarked = 0;

        for ($start = 1; $start <= $maxId; $start += $rangeSize) {
            $end = min($maxId, $start + $rangeSize - 1);
            $sql = "SELECT id FROM `$test` WHERE id >= $start AND id <= $end LIMIT $limit OPTION max_matches=$limit";
            $res = $mysql->query($sql);
            if ($res === false) {
                $err = $mysql->error ?: 'unknown error';
                fwrite(STDOUT, "ERROR: failed to load existing ids (start=$start end=$end): $err\n");
                exit(1);
            }
            while (($row = $res->fetch_row()) !== null) {
                $id = isset($row[0]) ? (int)$row[0] : 0;
                if ($id > 0 && $id <= $maxId && !self::existingHas($id)) {
                    self::existingSet($id);
                    $totalMarked++;
                }
            }
            $res->free();
        }

        fwrite(STDOUT, "Resume: loaded $totalMarked existing ids\n");
        fflush(STDOUT);
    }

    private static function detectMaxIdFromCsv(string $csvReal): int
    {
        $handle = fopen($csvReal, 'rb');
        if ($handle === false) {
            return 0;
        }
        $maxId = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null] || !isset($row[0])) {
                continue;
            }
            $id = (int)$row[0];
            if ($id > $maxId) {
                $maxId = $id;
            }
        }
        fclose($handle);
        return $maxId;
    }

    private static function existingHas(int $id): bool
    {
        if ($id <= 0 || $id > self::$existingMaxId || self::$existingIdBitset === '') return false;
        $byteIndex = intdiv($id, 8);
        $mask = 1 << ($id % 8);
        return ((ord(self::$existingIdBitset[$byteIndex]) & $mask) !== 0);
    }

    private static function existingSet(int $id): void
    {
        $byteIndex = intdiv($id, 8);
        $mask = 1 << ($id % 8);
        $byte = ord(self::$existingIdBitset[$byteIndex]);
        self::$existingIdBitset[$byteIndex] = chr($byte | $mask);
    }

    /**
     * @return array{exitCode:int}
     */
    private static function loadPartition(
        string $test,
        string $csvReal,
        string $host,
        int $port,
        int $maxSqlBytes,
        int $maxRowsPerBatch,
        int $queryTimeoutSec,
        int $maxRowsTotal,
        int $workers,
        int $workerId,
        string $mode,
        bool $skipExisting
    ): array {
        $mysql = new mysqli();
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_SEC);
        $mysql->options(MYSQLI_OPT_READ_TIMEOUT, $queryTimeoutSec);
        @ini_set('mysqlnd.net_read_timeout', (string)$queryTimeoutSec);
        $prefix = ($workers === 1) ? "" : "[w$workerId] ";
        fwrite(STDOUT, $prefix . "Connecting to Manticore MySQL at $host:$port\n");
        @$mysql->real_connect($host, '', '', '', $port);
        if ($mysql->connect_error) {
            fwrite(STDOUT, "[w$workerId] ERROR: MySQL connect error: {$mysql->connect_error}\n");
            return ['exitCode' => 1];
        }
        fwrite(STDOUT, $prefix . "Connected.\n");

        $columns = '(`id`,`story_id`,`story_text`,`story_author`,`comment_id`,`comment_text`,`comment_author`,`comment_ranking`,`author_comment_count`,`story_comment_count`)';
        $verb = ($mode === 'replace') ? 'REPLACE' : 'INSERT';
        $insertPrefix = "$verb INTO `$test` $columns VALUES ";

        $rows = 0;
        $batches = 0;
        $sql = $insertPrefix;
        $sqlBytes = strlen($sql);
        $pending = 0;

        $startedAt = microtime(true);
        if ($workers === 1) {
            $line = "Loading $csvReal into RT table `$test` (auto-embeddings from `comment_text`)";
            fwrite(STDOUT, $line . "\n");
        } else {
            $line = "[w$workerId] Starting";
            fwrite(STDOUT, $line . "\n");
        }
        fflush(STDOUT);

        $handle = fopen($csvReal, 'rb');
        if ($handle === false) {
            fwrite(STDOUT, "[w$workerId] ERROR: Failed to open CSV: $csvReal\n");
            return ['exitCode' => 1];
        }

        $recordNo = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $recordNo++;
            if ($maxRowsTotal > 0 && $recordNo > $maxRowsTotal) {
                break;
            }
            if ($workers > 1 && (($recordNo - 1) % $workers) !== $workerId) {
                continue;
            }

            if (count($row) !== self::EXPECTED_COLUMNS) {
                fwrite(STDOUT, "[w$workerId] ERROR: Unexpected CSV columns at record $recordNo: got " . count($row) . ", expected " . self::EXPECTED_COLUMNS . "\n");
                fclose($handle);
                return ['exitCode' => 1];
            }

            $id = (int)$row[0];
            if ($skipExisting && self::existingHas($id)) {
                continue;
            }

            $values = self::rowToSqlValues($mysql, $row);
            $chunkBytes = strlen($values) + ($pending === 0 ? 0 : 1);

            if ($pending > 0 && (($sqlBytes + $chunkBytes) >= $maxSqlBytes || $pending >= $maxRowsPerBatch)) {
                $batches++;
                $batchStartedAt = microtime(true);
                $line = ($workers === 1 ? "  " : "[w$workerId] ") . "executing batch $batches (rows=$pending bytes=$sqlBytes)";
                fwrite(STDOUT, $line . "\n");
                fflush(STDOUT);
                self::exec($mysql, $sql, "w$workerId batch=$batches");
                $batchElapsed = microtime(true) - $batchStartedAt;
                $line = ($workers === 1 ? "  " : "[w$workerId] ") . "finished batch $batches (sec=" . number_format($batchElapsed, 3, '.', '') . ")";
                fwrite(STDOUT, $line . "\n");
                fflush(STDOUT);
                $sql = $insertPrefix;
                $sqlBytes = strlen($sql);
                $pending = 0;
            }

            $delimiter = ($pending === 0) ? '' : ',';
            $sql .= $delimiter . $values;
            $sqlBytes += strlen($values) + (($delimiter === '') ? 0 : 1);
            $pending++;
            $rows++;

            if (($rows % 10000) === 0) {
                $elapsed = microtime(true) - $startedAt;
                $rate = $elapsed > 0 ? (int)round($rows / $elapsed) : 0;
                $line = ($workers === 1 ? "  " : "[w$workerId] ") . "queued: $rows (rows/s: $rate)";
                fwrite(STDOUT, $line . "\n");
                fflush(STDOUT);
            }
        }
        fclose($handle);

        if ($pending > 0) {
            $batches++;
            $batchStartedAt = microtime(true);
            $line = ($workers === 1 ? "  " : "[w$workerId] ") . "executing batch $batches (rows=$pending bytes=$sqlBytes)";
            fwrite(STDOUT, $line . "\n");
            fflush(STDOUT);
            self::exec($mysql, $sql, "w$workerId batch=$batches");
            $batchElapsed = microtime(true) - $batchStartedAt;
            $line = ($workers === 1 ? "  " : "[w$workerId] ") . "finished batch $batches (sec=" . number_format($batchElapsed, 3, '.', '') . ")";
            fwrite(STDOUT, $line . "\n");
            fflush(STDOUT);
        }

        $elapsed = microtime(true) - $startedAt;
        $rate = $elapsed > 0 ? (int)round($rows / $elapsed) : 0;
        $line = ($workers === 1 ? "" : "[w$workerId] ") . "Done. Inserted $rows rows (rows/s: $rate)";
        fwrite(STDOUT, $line . "\n");
        fflush(STDOUT);

        return ['exitCode' => 0];
    }
}

exit(HnSmallVectorRtLoader::main($argv));
