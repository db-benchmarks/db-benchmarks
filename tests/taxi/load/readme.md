# How to prepare taxi dataset csv files

The below is a dockerized version of https://github.com/toddwschneider/nyc-taxi-data

```
docker build -t taxi .
```

Make sure directory "out" has permissions 777, otherwise postgres from inside docker won't be able to write into it

```
docker run --name taxi --rm -e POSTGRES_PASSWORD= -e POSTGRES_USER=root -e POSTGRES_HOST_AUTH_METHOD=trust -v $(pwd)/postgres:/var/lib/postgresql/data -v $(pwd)/data:/nyc-taxi-data/data -v $(pwd)/out:/out taxi
```

```
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./download_raw_data.sh && ./remove_bad_rows.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./initialize_database.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./import_trip_data.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./import_fhv_trip_data.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./download_raw_2014_uber_data.sh'
docker exec -it taxi bash -c 'cd /nyc-taxi-data/; ./import_2014_uber_trip_data.sh'
docker exec -it taxi bash -c 'psql -d nyc-taxi-data -f /sql'
```

After all you should have 87 csv files in `./out`.

## Cleanup
You can now:
* stop the docker (docker stop taxi)
* `rm data/*`
* `rm -fr postgres/*`
* put the csv files from `.out/` to `../data/`
