#!/usr/bin/env php
<?php
$options = getopt('', ["type:", "test:", "shards:"]);
if (!isset($options['type'])) $options['type'] = 'rowwise';
if (!isset($options['test'])) exit(1);

$type = $options['type'];
$test = $options['test'];
$shards = (int)($options['shards'] ?? 0);
if ($shards < 1) exit(1);
$reportedVersion = getenv('MANTICORE_REPORTED_VERSION') ?: '';
$disableCachesInConfig = true;
if ($reportedVersion && version_compare($reportedVersion, '17.0.0', '>=')) {
    $disableCachesInConfig = false;
}

echo "
source csv1 {
        type = csvpipe
        csvpipe_command = cat /input/manticoresearch_shards/shard1.csv
        csvpipe_attr_uint = story_id
        csvpipe_field = story_text
        csvpipe_field_string = story_author
        csvpipe_attr_uint = comment_id
        csvpipe_field = comment_text
        csvpipe_field_string = comment_author
        csvpipe_attr_uint = comment_ranking
        csvpipe_attr_uint = author_comment_count
        csvpipe_attr_uint = story_comment_count
}
";

for ($n = 2; $n <= $shards; $n++) {
    echo "
source csv$n : csv1 {
        csvpipe_command = cat /input/manticoresearch_shards/shard$n.csv
}
";
}

for ($n = 1; $n <= $shards; $n++) {
    echo "
index {$test}$n {
        path = /var/lib/manticore/{$test}$n
        source = csv$n
	min_infix_len = 2
";

    if (strstr($type, 'columnar')) echo "
	columnar_attrs = id, story_id, comment_id, comment_ranking, author_comment_count, story_comment_count, story_author, comment_author
";

    echo "
}
";
}

echo "
index $test {
        type = distributed
";

for ($n = 1; $n <= $shards; $n++) {
    echo "        local = {$test}$n
";
}

echo "}
";

echo "
searchd {
        listen = 9306:mysql
        listen = 9308:http
        pid_file = /var/run/manticore/searchd.pid
";

if ($disableCachesInConfig) {
    echo "
	qcache_max_bytes = 0
   	docstore_cache_size = 0
	skiplist_cache_size = 0
";
}

echo "
        " . (strstr($type, '_ps0') ? "pseudo_sharding = 0" : "") . "
	binlog_path = /tmp/
}
";
