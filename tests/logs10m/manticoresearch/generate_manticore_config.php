#!/usr/bin/env php
<?php
$options = getopt('', ["test:", "type:"]);
if (!isset($options['type'])) exit(1);
if (!isset($options['test'])) exit(1);

$reportedVersion = getenv('MANTICORE_REPORTED_VERSION') ?: '';
$disableCachesInConfig = true;
if ($reportedVersion && version_compare($reportedVersion, '17.0.0', '>=')) {
    $disableCachesInConfig = false;
}

echo "
searchd
{
        listen = 9306:mysql
        listen = 9308:http
        pid_file = /var/run/manticore/searchd.pid
        data_dir = /var/lib/manticore
";

if ($disableCachesInConfig) {
    echo "
        qcache_max_bytes = 0
        docstore_cache_size = 0
        skiplist_cache_size = 0
";
}

echo "
        binlog_path = /tmp/
}
";
