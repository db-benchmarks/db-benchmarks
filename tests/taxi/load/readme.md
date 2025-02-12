# How to prepare taxi dataset csv files

The below is a dockerized version of https://github.com/toddwschneider/nyc-taxi-data

```
docker build --no-cache -t taxi .
```

Make sure directory "out" has permissions 777, otherwise postgres from inside docker won't be able to write into it

```
docker run --name taxi --rm -e POSTGRES_PASSWORD= \
-e POSTGRES_USER=root \
-e POSTGRES_HOST_AUTH_METHOD=trust \
-v $(pwd)/postgres:/var/lib/postgresql/data \
-v $(pwd)/data:/nyc-taxi-data/data \
-v $(pwd)/out:/out \
taxi
```

```
docker exec -it taxi bash -c 'cd /nyc-taxi-data/ && ./download_raw_data.sh && ./download_raw_2014_uber_data.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/data && \
wget https://raw.githubusercontent.com/db-benchmarks/nyc-taxi-data/refs/heads/master/data/central_park_weather.csv && \
wget https://raw.githubusercontent.com/db-benchmarks/nyc-taxi-data/refs/heads/master/data/fhv_bases.csv'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/ && ./initialize_database.sh && ./import_yellow_taxi_trip_data.sh && \
./import_green_taxi_trip_data.sh && ./import_fhv_trip_data.sh && \
./import_fhvhv_trip_data.sh && ./import_2014_uber_trip_data.sh'
docker exec -it taxi bash -c 'psql -d nyc-taxi-data -f /sql'
```

After all you should have 87 csv files in `./out`.

## Cleanup
You can now:
* stop the docker (docker stop taxi)
* `rm data/*`
* `rm -fr postgres/*`
* put the csv files from `.out/` to `../data/`
