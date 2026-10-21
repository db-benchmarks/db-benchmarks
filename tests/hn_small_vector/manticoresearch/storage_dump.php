#!/usr/bin/env php
<?php

// Rewrite only the vector table's CREATE statement; stream INSERTs unchanged.
if ($argc !== 3 || !is_readable($argv[1]) || !in_array($argv[2], ['rowwise', 'columnar'], true)) {
    fwrite(STDERR, "Usage: storage_dump.php <SQL dump> <rowwise|columnar>\n");
    exit(1);
}

$dump = fopen($argv[1], 'r');
$engine = $argv[2];
$create = '';
$found = false;
while (($line = fgets($dump)) !== false) {
    if ($create !== '' || preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?hn_small_vector`?\s*\(/i', $line)) {
        $create .= $line;
        if (!str_contains($create, ';')) {
            continue;
        }
        if ($found) {
            fwrite(STDERR, "Duplicate hn_small_vector CREATE TABLE\n");
            exit(1);
        }
        $found = true;
        // Only table options after the closing ')' may set the storage engine.
        // A field comment inside CREATE may also contain text like engine=columnar.
        $closing = strrpos($create, ')');
        $options = $closing === false ? '' : substr($create, $closing + 1);
        if ($closing === false || !preg_match('/^\s*[^;]*;\s*$/s', $options)
            || str_contains($options, '--') || str_contains($options, '/*')) {
            fwrite(STDERR, "Unsupported hn_small_vector CREATE TABLE options\n");
            exit(1);
        }
        if (preg_match('/\bengine\s*=\s*(?:\x27[^\x27]*\x27|"[^"]*"|[^\s;]+)/i', $options)) {
            $options = preg_replace('/\bengine\s*=\s*(?:\x27[^\x27]*\x27|"[^"]*"|[^\s;]+)/i', "engine='$engine'", $options, 1);
        } else {
            $options = preg_replace('/;/', " engine='$engine';", $options, 1);
        }
        echo substr($create, 0, $closing + 1) . $options;
        $create = '';
    } else {
        echo $line;
    }
}
fclose($dump);
if (!$found || $create !== '') {
    fwrite(STDERR, "Missing or incomplete hn_small_vector CREATE TABLE\n");
    exit(1);
}
