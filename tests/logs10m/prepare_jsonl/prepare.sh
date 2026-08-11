#!/bin/bash
test_dir=$(realpath "$(dirname "$0")")
cd "$test_dir"

if [ -f ../data/data.jsonl ] && [ -f ../data/data.csv ]; then
  echo "JSONL and CSV results exist. Skip conversion"
  exit 0
fi

if [ ! -f access.log ]; then
  docker run -it -v $(pwd)/data:/tmp/dump -d --rm --name=python-kaggle python:3.9.19
  docker exec python-kaggle curl -L -o web-server-access-logs.zip  https://www.kaggle.com/api/v1/datasets/download/eliasdabbas/web-server-access-logs
  docker exec python-kaggle unzip web-server-access-logs.zip access.log
  docker exec python-kaggle rm web-server-access-logs.zip
  docker exec python-kaggle mv access.log /tmp/dump
  docker stop python-kaggle
  mv ./data/access.log ./access.log
  rm -rf ./data/
fi

./converter.php
rm ./access.log
