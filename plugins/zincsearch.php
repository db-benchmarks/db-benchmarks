<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class zincsearch extends engine {

    private $port = 4080;
    private string $auth = 'admin:dbbenchmarks';
    private $curl = null; // curl connection
    private mixed $getCountFromRequest = false;

    protected function url() {
        return "https://zincsearch-docs.zinc.dev/";
    }

    protected function description() {
        return "Zinc is a search engine for doing full text search on documents. It is open source and built in Go. Instead of building the indexing engine from scratch, Zinc is built on top of bluge, an excellent indexing library.";
    }

    private function getUrl()
    {
        return "http://$this->auth@localhost:{$this->port}";
    }
    // attempts to fetch info about engine and return it
    protected function getInfo() {
        $ret = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->getUrl()."/version");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) {
            $ret['version'] = $o->version;
        }
        return $ret;
    }

    protected function appendType($info) {
        return "";
    }

    protected function canConnect() {
        $curl = curl_init();
        $url = $this->getUrl();
        curl_setopt($curl, CURLOPT_URL, "$url/healthz");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) {
            return !empty($o) && $o->status === "ok";
        }

        return false;
    }

    protected function prepareQuery($query) {
        return $query;
    }

    protected function beforeQuery() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
            'Content-type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query) {
        if (is_string($query)) {
            return ['timeout' => true];
        }
        assert(is_array($query));
        $this->getCountFromRequest = $query['getCountFromRequest'];
        return $this->sendRequest($query['payload'], $query['esCompatible'] ?? false);
    }


    protected function sendRequest($payload, $esCompatible): string {


            $query = $this->getUrl(). ($esCompatible ? "/es/" :"/api/").
                self::$commandLineArguments['test']."/_search";


        if (!empty($payload)) {
            $payload = json_encode($payload);
            $payload = str_replace('[]', '{}', $payload);
            curl_setopt($this->curl, CURLOPT_POST, 1);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($this->curl, CURLOPT_URL, $query);
        $curlResult = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $curlErrorCode = curl_errno($this->curl);
        $curlError = curl_error($this->curl);
        if ($httpCode != 200 or $curlErrorCode != 0 or $curlError != '') {
            $out = ['httpCode' => $httpCode, 'curlError' => $curlError];
            if ($curlErrorCode == 28 or preg_match('/timeout|timed out/', $curlError)) $out['timeout'] = true;
            return json_encode($out);
        }
        return $curlResult;
    }

    // To collect query stats after the query
    protected function afterQuery() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult) {

        $curlResult = json_decode($curlResult, true);
        return match (true) {
            $this->getCountFromRequest && isset($curlResult['hits']['total']['value']) => [
                ['count(*)' => $curlResult['hits']['total']['value']],
            ],
            !empty($curlResult['hits']['hits']) => self::filterResults($curlResult['hits']['hits']),
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $results
     * @return array<string,mixed>
     */
    protected static function filterResults(array $results): array {
        $filtered = [];
        foreach ($results as $row) {
            $ar = [];
            foreach ($row['_source'] as $k => $v) {
                if (in_array($k, ['id', '@timestamp'])) {
                    continue;
                } // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266

                if (is_numeric($v) && strpos($v, '.')) {
                    $v = round($v, 4);
                } // this is a workaround against different floating point calculations in different engines

                $ar[$k] = isset($v) ? $v : '';
            }
            ksort($ar);
            $filtered[] = $ar;
        }
        return $filtered;
    }

    // sends a command to engine to drop its caches
    protected function dropEngineCache() {
        // ? Do nothing due no information available
    }
}
