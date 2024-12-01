<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 * This class allows updating existing results and setting appropriate errors
 * according to the defined error types for frontend requirements.
 */
class ResultsUpdater
{

    public function saveResultsFromPath($path): bool
    {
        if (is_file($path)) {
            $iterator = [$path];
        } else {
            if (is_dir($path)) {
                $dir_iterator = new RecursiveDirectoryIterator($path);
                $iterator = new RecursiveIteratorIterator($dir_iterator,
                    RecursiveIteratorIterator::SELF_FIRST);
            } else {
                return false;
            }
        }

        foreach ($iterator as $file) {
            if (is_file($file)) {
                if (basename($file) == '.gitkeep') {
                    continue;
                }

                $results = @unserialize(file_get_contents($file));
                if (!$results) {
                    exit("ERROR: can't read from the file");
                }

                if (isset($results['stage']) && $results['stage'] === 'init') {
                    continue;
                }

                $needToSave = false;
                foreach ($results['queries'] as $k => $query) {
                    if (isset($query['result']['error'])) {

                        if (sizeof($query['result']['error'])>=2
                            && isset($query['result']['error']['type'])
                            && isset($query['result']['error']['message'])){
                            continue;
                        }
                        if (isset($query['result']['error']['curlError'])) {
                            $results['queries'][$k]['result']['error']['type']
                                = 'timeout';
                            if ($query['result']['error']['curlError']
                                === 'Empty reply from server'
                            ) {
                                $results['queries'][$k]['result']['error']['type']
                                    = 'error';
                            }

                            $results['queries'][$k]['result']['error']['message']
                                = $query['result']['error']['curlError'];
                            unset($results['queries'][$k]['result']['error']['httpCode']);
                            unset($results['queries'][$k]['result']['error']['curlError']);
                        } elseif (isset($query['result']['error']['timeout'])
                            && sizeof($query['result']['error']) === 1
                        ) {
                            $results['queries'][$k]['result']['error']['type']
                                = 'unsupported query';
                            $results['queries'][$k]['result']['error']['message']
                                = 'This query is not supported by the current engine';
                            unset($results['queries'][$k]['result']['error']['timeout']);
                        } elseif (isset($query['result']['error']['mysqlError'])) {
                            $results['queries'][$k]['result']['error']['type']
                                = 'error';
                            $results['queries'][$k]['result']['error']['message']
                                = $query['result']['error']['mysqlError'] . "("
                                . $query['result']['error']['mysqlErrorCode']
                                . ")";
                            unset($results['queries'][$k]['result']['error']['mysqlError']);
                            unset($results['queries'][$k]['result']['error']['mysqlErrorCode']);
                        }
                        $needToSave = true;
                    }
                }
                if ($needToSave){
                    echo "Saving file $file\n";
                    $this->save($file, $results);
                }
            }



        }
        return true;
    }

    private function save($path, array $content): void
    {
        file_put_contents($path, serialize($content));
    }
}

$b = new ResultsUpdater();
$b->saveResultsFromPath('../results');