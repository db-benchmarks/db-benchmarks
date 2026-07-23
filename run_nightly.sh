#!/bin/bash

# Script to run nightly Manticoresearch configured tests for both dev and latest versions

DATE=$(date +%Y%m%d)
LOG_DIR="${LOG_DIR:-/var/log/db-benchmarks}"
OVERALL_EXIT=0

# Ensure logs directory exists
if [ ! -d "$LOG_DIR" ]; then
    if ! mkdir -p "$LOG_DIR" 2>/dev/null; then
        sudo mkdir -p "$LOG_DIR"
        sudo chown "$USER:$USER" "$LOG_DIR"
    fi
fi

run_nightly_failure_hook() {
    local tag="$1"
    local status="$2"
    local exit_code="$3"
    local log_file="$4"

    if [ -f local_hooks/nightly_failure_hook.sh ]; then
        NIGHTLY_FAILURE_STATUS="$status" \
        NIGHTLY_FAILURE_TAG="$tag" \
        NIGHTLY_FAILURE_EXIT_CODE="$exit_code" \
        NIGHTLY_FAILURE_LOG="$log_file" \
            source local_hooks/nightly_failure_hook.sh >> "$log_file" 2>&1 || \
            echo "$(date): local_hooks/nightly_failure_hook.sh failed for ${status} ${tag}" >> "$log_file"
    fi
}

# Function to run test and handle logging
run_test() {
    local tag="$1"
    local temp_log="$LOG_DIR/nightly_${tag}_${DATE}_temp.log"
    local final_log="$LOG_DIR/nightly_${tag}_${DATE}.log"
    local failed_log="$LOG_DIR/nightly_${tag}_${DATE}_failed.log"
    local skipped_log="$LOG_DIR/nightly_${tag}_${DATE}_skipped.log"

    echo "$(date): Starting ${tag} tests" >> "$temp_log"
    if [ "$tag" = "dev" ]; then
        ./run_configured_tests.sh -c nightly_config.json >> "$temp_log" 2>&1
    else
        ./run_configured_tests.sh -c nightly_config.json -t "$tag" >> "$temp_log" 2>&1
    fi
    local exit_code=$?

    if [ $exit_code -eq 0 ]; then
        mv "$temp_log" "$final_log"
        echo "$(date): ${tag} tests completed successfully" >> "$final_log"
    elif [ $exit_code -eq 2 ]; then
        if [ "$OVERALL_EXIT" -eq 0 ]; then
            OVERALL_EXIT=2
        fi
        mv "$temp_log" "$skipped_log"
        echo "$(date): ${tag} tests skipped with exit code $exit_code" >> "$skipped_log"
        run_nightly_failure_hook "$tag" "skipped" "$exit_code" "$skipped_log"
    else
        OVERALL_EXIT=1
        mv "$temp_log" "$failed_log"
        echo "$(date): ${tag} tests failed with exit code $exit_code" >> "$failed_log"
        run_nightly_failure_hook "$tag" "failed" "$exit_code" "$failed_log"
    fi
}

#################################### IMPORTANT ####################################
#
# The sequence here is critical because links are sent in local_hooks/nightly.sh.
# If we pull the dev image first, the release version might change before we’ve
# actually run tests, which would result in broken links to non-existent tests.
#
# Therefore, we must run **latest** first, and only then run **dev**.
# (Links are sent only after the dev execution.)
#
###################################################################################

IFS=',' read -r -a NIGHTLY_TAGS <<< "${RUN_NIGHTLY_TAGS:-latest,dev}"
for nightly_tag in "${NIGHTLY_TAGS[@]}"; do
    run_test "$nightly_tag"
done

exit "$OVERALL_EXIT"