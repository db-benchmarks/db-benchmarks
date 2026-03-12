#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

dump_url="https://s3.db-benchmarks.com/hn_small_vector_dump.tar.gz"
dump_dir="data"
archive="$dump_dir/hn_small_vector_dump.tar.gz"
extract_dir="$dump_dir/extract_hn_small_vector_dump"
sql_out="data/hn_small_vector_dump.sql"

mkdir -p "$dump_dir"

if [ -s "$sql_out" ]; then
  echo "SQL dump already prepared: $sql_out"
  exit 0
fi

if [ ! -s "$archive" ]; then
  if command -v wget >/dev/null 2>&1; then
    echo "Downloading $dump_url -> $archive"
    wget -c --tries=5 --progress=bar:force:noscroll -O "$archive" "$dump_url"
  elif command -v curl >/dev/null 2>&1; then
    echo "Downloading $dump_url -> $archive"
    curl -fL --retry 5 --retry-delay 2 -C - --progress-bar -o "$archive" "$dump_url"
  else
    echo "ERROR: need wget or curl to download dump" >&2
    exit 1
  fi
else
  echo "Archive already present: $archive"
fi

rm -rf "$extract_dir"
mkdir -p "$extract_dir"

echo "Extracting $archive -> $extract_dir"
tar -xzf "$archive" -C "$extract_dir"

sql_file="$(find "$extract_dir" -maxdepth 2 -type f -name '*.sql' | head -n 1 || true)"
if [ -z "$sql_file" ]; then
  echo "ERROR: no .sql file found in $archive" >&2
  find "$extract_dir" -maxdepth 3 -type f | sed -n '1,50p' >&2 || true
  exit 1
fi

cp -f "$sql_file" "$sql_out"
echo "Prepared SQL dump: $sql_out"

rm -rf "$extract_dir"
rm -f "$archive"
echo "Cleaned up: $extract_dir, $archive"
