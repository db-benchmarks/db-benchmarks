#!/usr/bin/env php
<?php
$options = getopt('', ["type:", "test:"]);
if (!isset($options['type'])) $options['type'] = 'rowwise_tuned';
if (!isset($options['test'])) exit(1);

$type = $options['type'];
$test = $options['test'];
$engine = strstr($type, 'columnar') ? " engine='columnar'" : '';
$shards = 32;

echo "DROP TABLE IF EXISTS $test;\n";
echo "CREATE TABLE $test (" .
    "`@timestamp` string, " .
    "story_id int, " .
    "story_text text, " .
    "story_author text indexed attribute, " .
    "comment_id int, " .
    "comment_text text, " .
    "comment_author text indexed attribute, " .
    "comment_ranking int, " .
    "author_comment_count int, " .
    "story_comment_count int" .
    ") min_word_len='1' min_infix_len='2'$engine shards='$shards' rf='1';\n";