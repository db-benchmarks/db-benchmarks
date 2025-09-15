#!/bin/bash

# Script to run nightly Manticoresearch tests for both dev and latest versions

DATE=$(date +%Y%m%d)
LOG_DIR="/var/log/db-benchmarks"

# Ensure logs directory exists
sudo mkdir -p "$LOG_DIR"
sudo chown $USER:$USER "$LOG_DIR"

# Function to run test and handle logging
run_test() {
    local tag="$1"
    local temp_log="$LOG_DIR/nightly_${tag}_${DATE}_temp.log"
    local final_log="$LOG_DIR/nightly_${tag}_${DATE}.log"
    local failed_log="$LOG_DIR/nightly_${tag}_${DATE}_failed.log"

    echo "$(date): Starting ${tag} tests" >> "$temp_log"
    if [ "$tag" = "dev" ]; then
        ./nightly_manticore.sh >> "$temp_log" 2>&1
    else
        ./nightly_manticore.sh -t "$tag" >> "$temp_log" 2>&1
    fi
    local exit_code=$?

    if [ $exit_code -eq 0 ]; then
        mv "$temp_log" "$final_log"
        echo "$(date): ${tag} tests completed successfully" >> "$final_log"
    else
        mv "$temp_log" "$failed_log"
        echo "$(date): ${tag} tests failed with exit code $exit_code" >> "$failed_log"
    fi
}

# Run dev version
run_test "dev"

# Run latest version
run_test "latest"