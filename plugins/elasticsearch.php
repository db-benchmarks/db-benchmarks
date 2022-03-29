<?php

class elasticsearch extends engine {

    private $port = 9200;
    private $curl = null; // curl connection
    
    // attempts to fetch info about engine and return it
    protected function getInfo() {
        $ret = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/_cluster/health?level=indices");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) $ret['clusterInfo'] = $o;

        curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) $ret['instanceInfo'] = $o;

        if (isset($ret['instanceInfo']->version->number)) $ret['version'] = $ret['instanceInfo']->version->number;

        return $ret;
    }

    protected function canConnect() {
        $j = @json_decode(file_get_contents("http://localhost:{$this->port}/_cluster/health"));
        if (@$j->status == 'green' or @$j->status == 'yellow') return true;
        return false;
    }

    protected function prepareQuery($query) {
        return preg_replace('/match\((.*?)\)/i', "query($1)", $query);
    }

    protected function beforeQuery() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_URL, "http://localhost:{$this->port}/_sql?format=json&pretty");
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, ["content-type: application/json"]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    protected function testOnce($query) {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, '{"query": "' . $query . '", "request_timeout": "'.self::$commandLineArguments['query_timeout'].'s", "page_timeout": "'.self::$commandLineArguments['query_timeout'].'s"}');
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
        if ($curlResult and $curlResult = @json_decode($curlResult) and isset($curlResult->columns) and isset($curlResult->rows)) {
            $columns = [];
            foreach ($curlResult->columns as $k=>$v) $columns[$k] = $v->name;
            foreach ($curlResult->rows as $hit) {
                $ar = [];
                foreach ($hit as $k=>$v) {
                    if ($columns[$k] == 'tags') continue; // "tags" is a special field coming from logstash, we don't need to account it
                    if (is_float($v)) $v = round($v, 4); // this is a workaround against different floating point calculations in different engines
                    $ar[$columns[$k]] = $v;
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
        curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/_cache/clear?request=true&query=true&fielddata=true");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, array());
        curl_exec($curl);       
    }
}
