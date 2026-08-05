#!/usr/bin/env php
<?php
$options = getopt('', ["type:", "test:"]);
if (!isset($options['type'])) exit(1);
if (!isset($options['test'])) exit(1);

$type = $options['type'];
$test = $options['test'];
$engine = strstr($type, 'columnar') ? " engine='columnar'" : '';

echo "DROP TABLE IF EXISTS $test;
";
echo "CREATE TABLE $test (" .
    "`@timestamp` string, " .
    "remote_addr text, " .
    "remote_user text, " .
    "runtime int, " .
    "time_local timestamp, " .
    "request_type text, " .
    "request_path text indexed attribute, " .
    "request_protocol text, " .
    "status int, " .
    "size int, " .
    "referer text, " .
    "usearagent text" .
    ") min_word_len='1' min_infix_len='2'$engine;
";
