<p align="center">
  <a href="https://db-benchmarks.com" target="_blank" rel="noopener">
    <img id="intro" src="./logo.svg" width="50%" alt="db-benchmarks logo" style="color: white">
  </a>
</p>

<h3 align="center">
  <a href="https://db-benchmarks.com">📊 Benchmarks</a> •
  <a href="#introduction">Intro</a> •
  <a href="#why-is-this-important">Why this is important</a> •
  <a href="#test-framework">Features</a> •
  <a href="#testing-principles">Testing principles</a> •
  <a href="#installation">Installation</a> •
  <a href="https://github.com/db-benchmarks/ui/#intro">UI</a>
</h3>

<p>&nbsp;</p>

<!-- about -->
## Introduction

https://db-benchmarks.com aims to make database and search engines benchmarks:

⚖️ **Fair and transparent** - it should be clear under what conditions this or that database / search engine gives this or that performance

🚀 **High quality** - control over coefficient of variation allows producing results that remain the same if you run a query today, tomorrow or next week

👪 **Easily reproducible** - anyone can reproduce any test on their own hardware

📊 **Easy to understand** - the charts are very simple

➕ **Extendable** - pluggable architecture allows adding more databases to test

And keep it all **100% open source!**

This repository provides a test framework which does the job.

## Why is this important?

Many database benchmarks are not objective, others jsut don't care about results accuracy and stability which in some cases breaks the whole idea of benchmarks. Few examples:

### Druid vs ClickHouse vs Rockset

https://imply.io/blog/druid-nails-cost-efficiency-challenge-against-clickhouse-and-rockset/ :

> We actually wanted to do the benchmark on the same hardware, an m5.8xlarge, but the only pre-baked configuration we have for m5.8xlarge is actually the m5d.8xlarge ... Instead, we run on a c5.9xlarge instance

Bad news, guys: when you run benchmarks on different hardware, at the very least you can't then say that something is "106.76%" and "103.13%" of something else. Even when you test on the same bare-metal server it's quite difficult to get coefficient of variation lower than 5%. 3% difference on different servers can be highly likley ignored. Provided all that, how can one make sure the final conclusion is true?

-------

### Lots of databases and engines

https://tech.marksblogg.com/benchmarks.html

Mark did a great job making the taxi rides test on so many different databases and search engines. But since the tests are made on different hardware the numbers in the resulting table don't make much sense to compare one with another. You always need to remember about it when you evaluate the results in the table.

-------

### ClickHouse vs others

https://clickhouse.com/benchmark/dbms/

When you run each query just 3 times most likely you'll get very high coefficient of variation for each of them. Which means that if you make the same a minute later you may get some 20% different results. 
And how does one reproduce it if he wants to test on his own hardware? Unfortunately, I can't find how one can do it.

<!-- principles -->
## Testing principles

Our belief is that a fair database benchmark should follow some key principles:

✅ Test different databases on exactly same hardware

> Otherwise you at least can't appeal to little percents differences in test results.

✅ Test with full OS cache purged before each test

> Otherwise you can't test cold queries.

✅ Database which is being tested should have all it's internal caches disabled

> Otherwise you'll measure cache performance.

✅ Best if you measure a cold run too. It's especially important for analytical queries where cold queries may happen often

> Otherwise you completely hide how the database can handle I/O.

✅ Nothing else should be running during testing

> Otherwise your test results may be very unstable.

✅ You need to restart database before each query

> Otherwise previous queries can still impact current query's response time, despite clearing internal caches.

✅ You need to wait until the database warms up completely after it's started

> Otherwise you can at least end up competing with db's warmup process for I/O which can spoil your test results severely.

✅ Best if you provide a coefficient of variation, so everyone understands how stable your resutls are and make sure yourself it's low enough

> [Coefficient of variation](https://en.wikipedia.org/wiki/Coefficient_of_variation) is a very good metric which shows how stable your test results are. If it's higher than N% you can't say one database is N% faster than another.

✅ Best if you test on a fixed CPU frequency

> Otherwise if you are using "on-demand" cpu governor (which is normally a default) it can easily turn your 500ms response time into a 1000+ ms.

✅ Best if you test on SSD/NVME rather than HDD

> Otherwise depending on where your files are located on HDD you can get up to 2x lower/higher I/O performance (we tested), which can make at least your cold queries results wrong.

<!-- framework -->
## Test framework

The test framework which is used on the backend of https://db-benchmarks.com is fully open source with AGPLv3 license and can be found on https://github.com/db-benchmarks/db-benchmarks . Here's what it does:

* Automates data loading to the databases / search engines included in the repository
* Can run database / search engine in docker with particular CPU/RAM constraint
* While testing:
  * Purges OS cache automatically
  * Automates purging database caches before each cold run
  * Restarts database before each cold run
  * Looks after your CPU temperature to avoid throttling
  * Looks after coefficient of variation while making queries and can stop as soon as:
    - the CV is low enough
    - and the number of queries made is sufficient
  * After starting a database / search engine lets it do its warmup stage (preread needed data from disk), stops waiting as soon as:
    - there's no IO for a few seconds
    - and it can connect to the database / search engine
  * After stopping a database / search engine waits until it fully stops
  * Can accept different timeouts: start, warmup, initial connection, getting info about the database / search engine, query
  * Can emulate one physical core which allows benchmarking algorithmic capabilities of databases more objectively (`--limited`)
  * Can accept all the values as command line arguments as well as envionment variables for easier intergation with CI systems
  * `--test` saves test results to file
  * `--save` saves test results from files to remote database (neither of those that have been tested)
  * Tracks A LOT of things while testing:
    - Server info: CPU, memory, running processes, filesystem, hostname
    - Current repository info to make sure there's no local changes
    - Performance metrics: each query response time in microseconds, aggregated stats: 
      - Coefficient of variation of all queries
      - Coefficient of variation of 80% fastest queries
      - Cold query's response time
      - Avg(response times)
      - Avg(80% fastest queries' response times)
      - Slowest query's response time
    - Database / search engine info:
      - `select count(*)` and `select * limit 1` to make sure the data collections are similar in different databases
      - internal database / search engine data structures status (chunks, shards, segments, partitions, parts etc.) 
* Makes it easy to limit CPU / RAM consumption inside or outside the test (using environment variables `cpuset` and `mem`)
* Allows to start each database / search engine easily the same way it's started by the framework for manual testing and preparation of test queries

## Installation

Before you deploy the test framework make sure you have the following:
* Linux server fully dedicated to testing
* Fresh CPU thermal paste to make sure your CPUs don't throttle down
* `PHP 8` and:
  - `curl` module
  - `mysqli` module
* `docker`
* `docker-compose`
* `sensors` to control CPU temperature to prevent throttling
* `dstat`

To install:

1. git clone from the repository:
   ```bash
   git clone git@github.com:db-benchmarks/db-benchmarks.git
   cd db-benchmarks
   ```
2. update `mem` in `.env` with the default value of the memory (in megabytes) the test framework can use for secondary tasks (data loading, getting info about databases)

## Get started

### Prepare test

First you need to prepare a test:

Go to a particular test's directory (all tests must be in directory `./tests`), for example "hn_small":
```bash
cd tests/hn_small
```

Run the init script:
```bash
./init
```

to:

* download the data collection from the internet
* build tables and indexes

### Run test

Then run `../../test` (it's in the project root's folder) to see the options:

```bash
To run a particular test with specified engines, memory constraints and number of attempts and save the results locally:
	/perf/test_engines/test
	--test=test_name
	--engines={engine1:type,...,engineN}
	--memory=1024,2048,...,1048576 - memory constraints to test with, MB
	[--times=N] - max number of times to test each query, 100 by default
	[--dir=path] - if path is omitted - save to directory 'results' in the same dir where this file is located
	[--probe_timeout=N] - how long to wait for an initial connection, 30 seconds by default
	[--start_timeout=N] - how long to wait for a db/engine to start, 120 seconds by default
	[--warmup_timeout=N] - how long to wait for a db/engine to warmup after start, 300 seconds by default
	[--query_timeout=N] - max time a query can run, 900 seconds by default
	[--info_timeout=N] - how long to wait for getting info from a db/engine
	[--limited] - emulate one physical CPU core
	[--queries=/path/to/queries] - queries to test, ./tests/<test name>/test_queries by default
To save to db all results it finds by path
	/perf/test_engines/test
	--save=path/to/file/or/dir, all files in the dir recursively will be saved
	--host=HOSTNAME
	--port=PORT
	--username=USERNAME
	--password=PASSWORD
	--rm - remove after successful saving to database
----------------------
Environment vairables:
	All the options can be specified as environment variables, but you can't use the same option as an environment variables and an command line argument at the same time.
```

And run the test:

```bash
../../test --test=hn_small --engines=elasticsearch,clickhouse --memory=16384
```

Now you have test results in `./results/` (in the root of the repository), for example:

```bash
# ls results/
220401_054753
```

### Save to db to visualize

You can now upload the results to db for further visualization. The visualization tool which is used on https://db-benchmarks.com/ is also opensource and can be found here https://github.com/db-benchmarks/ui .

Here's how you can save the resuls:

```bash
username=login password=pass host=db.db-benchmarks.com port=443 save=./results ./test
```

or 

```
./test --username=login --password=pass --host=db.db-benchmarks.com --port=443 --save=./results
```

### Make pull request

We are eager to see your test results. If you believe they should be added to https://db-benchmarks.com feel free to make a pull request of your results to this repository:
* Your results should be located in directory `./results`
* If it's a new test/engine - the other changes should be in the same pull request
* Just remeber we (and anyone else) should be able to reproduce your test and hopefully get similar results, otherwise we won't be able to accept your pull request

We will then:
* Review your results to make sure they follow the testing principles
* Perhaps reproduce your test on our hardware so they are comparable with the other tests
* Discuss with you any arising questions 
* And will merge your pull request

## Directory structure

```
.
  |-.env                                    <- you need to update "mem" here
  |-test                                    <- the executable file which you need to run to test or save test results
  |-plugins                                 <- plugins directory: if you decide to extend the framework by adding one more database / search engine to test you need to put it into this directory
  |  |-elasticsearch.php                    <- Elasticsearch plugin
  |  |-manticoresearch.php                  <- Manticore Search plugin
  |  |-clickhouse.php                       <- ClickHouse plugin
  |  |-mysql.php                            <- Mysql plugin
  |-README.md                               <- you are reading this file
  |-tests                                   <- tests directory
  |  |-hn                                   <- Hackernews test
  |  |  |-prepare_csv                       <- Here we prepare the data collection, it's done in ./tests/hn/init
  |  |  |-description                       <- Test description which is included into test results and then is to be used when the results are visualized
  |  |  |-manticore                         <- In this dir happens everything related to testing Manticore Search WRT the current test (Hackernews)
  |  |  |  |-init                           <- This is a common script which should be in every <test>/<database> directory which is responsible for generating all for the <database>
  |  |  |-ch                                <- "Hackernews test -> ClickHouse" directory
  |  |  |  |-data_limited                   <- This will be mounted to ClickHouse docker if the docker-compose is run with env. var. suffix=_limited 
  |  |  |  |-post_load_limited.sh           <- This is a hook which is triggered after the data load, called by ./init in the same directory
                                               Note, there's no post_load.sh (with no suffix), which means that no hook will be called in this case.
  |  |  |  |-data                           <- This is another ClickHouse directory, no suffix means the docker-compose should be run with suffix= (empty value)
  |  |  |  |-init                           <- ClickHouse's init script
  |  |  |-es                                <- "Hackernews test -> Elasticsearch" directory
  |  |  |  |-logstash_limited               <- Logstash config dir for type "limited", hence suffix "_limited"
  |  |  |  |  |-post_load.sh                
  |  |  |  |  |-logstash.conf               <- Logstash config
  |  |  |  |  |-template.json               <- Logstash template
  |  |  |  |  |-jvm.options                 <- Logstash jvm options
  |  |  |  |-elasticsearch_limited.yml      <- Elasticsearch config for type "limited"
  |  |  |  |-logstash                       <- Logstash config dir for the default type
  |  |  |  |  |-logstash.conf               
  |  |  |  |  |-template.json
  |  |  |  |  |-jvm.options
  |  |  |  |-logstash_tuned                 <- Logstash config dir for type "tuned"
  |  |  |  |  |-post_load.sh
  |  |  |  |  |-logstash.conf
  |  |  |  |  |-template.json
  |  |  |  |  |-jvm.options
  |  |  |  |-elasticsearch.yml
  |  |  |  |-elasticsearch_tuned.yml
  |  |  |  |-init
  |  |  |-test_queries                      <- All test queries for the current test are here
  |  |  |-test_info_queries                 <- And here should be those queries that are called to get info about the data collection
  |  |  |-data                              <- Prepared data collection here
  |  |  |-init                              <- Main initialization script for the test
  |  |-taxi                                 <- Another test: Taxi rides, similar structure
  |  |-hn_small                             <- Another test: non-multiplied Hackernews dataset, similar structure
  |  |-logs10m                              <- Another test: Nginx logs, similar structure
  |-docker-compose.yml                      <- docker-compose config: responsible for starting / stopping the databases / search engines
  |-results                                 <- test results, the results you see on https://db-benchmarks.com/ can be found here and you can use ./test --save to visualize them yourself
```

## How to start a particular database / search engine with a particular dataset

```bash 
test=logs10m cpuset="0,1" mem=32768 suffix=_tuned docker-compose up elasticsearch
```
will:

* start Elasticsearch to test "logs10m" with the following settings:
* `suffix=_tuned`: maps ./tests/logs10m/es/data/idx_tuned as a data directory
* `mem=32768` limits RAM to 32GB, if not specified the default will be used from file `.env`
* `cpuset="0,1"`: Elasticsearch's container will be running only on CPU cores 0 and 1 (which may be the first whole physical CPU)

To stop - just `CTRL-C`.

## Notes

* The original test results layout of the [UI](https://github.com/db-benchmarks/ui) was heavily inspired by ClickHouse Benchmarks - https://clickhouse.com/benchmark/dbms/ . Thank you, Alexey Milovidov and ClickHouse team!

<!-- roadmap -->
## ❤️ Contribute

Want to get involved in the project? Here's how you can contribute:

### More databases and search engines
* mysql vs percona server
* cassandra vs scylla
* mysql vs postgresql
* mongodb vs ferretdb
* whatever else vs whatever else

these all are waiting for your contribution.

### Features wishlist: 
* Measure not only response time, but resource consumption:
  - RAM consumption for each query
  - CPU consumption
  - IO consumption
* Measure not only response time, but throughput
* Make it easy to use it in CI, so each new commit is tested and if it's slower than previously the test is not passed
* Make it mobile-friendly
* Higher quality for cold query tests (there's only one cold run made per query now which makes the metric usable in purely information purposes, it's not as high quality as Fast avg")
