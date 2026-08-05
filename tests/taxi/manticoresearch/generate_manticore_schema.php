#!/usr/bin/env php
<?php
$options = getopt('', ["type:", "test:"]);
if (!isset($options['type'])) exit(1);
if (!isset($options['test'])) exit(1);

$type = $options['type'];
$test = $options['test'];
$engine = strstr($type, 'columnar') ? " engine='columnar'" : '';
$shards = 32;

echo "DROP TABLE IF EXISTS $test;\n";
echo "CREATE TABLE $test (" .
    "`@timestamp` string, " .
    "vendor_id string, " .
    "pickup_datetime timestamp, " .
    "dropoff_datetime timestamp, " .
    "store_and_fwd_flag string, " .
    "rate_code_id int, " .
    "pickup_longitude float, " .
    "pickup_latitude float, " .
    "dropoff_longitude float, " .
    "dropoff_latitude float, " .
    "passenger_count int, " .
    "trip_distance float, " .
    "fare_amount float, " .
    "extra float, " .
    "mta_tax float, " .
    "tip_amount float, " .
    "tolls_amount float, " .
    "ehail_fee float, " .
    "improvement_surcharge float, " .
    "total_amount float, " .
    "payment_type string, " .
    "trip_type int, " .
    "pickup string, " .
    "dropoff string, " .
    "cab_type string, " .
    "rain float, " .
    "snow_depth float, " .
    "snowfall float, " .
    "max_temp int, " .
    "min_temp int, " .
    "wind float, " .
    "pickup_nyct2010_gid int, " .
    "pickup_ctlabel string, " .
    "pickup_borocode int, " .
    "pickup_boroname string, " .
    "pickup_ct2010 string, " .
    "pickup_boroct2010 string, " .
    "pickup_cdeligibil string, " .
    "pickup_ntacode string, " .
    "pickup_ntaname text indexed attribute, " .
    "pickup_puma string, " .
    "dropoff_nyct2010_gid int, " .
    "dropoff_ctlabel string, " .
    "dropoff_borocode int, " .
    "dropoff_boroname string, " .
    "dropoff_ct2010 string, " .
    "dropoff_boroct2010 string, " .
    "dropoff_cdeligibil string, " .
    "dropoff_ntacode string, " .
    "dropoff_ntaname text indexed attribute, " .
    "dropoff_puma string" .
    ") min_word_len='1' min_infix_len='2'$engine shards='$shards' rf='1';\n";