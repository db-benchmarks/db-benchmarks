FROM postgis/postgis:13-3.1 as builder
RUN apt update
RUN apt install -y postgis
FROM postgis/postgis:13-3.1
COPY --from=builder /usr/bin/shp2pgsql /usr/bin/shp2pgsql
ENV TZ=UTC
RUN apt -y update && apt -y install wget git r-base libxml2-dev libssl-dev \
    libcurl4-openssl-dev libfontconfig1-dev libharfbuzz-dev libfribidi-dev libfreetype6-dev \
    libpng-dev libtiff5-dev libjpeg-dev zlib1g-dev curl gzip libz-dev
RUN echo "TZ=$( cat /etc/timezone )" >> /etc/R/Renviron.site
RUN git clone https://github.com/db-benchmarks/nyc-taxi-data
ADD sql /
RUN mkdir /out/ && chown 777 /out
