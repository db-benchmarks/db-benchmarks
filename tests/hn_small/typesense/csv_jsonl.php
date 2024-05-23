<?php

if (!isset($argv[1], $argv[2])) {
    echo "You should pass source file in first argument and destination in second";
    exit(1);
}

$source = $argv[1];
$destination = $argv[2];
if (mime_content_type($source) !== 'text/csv') {
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
    "story_id",
    "story_text",
    "story_author",
    "comment_id",
    "comment_text",
    "comment_author",
    "comment_ranking",
    "author_comment_count",
    "story_comment_count"
];

$intFields = ['story_id','comment_id','comment_ranking','author_comment_count','story_comment_count'];


if (($handle = fopen($source, "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 20000, ",")) !== FALSE) {
        $result = [];
        foreach ($data as $k=>$field){
            if (!isset($fields[$k])){
                continue 2;
            }

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
