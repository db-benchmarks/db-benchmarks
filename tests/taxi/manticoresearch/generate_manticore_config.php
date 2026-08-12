#!/usr/bin/env php
<?php
$options = getopt('', ["type:", "test:", "shards:"]);
if (!isset($options['type'])) exit(1);
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
source csv1
{
        type = csvpipe
        csvpipe_command = ls /input/*.csv.* | awk 'NR % $shards == 1' | xargs -r cat
        # csvpipe_attr_uint = trip_id # the 1st one should be an id, so this has to be excluded
        csvpipe_attr_string = vendor_id
        csvpipe_attr_timestamp = pickup_datetime
        csvpipe_attr_timestamp = dropoff_datetime
        csvpipe_attr_string = store_and_fwd_flag
        csvpipe_attr_uint = rate_code_id
        csvpipe_attr_float = pickup_longitude
        csvpipe_attr_float = pickup_latitude
        csvpipe_attr_float = dropoff_longitude
        csvpipe_attr_float = dropoff_latitude
        csvpipe_attr_uint = passenger_count
        csvpipe_attr_float = trip_distance
        csvpipe_attr_float = fare_amount
        csvpipe_attr_float = extra
        csvpipe_attr_float = mta_tax
        csvpipe_attr_float = tip_amount
        csvpipe_attr_float = tolls_amount
        csvpipe_attr_float = ehail_fee
        csvpipe_attr_float = improvement_surcharge
        csvpipe_attr_float = total_amount
        csvpipe_attr_string = payment_type
        csvpipe_attr_uint = trip_type:8
        csvpipe_attr_string = pickup
        csvpipe_attr_string = dropoff
        csvpipe_attr_string = cab_type
        csvpipe_attr_float = rain
        csvpipe_attr_float = snow_depth
        csvpipe_attr_float = snowfall
        csvpipe_attr_uint = max_temp:8
        csvpipe_attr_uint = min_temp:8
        csvpipe_attr_float = wind
        csvpipe_attr_uint = pickup_nyct2010_gid
        csvpipe_attr_string = pickup_ctlabel
        csvpipe_attr_uint = pickup_borocode
        csvpipe_attr_string = pickup_boroname
        csvpipe_attr_string = pickup_ct2010
        csvpipe_attr_string = pickup_boroct2010
        csvpipe_attr_string = pickup_cdeligibil
        csvpipe_attr_string = pickup_ntacode
        csvpipe_field_string = pickup_ntaname
        csvpipe_attr_string = pickup_puma
        csvpipe_attr_uint = dropoff_nyct2010_gid
        csvpipe_attr_string = dropoff_ctlabel
        csvpipe_attr_uint = dropoff_borocode:8
        csvpipe_attr_string = dropoff_boroname
        csvpipe_attr_string = dropoff_ct2010
        csvpipe_attr_string = dropoff_boroct2010
        csvpipe_attr_string = dropoff_cdeligibil
        csvpipe_attr_string = dropoff_ntacode
        csvpipe_field_string = dropoff_ntaname
        csvpipe_attr_string = dropoff_puma
}

";

for ($n = 2; $n <= $shards; $n++) {
        $remainder = $n % $shards;
        echo "
source csv$n : csv1 {
        csvpipe_command = ls /input/*.csv.* | awk 'NR % $shards == $remainder' | xargs -r cat
}
";
}


for ($n = 1; $n <= $shards; $n++) {
        echo "

index {$test}$n {
        path = /var/lib/manticore/{$test}$n
        source = csv$n
";
        if (preg_match('/^_columnar/', $type)) echo "
        columnar_attrs = id, trip_id,vendor_id,pickup_datetime,dropoff_datetime,store_and_fwd_flag,rate_code_id,pickup_longitude,pickup_latitude,dropoff_longitude,dropoff_latitude,passenger_count,trip_distance,fare_amount,extra,mta_tax,tip_amount,tolls_amount,ehail_fee,improvement_surcharge,total_amount,payment_type,trip_type,pickup,dropoff,cab_type,rain,snow_depth,snowfall,max_temp,min_temp,wind,pickup_nyct2010_gid,pickup_ctlabel,pickup_borocode,pickup_boroname,pickup_ct2010,pickup_boroct2010,pickup_cdeligibil,pickup_ntacode,pickup_ntaname,pickup_puma,dropoff_nyct2010_gid,dropoff_ctlabel,dropoff_borocode,dropoff_boroname,dropoff_ct2010,dropoff_boroct2010,dropoff_cdeligibil,dropoff_ntacode,dropoff_ntaname,dropoff_puma

";
        echo "
}

";
}

echo "

index taxi {
        type = distributed
";

for ($n = 1; $n <= $shards; $n++) {
        echo "        local = taxi$n
";
}

echo "}

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
        skiplist_cache_size = 0
";
}

echo "
	binlog_path = /tmp/ # this doesn't disable binary logging, just leaves the log inside container to make each test run independent
}

";
