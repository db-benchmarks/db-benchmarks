#!/usr/bin/env bash

init_meilisearch() {
  index="$1"
  header="$2"
  if [ -z "${index}" ]; then
    echo "Usage: $0 index header" >&2
    exit 1
  fi



  count=$(curl -s -X GET "http://localhost:7700/indexes/$index/stats" \
    | jq | grep numberOfDocuments | head -n 1 | cut -d':' -f2 | tr -d ' ,')
  echo "Found ${count:-0} documents in Meilisearch"
  csv_file=./data/data.csv
  final_csv=./data/data_with_header.csv

  # If the index is not empty, probably we loaded the data before
  if (( count > 0 )); then
    echo -e "\tNo need to rebuild"
    exit 1
  fi

  if [ ! -f "../../misc/meilisearch-importer" ]; then
      echo "Error: meilisearch-importer is not located in the misc directory.
      Please download it from https://github.com/meilisearch/meilisearch-importer
      and place the executable into the misc directory"
      exit 1
  fi

if [ ! -f "$final_csv" ]; then
  cp $csv_file $final_csv
fi


  #  head -1 $final_csv|grep -q $header || sed -i "1i$header" "$final_csv"


  echo -en "\tStarting loading into $index at "; date


../../misc/meilisearch-importer \
    --url 'http://localhost:7700' \
    --index $index \
    --files $final_csv \
    --batch-size 90MB


  echo -en "\tFinished loading at "; date
  rm $final_csv
}

meilisearch_has_data() {
  if ls ./data/data.ms/indexes/*/data.mdb 2> /dev/null; then
    return
  fi

  false
}

