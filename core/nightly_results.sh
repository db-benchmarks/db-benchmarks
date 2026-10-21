#!/usr/bin/env bash

NIGHTLY_RESULT_MATCHER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/nightly_result_matches.php"

# Expects VERSION and SHORT_HASH from the image selected by nightly_manticore.sh.
has_config_result() {
  local test_name="$1" config="$2" phase="$3"
  local engine memory limited type="" suffix="" filename file
  engine=$(jq -r '.engine' <<< "$config")
  memory=$(jq -r '.memory' <<< "$config")
  limited=$(jq -r '.limited // false' <<< "$config")
  if [[ $engine == *:* ]]; then
    type=${engine#*:}
    suffix="_$type"
  fi
  filename="${test_name}_manticoresearch_${type}_${memory}"
  [[ $limited == true ]] && filename+="_limited"
  [[ $phase == retest ]] && filename+="_retest"
  # Older runs stored variants under manticoresearch; current runs use a type suffix.
  for file in "results/$test_name/manticoresearch$suffix"/*/"$filename" \
              "results/$test_name/manticoresearch"/*/"$filename"; do
    if [[ -f $file ]] && php "$NIGHTLY_RESULT_MATCHER" "$file" "$test_name" "$type" \
      "$memory" "$limited" "$VERSION" "$SHORT_HASH" "$phase"; then
      return 0
    fi
  done
  return 1
}
