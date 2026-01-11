<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class manticoresearch extends engine {

    private $mysqlPort;
    private $HTTPPort;
    private $mysql = null; // mysql connection
    private $curl = null; // curl connection
    private $reportedVersion = null;

    public function __construct($type) {
        parent::__construct($type);
        $this->mysqlPort = getenv('MANTICORE_MYSQL_PORT') ?: 9306;
        $this->HTTPPort = getenv('MANTICORE_HTTP_PORT') ?: 9308;
    }

    protected function url() {
        return "https://manticoresearch.com/";
    }

    protected function description() {
        return "Manticore Search is a multi-storage database designed specifically for search, including full-text search";
    }
    
    // attempts to fetch info about engine and return it
    public function getInfo() {
        $m = new mysqli();
        mysqli_report(MYSQLI_REPORT_OFF);
        @$m->real_connect('127.0.0.1', '', '', '', $this->mysqlPort);
        if ($m->connect_error) return false;
        $res = $m->query("show status like '%version%'");
        if (!$res) {
            return false;
        }
        $row = $res->fetch_row();
        if (preg_match('/(\d+\.\d+\.\d+) (\w+)@(\d+) .*?\(columnar ([0-9.\-]+) (.*?)@(.*?)\)/', $row[1], $match)) {
            $version = $match[1];
            $commit = substr($match[2], 0, 5)."_".substr($match[5], 0, 5);
            $date = max($match[3], $match[6]);
        } else return false;
        $date = date_parse_from_format('ymd', $date);
        $time = mktime($date['hour'], $date['minute'], $date['second'], $date['month'], $date['day'], $date['year']);

        return ['version' => $version, 'manticoreVersion' => $version, 'manticoreCommit' => $commit, 'manticoreCommitTime' => $time];
    }

    protected function appendType($info) {
        return "";
//        return "_".date('Ymd', $info['manticoreCommitTime'])."_".$info['manticoreCommit'];
    }

    protected function prepareQuery($query) {
        return $query; // no modifications required
    }

    // returns true when it's possible to connect to engine and it can run a simple query
    protected function canConnect() {
        if (@self::$commandLineArguments['http']) return $this->canConnectHTTP();
        else return $this->canConnectMysql();
    }

    private function canConnectMysql() {
        $m = new mysqli();
        mysqli_report(MYSQLI_REPORT_OFF); // Set MySQLi to throw exceptions

        @$m->real_connect('127.0.0.1', '', '', '', $this->mysqlPort);
        if (!@$m->connect_error) {
            $res = @$m->query("show status");
            if ($res) return true;
        }
        return false;
    }

    private function canConnectHTTP() {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "http://localhost:".$this->HTTPPort."/sql?mode=raw&query=".urlencode("show status"));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        $curlResult = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode == 200) return true; 
        return false;
    }

    protected function beforeQuery() {
        if (@self::$commandLineArguments['http']) $this->beforeHTTP();
        else $this->beforeMysql();
    }

    private function beforeMysql() {
        $this->mysql = new mysqli();
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->mysql->options(MYSQL_OPT_READ_TIMEOUT, self::$commandLineArguments['query_timeout']);
        ini_set('mysqlnd.net_read_timeout', self::$commandLineArguments['query_timeout']);
        @$this->mysql->real_connect('127.0.0.1', '', '', '', $this->mysqlPort);
    }

    private function beforeHTTP() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_URL, "http://localhost:".$this->HTTPPort."/sql");
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query):array {
        if (@self::$commandLineArguments['http']) return $this->testHTTP($query);
        else return $this->testMysql($query);
    }

    private function testMysql($query): array
    {
        $res = $this->mysql->query($query);

        $errorResult = $this->parseMysqlError($this->mysql->errno, 2006);
        if ($errorResult){
            return $errorResult;
        }
        return ['error' => false, 'response' => $res];
    }

    private function testHTTP($query): array
    {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, 'query=' . urlencode($query));
        $curlResult = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $curlErrorCode = curl_errno($this->curl);
        $curlError = curl_error($this->curl);

        $errorResult = $this->parseCurlError($httpCode, $curlErrorCode, $curlError);
        if ($errorResult){
            return $errorResult;
        }
        return ['error' => false, 'response' => $curlResult];
    }


    // To run SHOW META and collect other stats after a query is made
    protected function afterQuery() {
        if (@self::$commandLineArguments['http']) return $this->afterHTTP();
        else return $this->afterMysql();
    }

    // Runs SHOW META
    private function afterMysql() {
        $res = $this->mysql->query('SHOW META');
        $ar = [];
        if ($res and $res->num_rows > 0) while ($row = $res->fetch_row()) $ar[] = '"'.$row[0].'": "'.$row[1].'"';
        return "{\n".implode(",\n", $ar)."\n}";
    }

    // Returns empty array, because in HTTP it's problematic to run SHOW META after a query
    private function afterHTTP() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($result) {
        if (@self::$commandLineArguments['http']) return $this->parseHTTPResult($result);
        else return $this->parseMysqlResult($result);
    }

    private function parseMysqlResult($result) {
        $res = [];
        if ($result and $result->num_rows > 0) {
            while ($hit = $result->fetch_assoc()) {
                $ar = [];
                foreach ($hit as $k => $v) {
                    // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if ($k == 'id') {
                        continue;
                    }

                    // Detecting type, cause Manticore can send you float like 76.00000 as string, and we should convert it properly
                    if (is_numeric($v)) {
                        if (strpos($v, '.')) {
                            $v = explode('.', $v);
                            if ((int) $v[1] === 0) {
                                $v = (int) $v[0];
                            } else {
                                $v = round((float) $v[0].'.'.$v[1], 4);
                            }
                        } else {
                            $v = (int) $v;
                        }
                    }
                    $ar[$k] = $v;
                }
                ksort($ar);
                $res[] = $ar;
            }
        }
        return $res;
    }

    private function parseHTTPResult($curlResult) {
        $res = [];
        if ($curlResult and $curlResult = @json_decode($curlResult) and isset($curlResult->hits->hits)) {
            foreach ($curlResult->hits->hits as $hit) {
//              $ar = ['id' => $hit->_id];
                $ar = [];
                foreach ($hit->_source as $k=>$v) {
                    if ($k == 'id') continue; // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if (is_float($v)) $v = round($v, 4); // this is a workaround against different floating point calculations in different engines
                    if (is_array($v)) $v = implode(',',$v);
                    $ar[$k] = $v;
                }
                ksort($ar);
                $res[] = $ar;
            }
        }
        return $res;
    }

    // sends a command to engine to drop its caches
    protected function dropEngineCache() {
        if (!$this->shouldDropCache()) {
            return;
        }

        if (@self::$commandLineArguments['http']) {
            if ($this->curl) {
                curl_setopt($this->curl, CURLOPT_POSTFIELDS,
                    'query=' . urlencode('DROP CACHE'));
                curl_exec($this->curl);
            }
            return;
        }

        if ($this->mysql) {
            $this->mysql->query('DROP CACHE');
        }
    }

    private function shouldDropCache(): bool
    {
        $version = $this->getReportedVersion();
        if (!$version) {
            return false;
        }
        return version_compare($version, '17.0.0', '>=');
    }

    private function getReportedVersion(): ?string
    {
        if ($this->reportedVersion !== null) {
            return $this->reportedVersion;
        }
        $info = $this->getInfo();
        if (!is_array($info) || empty($info['version'])) {
            $this->reportedVersion = null;
            return null;
        }
        $this->reportedVersion = $info['version'];
        return $this->reportedVersion;
    }
}
