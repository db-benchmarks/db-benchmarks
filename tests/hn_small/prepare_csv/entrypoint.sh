#!/bin/bash

#This file is called by docker run (i.e. in a container) from prepare.sh

[ ! -f "/data/downloaded.csv" ] && wget https://zenodo.org/record/45901/files/hacker_news_comments.csv?download=1 -O /data/downloaded.csv
echo "Cleaning";
cat /data/downloaded.csv | tr -cd '\11\12\15\40-\176' > /data/cleaned.csv
echo "Preparing"
csvcut -e utf-8 -l -c 1,4,5,6,7,8,9,10,11 -z 1073741824 /data/cleaned.csv|grep -v author_comment_count|csvformat -U1 -z 1073741824 > /data/data.csv
rm /data/cleaned.csv

