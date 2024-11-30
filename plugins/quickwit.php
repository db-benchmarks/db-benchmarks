<?php

/* Copyright (C) 2024 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class quickwit extends engine
{
    use EsCompatible;

    private $port = 7280;
    private CurlHandle|false $curl = false; // curl connection
    private ?array $retrieveFields = null;

    protected function url(): string
    {
        return "https://quickwit.io/";
    }

    protected function description(): string
    {
        return "Sub-second search & analytics engine on cloud storage";
    }

    // attempts to fetch info about engine and return it
    public function getInfo(): array
    {
        $ret = [];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,
            "http://localhost:{$this->port}/api/v1/version");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $o = curl_exec($curl);
        if ($o and $o = @json_decode($o)) {
            $ret['version'] = $o->build->version;
        }

        return $ret;
    }

    protected function appendType($info): string
    {
        return "";
    }

    protected function canConnect(): bool
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,
            "http://localhost:{$this->port}/health/livez");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $o = curl_exec($curl);
        return ($o and $o === 'true');
    }

    // ? Hmm, what should it do
    protected function prepareQuery($query)
    {
        return $query;
    }

    protected function beforeQuery(): void
    {
        sleep(1);
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
    protected function testOnce($query):array
    {
        if (is_string($query)) {
            return [
                'error' => true,
                'type' => self::UNSUPPORTED_QUERY_ERROR
            ];
        }

        $this->retrieveFields = $query['retrieve'];
        $this->resultsMapping = $query['mapping'];
        return $this->sendRequest($query['path'], $query['query']);
    }

    // To collect query stats after the query
    protected function afterQuery()
    {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($curlResult): array
    {
        $curlResult = json_decode($curlResult, true);
        return match (true) {
            isset($curlResult['hits']) && $curlResult['hits'] === []
            && isset($curlResult['aggregations'])
            => self::parseAggregations($curlResult['aggregations']),

            // If elastic compatibility request
            isset($curlResult['hits']['hits'])
            => self::filterResults($curlResult['hits']['hits'],
                true),
            // Quickwit regular request
            !empty($curlResult['hits']) => self::filterResults($curlResult['hits']),
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $results
     *
     * @return array<string,mixed>
     */
    protected function filterResults(
        array $results,
        $elasticStyle = false
    ): array {
        $filtered = [];
        foreach ($results as $row) {
            $ar = [];

            if ($elasticStyle) {
                $row = $row['_source'];
            }

            foreach ($row as $k => $v) {
                if ($k === 'id') {
                    continue;
                } // removing id from the output since Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266

                if (!empty($this->retrieveFields)
                    && !in_array($k, $this->retrieveFields)
                ) {
                    continue;
                }

                if (is_numeric($v) && strpos($v, '.')) {
                    $v = round($v, 4);
                } // This is a workaround for the differences in floating point calculation accuracy across different engines.

                $ar[$k] = isset($v) ? $v : '';
            }
            ksort($ar);
            $filtered[] = $ar;
        }
        return $filtered;
    }

    // sends a command to engine to drop its caches
    protected function dropEngineCache()
    {
        // Do nothing because no information is available
    }

    protected function sendRequest(string $path, $payload): array
    {
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
        $errorResult = $this->parseCurlError($httpCode, $curlErrorCode,
            $curlError);
        if ($errorResult) {
            return $errorResult;
        }
        return ['error' => false, 'response' => $curlResult];
    }
}
