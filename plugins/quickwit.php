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

    private $port = 7280;
    private CurlHandle|false $curl = false; // curl connection
    private ?array $resultsMapping = null;
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
    protected function testOnce($query)
    {
        if (is_string($query)) {
            return ['timeout' => true];
        }
        assert(is_array($query));

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

    private function parseAggregations($aggregations): array
    {
        if (isset($aggregations['count(*)']['value'])) {
            return [['count(*)' => (int) $aggregations['count(*)']['value']]];
        }

        if (isset($aggregations['count(*)']['buckets'])) {
            return [
                array_map(
                    function ($row) {
                        return [
                            'count(*)' => $row['doc_count'],
                            'story_author' => $row['key']
                        ];
                    }, $aggregations['count(*)']['buckets']
                )
            ];
        }


        if ($this->resultsMapping) {
            return $this->extractValues($aggregations);
        }

        return $aggregations;
    }


    private function extractValues($data): array
    {
        $results = [];
        $this->extractRecursive($data, $this->resultsMapping, $results);
        return $this->sortResults($results);
    }

    private function sortResults($results): array
    {
        return array_map(
            function ($row) {
                ksort($row, SORT_STRING);
                return $row;
            }, $results
        );
    }

    private function extractRecursive(
        $data,
        $structure,
        &$results,
        &$currentResult = null
    ): void {
        if ($currentResult === null) {
            $currentResult = [];
        }

        foreach ($structure as $key => $value) {
            if ($key === "[]") {
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $subResult = [];
                        $this->extractRecursive($item, $value, $results,
                            $subResult);
                        $results[] = $subResult;
                    }
                }
            } elseif ($key === "single_value") {
                $subResult = [];
                $this->extractRecursive(
                    $data, $value, $results, $subResult
                );
                $results[] = $subResult;
            } elseif (is_array($value)) {
                if (array_key_exists($key, $data)) {
                    $this->extractRecursive(
                        $data[$key], $value, $results, $currentResult
                    );
                }
            } else {
                if (array_key_exists($key, $data)) {
                    $fieldInfo = explode(':', $value);
                    $fieldName = trim($fieldInfo[0]);
                    $fieldType = isset($fieldInfo[1]) ? trim($fieldInfo[1])
                        : null;

                    $fieldValue = $data[$key];
                    if ($fieldType === 'float') {
                        $currentResult[$fieldName] = round((float) $fieldValue,
                            4);
                    } elseif ($fieldType === 'int') {
                        $currentResult[$fieldName] = (int) $fieldValue;
                    } else {
                        $currentResult[$fieldName] = $fieldValue;
                    }
                }
            }
        }
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
        // ? Do nothing due no information available
    }

    protected function sendRequest(string $path, $payload): string
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
        if ($httpCode != 200 or $curlErrorCode != 0 or $curlError != '') {
            $out = ['httpCode' => $httpCode, 'curlError' => $curlError];
            if ($curlErrorCode == 28 or preg_match('/timeout|timed out/',
                    $curlError)
            ) {
                $out['timeout'] = true;
            }
            return json_encode($out);
        }
        return $curlResult;
    }
}
