#!/bin/bash
test_dir=$(realpath "$(dirname "$0")")
cd "$test_dir"

# Here we have small hack. We store two files, CSV and TSV, cause manticore can't correct indexing TSV escaping.
# TSV format more convenient for system tools test

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
fi
