#!/usr/bin/env bash

init_meilisearch() {
  index="$1"
  header="$2"
  if [ -z "${index}" ]; then
    echo "Usage: $0 index header [chunk_size=250000]" >&2
    exit 1
  fi

  chunk_size="${3:-250000}"

  pushd ../..
  test="$index" suffix="" docker-compose -f "./docker-compose.yml" --env-file .env stop meilisearch
  test="$index" suffix="" docker-compose -f "./docker-compose.yml" --env-file .env rm -f meilisearch  
  test="$index" suffix="" docker-compose -f "./docker-compose.yml" --env-file .env up -d meilisearch
  popd || exit 1

  count=$(curl -s -X GET "http://localhost:7700/indexes/$index/stats" \
    | jq | grep numberOfDocuments | head -n 1 | cut -d':' -f2 | tr -d ' ,')
  echo "Found ${count:-0} documents in Meilisearch"
  csv_file=./data/data.csv

  # If the index is not empty, probably we loaded the data before
  if (( count > 0 )); then
    echo -e "\tNo need to rebuild"
    exit 1
  fi

  # Remove old split files in case they exist
  ls "$csv_file."????? 2> /dev/null && rm -f $csv_file.?????
  # Split big file into chunks and process one by one with curl
  split_csv "$csv_file" "$chunk_size"

  # Create an array of CSV files in the data directory
  csv_files=($(ls "$csv_file."*))

  for f in "${csv_files[@]}"; do
    head -1 $f|grep -q $header || sed -i "1i$header" "$f"
  done

  echo -en "\tStarting loading into $index at "; date

  # Iterate through the CSV files
  for csv_file in "${csv_files[@]}"; do
    # Process the current CSV file
    insert_data "$csv_file"
  done

  # Wait until the results_length becomes 0 before exiting
  while true; do
    if [ "$(curl -s -X GET "http://localhost:7700/tasks/?limit=10000&statuses=enqueued,processing" | jq '.results|length')" -eq 0 ]; then break; fi

    # Pause for 1 second before the next attempt
    sleep 1
  done
  
  echo -en "\tFinished loading at "; date
}


insert_data() {
  task_id=$(curl -s \
    -X POST "http://localhost:7700/indexes/$index/documents?primaryKey=id" \
    -H 'Content-Type: text/csv' \
    --data-binary @"$1" | jq | grep taskUid | cut -d: -f2 | tr -d ' ,"' )
  printf "\tFile: %s\n" "$1"
  printf "\tTask: %s" "$task_id"
}

meilisearch_has_data() {
  test=$1
  if [[ -z "$test" ]]; then
    >&2 echo 'You must pass index name as first argument'
    false
  fi

  if ls ./data/data.ms/indexes/*/data.mdb 2> /dev/null; then
    return
  fi

  false
}

meilisearch_wait() {
  task_id="$1"
  if [[ -z "$task_id" ]]; then
    >&2 echo "Usage: $0 task_id"
    exit 1
  fi

  # Little helper with map for sleeping while awaitin for task is done
  sleep_map=([0]=1 [1]=3 [2]=5 [3]=8 [4]=16 [5]=30 [6]=60)

  n=0
  prev_status=
  while true; do
    status=$(curl -s -X GET "http://localhost:7700/tasks/$task_id" | jq | grep status | head -n 1 | cut -d: -f2 | tr -d ' ,"')
    if [[ "$prev_status" != "$status" ]]; then
      prev_status="$status"
      printf "\n\t\t%s" "$status"
    fi

    if [[ "$status" == "succeeded" ]]; then
      break
    fi
    echo -n '.'
    sleep "${sleep_map[$n]:-1}"
    n=$(( n + 1 ))
  done

  echo
}

# Helper to split csv with new lines symbols in fields that make it impossilbe
# to simply use split linux command
split_csv() {
  file="$1"
  count="$2"
  if [[ ! -f "$file" && -z "$count" ]]; then
    >&2 echo "Usage: $0 file lines"
    exit 1
  fi

  # Test that fix files is not too old and remove it otherwise
  if [ -f "$file.fix" ] && [ "$(stat -c %Y "$file")" -gt "$(stat -c %Y "$file.fix")" ]; then
    rm -f "$file.fix"
  fi

  if [[ ! -f "$file.fix" ]]; then

    awk '
      BEGIN {
        prev_line = ""
      }

      {
        if ($0 ~ /^"/) {
          if (prev_line != "") {
            print prev_line
          }
          prev_line = $0
        } else {
          prev_line = prev_line "<-nl->" substr($0, 2)
        }
      }

      END {
        if (prev_line != "") {
          print prev_line
        }
      }
    '  "$file" > "$file.fix"
  fi

  split "$file.fix" -a 5 -l "$chunk_size" "$file".
  for chunk in "$file".?????; do
    awk '{gsub(/<-nl->/, "\n"); print}' "$chunk" > "$chunk.fix"
    mv "$chunk.fix" "$chunk"
  done
  rm "$file.fix"
}
