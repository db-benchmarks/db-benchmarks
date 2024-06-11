<?php

/* Copyright (C) 2024 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class quickwit extends engine {

    private $port = 7280;
    private $curl = null; // curl connection
    protected function url() {
        return "https://quickwit.io/";
    }

    protected function description() {
        return "Sub-second search & analytics engine on cloud storage";
    }

    // attempts to fetch info about engine and return it
    protected function getInfo() {
        $ret = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/api/v1/version");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) {
            $ret['version'] = $o->build->version;
        }

        return $ret;
    }

    protected function appendType($info) {
        return "";
    }

    protected function canConnect() {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "http://localhost:{$this->port}/health/livez");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        return ($o and $o === 'true');
    }

    // ? Hmm, what should it do
    protected function prepareQuery($query) {
        return $query;
    }

    protected function beforeQuery() {
        sleep(1);
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
            'Content-type: application/json',
            'Accept: application/json',
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
        return $this->sendRequest($query[0], $query[1]);
    }

    // To collect query stats after the query
    protected function afterQuery() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult) {

        $curlResult = json_decode($curlResult, true);
        return match (true) {

            isset($curlResult['aggregations']['id_count']['value']) => [
                ['count(*)' => (int)$curlResult['aggregations']['id_count']['value']],
            ],

            isset($curlResult['hits']) => self::filterResults($curlResult['hits']),
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
            foreach ($row as $k => $v) {
                if ($k === 'id') {
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

    protected function sendRequest(string $path, $payload): string {
        $query = "http://localhost:{$this->port}{$path}";
        curl_setopt($this->curl, CURLOPT_POST, !!$payload);
        if ($payload) {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($payload));
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
}
