#!/bin/bash
test_dir=$(realpath "$(dirname "$0")")
cd "$test_dir"

if [ ! -f ../data/data.csv ]; then
  if [ ! -f access.log ]; then
    # see https://github.com/Kaggle/kaggle-api
    kaggle datasets download -d eliasdabbas/web-server-access-logs 
#    the below fails (permissions issue), that's why we have to do the above
#    wget -O dump.zip https://dataverse.harvard.edu/api/access/datafile/:persistentId?persistentId=doi:10.7910/DVN/3QBYB5/NXKB6J
    unzip web-server-access-logs.zip access.log
    rm web-server-access-logs.zip
  fi

  ./converter.php
  rm access.log
  egrep -v "\"2222903\"|\"2537971\"|\"4831596\"" ../data/data.csv > ../data/data.prepared.csv
  mv ../data/data.prepared.csv ../data/data.csv
fi
