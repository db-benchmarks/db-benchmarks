#!/bin/bash

# Nightly Manticoresearch tests script

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
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

# Set nightly image (dev tag for nightly builds)
export MANTICORE_IMAGE=manticoresearch/manticore:dev

# Test to run (limit to hn_small for beginning)
TEST="hn_small"

log "info" "Pulling dev image..."
docker pull $MANTICORE_IMAGE

log "info" "Getting Manticoresearch version and hash from dev image..."
OUTPUT=$(docker run --rm $MANTICORE_IMAGE searchd --version)
VERSION=$(echo "$OUTPUT" | awk '/Manticore/ {for(i=1;i<=NF;i++) if($i ~ /^[0-9]+\.[0-9]+\.[0-9]+$/) print $i}' | head -1)
HASH=$(echo "$OUTPUT" | awk '{for(i=1;i<=NF;i++) if($i ~ /@/ && $i !~ /columnar/) {split($i,a,"@"); print a[1]; exit}}')
if [ -z "$VERSION" ] || [ -z "$HASH" ]; then
  log "error" "Failed to get version or hash"
  exit 1
fi

SHORT_HASH="${HASH:0:5}"

log "success" "Version: $VERSION, Hash: $SHORT_HASH"

log "info" "Checking for existing results with this version and hash..."
if find ./results/$TEST/manticoresearch -type f -exec grep -l "$VERSION" {} \; | xargs grep -l "$SHORT_HASH" | head -1 | grep -q .; then
  log "error" "Results for version $VERSION and hash $SHORT_HASH already exist. Skipping."
  exit 0
fi

log "success" "No existing results found. Proceeding with tests."

log "info" "Preparing data for $TEST..."

cd "tests/$TEST"
./prepare_csv/prepare.sh
if [ $? -ne 0 ]; then
  log "error" "Couldn't prepare CSV"
  exit 1
fi

# Shut down Manticore
log "info" "Shutting down Manticore..."
suffix="" test=$TEST docker-compose down

# Remove idx folder to force re-indexing
log "info" "Removing idx folder..."
rm -rf "manticoresearch/idx"

# Run init again
log "info" "Running init again for Manticoresearch..."
../../init --test=$TEST --engine=manticoresearch
if [ $? -ne 0 ]; then
  log "error" "Init failed with exit code $?"
  exit 1
fi
cd ../..

# Run tests
log "info" "Running tests for $TEST..."
./test --test="$TEST" --engines=manticoresearch --memory=6000 --dir="results/$TEST/manticoresearch" --skip_inaccuracy --probe_timeout=300 --query_timeout=30 --times=5

#./test --test="$TEST" --engines=manticoresearch:columnar --memory=110000 --dir="results/$TEST/manticoresearch"
#./test --test="$TEST" --engines=manticoresearch:rowwise --memory=110000 --dir="results/$TEST/manticoresearch"

log "info" "Saving results to DB..."
#./test --save=./results --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --password="$DB_PASSWORD"

log "success" "Nightly tests completed."