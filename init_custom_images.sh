#!/usr/bin/env bash
set -euo pipefail

config_file="configs/nightly.json"
config_file="$(cd "$(dirname "$config_file")" && pwd)/$(basename "$config_file")"
if ! jq -e '
    . as $config | (.custom_images // []) as $images |
    ($images | type) == "array" and ($images | length) > 0 and all($images[];
        type == "object" and (.image | type == "string" and length > 0)
        and (.tests | type == "array" and length > 0)
        and all(.tests[]; . as $name | type == "string" and ($config.init[$name] | type) == "array" and ($config.tests[$name] | type) == "array")
    )
' "$config_file" >/dev/null; then
    echo "No valid custom images in $config_file" >&2
    exit 1
fi

if [ -f /tmp/db_benchmarks.lock ]; then
    lock_pid=$(cat /tmp/db_benchmarks.lock)
    if [ -n "$lock_pid" ] && kill -0 "$lock_pid" 2>/dev/null; then
        echo "Benchmark process $lock_pid is running; cannot initialize custom images" >&2
        exit 2
    fi
fi

while IFS= read -r entry; do
    image=$(jq -r '.image' <<< "$entry")
    image_tag="${image##*:}"
    image_tag="${image_tag//[^a-zA-Z0-9_.-]/_}"
    export MANTICORE_IMAGE="$image"
    export MANTICORE_CUSTOM_IMAGE="$image"
    export MANTICORE_CUSTOM_IMAGE_SUFFIX="_custom_${image_tag}"

    echo "Pulling $image before initialization..."
    docker pull "$image"
    if [[ "$image_tag" == test-kit-* ]]; then
        # Testkit reports 0.0.0, although it supports modern config options.
        export MANTICORE_REPORTED_VERSION=17.0.0
    else
        unset MANTICORE_REPORTED_VERSION
    fi

    while IFS= read -r suite; do
        echo "Initializing $suite with $image..."
        (
            set -e
            cd "tests/$suite"
            test="$suite" suffix="" docker compose down
            for prepare in prepare_sql/prepare.sh prepare_data/prepare.sh prepare_jsonl/prepare.sh prepare_csv/prepare.sh; do
                if [ -f "$prepare" ]; then
                    "./$prepare"
                    break
                fi
            done
            while IFS= read -r engine_type; do
                engine="${engine_type%%:*}"
                if [[ "$engine_type" == *:* ]]; then
                    type="${engine_type#*:}"
                    RESULTS_ROOT="../../results/nightly" ../../init --test="$suite" --engine="$engine" --type="$type"
                else
                    RESULTS_ROOT="../../results/nightly" ../../init --test="$suite" --engine="$engine"
                fi
            done < <(jq -r --arg suite "$suite" '.init[$suite][]' "$config_file")
        )
    done < <(jq -r '.tests[]' <<< "$entry")
done < <(jq -c '.custom_images[]' "$config_file")
echo "Custom image initialization completed."
