#!/bin/bash

# Load environment variables from .env file if it exists
if [ -f .env ]; then
  while IFS= read -r line; do
    if [[ $line =~ ^[[:space:]]*([^=]+)=(.*)$ ]]; then
      export "${BASH_REMATCH[1]}"="${BASH_REMATCH[2]}"
    fi
  done < .env
fi

# Nightly Manticoresearch tests script

# Parse command line arguments
TAG="dev"
while getopts "t:" opt; do
  case $opt in
    t) TAG="$OPTARG" ;;
    *) echo "Usage: $0 [-t tag]" >&2; exit 1 ;;
  esac
done

# Validate tag
if [[ "$TAG" != "dev" && "$TAG" != "latest" ]]; then
  script_log "error" "Invalid tag: $TAG. Must be 'dev' or 'latest'."
  exit 1
fi

# Flag to track if any tests were executed
export TESTS_EXECUTED=false

# Removed set -e to allow proper error handling

# Lock file path
LOCK_FILE="/tmp/db_benchmarks.lock"
LOAD_THRESHOLD=0.1  # Low threshold for idle server

# Function to check load
check_load() {
    currentLoad=$(uptime | awk -F'load average:' '{ print $2 }' | awk -F',' '{ print $1 }' | tr -d ' ')
    highLoad=$(echo "$currentLoad > $LOAD_THRESHOLD" | bc)
    if [ "$highLoad" -eq 1 ]; then
        script_log "warning" "Server load ($currentLoad) is above threshold ($LOAD_THRESHOLD). Skipping nightly tests."
        exit 0
    fi
}

# Check for existing lock
if [ -f "$LOCK_FILE" ]; then
    # Read PID from lock file
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        script_log "warning" "Lock file $LOCK_FILE exists and process $LOCK_PID is running. Another benchmark process may be running. Skipping."
        exit 0
    else
        script_log "info" "Removing stale lock file $LOCK_FILE (process $LOCK_PID not running)."
        rm -f "$LOCK_FILE"
    fi
fi

# Check load
check_load

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
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


# Set nightly image based on tag
export MANTICORE_IMAGE=manticoresearch/manticore:$TAG

# Check for jq
if ! command -v jq &> /dev/null; then
  script_log "error" "jq is required but not installed. Please install jq to use this script."
  exit 1
fi

# Configuration file
config_file="nightly_config.json"
if [[ ! -f $config_file ]]; then
  script_log "error" "Configuration file $config_file not found"
  exit 1
fi

# Get unique tests from JSON config
unique_tests=($(jq -r '.tests | keys[]' $config_file))

script_log "info" "Pulling dev image..."
docker pull $MANTICORE_IMAGE

script_log "info" "Getting Manticoresearch version and hash from dev image..."
OUTPUT=$(docker run --rm $MANTICORE_IMAGE searchd --version)
VERSION=$(echo "$OUTPUT" | awk '/Manticore/ {for(i=1;i<=NF;i++) if($i ~ /^[0-9]+\.[0-9]+\.[0-9]+$/) print $i}' | head -1)
HASH=$(echo "$OUTPUT" | awk '{for(i=1;i<=NF;i++) if($i ~ /@/ && $i !~ /columnar/) {split($i,a,"@"); print a[1]; exit}}')
if [ -z "$VERSION" ] || [ -z "$HASH" ]; then
  script_log "error" "Failed to get version or hash"
  exit 1
fi

SHORT_HASH="${HASH:0:5}"

script_log "success" "Version: $VERSION, Hash: $SHORT_HASH"

script_log "success" "Proceeding with tests."

# Process each test
for TEST in "${unique_tests[@]}"; do
  script_log "info" "Checking for existing results for $TEST with version $VERSION and hash $SHORT_HASH..."
  if find ./results/$TEST -path "*/manticoresearch*" -type f -exec grep -l "$VERSION" {} \; | xargs grep -l "$SHORT_HASH" | head -1 | grep -q . 2>/dev/null; then
    script_log "warning" "Results for $TEST with version $VERSION and hash $SHORT_HASH already exist. Skipping $TEST."
    continue
  fi

  script_log "success" "No existing results found for $TEST. Proceeding."
  export TESTS_EXECUTED=true

   # Prepare data for this test
    script_log "info" "Preparing data for $TEST..."
    cd "tests/$TEST"
    if [ -f "./prepare_csv/prepare.sh" ]; then
      ./prepare_csv/prepare.sh
      if [ $? -ne 0 ]; then
        script_log "error" "Couldn't prepare CSV for $TEST"
        cd ../..
        exit 1
      fi
    else
      script_log "warning" "No prepare.sh found for $TEST, skipping prepare.sh."
    fi
    cd ../..

  # Step 1: Down all engines (once per test)
  script_log "info" "Shutting down all engines for $TEST..."
  cd "tests/$TEST"
  suffix="" test=$TEST docker compose down
  cd ../..

  # Get init engines for this test
  init_engines=($(jq -r ".init.\"$TEST\"[]" $config_file))

  # Step 2-3: rm indexes + run init (combined block per engine)
  cd "tests/$TEST"
  for init_engine in "${init_engines[@]}"; do
    # Determine idx_folder for this engine
    if [[ $init_engine == "manticoresearch" ]]; then
      idx_folder="idx"
    elif [[ $init_engine == *:* ]]; then
      idx_folder="idx_${init_engine#*:}"
    else
      idx_folder="idx"  # fallback
    fi

    # Remove idx folder to force re-indexing
    script_log "info" "Removing $idx_folder folder for $TEST engine $init_engine..."
    rm -rf "manticoresearch/$idx_folder"

    # Run init
    script_log "info" "Running init for $TEST with engine $init_engine..."
    if [[ $init_engine == *:* ]]; then
        engine_part=${init_engine%%:*}
        type_part=${init_engine#*:}
        ../../init --test=$TEST --engine=$engine_part --type=$type_part
    else
        ../../init --test=$TEST --engine=$init_engine
    fi
    if [ $? -ne 0 ]; then
      script_log "error" "Init failed for $TEST with engine $init_engine"
      cd ../..
      exit 1
    fi
  done
  cd ../..

  # Step 4: Run test configurations
  configs_json=$(jq -c ".tests.\"$TEST\"[]" $config_file)
  while IFS= read -r config_json; do
    engine=$(jq -r '.engine' <<< "$config_json")
    memory=$(jq -r '.memory' <<< "$config_json")
    query_timeout=$(jq -r '.query_timeout // 30' <<< "$config_json")
    limited=$(jq -r '.limited // false' <<< "$config_json")
    idx_folder=$(jq -r '.idx_folder // "idx"' <<< "$config_json")

    # Determine directory suffix
    suffix=""
    if [[ $engine == *:* ]]; then
      suffix="_${engine#*:}"
    fi
    dir="results/$TEST/manticoresearch$suffix"

    script_log "info" "Running tests for $TEST with engine $engine, memory $memory, dir $dir..."

    # Run tests (no more docker compose down or rm here - already done)
    script_log "info" "Running test command for $TEST..."
    cmd="./test --test=\"$TEST\" --engines=\"$engine\" --memory=\"$memory\" --dir=\"$dir\""
    if [[ $limited == "true" ]]; then
      cmd="$cmd --limited"
    fi
    eval $cmd
  done <<< "$configs_json"
done



# Saving results
script_log "info" "Saving Manticoresearch results to DB..."

./test --save=./results --engine=manticoresearch --host="$NIGHTLY_DB_HOST" --port=443 --username="$NIGHTLY_USER" --password="$NIGHTLY_PASSWORD"

# Source local hook if it exists
if [ -f local_hooks/nightly_hook.sh ]; then
  source local_hooks/nightly_hook.sh
fi

script_log "success" "Nightly tests completed."