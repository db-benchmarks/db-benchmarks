#!/usr/bin/env bash

init_meilisearch() {
  index="$1"
  header="$2"
  if [ -z "${index}" ]; then
    echo "Usage: $0 index header [chunk_size=250000]" >&2
    exit 1
  fi

  chunk_size="${3:-250000}"

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
    rm $csv_file;
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
  printf "\tFile: %s. " "$1"
  task_id=$(curl -s \
    -X POST "http://localhost:7700/indexes/$index/documents?primaryKey=id" \
    -H 'Content-Type: text/csv' \
    --data-binary @"$1" | jq | grep taskUid | cut -d: -f2 | tr -d ' ,"' )
  printf "Task: %s\n" "$task_id"
}

meilisearch_has_data() {
  if ls ./data/data.ms/indexes/*/data.mdb 2> /dev/null; then
    return
  fi

  false
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
        if ($0 ~ /^"[0-9]/) {
          if (prev_line != "") {
            print prev_line
          }
          prev_line = $0
        } else {
          prev_line = prev_line "<-nl->" substr($0, 1)
        }
      }

      END {
        if (prev_line != "") {
          print prev_line
        }
      }
    '  "$file" > "$file.fix"
  fi

  split -a 5 -l "$chunk_size" "$file.fix" "${file}."
  for chunk in "$file".?????; do
    awk '{gsub(/<-nl->/, "\n"); print}' "$chunk" > "$chunk.fix"
    mv "$chunk.fix" "$chunk"
  done
  rm "$file.fix"
}
