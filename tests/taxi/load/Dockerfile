FROM postgis/postgis:13-3.1 as builder
RUN apt update
RUN apt install -y postgis
FROM postgis/postgis:13-3.1
COPY --from=builder /usr/bin/shp2pgsql /usr/bin/shp2pgsql
RUN apt -y update && apt -y install wget git
RUN git clone https://github.com/toddwschneider/nyc-taxi-data
RUN cd nyc-taxi-data/ && git checkout 297c23af4faa83d0fec8559dd1926bede6bf2325
RUN sed -i 's|https://s3.amazonaws.com/nyc-tlc/trip+data/\([^ ]*\)\.csv|https://d37ci6vzurychx.cloudfront.net/trip-data/\1.parquet|g' nyc-taxi-data/setup_files/raw_data_urls.txt
ADD sql /
RUN mkdir /out/ && chown 777 /out
