<?php

if (!isset($argv[1], $argv[2])) {
    echo "You should pass source file in first argument and destination in second";
    exit(1);
}

$source = $argv[1];
$destination = $argv[2];
if (!in_array(mime_content_type($source), ['text/csv', 'application/csv'])) {
    echo "Source file isn't CSV";
    exit(1);
}

if (file_exists($destination)) {
    echo "Destination file already exist";
    exit(1);
}

if (!$fp = fopen($destination, 'w')) {
    echo "Cannot open file ($destination)";
    exit(1);
}

$fields = [
    "id",
    "vendor_id",
    "pickup_datetime",
    "dropoff_datetime",
    "store_and_fwd_flag",
    "rate_code_id",
    "pickup_longitude",
    "pickup_latitude",
    "dropoff_longitude",
    "dropoff_latitude",
    "passenger_count",
    "trip_distance",
    "fare_amount",
    "extra",
    "mta_tax",
    "tip_amount",
    "tolls_amount",
    "ehail_fee",
    "improvement_surcharge",
    "total_amount",
    "payment_type",
    "trip_type",
    "pickup",
    "dropoff",
    "cab_type",
    "rain",
    "snow_depth",
    "snowfall",
    "max_temp",
    "min_temp",
    "wind",
    "pickup_nyct2010_gid",
    "pickup_ctlabel",
    "pickup_borocode",
    "pickup_boroname",
    "pickup_ct2010",
    "pickup_boroct2010",
    "pickup_cdeligibil",
    "pickup_ntacode",
    "pickup_ntaname",
    "pickup_puma",
    "dropoff_nyct2010_gid",
    "dropoff_ctlabel",
    "dropoff_borocode",
    "dropoff_boroname",
    "dropoff_ct2010",
    "dropoff_boroct2010",
    "dropoff_cdeligibil",
    "dropoff_ntacode",
    "dropoff_ntaname",
    "dropoff_puma",
];


$floatFields = [
    "pickup_longitude",
    "pickup_latitude",
    "dropoff_longitude",
    "dropoff_latitude",
    "trip_distance",
    "fare_amount",
    "extra",
    "mta_tax",
    "tip_amount",
    "tolls_amount",
    "ehail_fee",
    "improvement_surcharge",
    "total_amount",
    "rain",
    "snow_depth",
    "snowfall",
    "wind",
];

$intFields = [
    'id',
    'pickup_datetime',
    'dropoff_datetime',
    'rate_code_id',
    'passenger_count',
    'pickup_nyct2010_gid',
    'dropoff_nyct2010_gid',
    "trip_type", //bytes
    "max_temp", //bytes
    "min_temp", //bytes
    "pickup_borocode", //bytes
    "dropoff_borocode", //bytes
];


if (($handle = fopen($source, "r")) !== false) {
    while (($data = fgetcsv($handle, 20000, ",", '"', '"')) !== false) {
        $result = [];

        foreach ($data as $k => $field) {
            if (in_array($fields[$k], $intFields)) {
                $field = (int) $field;
            } elseif (in_array($fields[$k], $floatFields)) {
                $field = (float) $field;
            } else {
                $result[$fields[$k]] = $field;
            }
        }

        if (fwrite($fp, json_encode($result) . "\n") === false) {
            echo "Cannot write to file ($destination)";
            exit(1);
        }
    }

    fclose($handle);
}
