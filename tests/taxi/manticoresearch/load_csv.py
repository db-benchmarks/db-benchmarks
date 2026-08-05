#!/usr/bin/env python3
import csv
import glob
import http.client
import json
import os
from pathlib import Path
import sys

COLUMNS = [
    'trip_id', 'vendor_id', 'pickup_datetime', 'dropoff_datetime', 'store_and_fwd_flag',
    'rate_code_id', 'pickup_longitude', 'pickup_latitude', 'dropoff_longitude', 'dropoff_latitude',
    'passenger_count', 'trip_distance', 'fare_amount', 'extra', 'mta_tax', 'tip_amount',
    'tolls_amount', 'ehail_fee', 'improvement_surcharge', 'total_amount', 'payment_type',
    'trip_type', 'pickup', 'dropoff', 'cab_type', 'rain', 'snow_depth', 'snowfall',
    'max_temp', 'min_temp', 'wind', 'pickup_nyct2010_gid', 'pickup_ctlabel',
    'pickup_borocode', 'pickup_boroname', 'pickup_ct2010', 'pickup_boroct2010',
    'pickup_cdeligibil', 'pickup_ntacode', 'pickup_ntaname', 'pickup_puma',
    'dropoff_nyct2010_gid', 'dropoff_ctlabel', 'dropoff_borocode', 'dropoff_boroname',
    'dropoff_ct2010', 'dropoff_boroct2010', 'dropoff_cdeligibil', 'dropoff_ntacode',
    'dropoff_ntaname', 'dropoff_puma',
]

INT_COLUMNS = {
    'trip_id', 'pickup_datetime', 'dropoff_datetime', 'rate_code_id', 'passenger_count',
    'trip_type', 'max_temp', 'min_temp', 'pickup_nyct2010_gid', 'pickup_borocode',
    'dropoff_nyct2010_gid', 'dropoff_borocode',
}
FLOAT_COLUMNS = {
    'pickup_longitude', 'pickup_latitude', 'dropoff_longitude', 'dropoff_latitude',
    'trip_distance', 'fare_amount', 'extra', 'mta_tax', 'tip_amount', 'tolls_amount',
    'ehail_fee', 'improvement_surcharge', 'total_amount', 'rain', 'snow_depth',
    'snowfall', 'wind',
}

INDEX_NAME = 'taxi'


def env_value(name, default):
    value = os.getenv(name)
    if value is not None:
        return value

    env_path = Path(__file__).resolve().parents[3] / '.env'
    if not env_path.exists():
        return default

    for line in env_path.read_text().splitlines():
        key, separator, value = line.partition('=')
        if separator and key == name:
            return value.strip().strip('"\'')

    return default


BATCH_SIZE = 1000
MANTICORE_HOST = env_value('MANTICORE_HTTP_HOST', 'localhost')
MANTICORE_PORT = int(env_value('MANTICORE_HTTP_PORT', '9308'))


def convert_value(key, value):
    if value == '':
        return None
    if key in INT_COLUMNS:
        return int(float(value))
    if key in FLOAT_COLUMNS:
        return float(value)
    return value


def post_batch(batch):
    if not batch:
        return

    body = ''.join(batch).encode()

    try:
        conn = http.client.HTTPConnection(MANTICORE_HOST, MANTICORE_PORT, timeout=600)
        conn.request('POST', '/_bulk?filter_path=errors,took', body, {'Content-Type': 'application/x-ndjson'})
        response = conn.getresponse()
        payload = response.read()
        conn.close()
    except OSError as e:
        print(f'Failed to connect to Manticore HTTP at {MANTICORE_HOST}:{MANTICORE_PORT}: {e}', file=sys.stderr)
        raise SystemExit(1)

    if response.status >= 400:
        print(f'Manticore bulk HTTP {response.status}: {payload[:4000].decode(errors="replace")}', file=sys.stderr)
        raise SystemExit(1)

    if payload:
        data = json.loads(payload)
        if data.get('errors'):
            print('Manticore bulk load reported item errors', file=sys.stderr)
            print(json.dumps(data)[:4000], file=sys.stderr)
            raise SystemExit(1)


def rows_from_file(path):
    with open(path, newline='') as f:
        lines = f.read().splitlines(keepends=True)

    # Fluent Bit's tail input ignored the final unterminated row in the local taxi
    # shard. Preserve that behavior so the migration does not change row counts.
    if lines and not lines[-1].endswith('\n'):
        lines = lines[:-1]

    yield from csv.reader(lines)


def main():
    batch = []
    records = 0

    for path in sorted(glob.glob('data/trips.csv.*')):
        for row in rows_from_file(path):
            if len(row) != len(COLUMNS):
                print(f'{path}: expected {len(COLUMNS)} columns, got {len(row)}', file=sys.stderr)
                raise SystemExit(1)

            source = {}
            doc_id = None
            for key, value in zip(COLUMNS, row):
                converted = convert_value(key, value)
                if converted is None:
                    continue
                if key == 'trip_id':
                    doc_id = str(converted)
                else:
                    source[key] = converted

            if doc_id is None:
                print(f'{path}: missing trip_id', file=sys.stderr)
                raise SystemExit(1)

            batch.append(json.dumps({'index': {'_index': INDEX_NAME, '_id': doc_id}}, separators=(',', ':')) + '\n')
            batch.append(json.dumps(source, separators=(',', ':')) + '\n')
            records += 1

            if records % BATCH_SIZE == 0:
                post_batch(batch)
                batch = []

    if records == 0:
        print('No taxi CSV rows found in data/trips.csv.*', file=sys.stderr)
        raise SystemExit(1)

    post_batch(batch)
    print(f'Loaded taxi CSV rows into Manticore: {records}')


if __name__ == '__main__':
    main()
