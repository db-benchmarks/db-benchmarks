<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class meilisearch extends engine {

    private $port = 7700;
    private $curl = null; // curl connection

    protected function url() {
        return "https://www.meilisearch.com";
    }

    protected function description() {
        return "Meilisearch is a flexible and powerful user-focused search engine that can be added to any website or application.";
    }

    // attempts to fetch info about engine and return it
    public function getInfo() {
        $ret = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,
            "http://localhost:{$this->port}/version");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) {
            $ret['version'] = $o->pkgVersion;
            $ret['commitSha'] = $o->commitSha;
            $ret['commitDate'] = $o->commitDate;
        }

        return $ret;
    }

    protected function appendType($info) {
        return "";
    }

    protected function canConnect() {
        $j = @json_decode(file_get_contents("http://localhost:{$this->port}/health"));
        return @$j->status === 'available';
    }

    // ? Hmm, what should it do
    protected function prepareQuery($query) {
        return $query;
    }

    protected function beforeQuery() {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
            'Content-type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_TIMEOUT,
            self::$commandLineArguments['query_timeout']);
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query): array
    {
        if (is_string($query)) {
            return [
                'error' => true,
                'type' => self::UNSUPPORTED_QUERY_ERROR
            ];
        }

        return $this->sendRequest(...$query);
    }

    // To collect query stats after the query
    protected function afterQuery() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult) {
        $curlResult = json_decode($curlResult, true);
        return match (true) {
            isset($curlResult['type'])
            && $curlResult['type'] === 'invalid_request' => [
                ['error' => $curlResult['message'], 'result' => $curlResult],
            ],
            isset($curlResult['numberOfDocuments']) => [
                ['count(*)' => $curlResult['numberOfDocuments']],
            ],
            isset($curlResult['hits'])
            && $curlResult['limit']
            > 0 => static::filterResults($curlResult['hits']),
            isset($curlResult['estimatedTotalHits'])
            && $curlResult['limit'] === 0 => [
                ['count(*)' => $curlResult['estimatedTotalHits']],
            ],
            isset($curlResult['type'])
            && $curlResult['type'] === 'invalid_request' => [
                ['error' => 'invalid query'],
            ],
            isset($curlResult['results'])
            && $curlResult['limit']
            > 0 => static::filterResults($curlResult['results']),
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $results
     *
     * @return array<string,mixed>
     */
    protected static function filterResults(array $results): array
    {
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

    protected function sendRequest(string $path, array $payload = []): array
    {
        curl_setopt($this->curl, CURLOPT_URL,
            "http://localhost:{$this->port}{$path}");
        curl_setopt($this->curl, CURLOPT_POST, !!$payload);

        if ($payload) {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $curlResult = curl_exec($this->curl);
        $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $curlErrorCode = curl_errno($this->curl);
        $curlError = curl_error($this->curl);

        $errorResult = $this->parseCurlError($httpCode, $curlErrorCode,
            $curlError);
        if ($errorResult) {
            return $errorResult;
        }
        return ['error' => false, 'response' => $curlResult];
    }
}
