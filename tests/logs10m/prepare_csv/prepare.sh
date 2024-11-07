#!/bin/bash
test_dir=$(realpath "$(dirname "$0")")
cd "$test_dir"

if [ ! -f ../data/data.csv ]; then
  if [ ! -f access.log ]; then
    docker run -it -v $(pwd)/data:/tmp/dump -d --rm --name=python-kaggle python:3.9.19
    docker exec python-kaggle pip install kaggle
    # see https://github.com/Kaggle/kaggle-api
    docker exec python-kaggle kaggle datasets download -d eliasdabbas/web-server-access-logs
#    the below fails (permissions issue), that's why we have to do the above
#    wget -O dump.zip https://dataverse.harvard.edu/api/access/datafile/:persistentId?persistentId=doi:10.7910/DVN/3QBYB5/NXKB6J
    docker exec python-kaggle unzip web-server-access-logs.zip access.log
    docker exec python-kaggle rm web-server-access-logs.zip
    docker exec python-kaggle mv access.log /tmp/dump
    docker stop python-kaggle
    mv ./data/access.log ./access.log
    rm -rf ./data/

    ./converter.php
    rm ./access.log
  fi


  egrep -v "\"2222903\"|\"2537971\"|\"4831596\"" ../data/data.csv > ../data/data.prepared.csv
  mv ../data/data.prepared.csv ../data/data.csv
fi
