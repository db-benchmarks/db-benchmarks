echo -e "\tRunning forcemerge"
curl -XPOST "localhost:9200/$test/_forcemerge?max_num_segments=1"
