FROM postgis/postgis:13-3.1 as builder
RUN apt update
RUN apt install -y postgis
FROM postgis/postgis:13-3.1
COPY --from=builder /usr/bin/shp2pgsql /usr/bin/shp2pgsql
RUN apt -y update && apt -y install wget git
RUN git clone https://github.com/toddwschneider/nyc-taxi-data
RUN cd nyc-taxi-data/ && git checkout 297c23af4faa83d0fec8559dd1926bede6bf2325
ADD sql /
RUN mkdir /out/ && chown 777 /out
