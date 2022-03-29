# This file is to run manually or by a parent script (not by docker as this is the file which is to run docker build and docker run)

cd "$(dirname "$0")"
[ -f "../data/data.csv" ] && echo "The csv is already prepared, no need to rebuild" && exit 0
docker build --force-rm -t test_engines_hn_prepare_csv -f Dockerfile_prepare_csv . \
&& docker run -v `pwd`/../data:/data test_engines_hn_prepare_csv

