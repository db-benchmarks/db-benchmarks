#!/usr/bin/php
<?php
$options = getopt('', ["type:", "test:"]);
if (!isset($options['type'])) exit(1);
if (!isset($options['test'])) exit(1);

$type = $options['type'];
$test = $options['test'];

echo "
source csv1
{
        type = csvpipe
        csvpipe_command = ls /input/*.csv.*|head -3 |tail -3 |xargs -I aa cat aa
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

source csv2 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -6|tail -3 |xargs -I aa cat aa
}

source csv3 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -9|tail -3 |xargs -I aa cat aa
}

source csv4 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -12|tail -3 |xargs -I aa cat aa
}

source csv5 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -15|tail -3 |xargs -I aa cat aa
}

source csv6 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -18|tail -3 |xargs -I aa cat aa
}

source csv7 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -21|tail -3 |xargs -I aa cat aa
}

source csv8 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -24|tail -3 |xargs -I aa cat aa
}

source csv9 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -27|tail -3 |xargs -I aa cat aa
}

source csv10 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -30|tail -3 |xargs -I aa cat aa
}

source csv11 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -33|tail -3 |xargs -I aa cat aa
}

source csv12 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -36|tail -3 |xargs -I aa cat aa
}

source csv13 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -39|tail -3 |xargs -I aa cat aa
}

source csv14 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -42|tail -3 |xargs -I aa cat aa
}

source csv15 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -45|tail -3 |xargs -I aa cat aa
}

source csv16 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -48|tail -3 |xargs -I aa cat aa
}

source csv17 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -51|tail -3 |xargs -I aa cat aa
}

source csv18 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -54|tail -3 |xargs -I aa cat aa
}

source csv19 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -57|tail -3 |xargs -I aa cat aa
}

source csv20 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -60|tail -3 |xargs -I aa cat aa
}

source csv21 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -63|tail -3 |xargs -I aa cat aa
}

source csv22 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -66|tail -3 |xargs -I aa cat aa
}

source csv23 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -68|tail -2 |xargs -I aa cat aa
}

source csv24 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -70|tail -2 |xargs -I aa cat aa
}

source csv25 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -72|tail -2 |xargs -I aa cat aa
}

source csv26 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -74|tail -2 |xargs -I aa cat aa
}

source csv27 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -76|tail -2 |xargs -I aa cat aa
}

source csv28 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -78|tail -2 |xargs -I aa cat aa
}

source csv29 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -80|tail -2 |xargs -I aa cat aa
}

source csv30 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -82|tail -2 |xargs -I aa cat aa
}

source csv31 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -84|tail -2 |xargs -I aa cat aa
}

source csv32 : csv1 {
        csvpipe_command = ls /input/*.csv.*|head -87|tail -3 |xargs -I aa cat aa
}

";

for ($n=1;$n<=32;$n++) {
        echo "

index {$test}$n {
        path = /var/lib/manticore/{$test}$n
        source = csv$n
";
        if (preg_match('/^columnar/', $type)) echo "
        columnar_attrs = id, trip_id,vendor_id,pickup_datetime,dropoff_datetime,store_and_fwd_flag,rate_code_id,pickup_longitude,pickup_latitude,dropoff_longitude,dropoff_latitude,passenger_count,trip_distance,fare_amount,extra,mta_tax,tip_amount,tolls_amount,ehail_fee,improvement_surcharge,total_amount,payment_type,trip_type,pickup,dropoff,cab_type,rain,snow_depth,snowfall,max_temp,min_temp,wind,pickup_nyct2010_gid,pickup_ctlabel,pickup_borocode,pickup_boroname,pickup_ct2010,pickup_boroct2010,pickup_cdeligibil,pickup_ntacode,pickup_ntaname,pickup_puma,dropoff_nyct2010_gid,dropoff_ctlabel,dropoff_borocode,dropoff_boroname,dropoff_ct2010,dropoff_boroct2010,dropoff_cdeligibil,dropoff_ntacode,dropoff_ntaname,dropoff_puma

";
        echo "
}

";
}

echo "

index taxi {
        type = distributed
        local = taxi1
        local = taxi2
        local = taxi3
        local = taxi4
        local = taxi5
        local = taxi6
        local = taxi7
        local = taxi8
        local = taxi9
        local = taxi10
        local = taxi11
        local = taxi12
        local = taxi13
        local = taxi14
        local = taxi15
        local = taxi16
        local = taxi17
        local = taxi18
        local = taxi19
        local = taxi20
        local = taxi21
        local = taxi22
        local = taxi23
        local = taxi24
        local = taxi25
        local = taxi26
        local = taxi27
        local = taxi28
        local = taxi29
        local = taxi30
        local = taxi31
        local = taxi32
}

searchd
{
        listen = 9306:mysql
        listen = 9308:http
        pid_file = /var/run/manticore/searchd.pid
        qcache_max_bytes = 0
        docstore_cache_size = 0
	binlog_path = /tmp/ # this doesn't disable binary logging, just leaves the log inside container to make each test run independent
        secondary_indexes = 1
}

";
