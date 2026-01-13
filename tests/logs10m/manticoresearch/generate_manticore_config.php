#!/usr/bin/env php
<?php
$options = getopt('', ["test:", "type:"]);
if ( ! isset($options['type'])) {
    exit(1);
}
if ( ! isset($options['test'])) {
    exit(1);
}

$test = $options['test'];
$type    = $options['type'];
$reportedVersion = getenv('MANTICORE_REPORTED_VERSION') ?: '';
$disableCachesInConfig = true;
if ($reportedVersion && version_compare($reportedVersion, '17.0.0', '>=')) {
    $disableCachesInConfig = false;
}

echo "
source $test
{
        type = csvpipe
        csvpipe_command = cat /input/data.csv

        csvpipe_field = remote_addr
        csvpipe_field = remote_user
        csvpipe_attr_uint = runtime
        csvpipe_attr_timestamp = time_local
        csvpipe_field = request_type
        csvpipe_field_string = request_path
        csvpipe_field = request_protocol        
        csvpipe_attr_uint = status        
        csvpipe_attr_uint = size
        csvpipe_field = referer
        csvpipe_field = usearagent
}

index $test
{
        path = /var/lib/manticore/$test
        source = $test
	" . (strstr($type, 'columnar') ? "columnar_attrs = id, remote_addr, remote_user, request_type, request_protocol, referer,  runtime, status, size, usearagent, request_path" : "") . "
}

searchd
{
        listen = 9306:mysql
        listen = 9308:http
        pid_file = /var/run/manticore/searchd.pid
";

if ($disableCachesInConfig) {
    echo "
        qcache_max_bytes = 0
        docstore_cache_size = 0
";
}

echo "
        " . (strstr($type, '_ps0') ? "pseudo_sharding = 0" : "") . "
	binlog_path = /tmp/
        secondary_indexes = 1
}
";
