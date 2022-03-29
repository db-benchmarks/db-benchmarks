<?php

class clickhouse extends engine {

    private $port = 8123;
    private $curl = null; // curl connection
    
    // attempts to fetch info about engine and return it
    protected function getInfo() {
        $ret = [];
        $version = @file_get_contents("http://localhost:{$this->port}/?query=".urlencode('select version()'));
        if ($version) $ret['version'] = trim($version);
        return $ret;
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
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    protected function testOnce($query) {
        curl_setopt($this->curl, CURLOPT_URL, "http://localhost:{$this->port}/?query=" . urlencode($query));
        $curlResult = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        if ($httpCode != 200 && $httpCode != 0) {
            return ['httpCode' => $httpCode, 'curlError' => curl_error($this->curl)];
        }
        return $curlResult;
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult) {
        $res = [];

        if ($curlResult and $curlResult = @json_decode($curlResult) and isset($curlResult->data)) {
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
