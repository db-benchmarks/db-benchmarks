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

$intFields = ['story_id', 'comment_id', 'comment_ranking', 'author_comment_count', 'story_comment_count'];


if (($handle = fopen($source, "r")) !== FALSE) {
    $combinedLine='';
    while (($line = fgets($handle,20000)) !== false) {


if ($combinedLine === ''){
    $combinedLine = $line;
}else{
    $combinedLine .= $line;
}

        $matches=[];
        if (preg_match('/^"(?<id>.*)","(?<story_id>.*)","(?<story_text>.*)",'.
            '"(?<story_author>.*)","(?<comment_id>.*)","(?<comment_text>.*)","(?<comment_author>.*)",'.
            '"(?<comment_ranking>.*)","(?<author_comment_count>.*)",'.
            '"(?<story_comment_count>.*)"$/usi', $combinedLine, $matches)){
            $combinedLine='';
            $result = [];
            foreach ($fields as $field) {
                $value = $matches[$field];
                if (in_array($field, $intFields)) {
                    $value = (int)$matches[$field];
                }
                $result[$field] = $value;
            }

            if (fwrite($fp, json_encode($result) . "\n") === FALSE) {
                echo "Cannot write to file ($destination)";
                exit(1);
            }

        }
    }

    fclose($handle);
}
