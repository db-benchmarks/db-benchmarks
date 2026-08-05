#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 HOST PORT CONFIG_DIR [TEMPLATE_DIR|-] [NORMALIZE_FUNCTION] [ID_KEY]" >&2
  exit 1
fi

host="$1"
port="$2"
fluent_bit_dir="$3"
template_dir="${4:--}"
normalize_function="${5:-}"
id_key="${6:-}"
pipeline=""
path=$(pwd)

if [[ -z "${test:-}" ]]; then
  echo "test environment variable is required" >&2
  exit 1
fi

if [[ ! -f "$fluent_bit_dir/fluent-bit.conf" ]]; then
  echo "Missing Fluent Bit config: $fluent_bit_dir/fluent-bit.conf" >&2
  exit 1
fi

parser_file="$fluent_bit_dir/fluent-bit-parsers.conf"
if [[ ! -f "$parser_file" ]]; then
  parser_file="$path/../misc/fluent-bit/fluent-bit-parsers.conf"
fi

if [[ "$host" == "elasticsearch" ]]; then
  pipeline="${test}_fluent_bit_cleanup"
fi

workers=$(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || nproc)
config=$(mktemp)
log=$(mktemp)
trap 'rm -f "$log" "$config"' EXIT

while IFS= read -r line; do
  line=${line//__WORKERS__/$workers}
  line=${line//__HOST__/$host}
  line=${line//__PORT__/$port}
  line=${line//__NORMALIZE_FUNCTION__/$normalize_function}
  line=${line//__ID_KEY__/$id_key}
  line=${line//__PIPELINE__/$pipeline}
  if [[ -z "$pipeline" && "$line" == *'Pipeline'* ]]; then
    continue
  fi
  printf '%s\n' "$line"
done < "$fluent_bit_dir/fluent-bit.conf" > "$config"

if [[ "$template_dir" != "-" ]]; then
  if [[ ! -f "$template_dir/template.json" ]]; then
    echo "Missing Elasticsearch template: $template_dir/template.json" >&2
    exit 1
  fi

  curl -fsS -X PUT "http://localhost:9200/_index_template/$test" \
    -H 'Content-Type: application/json' \
    --data-binary "@$template_dir/template.json" >/dev/null

  curl -fsS -X PUT "http://localhost:9200/_ingest/pipeline/$pipeline" \
    -H 'Content-Type: application/json' \
    --data-binary @- >/dev/null <<'JSON'
{
  "processors": [
    {"remove": {"field": "@timestamp", "ignore_missing": true}},
    {"remove": {"field": "id", "ignore_missing": true}}
  ]
}
JSON
fi

manticore_table_type() {
  docker exec manticoresearch_engine mysql -h127.0.0.1 -P9306 -N -B \
    -e "SHOW TABLES" 2>/dev/null | awk -v table="$test" '($1 == table) { print $2; exit } ($2 == table) { print $4; exit }'
}

alter_manticore_column() {
  local table="$1"
  local action="$2"

  if [[ "$action" == "ADD" ]]; then
    docker exec manticoresearch_engine mysql -h127.0.0.1 -P9306 \
      -e "ALTER TABLE $table ADD COLUMN \`@timestamp\` string" >/dev/null 2>&1 || true
  else
    docker exec manticoresearch_engine mysql -h127.0.0.1 -P9306 \
      -e "ALTER TABLE $table DROP COLUMN \`@timestamp\`" >/dev/null 2>&1 || true
  fi
}

for_manticore_tables() {
  local action="$1"
  local table_type

  table_type=$(manticore_table_type)
  if [[ "$table_type" == "shard" ]]; then
    for i in $(seq 0 1023); do
      if ! docker exec manticoresearch_engine mysql -h127.0.0.1 -P9306 \
        -e "DESC system.${test}_s$i" >/dev/null 2>&1; then
        break
      fi

      alter_manticore_column "system.${test}_s$i" "$action"
    done
  else
    alter_manticore_column "$test" "$action"
  fi
}

if [[ "$host" == "manticoresearch" ]]; then
  for_manticore_tables ADD
fi

# We use docker compose here because it usually has the newer JSON-capable version.
network_name=$(docker compose -f ../../docker-compose.yml --env-file ../../.env config --format=json | jq -r .networks.default.name)
cmd=(
  docker run
  --env-file ../../.env
  --network="$network_name"
  --rm
  -v "$path/data/:/data/"
  -v "$config:/fluent-bit/etc/fluent-bit.conf:ro"
)

if [[ -f "$parser_file" ]]; then
  cmd+=( -v "$parser_file:/fluent-bit/etc/fluent-bit-parsers.conf:ro" )
fi

cmd+=(
  -v "$path/../misc/fluent-bit/parse.lua:/fluent-bit/etc/parse.lua:ro"
  fluent/fluent-bit:3.2
)

fluent_bit_error_pattern='\[error\]|HTTP status=[45][0-9][0-9]|could not flush|failed to flush|retry limit|Broken pipe|out of memory|cannot allocate memory'

set +e
"${cmd[@]}" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}
set -e

if [[ "$host" == "manticoresearch" ]]; then
  for_manticore_tables DROP
fi

if [[ $status -ne 0 ]]; then
  exit "$status"
fi

if grep -qEi "$fluent_bit_error_pattern" "$log"; then
  echo -e "\tFluent Bit reported output errors; failing ingestion before post_hook."
  grep -Ei "$fluent_bit_error_pattern" "$log" | tail -20 >&2
  exit 1
fi
