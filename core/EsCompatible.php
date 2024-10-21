<?php

trait EsCompatible
{

    private ?array $resultsMapping = null;

    protected function parseAggregations($aggregations): array
    {
        if (isset($aggregations['count(*)']['value'])) {
            return [['count(*)' => (int) $aggregations['count(*)']['value']]];
        }

        // TODO seems outdated
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
}