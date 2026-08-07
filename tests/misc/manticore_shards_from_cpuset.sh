#!/usr/bin/env bash
set -euo pipefail

env_file=${1:-../../.env}

cpuset_value=""
if [ -f "$env_file" ]; then
  cpuset_value=$(grep -E '^[[:space:]]*cpuset=' "$env_file" | tail -1 | cut -d= -f2- || true)
  cpuset_value=${cpuset_value%%#*}
  cpuset_value=${cpuset_value//[[:space:]\"\']/}
fi

if [ -z "$cpuset_value" ]; then
  getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.ncpu
  exit 0
fi

count=0
IFS=',' read -ra parts <<< "$cpuset_value"
for part in "${parts[@]}"; do
  [ -z "$part" ] && continue

  if [[ $part =~ ^([0-9]+)-([0-9]+)$ ]]; then
    start=${BASH_REMATCH[1]}
    end=${BASH_REMATCH[2]}
    if [ "$end" -lt "$start" ]; then
      echo "Invalid cpuset range: $part" >&2
      exit 1
    fi
    count=$((count + end - start + 1))
  elif [[ $part =~ ^[0-9]+$ ]]; then
    count=$((count + 1))
  else
    echo "Invalid cpuset value: $cpuset_value" >&2
    exit 1
  fi
done

if [ "$count" -lt 1 ]; then
  echo "Invalid cpuset value: $cpuset_value" >&2
  exit 1
fi

echo "$count"
