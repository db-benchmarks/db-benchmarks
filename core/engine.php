<?php
/* Copyright (C) 2022-2024 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */


abstract class engine
{

    use Helpers;

    protected const UNSUPPORTED_QUERY_ERROR = 'unsupported';
    protected const TIMEOUT_QUERY_ERROR = 'timeout';
    protected const UNEXPECTED_QUERY_ERROR = 'error';

    public static $mode; // work mode: test, save or dump
    protected static $startTime; // test start timestamp to be used as a sub-directory when saving results
    protected static $cwd; // the initial working dir the script was run in
    public static $commandLineArguments;
    protected $type; // engine type (e.g. columnar_plain_ps)
    private static $queries = false; // queries to process
    private static $formatVersion = 1; // output files/db record format version

    // fetches and returns info about engine
    // should return non-empty array including element "version"
    abstract public function getInfo();

    // should return a value which is then gets appeneded to the engine type
    // it's called after getInfo() and accepts the result of getInfo();
    abstract protected function appendType($info);

    // should return non-empty url to database site
    abstract protected function url();

    // should return non-empty database description
    abstract protected function description();

    // runs before query, supposed to be used for preparing for a query, so the time spent on that is not count towards the actual query
    abstract protected function beforeQuery();

    // runs after engine is started to make sure we can connect to it before we make any actual query
    abstract protected function canConnect();

    // runs one query against engine
    // should respect self::$commandLineArguments['query_timeout']
    // must respect self::$commandLineArguments['query_timeout']
    // TODO: control the timeout automatically
    abstract protected function testOnce($query):array;


    // runs after query to collect query stats
    abstract protected function afterQuery();

    // parses query result and returns it in the format that should be common across all engines
    abstract protected function parseResult($result);

    // sends a command to engine to drop its caches
    abstract protected function dropEngineCache();

    // modifies $query for the case when it's defined in simple form in queries file, i.e. common for all the engines
    protected abstract function prepareQuery($query);

    // creates instance of engine of different type (defined by the inheritant class) and subtype (defined in $type)
    // not the engine class itself is abstract, therefore should not be instantiated
    // it's just that the constructor can be common for all the inheritants, that's why it's implemented in the abstract class
    public function __construct($type)
    {
        $this->type = $type;
    }

    // $modifyMode=true means we deal with a log line which is already processed, we just need to modify the tabs after time and:
    // * insert tabs between date and message, not in the beginning of each line
    // * do not apply any colors

    public static function saveResultsFromPath($path): bool
    {
        if (is_file($path)) {
            $iterator = [$path];
        } else {
            if (is_dir($path)) {
                $dir_iterator = new RecursiveDirectoryIterator($path);
                $iterator = new RecursiveIteratorIterator($dir_iterator,
                    RecursiveIteratorIterator::SELF_FIRST);
            } else {
                return false;
            }
        }

        foreach ($iterator as $file) {
            if (is_file($file)) {
                if (in_array(basename($file), ['.gitkeep','.gitignore']) ) {
                    continue;
                }
                self::log("Saving from file $file", 1, 'yellow');
                $results = @unserialize(file_get_contents($file));
                if (!$results) {
                    self::die("ERROR: can't read from the file", 1);
                }
                if (self::saveToDB($results) === true
                    && self::$commandLineArguments['rm']
                ) {
                    unlink($file);
                    self::log("Removed $file", 2);
                }
            }
        }
        return true;
    }


    private static function mres($value)
    {
        $search = array("\\", "\x00", "\n", "\r", "'", '"', "\x1a");
        $replace = array("\\\\", "\\0", "\\n", "\\r", "\'", '\"', "\\Z");
        if (is_array($value)) {
            $value = json_encode($value);
        }
        return str_replace($search, $replace, $value);
    }

    private static function saveToDB($results): bool
    {
        self::log("Saving results for {$results['engine']}", 2);
        if (@$results['formatVersion'] != self::$formatVersion) {
            self::die("ERROR: can't save to db (unsupported format)", 3);
        }

        $stage = 'saveResults';
        if (isset($results['stage']) && $results['stage'] === 'init') {
            $stage = 'saveInit';
        }


        if ($stage === 'saveResults') {
            if (!empty($results['limited'])) {
                $results['engine'] .= '_1_core';
            }
            $queries = $results['queries'];
            unset($results['queries']);

            $query
                /** @lang manticore */
                = "create table if not exists results (" .
                "test_name string, " .
                "memory int, " .
                "test_info text, " .
                "test_time timestamp, " .
                "server_id string, " .
                "server_info string stored, " .
                "format_version int, " .
                "engine_name string engine='rowwise', " .
                "type string engine='rowwise', " .
                "info json, " .
                "avg float, " .
                "cv float, " .
                "avg_fastest float, " .
                "cv_avg_fastest float, " .
                "cold bigint, " .
                "fastest bigint, " .
                "slowest bigint, " .
                "times json, " .
                "times_count int, " .
                "original_query text indexed attribute, " .
                "modified_query text indexed attribute, " .
                "result string, " .
                "stats text stored, " .
                "checksum int, " .
                "warmup_time bigint, " .
                "query_timeout int, " .
                "error json, " .
                "retest bool" .
                ") min_word_len = '1' min_infix_len = '2' engine='columnar'";
        } else {
            $query
                /** @lang manticore */
                = "create table if not exists init_results (" .
                "test_name string, " .
                "init_time float, " .
                "format_version int, " .
                "engine_name string engine='rowwise', " .
                "type string engine='rowwise', " .
                "version string, " .
                "metrics json " .
                ") min_word_len = '1' min_infix_len = '2' engine='columnar'";
        }


        $curl = curl_init();

        $protocol = (self::$commandLineArguments['port'] == 443)
            ? 'https'
            : 'http';
        $host = self::$commandLineArguments['host'];
        $port = self::$commandLineArguments['port'];
        $userName = self::$commandLineArguments['username'];
        $password = self::$commandLineArguments['password'];

        curl_setopt($curl, CURLOPT_URL, "$protocol://$host:$port/sql?mode=raw");
        curl_setopt($curl, CURLOPT_USERPWD, "$userName:$password");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'query=' . urlencode($query));
        $curlResult = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            self::die("ERROR: can't create or verify table to save results: http code: $httpCode",
                3);
        }

        if ($stage === 'saveResults') {
            $fields = [
                'id',
                'test_name',
                'memory',
                'test_info',
                'test_time',
                'server_id',
                'server_info',
                'format_version',
                'engine_name',
                'type',
                'info',
                'avg',
                'cv',
                'avg_fastest',
                'cv_avg_fastest',
                'cold',
                'fastest',
                'slowest',
                'times',
                'times_count',
                'original_query',
                'modified_query',
                'result',
                'stats',
                'checksum',
                'warmup_time',
                'query_timeout',
                'error',
                'retest'
            ];

            foreach ($queries as $query) {
                // if we retest the same version the results should override those that are already saved
                if (!isset($query['retest'])) {
                    $query['retest'] = 0;
                }
                $hashBase = "{$results['testName']} /" .
                    " {$results['memory']} /" .
                    " {$results['engine']} /" .
                    " {$results['type']} /" .
                    " {$results['info']['version']} /" .
                    " {$query['originalQuery']} /" .
                    " " . (int) $query['retest'];

                $id = unpack('q', sha1($hashBase, true));
                $id = abs($id[1]);
                $values = [
                    $id,
                    "'" . self::mres($results['testName']) . "'",
                    $results['memory'],
                    "'" . self::mres($results['testInfo']) . "'",
                    $results['testTime'],
                    "'" . $results['serverId'] . "'",
                    "'" . self::mres(json_encode($results['serverInfo'],
                        JSON_PRETTY_PRINT)) . "'",
                    self::$formatVersion,
                    "'{$results['engine']}'",
                    "'{$results['type']}'",
                    "'" . self::mres(json_encode($results['info'])) . "'",
                    $query['avg'],
                    $query['cv'],
                    $query['avgFastest'],
                    $query['cvAvgFastest'],
                    $query['cold'],
                    $query['fastest'],
                    $query['slowest'],
                    "'" . json_encode($query['times']) . "'",
                    count($query['times']),
                    "'" . self::mres($query['originalQuery']) . "'",
                    "'" . self::mres($query['modifiedQuery']) . "'",
                    "'" . self::mres(json_encode($query['result'],
                        JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK)) . "'",
                    "'" . @self::mres($query['stats']) . "'",
                    $query['checksum'],
                    $query['warmupTime'],
                    0,
                    "'" . self::mres(json_encode(@$query['result']['error']))
                    . "'",
                    (int) $query['retest']
                ];

                if (is_array($fields)) {
                    $fields = implode(',', $fields);
                }

                $values = implode(',', $values);
                $query
                    /** @lang manticore */
                    = "replace into results ($fields) values ($values)";

                self::saveRow($query, $curl, $id, $hashBase);
            }
        } else {
            $fields = [
                'id',
                'test_name',
                'init_time',
                'format_version',
                'engine_name',
                'type',
                'version',
                'metrics'
            ];


            $hashBase = "{$results['test']} /" .
                " {$results['engine']} /" .
                " {$results['type']} /" .
                " {$results['version']} ";

            $id = unpack('q', sha1($hashBase, true));
            $id = abs($id[1]);
            $values = [
                $id,
                "'" . self::mres($results['test']) . "'",
                $results['elapsedTime'],
                self::$formatVersion,
                "'{$results['engine']}'",
                "'{$results['type']}'",
                "'" . $results['version'] . "'",
                "'" . self::mres($results['metrics']) . "'"
            ];

            $fields = implode(',', $fields);
            $values = implode(',', $values);
            $query
                /** @lang manticore */
                = "replace into init_results ($fields) values ($values)";

            self::saveRow($query, $curl, $id, $hashBase);
        }

        return true;
    }


    private static function saveRow(string $query, $curl, $id, $hashBase): void
    {
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'query=' . urlencode($query));
        $curlResult = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlResult = @json_decode($curlResult);
        if (is_array($curlResult)) {
            $curlResult = array_shift($curlResult);
        }
        $errorMessage
            = "ERROR: can't save results to db: http code: $httpCode";
        if (isset($curlResult->error) and $curlResult->error) {
            $errorMessage .= "; error: " . $curlResult->error;
        }
        if ($httpCode != 200 or (isset($curlResult->error)
                and $curlResult->error)
        ) {
            self::die($errorMessage, 3);
        } else {
            self::log("Saved $id (doc $hashBase)", 3);
        }
    }


    // runs test based on config for all engines
    public static function test($cwd)
    {
        chdir(dirname(__FILE__));

        $enginesInfo = [];

        self::prepareEnvironmentForTest(); // stop all that can be running

        self::log("Getting general server info", 1, 'cyan');

        $commands = [
            'serverId' => 'cat /etc/machine-id',
            'cpuInfo' => 'cat /proc/cpuinfo',
            'free' => 'free',
            'ps' => 'ps aux',
            'DMIInfo' => 'dmidecode',
            'df' => 'df -h',
            'lshw' => 'lshw',
            'hostname' => 'hostname',
            'git' => 'git describe --abbrev=40 --always --dirty=+'
        ];

        $serverInfo = ['argv' => implode(' ', $_SERVER['argv'])];
        foreach ($commands as $k => $v) {
            self::log("running \"$v\" to get $k", 2);
            $o = $r = null;
            exec($v, $o, $r);
            if (!$o or $r) {
                if (!isset(self::$commandLineArguments['skip_inaccuracy'])) {
                    self::die("ERROR: cannot get server info (\"$v\" failed)",
                        3);
                }
            }
            $serverInfo[$k] = implode("\n", $o);
        }
        $serverId
            = $serverInfo['serverId']; // we'll store serverId separately from ther other server info
        unset($serverInfo['serverId']);

        // let's first start all engines one by one with no constraint even if they were set just to figure out their info
        self::log("Getting info about engines", 1, 'cyan');
        foreach (self::$commandLineArguments['engines'] as $engine) {
            $engineOptions = self::parseEngineName($engine);
            /** @var engine $engine */
            $engine = new $engineOptions['engine']($engineOptions['type']);

            try {
                // no memory constraint, no CPU constraint, no IO waiting
                $engine->start(false, false, false);
            } catch (Exception $exception) {
                self::die($exception->getMessage(), 2);
            }

            $t = microtime(true);
            self::log("Getting info about {$engineOptions['engine']}"
                . ($engineOptions['type'] ? " (type {$engineOptions['type']})"
                    : ""), 2);
            do {
                self::log("Trying to connect", 3);
                ob_start();
                $t = microtime(true);
                while (true) {
                    $canConnect = $engine->canConnect();
                    if ($canConnect) {
                        break;
                    }
                    if (microtime(true) - $t
                        > self::$commandLineArguments['probe_timeout']
                    ) {
                        break;
                    }
                }
                self::log(ob_get_clean(), 3);
                if (!$canConnect) {
                    self::die("ERROR: couldn't connect to engine", 4);
                }

                ob_start();
                $info = $engine->getInfo();
                if (!$info) {
                    self::die("ERROR: can't get info", 3);
                }
                $info['typeAppendum'] = $engine->appendType($info);
                if (!$info['typeAppendum']) {
                    $info['typeAppendum'] = $info['version'];
                }
                $info['url'] = $engine->url();
                $info['description'] = $engine->description();
                self::log(ob_get_clean(), 3);
                if (is_array($info) and !empty($info)) {
                    $enginesInfo[$engineOptions['engine']][$engineOptions['type']]
                        = $info;
                    $file = __DIR__ . '/../tests/'
                        . self::$commandLineArguments['test']
                        . '/test_info_queries';
                    if (!file_exists($file)) {
                        self::die("ERROR: cannot get engine info, $file is not accessible",
                            3);
                    }
                    $json = @json_decode(file_get_contents($file), true);
                    if (!$json) {
                        self::die("ERROR: cannot get engine info, $file is not JSON",
                            3);
                    }
                    if (!isset($json['count']) or !isset($json['doc'])) {
                        self::die("ERROR: cannot get engine info, $file doesn't have elements \"count\" or \"doc\"",
                            3);
                    }

                    $engine
                        = new $engineOptions['engine']($engineOptions['type']);

                    $queries = ['count', 'doc'];
                    $outs = [];
                    foreach ($queries as $type) {
                        $query = $json[$type];
                        if (!is_array($query)) {
                            $preparedQuery = $engine->prepareQuery($query);
                        } else {
                            $preparedQuery = $query[$engineOptions['engine']] ??
                                $query['default'];
                        }
                        if (!$preparedQuery) {
                            self::die("ERROR: cannot get engine info, unknown query of type $type",
                                3);
                        }
                        $preparedQueryJson = json_encode($preparedQuery);
                        self::log("Sending informational query $preparedQueryJson to {$engineOptions['engine']}"
                            . ($engineOptions['type']
                                ? " (type {$engineOptions['type']})" : ""), 3,
                            'yellow');
                        $engine->beforeQuery();
                        $result = $engine->testOnce($preparedQuery);
                        if ($result['error']){
                            self::die("ERROR: ".$result['message'], 3);
                        }
                        $outs[$type] = $engine->parseResult($result['response']);
                    }
                    $count = false;
                    if (isset($outs['count']) and is_array($outs['count'])
                        and !empty($outs['count'])
                    ) {
                        $count
                            = @array_shift(@array_values(@array_shift($outs['count'])));
                    }
                    if (!$count) {
                        self::die("ERROR: cannot continue since the dataset is empty (COUNT query returned zero)",
                            3);
                    }
                    $enginesInfo[$engineOptions['engine']][$engineOptions['type']]['datasetCount']
                        = $count;
                    $doc = (object) array_shift($outs['doc']);
                    if (!$doc) {
                        self::die("ERROR: cannot continue since we couldn't fetch a sample document",
                            3);
                    }
                    $enginesInfo[$engineOptions['engine']][$engineOptions['type']]['datasetSampleDocument']
                        = $doc;
                    break;
                }
                sleep(5);
            } while (microtime(true) - $t
            < self::$commandLineArguments['info_timeout']);
            if (!is_array($info)) {
                self::die("ERROR: couldn't get info about $engine", 1);
            }
        }

        self::prepareEnvironmentForTest(); // stop all again after getting info about engines

        self::readQueries();
        if (!self::$queries) {
            self::die("ERROR: empty queries", 1);
        }

        $limited = !empty(self::$commandLineArguments['limited']);
        // let's test in all memory modes
        self::log('Starting testing', 1, 'cyan');
        foreach (self::$commandLineArguments['memory'] as $mem) {
            self::log("Memory: {$mem}m", 1);
            foreach (self::$commandLineArguments['engines'] as $engine) {
                $engineOptions = self::parseEngineName($engine);
                self::log("Engine: " . $engineOptions['engine'] . ", type: "
                    . $engineOptions['type'], 2);
                if (@$engineOptions['limited']) {
                    $limited = true;
                }
                if ($limited) {
                    self::log("CPU: limited (1 physical core)", 2);
                }
                $engine = new $engineOptions['engine']($engineOptions['type']);
                for ($attempt = 0; $attempt <= 1; $attempt++) {
                    $queryTimes = [];
                    foreach (self::$queries as $qn => $query) {
                        $queryStats = [];
                        $originalQuery = is_array($query) ? current($query)
                            : $query;
                        $preparedQuery = false;
                        self::log("Original query(" . ($qn + 1) . "/"
                            . count(self::$queries) . ", " . ($attempt
                                ? 'retest' : 'first attempt')
                            . "): $originalQuery", 3, 'yellow');
                        if (!is_array($query)) {
                            $preparedQuery = $engine->prepareQuery($query);
                        } else { // query is an array (json node, not just a single string common for all dbs)
                            if (isset($query[$engineOptions['engine'] . ':'
                                . $engineOptions['type']])
                            ) {
                                $preparedQuery = $query[$engineOptions['engine']
                                . ':' . $engineOptions['type']];
                            }
                            if (!$preparedQuery) {// exact match is not found, let's check if there's regex query keys
                                foreach ($query as $k => $v) {
                                    if ($k[0] == '/' and preg_match($k,
                                            $engineOptions['engine'] . ':'
                                            . $engineOptions['type'])
                                    ) {
                                        $preparedQuery = $v;
                                    }
                                }
                            }
                            if (!$preparedQuery) {
                                if (isset($query[$engineOptions['engine']])) {
                                    $preparedQuery
                                        = $query[$engineOptions['engine']];
                                }
                            }
                            if (!$preparedQuery) {
                                if (isset($query['default'])) {
                                    $preparedQuery = $query['default'];
                                }
                            }
                            if (!$preparedQuery) {
                                self::die("ERROR: no query found for "
                                    . $engineOptions['engine'] . ':'
                                    . $engineOptions['type'], 3);
                            }
                        }
                        $preparedQueryJson = json_encode($preparedQuery);
                        self::log("Modified query: $preparedQueryJson", 3,
                            'yellow');

                        // starting Engine
                        ob_start();
                        self::dropIOCache();

                        $startError = false;
                        $warmupTime = false;
                        try {
                            $warmupTime = $engine->start($mem, $limited);
                        } catch (Exception $exception) {
                            $startError = $exception->getMessage();
                            self::log($startError, 2, 'red');
                        }

                        if ($warmupTime === true or $warmupTime === false) {
                            $warmupTime = -1;
                        }
                        self::log(ob_get_clean(), 4, false, true, true);
                        self::log(ob_get_clean(), 4);

                        // making initial connection to make sure the 1st real query doesn't spend extra time on connection
                        ob_start();
                        $t = microtime(true);
                        if (!$startError) {
                            while (true) {
                                $canConnect = $engine->canConnect();
                                if ($canConnect) {
                                    break;
                                }
                                if (microtime(true) - $t
                                    > self::$commandLineArguments['probe_timeout']
                                ) {
                                    break;
                                }
                            }
                            self::log(ob_get_clean(), 4);
                            if (!$canConnect) {
                                $message = "Couldn't connect to engine after " .
                                    self::$commandLineArguments['probe_timeout']
                                    . " seconds. ";
                                $startError = $message;
                                self::log('ERROR: ' . $message, 4);
                            } else {
                                $failedIngestion
                                    = $engine->checkFailedIngestion();
                                if ($failedIngestion) {
                                    $startError = $failedIngestion;
                                    self::log('ERROR: ' . $failedIngestion, 4);
                                }
                            }
                        }

                        if ($startError) {
                            $normalizedResult = [
                                'error' => [
                                    'type' => self::UNEXPECTED_QUERY_ERROR,
                                    'message' => $startError
                                ]
                            ];
                            $queryTimes[] = [
                                'originalQuery' => $originalQuery,
                                'modifiedQuery' => $preparedQuery,
                                'times' => [1],
                                'result' => $normalizedResult,
                                'stats' => '',
                                'checksum' => self::checksum($normalizedResult),
                                'warmupTime' => $warmupTime
                            ];
                            $engine->stop();
                            continue;
                        }

                        self::log("Making queries", 4);
                        $times = [];
                        for (
                            $n = 0; $n < self::$commandLineArguments['times'];
                            $n++
                        ) {
                            if (!self::checkTempAndWait()) {
                                self::die("ERROR: can't check temperature. High risk of inaccuracy!",
                                    4);
                            }

                            $engine->beforeQuery();
                            /**
                             * Before we test we need to drop caches inside the engine,
                             * otherwise we'll test cache performance which in most cases is wrong
                             */
                            $engine->dropEngineCache();
                            $t = microtime(true);
                            $result = $engine->testOnce($preparedQuery);

                            $t2 = microtime(true) - $t;
                            if ($result['error']){

                                if (isset($result['type']) && $result['type'] === self::UNSUPPORTED_QUERY_ERROR){
                                    unset($result['error']);
                                    $result['message'] = 'This query is not supported by the current engine';
                                }else{
                                    $result = $engine->checkEngineStatus($result);
                                }

                                $s = "WARNING: query failed. Details: ";
                                $s .= json_encode($result);
                                $s .= ". It doesn't make sense to test this query further.";
                                self::log($s, 5);
                                $normalizedResult = ['error' => $result];
                                break;
                            }

                            $times[] = $t2;
                            self::log(floor((microtime(true) - $t) * 1000000)
                                . " us", 5, 'white');
                            $normalizedResult = $engine->parseResult($result['response']);
                            $queryStats = $engine->afterQuery();

                            $tmpTimes = $times;
                            sort($tmpTimes);
                            if (count($tmpTimes)
                                > self::$commandLineArguments['times'] / 3
                            ) {
                                $cv = self::coefficientOfVariation(array_slice($tmpTimes, 0,
                                    floor(0.8 * count($tmpTimes))));
                                if ($cv > 0 and $cv <= 2) {
                                    // if the coefficient of variation of fastest 80%
                                    // of response times <2% and there were
                                    // at least 1/3 of attempts made - that's enough

                                    self::log(($n + 1)
                                        . " queries is enough, the quality is sufficient",
                                        5, 'white');
                                    break;
                                }
                            }
                        }
                        $engine->stop();

                        $queryTimes[] = [
                            'originalQuery' => $originalQuery,
                            'modifiedQuery' => $preparedQuery,
                            'times' => $times,
                            'result' => $normalizedResult,
                            'stats' => $queryStats,
                            'checksum' => self::checksum($normalizedResult),
                            'warmupTime' => $warmupTime
                        ];
                    }
                    $engine->saveToDir($queryTimes, $mem, $engineOptions,
                        $enginesInfo[$engineOptions['engine']][$engineOptions['type']],
                        $serverId, $serverInfo, ($attempt == 1));
                }
            }
        }
    }

    private function checkEngineStatus($error)
    {
        $runningCheckCommand = "docker inspect ".get_class($this)."_engine --format='{{.State.Running}}'";
        $isRunning = exec($runningCheckCommand);
        if ($isRunning !== 'true'){
            $statusCodeCheckCommand = "docker inspect ".get_class($this)."_engine --format='{{json .State}}'";
            $state = exec($statusCodeCheckCommand);
            $state = json_decode($state,true);

            $error['message'] = "Engine finished with exit code ".$state['ExitCode'];

            if ($state['OOMKilled'] === true){
                $error['message'] .= " due to an Out of Memory (OOM) error";
            }

        }
        unset($error['error']);
        return $error;
    }
    // calculates result's checksum
    private static function checksum($normalizedResult)
    {
        if (!$normalizedResult) {
            return 0;
        }
        $csPayload = [];
        foreach ($normalizedResult as $v) {
            $csPayload = array_merge($csPayload, array_values($v));
        }
        return crc32(implode('_', $csPayload));
    }

    // saves test results
    protected function saveToDir(
        $queryTimes,
        $memory,
        $engineOptions,
        $info,
        $serverId,
        $serverInfo,
        $retest = false
    ) : void {
        self::log("Saving data for engine \"" . get_class($this) . "\""
            . ($retest ? ' (retest)' : ''), 1, 'cyan');

        $engine = get_class($this);
        if (!isset($info['version'])) {
            self::die("ERROR: version for $engine is not found, can't save results",
                2);
        }

        $limited = (!empty(self::$commandLineArguments['limited'])
            || $engineOptions['limited']);
        $fileName = self::$commandLineArguments['test']
            . "_{$engine}_{$this->type}_{$memory}"
            . ($limited ? '_limited' : '')
            . ($retest ? '_retest' : '');

        $final = [
            'testName' => self::$commandLineArguments['test'],
            'testTime' => self::$startTime,
            'formatVersion' => self::$formatVersion,
            'engine' => $engine,
            'type' => ltrim($this->type . ($info['typeAppendum'] ? ('_'
                    . $info['typeAppendum']) : ''), '_'),
            'memory' => $memory,
            'info' => $info,
            'queries' => [],
            'limited' => (int) $limited,
            'serverId' => $serverId,
            'serverInfo' => $serverInfo
        ];

        $final['testInfo'] = file_get_contents('../tests/'
            . self::$commandLineArguments['test'] . '/description');

        foreach ($queryTimes as $result) {
            $out = [];
            $times = $result['times'];
            $timesSorted = $times;
            sort($timesSorted);
            $out['avgFastest'] = (floor(0.8 * count($timesSorted)) != 0)
                ? ((int) @floor(array_sum(array_slice($timesSorted, 0,
                        floor(0.8 * count($timesSorted)))) / floor(0.8
                        * count($timesSorted)) * 1000000)) : -1;
            $out['cv'] = self::coefficientOfVariation($times);
            if (!$out['cv']) {
                $out['cv'] = -1;
            } // let's save -1 instead of 0 to make it less confusing
            $out['avg'] = (count($times) > 0) ? floor(array_sum($times)
                / count($times) * 1000000) : -1;
            $out['cvAvgFastest'] = self::coefficientOfVariation(array_slice($timesSorted, 0,
                floor(0.8 * count($timesSorted)))) ?? -1;
            if (!$out['cvAvgFastest']) {
                $out['cvAvgFastest'] = -1;
            } // let's save -1 instead of 0
            $out['cold'] = isset($times[0]) ? round($times[0] * 1000000) : -1;
            $out['fastest'] = isset($timesSorted[0]) ? round($timesSorted[0]
                * 1000000) : -1;
            $out['slowest'] = isset($timesSorted[count($timesSorted) - 1])
                ? round($timesSorted[count($timesSorted) - 1] * 1000000) : -1;
            foreach ($times as $k => $time) {
                $times[$k] = round($time * 1000000);
            }
            $out['times'] = $times;
            $out['originalQuery'] = $result['originalQuery'];
            $out['modifiedQuery'] = $result['modifiedQuery'];
            $out['result'] = $result['result'];
            $out['stats'] = $result['stats'];
            $out['checksum'] = $result['checksum'];
            $out['warmupTime'] = $result['warmupTime'];
            $out['retest'] = $retest;
            $final['queries'][] = $out;
        }

        $time = date('ymd_his', self::$startTime);
        @mkdir(self::$commandLineArguments['dir'] . "/" . $time, 0777, true);
        $fileName = self::$commandLineArguments['dir'] . "/" . $time . "/"
            . $fileName;
        if (!@file_put_contents($fileName, serialize($final))) {
            self::log("WARNING: couldn't save test result to file $fileName",
                2);
        } else {
            self::log("Saved to $fileName", 3);
        }
    }

    // parses engine name, e.g. manticoresearch:columnar_plain_ps into parses
    private static function parseEngineName($engine): array
    {
        $engine_type = explode(':', $engine);
        if (!$engine_type) {
            self::die("ERROR: $engine cannot be parsed", 2);
        }
        $out = [];
        $out['engine'] = $engine_type[0];
        $out['type'] = (isset($engine_type[1])) ? $engine_type[1] : '';
        $out['limited'] = strstr($out['type'], 'limited');
        return $out;
    }

    // starts one engine
    // returns warmup time in milliseconds
    //   or false in case of some issue
    //   or true in case of  $skipIOCheck = true
    protected function start(
        $memory = false,
        $limited = false,
        $skipIOCheck = false
    ): float {
        $suffix = $this->type ? "_" . $this->type
            : ""; // suffix defines some volumes in the docker-compose, e.g. ./tests/${test}/manticore/idx${suffix}:/var/lib/manticore
        if (!$memory) {
            $memory = 0;
        } // supposed to be in megabytes, let's set to 1 TB by default

        // "limited" can be set as --limited or as a "*limited*" in engine name
        if ($limited) {
            $limited = 'cpuset=0,1';
        } // only one core (perhaps virtual)

        $engine = get_class($this);

        self::log("Starting $engine", 1, 'cyan');
        $o = [];
        $exec = "test=" . self::$commandLineArguments['test']
            . " mem=$memory suffix=$suffix $limited docker-compose up -d $engine 2>&1";
        self::log($exec, 2);
        exec($exec, $o, $r);
        self::log(implode("\n", $o), 2, 'bright_black');
        if ($r) {
            $message = "ERROR: couldn't start $engine";
            throw new RuntimeException($message);
        }
        self::log("Waiting for $engine to come up", 2);
        $t = microtime(true);
        while ($this->checkHealth()) {
            sleep(1);
            if (microtime(true) - $t
                > self::$commandLineArguments['start_timeout']
            ) {
                $message = "ERROR: $engine starting time exceeded timeout ("
                    . self::$commandLineArguments['start_timeout']
                    . " seconds)";
                    throw new RuntimeException($message);
            }
        }
        self::log("$engine " . ($this->type ? "(type: {$this->type}) " : '')
            . "is up and running", 3);
        if ($skipIOCheck) {
            return 0.0;
        }
        $t = microtime(true);
        self::waitForNoIO();
        return round((microtime(true) - $t) * 1000);
    }

    private static function waitForNoIO(): void
    {
        if (isset(self::$commandLineArguments['skip_inaccuracy'])) {
            return;
        }
        self::log("Making sure there's no activity on disks", 2);
        $t = microtime(true);
        while (true) {
            $o = [];
            if (!exec('dstat --noupdate --nocolor -d 3 3|tail -1', $o)) {
                self::die("Dstat not installed", 1);
            }
            if (str_starts_with(trim($o[0]), '0')) {
                break;
            }
            if (microtime(true) - $t
                > self::$commandLineArguments['warmup_timeout']
            ) {
                $message = "ERROR: warmup timeout ("
                    . self::$commandLineArguments['warmup_timeout']
                    . " seconds) exceeded";
                throw new RuntimeException($message);
            }
        }
        self::log("disks are calm", 2);
    }

    // stops engine
    private function stop(): void
    {
        $engine = get_class($this);
        self::log("Stopping $engine " . ($this->type ? "(type {$this->type})"
                : ""), 1, 'cyan');
        $suffix = $this->type ? "_" . $this->type
            : ""; // suffix defines some volumes in the docker-compose, e.g. ./tests/${test}/manticore/idx${suffix}:/var/lib/manticore, it has to be set on stop too
        exec("test=" . self::$commandLineArguments['test']
            . " suffix=$suffix docker-compose rm -fsv $engine > /dev/null 2>&1");
        self::waitForNoIO();

        self::log("Attempting to kill $engine in case it's still running", 2);
        exec("test=" . self::$commandLineArguments['test']
            . " suffix=$suffix docker-compose kill $engine > /dev/null 2>&1");
    }

    // drops all global IO caches
    // some engines require to be stopped before that, otherwise it's not effective
    private static function dropIOCache(): void
    {
        if (isset(self::$commandLineArguments['skip_inaccuracy'])) {
            return;
        }
        system('echo 3 > /proc/sys/vm/drop_caches');
        system('sync');
    }

    // checks health of engine
    // returns exit code (0 in case of no problems)
    protected function checkHealth()
    {
        return $this->checkEngineHealth(get_class($this));
    }

    // reads queries from disk and prepares them for further processing
    // sets static $queries property
    private static function readQueries()
    {
        if (!file_exists(self::$commandLineArguments['queries'])) {
            self::die("ERROR: " . self::$commandLineArguments['queries']
                . " is not accessible", 1);
        }
        $queries = file_get_contents(self::$commandLineArguments['queries']);
        if (!@json_decode($queries)) {
            self::log("WARNING: couldn't decode the queries file, probably not a json. JSON error: "
                . json_last_error_msg(), 1);
            $queries = explode("\n", trim($queries));
        } else {
            $queries = json_decode($queries, true);
        }
        self::$queries = $queries;
    }

    private static function dieWithUsage()
    {
        self::log("To run a particular test with specified engines, memory constraints and number of attempts and save the results locally:
\t" . __FILE__ . "
\t--test=test_name
\t--engines={engine1:type,...,engineN}
\t--memory=1024,2048,...,1048576 - memory constraints to test with, MB
\t[--times=N] - max number of times to test each query, 100 by default
\t[--dir=path] - if path is omitted - save to directory 'results' in the same dir where this file is located
\t[--probe_timeout=N] - how long to wait for an initial connection, 60 seconds by default
\t[--start_timeout=N] - how long to wait for a db/engine to start, 120 seconds by default
\t[--warmup_timeout=N] - how long to wait for a db/engine to warmup after start, 300 seconds by default
\t[--query_timeout=N] - max time a query can run, 900 seconds by default
\t[--info_timeout=N] - how long to wait for getting info from a db/engine
\t[--limited] - emulate one physical CPU core
\t[--queries=/path/to/queries] - queries to test, ./tests/<test name>/test_queries by default
To save to db all results it finds by path
\t" . __FILE__ . "
\t--save=path/to/file/or/dir, all files in the dir recursively will be saved
\t--host=HOSTNAME
\t--port=PORT
\t--username=USERNAME
\t--password=PASSWORD
\t--rm - remove after successful saving to database
\t--skip_calm - avoid waiting until discs become calm
----------------------
Environment vairables:
\tAll the options can be specified as environment variables, but you can't use the same option as an environment variables and an command line argument at the same time.
", 1, 'white', true);
        exit(1);
        /*
        To dump from db all results or a particular one by id:
        \t".__FILE__."
        \t--dump {test id}
        */
    }

    public static function parseCommandLineArguments()
    {
        self::$commandLineArguments = self::getopt(
            [
                "test:",
                "memory:",
                "dir::",
                "engines:",
                "times::",
                "probe_timeout::",
                "mysql::",
                "start_timeout::",
                "warmup_timeout::",
                "limited::",
                "queries::",
                "query_timeout::",
                "info_timeout::",
                "rm::",
                "skip_inaccuracy::"
            ]);
        if (@self::$commandLineArguments['test']
            and @self::$commandLineArguments['engines']
        ) {
            self::$mode = 'test';

            self::$commandLineArguments['engines'] = explode(',',
                self::$commandLineArguments['engines']);

            if (!isset(self::$commandLineArguments['probe_timeout'])) {
                self::$commandLineArguments['probe_timeout'] = 60;
            }
            if (!isset(self::$commandLineArguments['query_timeout'])) {
                self::$commandLineArguments['query_timeout'] = 900;
            }
            if (!isset(self::$commandLineArguments['start_timeout'])) {
                self::$commandLineArguments['start_timeout'] = 120;
            }
            if (!isset(self::$commandLineArguments['warmup_timeout'])) {
                self::$commandLineArguments['warmup_timeout'] = 300;
            }
            if (!isset(self::$commandLineArguments['info_timeout'])) {
                self::$commandLineArguments['info_timeout'] = 60;
            }
            if (!isset(self::$commandLineArguments['queries'])) {
                self::$commandLineArguments['queries'] = '../tests/'
                    . self::$commandLineArguments['test'] . '/test_queries';
            }
            if (!isset(self::$commandLineArguments['dataset'])) {
                self::$commandLineArguments['dataset']
                    = self::$commandLineArguments['test'];
            }
            if (!isset(self::$commandLineArguments['times'])) {
                self::$commandLineArguments['times'] = 100;
            }

            if (!isset(self::$commandLineArguments['dir'])) {
                self::$commandLineArguments['dir'] = dirname(__FILE__)
                    . '/../results/';
            }
            if (self::$commandLineArguments['dir'][0] != "/") {
                self::$commandLineArguments['dir'] = self::$cwd . "/"
                    . self::$commandLineArguments['dir'];
            }
            $exists = file_exists(self::$commandLineArguments['dir']);
            if (!$exists and !@mkdir(self::$commandLineArguments['dir'], 0777,
                    true)
            ) {
                self::die("ERROR: --dir " . self::$commandLineArguments['dir']
                    . " doesn't exist or can't be created", 1, 'red', true);
            }
            if (!$exists) {
                rmdir(self::$commandLineArguments['dir']);
            } // if the dir didn't exist (meaning we've just created it) let's remove it since in this function we are just parsing command line arguments, not creating any dirs

            if (!isset(self::$commandLineArguments['mysql'])) {
                self::$commandLineArguments['mysql'] = false;
            } else {
                self::$commandLineArguments['mysql'] = true;
            }
            if (isset(self::$commandLineArguments['limited'])) {
                self::$commandLineArguments['limited'] = true;
            }

            if (isset(self::$commandLineArguments['memory'])) {
                self::$commandLineArguments['memory'] = explode(',',
                    self::$commandLineArguments['memory']);
            } else {
                self::die("ERROR: --memory should be specified", 1);
            }
            return true;
        }
        self::$commandLineArguments = self::getopt([
            "save:", "host:", "port:", "username:", "password:", "rm::"
        ]);
        if (@self::$commandLineArguments['save']) {
            self::$mode = 'save';

            if (self::$commandLineArguments['save'][0] != '/') {
                self::$commandLineArguments['save'] = self::$cwd . "/"
                    . self::$commandLineArguments['save'];
            }

            if (!file_exists(self::$commandLineArguments['save'])) {
                self::die("ERROR: path " . self::$commandLineArguments['save']
                    . " not found", 1, 'red', true);
            } else {
                self::$commandLineArguments['save']
                    = realpath(self::$commandLineArguments['save']);
            }
            if (isset(self::$commandLineArguments['rm'])) {
                self::$commandLineArguments['rm'] = true;
            } else {
                self::$commandLineArguments['rm'] = false;
            }
        }
        if (isset(self::$commandLineArguments['host'])
            && isset(self::$commandLineArguments['port'])
            && isset(self::$commandLineArguments['username'])
            && isset(self::$commandLineArguments['password'])
        ) {
            return true;
        }
        self::dieWithUsage();
    }

    // to be run after parsing command line arguments
    // checks that it's ok to run the test
    public static function sanitize(): void
    {
        if (self::$mode == 'test') {
            $file = dirname(__FILE__) . '/../tests/'
                . self::$commandLineArguments['test'] . '/description';
            if (!file_exists($file)) {
                self::die("ERROR: Test description is not found in $file", 1);
            }
        }
    }

    // initializes some global things
    public static function init($cwd): void
    {
        self::$startTime = time();
        self::$cwd = $cwd;
        if (!defined('MYSQL_OPT_READ_TIMEOUT')) {
            define('MYSQL_OPT_READ_TIMEOUT', 30);
        }

        if (!defined('MYSQL_OPT_WRITE_TIMEOUT')) {
            define('MYSQL_OPT_WRITE_TIMEOUT', 30);
        }

        ini_set('mysql.connect_timeout', '60');
    }

    // function intended to prepare the environment for proper testing: stop all docker instances, clear global caches etc.
    public static function prepareEnvironmentForTest(): void
    {
        self::log("Preparing environment for test", 1, 'cyan');
        system("test=" . self::$commandLineArguments['test']
            . " docker-compose down > /dev/null 2>&1");
        system("test=" . self::$commandLineArguments['test']
            . " docker-compose rm > /dev/null 2>&1");
        system("docker stop $(docker ps -aq) > /dev/null 2>&1");
        system("docker ps -a|grep _engine|awk '{print $1}'|xargs docker rm > /dev/null 2>&1");
    }

    static private function checkTempAndWait(): bool
    {
        if (isset(self::$commandLineArguments['skip_inaccuracy'])) {
            return true;
        }

        $cool = false;
        do {
            $o = [];
            $r = null;
            exec('sensors', $o, $r);
            if ($r) {
                return false;
            }
            // It's okay because sometimes we can have no pci adapter
            // We matching kind of this:
            // Adapter: PCI adapter
            //     Tctl:         +34.1°C
            //     Tdie:         +34.1°C
            //     Tccd1:        +32.2°C
            //     Tccd2:        +33.0°C

            if (!preg_match_all('/Tctl:\s+\+([0-9\.]+)°C/i', implode("\n", $o),
                $matches)
            ) {
                return true;
            }
            $max = max($matches[1]);
            if ($max > 80) {
                if (!$cool) {
                    self::log("WARNING: CPU throttling threat (detected temperature {$max}°C). Waiting until the CPU gets cooler:",
                        5);
                }
                $cool = true;
            }
            if ($max < 60) {
                $cool = false;
            }
            if ($cool) {
                self::log("🧯", 5);
                sleep(1);
            }
        } while ($cool);
        return true;
    }

    // function calculates coefficient of variation
    private static function coefficientOfVariation($ar): ?float
    {
        $c = count($ar);
        if (!$c) {
            return null;
        }
        $variance = 0.0;
        $average = array_sum($ar) / $c;
        foreach ($ar as $i) {
            $variance += pow(($i - $average), 2);
        }
        return round(sqrt($variance / $c) / $average * 100, 2);
    }

    protected function parseCurlError(int $httpCode, int $curlErrorCode, $curlError): bool|array
    {
        if ($httpCode != 200 or $curlErrorCode != 0 or $curlError != '') {
            $out = [
                'error' => true,
                'type' => self::UNEXPECTED_QUERY_ERROR
            ];
            if ($curlErrorCode == 28
                || preg_match('/timeout|timed out/', $curlError)
            ) {
                $out['type'] = self::TIMEOUT_QUERY_ERROR;
                $out['message'] = 'Operation timed out after ' .
                self::$commandLineArguments['query_timeout'] .
                ' seconds with 0 bytes received';
            }
            return $out;
        }
        return false;
    }

    protected function parseMysqlError($mysqlErrno, $timeoutErrorCode): bool|array
    {
        if ($mysqlErrno) {
            $out = [
                'error' => true,
                'type' => self::UNEXPECTED_QUERY_ERROR
            ];
            if ($mysqlErrno == $timeoutErrorCode) {
                $out['type'] = self::TIMEOUT_QUERY_ERROR;
                $out['message'] = 'Operation timed out after ' .
                    self::$commandLineArguments['query_timeout'] .
                    ' seconds with 0 bytes received';
            }
            return $out;
        }
        return false;
    }

    protected function checkFailedIngestion(): bool|string
    {
        $info = $this->getInfo();
        $fileName = self::$cwd . DIRECTORY_SEPARATOR .
            $this->getFailedIngestionPath() .
            self::$commandLineArguments['test'] . "_" .
            get_class($this) . "_" .
            $info['version'];

        if (file_exists($fileName)) {
            return file_get_contents($fileName);
        }
        return false;
    }
}