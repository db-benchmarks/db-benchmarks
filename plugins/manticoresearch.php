<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class manticoresearch extends engine {

    private $mysqlPort = 9306;
    private $HTTPPort = 9308;
    private $mysql = null; // mysql connection
    private $curl = null; // curl connection

    protected function url() {
        return "https://manticoresearch.com/";
    }

    protected function description() {
        return "Manticore Search is a multi-storage database designed specifically for search, including full-text search";
    }
    
    // attempts to fetch info about engine and return it
    protected function getInfo() {
        $m = new mysqli();
        @$m->real_connect('127.0.0.1', '', '', '', $this->mysqlPort);
        if ($m->connect_error) return false;
        $res = $m->query("show status like '%version%'");
        if (!$res) {
            return false;
        }
        $row = $res->fetch_row();
        if (preg_match('/ (\w+)@(\d+) .*?\(columnar ([0-9.\-]+) (.*?)@(.*?)\)/', $row[1], $match)) {
            $version = $match[3];
            $commit = substr($match[1], 0, 5)."_".substr($match[4], 0, 5);
            $date = max($match[2], $match[5]);
        } else if (!preg_match('/^(\d\.\d\.\d+) (.*?)@(.*?) /', $row[1], $match)) {
            return false;
        } else {
            $date = $match[3];
            $version = $match[1];
            $commit = $match[2];
        }
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
        if (@self::$commandLineArguments['mysql']) return $this->canConnectMysql();
        else return $this->canConnectHTTP();
    }

    private function canConnectMysql() {
        $m = new mysqli();
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
        if (@self::$commandLineArguments['mysql']) return $this->beforeMysql();
        else return $this->beforeHTTP();
    }

    private function beforeMysql() {
        $this->mysql = new mysqli();
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
    protected function testOnce($query) {
        if (@self::$commandLineArguments['mysql']) return $this->testMysql($query);
        else return $this->testHTTP($query);
    }

    private function testMysql($query) {
        $res = $this->mysql->query($query);
        if ($this->mysql->errno) {
            $out = ['mysqlError' => $this->mysql->error, 'mysqlErrorCode' => $this->mysql->errno];
            if ($this->mysql->errno == 2006) $out['timeout'] = true;
            return $out;
        }
        return $res;
    }

    private function testHTTP($query) {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, 'query=' . urlencode($query));
        $curlResult = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $curlErrorCode = curl_errno($this->curl);
        $curlError = curl_error($this->curl);
        if ($httpCode != 200 or $curlErrorCode != 0 or $curlError != '') {
            $out = ['httpCode' => $httpCode, 'curlError' => $curlError];
            if ($curlErrorCode == 28 or preg_match('/timeout|timed out/', $curlError)) $out['timeout'] = true;
            return $out;
        }
        return $curlResult;

    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($result) {
        if (@self::$commandLineArguments['mysql']) return $this->parseMysqlResult($result);
        else return $this->parseHTTPResult($result);
    }

    private function parseMysqlResult($result) {
        $res = [];
        if ($result and $result->num_rows > 0) {
            while ($hit = $result->fetch_assoc()) {
                $ar = [];
                foreach ($hit as $k=>$v) {
                    if ($k == 'id') continue; // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if (is_float($v)) $v = round($v, 4); // this is a workaround against different floating point calculations in different engines
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

    }
}
