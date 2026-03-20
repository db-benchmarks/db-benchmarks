#!/usr/bin/env php
<?php

$options = getopt('', ["type:", "test:"]);
if (!isset($options['type'])) {
    $options['type'] = '';
}

$type = $options['type'];
$reportedVersion = getenv('MANTICORE_REPORTED_VERSION') ?: '';
$disableCachesInConfig = true;
if ($reportedVersion && version_compare($reportedVersion, '17.0.0', '>=')) {
    $disableCachesInConfig = false;
}
echo "
searchd {
        listen = 9306:mysql
        listen = 9308:http
        pid_file = /var/run/manticore/searchd.pid
        data_dir = /var/lib/manticore
        auto_optimize = 0
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
        binlog_path =
        secondary_indexes = 1
}
";
