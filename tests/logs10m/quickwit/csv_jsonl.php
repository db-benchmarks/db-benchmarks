<?php

if (!isset($argv[1], $argv[2])) {
    echo "You should pass source file in first argument and destination in second";
    exit(1);
}

$source = $argv[1];
$destination = $argv[2];
if (!in_array(mime_content_type($source), ['text/csv', 'application/csv'])) {
    echo "Source file isn't CSV";
    exit(1);
}

if (file_exists($destination)) {
    echo "Destination file already exist";
    exit(1);
}

if (!$fp = fopen($destination, 'w')) {
    echo "Cannot open file ($destination)";
    exit(1);
}

$fields = [
    "id",
    "remote_addr",
    "remote_user",
    "runtime",
    "time_local",
    "request_type",
    "request_path",
    "request_protocol",
    "status",
    "size",
    "referer",
    "usearagent"
];

$intFields = ['id', 'runtime', 'time_local', 'status', 'size'];


if (($handle = fopen($source, "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 20000, ",", '"','"')) !== FALSE) {
        $result = [];

        foreach ($data as $k=>$field){
            if (in_array($fields[$k], $intFields)){
                $field = (int) $field;
            }
            $result[$fields[$k]] = $field;
        }

        if (fwrite($fp, json_encode($result)."\n") === FALSE) {
            echo "Cannot write to file ($destination)";
            exit(1);
        }
    }

    fclose($handle);
}
