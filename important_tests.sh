./test --test=taxi --engines=elasticsearch:tuned --memory=110000 --dir=results/taxi/elasticsearch
./test --test=taxi --engines=clickhouse --memory=110000 --dir=results/taxi/clickhouse
./test --test=taxi --engines=manticoresearch:columnar --memory=110000 --dir=results/taxi/manticoresearch

./test --test=hn_small --engines=elasticsearch --memory=110000 --dir=results/hn_small/elasticsearch
./test --test=hn_small --engines=clickhouse --memory=110000 --dir=results/hn_small/clickhouse
./test --test=hn_small --engines=manticoresearch:rowwise --memory=110000 --dir=results/hn_small/manticoresearch
./test --test=hn_small --engines=mysql --memory=110000 --dir=results/hn_small/mysql
./test --test=hn_small --engines=mysql_percona --memory=110000 --dir=results/hn_small/mysql_percona

./test --test=hn_small --engines=elasticsearch --memory=1024 --dir=results/hn_small/elasticsearch
./test --test=hn_small --engines=clickhouse --memory=1024 --dir=results/hn_small/clickhouse
./test --test=hn_small --engines=manticoresearch:rowwise --memory=1024 --dir=results/hn_small/manticoresearch
./test --test=hn_small --engines=mysql --memory=1024 --dir=results/hn_small/mysql
./test --test=hn_small --engines=mysql_percona --memory=1024 --dir=results/hn_small/mysql_percona

./test --test=hn --engines=mysql:tuned --memory=110000 --dir=results/hn/mysql
./test --test=hn --engines=elasticsearch:tuned --memory=110000 --dir=results/hn/elasticsearch
./test --test=hn --engines=clickhouse --memory=110000 --dir=results/hn/clickhouse
./test --test=hn --engines=manticoresearch:columnar --memory=110000 --dir=results/hn/manticoresearch
./test --test=hn --engines=manticoresearch:rowwise --memory=110000 --dir=results/hn/manticoresearch

./test --test=hn --engines=mysql:tuned --memory=1024 --dir=results/hn/mysql
./test --test=hn --engines=elasticsearch:tuned --memory=1024 --dir=results/hn/elasticsearch
./test --test=hn --engines=clickhouse --memory=1024 --dir=results/hn/clickhouse
./test --test=hn --engines=manticoresearch:columnar --memory=1024 --dir=results/hn/manticoresearch
./test --test=hn --engines=manticoresearch:rowwise --memory=1024 --dir=results/hn/manticoresearch

./test --test=logs10m --engines=elasticsearch --memory=110000 --dir=results/logs10m/elasticsearch --query_timeout=600
./test --test=logs10m --engines=elasticsearch:tuned --memory=110000 --dir=results/logs10m/elasticsearch --query_timeout=600
./test --test=logs10m --engines=clickhouse --memory=110000 --dir=results/logs10m/clickhouse --query_timeout=600
./test --test=logs10m --engines=manticoresearch:columnar --memory=110000 --dir=results/logs10m/manticoresearch --query_timeout=600
./test --test=logs10m --engines=manticoresearch:rowwise --memory=110000 --dir=results/logs10m/manticoresearch --query_timeout=600

