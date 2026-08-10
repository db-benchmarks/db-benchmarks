#!/bin/bash

# Load simple KEY=VALUE entries from .env if it exists. Keep this parser
# conservative: skip comments/blank lines and ignore malformed variable names.
if [ -f .env ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    [[ $line =~ ^[[:space:]]*$ ]] && continue
    [[ $line =~ ^[[:space:]]*# ]] && continue
    if [[ $line =~ ^[[:space:]]*export[[:space:]]+(.+)$ ]]; then
      line="${BASH_REMATCH[1]}"
    fi
    if [[ $line =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
      export "${BASH_REMATCH[1]}"="${BASH_REMATCH[2]}"
    fi
  done < .env
fi

TAG="dev"
SKIP_INIT=false
CONFIG_FILE="configs/nightly.json"

usage() {
  echo "Usage: $0 [-c configs/config.json] [-t tag] [-s]" >&2
  echo "  -c configs/config.json: test run config (defaults to configs/nightly.json)" >&2
  echo "  -t tag: Docker tag for configured image templates (dev by default)" >&2
  echo "  -s: skip initialization (reuse existing indexes/data)" >&2
}

while getopts "c:t:s" opt; do
  case $opt in
    c) CONFIG_FILE="$OPTARG" ;;
    t) TAG="$OPTARG" ;;
    s) SKIP_INIT=true ;;
    *) usage; exit 1 ;;
  esac
done

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

script_log() {
    local level="$1"
    local message="$2"

    case "$level" in
        "info")
            echo -e "${YELLOW}$message${NC}"
            ;;
        "success")
            echo -e "${GREEN}$message${NC}"
            ;;
        "error")
            echo -e "${RED}$message${NC}"
            ;;
        "warning")
            echo -e "${BLUE}$message${NC}"
            ;;
        *)
            echo -e "$message"
            ;;
    esac
}

if [[ -z "$TAG" ]]; then
  script_log "error" "Invalid tag: tag cannot be empty."
  exit 1
fi
export TESTS_EXECUTED=false

LOCK_FILE="/tmp/db_benchmarks.lock"
LOAD_THRESHOLD="${LOAD_THRESHOLD:-0.1}"
LOAD_WAIT_STEPS="${LOAD_WAIT_STEPS:-60}"

if ! command -v jq &> /dev/null; then
  script_log "error" "jq is required but not installed. Please install jq to use this script."
  exit 1
fi

if [[ ! -f $CONFIG_FILE ]]; then
  script_log "error" "Configuration file $CONFIG_FILE not found"
  exit 1
fi
CONFIG_FILE="$(cd "$(dirname "$CONFIG_FILE")" && pwd)/$(basename "$CONFIG_FILE")"

setting_bool() {
  local name="$1"
  local default_value="$2"
  jq -r --arg name "$name" --argjson default_value "$default_value" \
    '.settings as $settings | if (($settings | type) == "object" and ($settings | has($name))) then $settings[$name] else $default_value end' \
    "$CONFIG_FILE"
}

setting_value() {
  local name="$1"
  local default_value="$2"
  jq -r --arg name "$name" --arg default_value "$default_value" '.settings[$name] // $default_value' "$CONFIG_FILE"
}

SAVE_RESULTS=$(setting_bool "save" true)
QUIET_TESTS=$(setting_bool "quiet" true)
IMAGE_TEMPLATE=$(setting_value "image" "manticoresearch/manticore:%s")
RESULT_DB_ENGINE=$(setting_value "result_engine" "manticoresearch")
SUCCESS_HOOK=$(setting_value "success_hook" "local_hooks/nightly_hook.sh")
HAS_INIT=false
if jq -e '.init // empty' "$CONFIG_FILE" > /dev/null; then
  HAS_INIT=true
fi
RESULT_DB_ENV_PREFIX="NIGHTLY_DB"
if [ "$HAS_INIT" != true ]; then
  RESULT_DB_ENV_PREFIX="RESULT_DB"
fi

get_current_load() {
    uptime | awk -F'load averages?:' '{ print $2 }' | awk -F',' '{ print $1 }' | awk '{ print $1 }'
}

is_high_load() {
    local current_load="$1"
    awk -v current_load="$current_load" -v threshold="$LOAD_THRESHOLD" 'BEGIN { exit (current_load > threshold ? 0 : 1) }'
}

check_load() {
    script_log "info" "Checking server load..."
    local wait_count=0
    local max_wait="$LOAD_WAIT_STEPS"

    while [ $wait_count -lt $max_wait ]; do
        currentLoad=$(get_current_load)

        if [ -z "$currentLoad" ]; then
            script_log "error" "Could not determine server load from uptime output: $(uptime)"
            exit 1
        fi

        if ! is_high_load "$currentLoad"; then
            script_log "success" "Server load is acceptable ($currentLoad <= $LOAD_THRESHOLD). Proceeding with tests."
            return 0
        fi

        wait_count=$((wait_count + 1))
        script_log "warning" "Server load ($currentLoad) is above threshold ($LOAD_THRESHOLD). Waiting... ($wait_count/$max_wait)"

        if [ $wait_count -lt $max_wait ]; then
            sleep 10
        fi
    done

    script_log "error" "Server load remained high for $max_wait checks. Skipping tests."
    exit 2
}

wait_for_settle() {
    script_log "info" "Waiting for server to settle before starting retest phase..."
    local stable_count=0
    local required_stable=3

    while [ $stable_count -lt $required_stable ]; do
        currentLoad=$(get_current_load)

        if [ -z "$currentLoad" ]; then
            script_log "error" "Could not determine server load from uptime output: $(uptime)"
            exit 1
        fi

        if ! is_high_load "$currentLoad"; then
            stable_count=$((stable_count + 1))
            script_log "info" "Load check $stable_count/$required_stable passed (load: $currentLoad)"
        else
            stable_count=0
            script_log "info" "Load still high ($currentLoad > $LOAD_THRESHOLD), resetting counter"
        fi

        if [ $stable_count -lt $required_stable ]; then
            sleep 30
        fi
    done

    script_log "success" "Server load settled. Starting retest phase..."
}

if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        script_log "warning" "Lock file $LOCK_FILE exists and process $LOCK_PID is running. Another benchmark process may be running. Skipping."
        exit 2
    else
        script_log "info" "Removing stale lock file $LOCK_FILE (process $LOCK_PID not running)."
        rm -f "$LOCK_FILE"
    fi
fi

is_port_listening() {
    local port="$1"

    if command -v lsof &> /dev/null; then
        lsof -nP -iTCP:"$port" -sTCP:LISTEN &> /dev/null
        return $?
    fi

    if command -v ss &> /dev/null; then
        ss -ltn "sport = :$port" | awk 'NR > 1 { found = 1 } END { exit found ? 0 : 1 }'
        return $?
    fi

    if command -v netstat &> /dev/null; then
        netstat -ltn 2> /dev/null | awk -v port="$port" '$4 ~ ":" port "$" { found = 1 } END { exit found ? 0 : 1 }'
        return $?
    fi

    script_log "warning" "Cannot check port $port: lsof, ss, and netstat are unavailable."
    return 1
}

manticore_container_owns_port() {
    local port="$1"
    local container="manticoresearch_engine"

    if ! docker inspect "$container" &> /dev/null; then
        return 1
    fi

    docker port "$container" 2> /dev/null | awk -v port="$port" '
        $0 ~ "127\\.0\\.0\\.1:" port "$" { found = 1 }
        $0 ~ "0\\.0\\.0\\.0:" port "$" { found = 1 }
        $0 ~ "\\[::\\]:" port "$" { found = 1 }
        $0 ~ ":::" port "$" { found = 1 }
        END { exit found ? 0 : 1 }
    '
}

describe_port_listener() {
    local port="$1"

    if command -v lsof &> /dev/null; then
        lsof -nP -iTCP:"$port" -sTCP:LISTEN 2> /dev/null | tail -n +2 | head -1
        return
    fi

    if command -v ss &> /dev/null; then
        ss -ltnp "sport = :$port" 2> /dev/null | tail -n +2 | head -1
        return
    fi

    if command -v netstat &> /dev/null; then
        netstat -ltnp 2> /dev/null | awk -v port="$port" '$4 ~ ":" port "$" { print; exit }'
    fi
}

check_manticore_ports_available() {
    local mysql_port="${MANTICORE_MYSQL_PORT:-9306}"
    local http_port="${MANTICORE_HTTP_PORT:-9308}"
    local port_name
    local port
    local listener

    for port_name in "MANTICORE_MYSQL_PORT:$mysql_port" "MANTICORE_HTTP_PORT:$http_port"; do
        port="${port_name#*:}"

        if ! is_port_listening "$port"; then
            continue
        fi

        if manticore_container_owns_port "$port"; then
            script_log "info" "${port_name%%:*} $port is already used by manticoresearch_engine. Continuing."
            continue
        fi

        listener=$(describe_port_listener "$port")
        script_log "warning" "${port_name%%:*} $port is already listening. Skipping tests."
        if [ -n "$listener" ]; then
            script_log "warning" "Listener: $listener"
        fi
        exit 2
    done
}

if [ "$HAS_INIT" = true ]; then
  check_manticore_ports_available
  check_load
fi

VERSION=""
SHORT_HASH=""
if [ -n "$IMAGE_TEMPLATE" ]; then
  MANTICORE_IMAGE="${IMAGE_TEMPLATE//%s/$TAG}"
  export MANTICORE_IMAGE

  script_log "info" "Pulling $MANTICORE_IMAGE image..."
  if ! docker pull "$MANTICORE_IMAGE"; then
    script_log "error" "Failed to pull $MANTICORE_IMAGE"
    exit 1
  fi

  script_log "info" "Getting version and hash from $MANTICORE_IMAGE image..."
  if ! OUTPUT=$(docker run --rm "$MANTICORE_IMAGE" searchd --version); then
    script_log "error" "Failed to get version from $MANTICORE_IMAGE"
    exit 1
  fi
  VERSION=$(echo "$OUTPUT" | awk '/Manticore/ {for(i=1;i<=NF;i++) if($i ~ /^[0-9]+\.[0-9]+\.[0-9]+$/) print $i}' | head -1)
  HASH=$(echo "$OUTPUT" | awk '{for(i=1;i<=NF;i++) if($i ~ /@/ && $i !~ /columnar/) {split($i,a,"@"); print a[1]; exit}}')
  if [ -z "$VERSION" ] || [ -z "$HASH" ]; then
    script_log "error" "Failed to get version or hash"
    exit 1
  fi
  SHORT_HASH="${HASH:0:5}"
  script_log "success" "Version: $VERSION, Hash: $SHORT_HASH"
fi

unique_tests=($(jq -r '.tests | keys_unsorted[]' "$CONFIG_FILE"))

get_result_dir() {
  local test_name="$1"
  local config_json="$2"
  local include_type_suffix="$3"
  local engine_name
  local engine_base
  local result_root="results"
  local suffix=""

  if [ "$include_type_suffix" = true ]; then
    result_root="results/nightly"
  fi

  engine_name=$(jq -r '.engine' <<< "$config_json")
  engine_base="${engine_name%%:*}"
  if [ "$include_type_suffix" = true ] && [[ $engine_name == *:* ]]; then
    suffix="_${engine_name#*:}"
  fi
  echo "$result_root/$test_name/$engine_base$suffix"
}

results_exist() {
  local test_name="$1"
  local config_json="$2"
  local retest_only="$3"
  local include_type_suffix="$4"
  local dir
  local file
  local memory
  local limited
  local result_name_pattern

  if [ -z "$VERSION" ] || [ -z "$SHORT_HASH" ]; then
    return 1
  fi

  memory=$(jq -r '.memory' <<< "$config_json")
  limited=$(jq -r '.limited // false' <<< "$config_json")
  if [ "$limited" = true ]; then
    result_name_pattern="_${memory}_limited(_retest)?$"
  else
    result_name_pattern="_${memory}(_retest)?$"
  fi

  dir=$(get_result_dir "$test_name" "$config_json" "$include_type_suffix")
  if [ ! -d "./$dir" ]; then
    return 1
  fi

  while IFS= read -r file; do
    if [[ ! $(basename "$file") =~ $result_name_pattern ]]; then
      continue
    fi
    if grep -q "$VERSION" "$file" && grep -q "$SHORT_HASH" "$file"; then
      return 0
    fi
  done < <(
    if [ "$retest_only" = true ]; then
      find "./$dir" -name "*_retest*" -type f
    else
      find "./$dir" ! -name "*_retest*" -type f
    fi
  )

  return 1
}

test_has_missing_results() {
  local test_name="$1"
  local retest_only="$2"
  local configs_json
  local config_json

  configs_json=$(jq -c --arg test_name "$test_name" '.tests[$test_name][]' "$CONFIG_FILE")
  while IFS= read -r config_json; do
    if ! results_exist "$test_name" "$config_json" "$retest_only" true; then
      return 0
    fi
  done <<< "$configs_json"

  return 1
}

test_has_missing_run_results() {
  local test_name="$1"

  test_has_missing_results "$test_name" false || test_has_missing_results "$test_name" true
}

run_test_config() {
  local test_name="$1"
  local config_json="$2"
  local mode_flag="$3"
  local include_type_suffix="$4"
  local engine
  local memory
  local query_timeout
  local limited
  local dir
  local cmd

  engine=$(jq -r '.engine' <<< "$config_json")
  memory=$(jq -r '.memory' <<< "$config_json")
  query_timeout=$(jq -r '.query_timeout // empty' <<< "$config_json")
  limited=$(jq -r '.limited // false' <<< "$config_json")
  dir=$(get_result_dir "$test_name" "$config_json" "$include_type_suffix")

  script_log "info" "Running $test_name with engine $engine, memory $memory, query timeout ${query_timeout:-default}, dir $dir..."

  cmd=(./test --test="$test_name" --engines="$engine" --memory="$memory" --dir="$dir")
  if [ -n "$query_timeout" ]; then
    cmd+=(--query_timeout="$query_timeout")
  fi
  if [ "$QUIET_TESTS" = true ]; then
    cmd+=(--quiet)
  fi
  if [ -n "$mode_flag" ]; then
    cmd+=("$mode_flag")
  fi
  if [[ $limited == "true" ]]; then
    cmd+=(--limited)
  fi

  if ! "${cmd[@]}"; then
    script_log "error" "Test command failed for $test_name with engine $engine, memory $memory"
    exit 1
  fi
}

script_log "success" "Proceeding with tests from $CONFIG_FILE."

if [ "$HAS_INIT" != true ]; then
  for TEST in "${unique_tests[@]}"; do
    configs_json=$(jq -c --arg test_name "$TEST" '.tests[$test_name][]' "$CONFIG_FILE")
    if [ -n "$configs_json" ]; then
      while IFS= read -r config_json; do
        export TESTS_EXECUTED=true
        run_test_config "$TEST" "$config_json" "" false
      done <<< "$configs_json"
    fi
  done
else
  for TEST in "${unique_tests[@]}"; do
  if ! test_has_missing_run_results "$TEST"; then
    script_log "warning" "First-run and retest results for all $TEST configs with version $VERSION and hash $SHORT_HASH already exist. Skipping $TEST."
    continue
  fi

  script_log "success" "Running configured tests for $TEST."

  script_log "info" "Shutting down all engines for $TEST..."
  cd "tests/$TEST"
  if ! suffix="" test=$TEST docker compose down; then
    script_log "error" "Docker compose down failed for $TEST"
    cd ../..
    exit 1
  fi
  cd ../..

  if jq -e --arg test_name "$TEST" '.init[$test_name] // empty' "$CONFIG_FILE" > /dev/null; then
    if [ "$SKIP_INIT" = true ]; then
      script_log "info" "Skipping initialization for $TEST (--skip-init flag enabled)"
    else
      cd "tests/$TEST"
      script_log "info" "Preparing data for $TEST before init..."
      if [ -f "./prepare_sql/prepare.sh" ]; then
        ./prepare_sql/prepare.sh
        if [ $? -ne 0 ]; then
          script_log "error" "Couldn't prepare SQL for $TEST"
          cd ../..
          exit 1
        fi
      elif [ -f "./prepare_jsonl/prepare.sh" ]; then
        ./prepare_jsonl/prepare.sh
        if [ $? -ne 0 ]; then
          script_log "error" "Couldn't prepare JSONL for $TEST"
          cd ../..
          exit 1
        fi
      elif [ -f "./prepare_csv/prepare.sh" ]; then
        ./prepare_csv/prepare.sh
        if [ $? -ne 0 ]; then
          script_log "error" "Couldn't prepare CSV for $TEST"
          cd ../..
          exit 1
        fi
      else
        script_log "warning" "No prepare.sh found for $TEST, skipping prepare step."
      fi

      init_engines=($(jq -r --arg test_name "$TEST" '.init[$test_name][]' "$CONFIG_FILE"))
      for init_engine in "${init_engines[@]}"; do
        script_log "info" "Running init for $TEST with engine $init_engine..."
        if [[ $init_engine == *:* ]]; then
            engine_part=${init_engine%%:*}
            type_part=${init_engine#*:}
            RESULTS_ROOT="../../results/nightly" ../../init --test=$TEST --engine=$engine_part --type=$type_part
        else
            RESULTS_ROOT="../../results/nightly" ../../init --test=$TEST --engine=$init_engine
        fi
        if [ $? -ne 0 ]; then
          script_log "error" "Init failed for $TEST with engine $init_engine"
          cd ../..
          exit 1
        fi
      done
      cd ../..
    fi
  fi

  configs_json=$(jq -c --arg test_name "$TEST" '.tests[$test_name][]' "$CONFIG_FILE")
  while IFS= read -r config_json; do
    if results_exist "$TEST" "$config_json" false true; then
      script_log "warning" "Initial result for $TEST config $config_json with version $VERSION and hash $SHORT_HASH already exists. Skipping."
      continue
    fi
    export TESTS_EXECUTED=true
    run_test_config "$TEST" "$config_json" "--no-retest" true
  done <<< "$configs_json"
  done

  wait_for_settle

  script_log "info" "Starting retest phase for all tests..."
  for TEST in "${unique_tests[@]}"; do
    if ! test_has_missing_results "$TEST" true; then
      script_log "warning" "Retest results for all $TEST configs with version $VERSION and hash $SHORT_HASH already exist. Skipping retests for $TEST."
      continue
    fi

    script_log "success" "Running retests for $TEST."

    configs_json=$(jq -c --arg test_name "$TEST" '.tests[$test_name][]' "$CONFIG_FILE")
    while IFS= read -r config_json; do
      if results_exist "$TEST" "$config_json" true true; then
        script_log "warning" "Retest result for $TEST config $config_json with version $VERSION and hash $SHORT_HASH already exists. Skipping."
        continue
      fi
      export TESTS_EXECUTED=true
      run_test_config "$TEST" "$config_json" "--retest-only" true
    done <<< "$configs_json"
  done
fi

if [ "$TESTS_EXECUTED" != true ]; then
  script_log "success" "No new tests or retests were executed; all configured results already exist."
  exit 0
fi

if [ "$SAVE_RESULTS" = true ]; then
  script_log "info" "Saving results to DB..."

  result_db_host_var="${RESULT_DB_ENV_PREFIX}_HOST"
  result_db_user_var="${RESULT_DB_ENV_PREFIX}_USER"
  result_db_password_var="${RESULT_DB_ENV_PREFIX}_PASSWORD"

  RESULT_DB_HOST="${!result_db_host_var:-}"
  RESULT_DB_USER="${!result_db_user_var:-}"
  RESULT_DB_PASSWORD="${!result_db_password_var:-}"

  if [ -z "$RESULT_DB_HOST" ] || [ -z "$RESULT_DB_USER" ] || [ -z "$RESULT_DB_PASSWORD" ]; then
    script_log "error" "Result DB connection is not configured. Set ${RESULT_DB_ENV_PREFIX}_HOST, ${RESULT_DB_ENV_PREFIX}_USER and ${RESULT_DB_ENV_PREFIX}_PASSWORD."
    exit 1
  fi

  save_results_path() {
    local save_path="$1"
    local save_cmd

    if [ ! -e "$save_path" ]; then
      script_log "warning" "Result path $save_path does not exist, skipping save."
      return 0
    fi

    save_cmd=(./test --save="$save_path" --host="$RESULT_DB_HOST" --port=443 --username="$RESULT_DB_USER" --password="$RESULT_DB_PASSWORD")
    if [ -n "$RESULT_DB_ENGINE" ]; then
      save_cmd+=(--engine="$RESULT_DB_ENGINE")
    fi

    if ! "${save_cmd[@]}"; then
      script_log "error" "Saving results from $save_path to DB failed. Stopping flow."
      exit 1
    fi
  }

  if [ "$HAS_INIT" != true ]; then
    for save_path in ./results/*; do
      if [ ! -e "$save_path" ] || [ "$save_path" = "./results/nightly" ]; then
        continue
      fi
      save_results_path "$save_path"
    done
  else
    save_results_path ./results/nightly
  fi
fi

if [ -n "$SUCCESS_HOOK" ] && [ -f "$SUCCESS_HOOK" ]; then
  source "$SUCCESS_HOOK"
fi

script_log "success" "Configured tests completed."
