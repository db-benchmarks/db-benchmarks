<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class clickhouse extends engine {

    private $port = 8123;
    private $curl = null; // curl connection


    public function __construct($type)
    {
        parent::__construct($type);
        ini_set('default_socket_timeout', self::$commandLineArguments['query_timeout']);
    }

    protected function url(): string
    {
        return "https://clickhouse.com/";
    }

    protected function description() {
        return "ClickHouse® is an open-source column-oriented database management system that allows generating analytical data reports in real-time";
    }
    
    // attempts to fetch info about engine and return it
    public function getInfo(): array
    {
        $ret = [];
        $version = @file_get_contents("http://localhost:{$this->port}/?query=".urlencode('select version()'));
        if ($version) {
            $ret['version'] = trim($version);
        }
        return $ret;
    }

    protected function appendType($info) {
        return "";
    }

    protected function canConnect() {
        $o = @file_get_contents("http://localhost:{$this->port}/ping");
        if ($o == "Ok.\n") return true;
        return false;
    }

    protected function prepareQuery($query) {
        $query = preg_replace('/(\W)year\(/i', '$1toYear(', $query);
        $query = preg_replace('/(\W)hour\(/i', '$1toHour(', $query);
        $query .= " FORMAT JSON";
        return $query;
    }

    protected function beforeQuery() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, ["content-type: application/json"]);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, ["X-ClickHouse-Format: JSON"]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query):array {
        curl_setopt($this->curl, CURLOPT_URL, "http://localhost:{$this->port}/?query=" . urlencode($query));
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

    // To collect query stats after the query
    protected function afterQuery() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult) {
        $res = [];
        if ($curlResult and is_string($curlResult) and $curlResult = @json_decode($curlResult) and isset($curlResult->data)) {
            foreach ($curlResult->data as $hit) {
                $ar = [];
                foreach ($hit as $k=>$v) {
                    if ($k == 'id') continue; // removing id from clickhouse's output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if (is_float($v)) $v = round($v, 4); // this is a workaround against different floating point calculations in different engines
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
        $curl = curl_init();
        foreach (['SYSTEM DROP MARK CACHE', 'SYSTEM DROP UNCOMPRESSED CACHE', 'SYSTEM DROP COMPILED EXPRESSION CACHE'] as $q) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/");
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Length: ' . strlen($q)));
            curl_setopt($curl, CURLOPT_POSTFIELDS, $q);
            curl_exec($curl);
            curl_close($curl);
        }
    }
}
