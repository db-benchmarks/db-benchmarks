#!/usr/bin/php
<?php

class Converter
{
    private const CSV_RESULT_FILE = '../data/data.csv';
    private const BATCH_SIZE = 500;
    private $batch;
    private $counter = 0;

    public function __construct()
    {
        if (file_exists(self::CSV_RESULT_FILE)) {
            unlink(self::CSV_RESULT_FILE);
        }
    }

    private function parseLine($id, $line)
    {
        preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', $line, $matches);

        if (preg_last_error()) {
            return false;
        }
        if ( ! empty($matches[0])) {

            $request = explode(" ", $matches[0][5]);

            if ( ! isset($request[0]) || ! in_array($request[0],
                    ['"GET', '"POST', '"PUT', '"PATCH', '"DELETE', '"HEAD', '"OPTIONS'])) {
                $request[0] = '';
                $request[1] = '';
                $request[2] = '';
            }

            if ( ! isset($request[1])) {
                $request[1] = '';
            }

            if ( ! isset($request[2])) {
                $request[2] = '';
            }

            $request[1] = urldecode($request[1]);
            $time       = strtotime(substr($matches[0][3] . ' ' . $matches[0][4], 1, -1));

            if ( ! isset($matches[0][7])) {
                throw new RuntimeException("Something went wrong on line parsing");
            }

            return [
                'id'               => $id,
                'remote_addr'      => $matches[0][0],
                'remote_user'      => $matches[0][2],
                'runtime'          => rand(100, 10000),
                'time_local'       => $time,
                'request_type'     => substr($request[0], 1),
                'request_path'     => $request[1],
                'request_protocol' => substr($request[2], 0, -1),
                'status'           => (int)$matches[0][6],
                'size'             => (int)$matches[0][7],
                'referer'          => substr($matches[0][8], 1, -1),
                'usearagent'       => substr($matches[0][9], 1, -1)
            ];
        }

        return false;
    }


    public function save(): bool
    {
        if (file_exists(self::CSV_RESULT_FILE)) {
            echo "CSV results exists. Skip conversion";

            return false;
        }

        $handle = @fopen("access.log", 'rb');
        if ($handle) {
            $i = 0;
            while (($buffer = fgets($handle, 16384)) !== false) {
                $i++;
                $data = $this->parseLine($i, $buffer);
                if ($data !== false) {
                    $this->stackToBatch($data);
                }

            }
            if ( ! feof($handle)) {
                throw new RuntimeException("fgets() error");
            }
            fclose($handle);
        }

        $this->saveToCsv();

        return true;
    }

    private function stackToBatch($line): void
    {
        $this->batch[] = $line;
        if ((count($this->batch) >= self::BATCH_SIZE) && $this->saveToCsv()) {
            echo 'Converted ' . $this->counter . " records\n";
        }
    }

    private function saveToCsv(): bool
    {

        if (count($this->batch) === 0) {
            return false;
        }

        $csv = fopen(self::CSV_RESULT_FILE, 'ab');
        $mem = fopen('php://temp/maxmemory:1048576', 'w');
        foreach ($this->batch as $fields) {
            foreach ($fields as $k=>$v) {
                $v = str_replace(array(
                    // control characters
                   chr(0), chr(1), chr(2), chr(3), chr(4), chr(5), chr(6), chr(7), chr(8),
                   chr(11), chr(12), chr(14), chr(15), chr(16), chr(17), chr(18), chr(19), chr(20),
                   chr(21), chr(22), chr(23), chr(24), chr(25), chr(26), chr(27), chr(28), chr(29), chr(30),
                   chr(31),
                   // non-printing characters
                   chr(127)
                ), '', $v);
                $v = '"'.str_replace('"','""',$v).'"';
                $fields[$k] = $v;
            }
            fputcsv($mem, $fields, ',', "\0");
            rewind($mem);
            fwrite($csv, str_replace(chr(0), '', stream_get_contents($mem)));
            ftruncate($mem, 0);
        }
        fclose($csv);
        fclose($mem);

        $this->counter += count($this->batch);
        $this->batch   = [];

        return true;
    }
}

srand(1);
(new Converter())->save();


