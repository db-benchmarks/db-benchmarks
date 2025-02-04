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

And keep it all **100% Open Source!**

This repository provides a test framework which does the job.

## Why is this important?

Many database benchmarks are not objective. Others don't do enough to ensure results accuracy and stability, which in some cases breaks the whole idea of benchmarks. A few examples:

### Druid vs ClickHouse vs Rockset

https://imply.io/blog/druid-nails-cost-efficiency-challenge-against-clickhouse-and-rockset/ :

> We actually wanted to do the benchmark on the same hardware, an m5.8xlarge, but the only pre-baked configuration we have for m5.8xlarge is actually the m5d.8xlarge ... Instead, we run on a c5.9xlarge instance

Bad news, guys: when you run benchmarks on different hardware, at the very least you can't then say that something is "106.76%" and "103.13%" of something else. Even when you test on the same bare-metal server, it's quite difficult to get a coefficient of variation lower than 5%. A 3% difference on different servers can most likely be ignored. Given all that, how can one make sure the final conclusion is true?

-------

### Lots of databases and engines

https://tech.marksblogg.com/benchmarks.html

Mark did a great job making the taxi rides test on so many different databases and search engines. But since the tests are made on different hardware, the numbers in the resulting table aren't really comparable. You always need to keep this in mind when evaluating the results in the table.


-------

### ClickHouse vs others

https://clickhouse.com/benchmark/dbms/

When you run each query just 3 times, you'll most likely get very high coefficients of variation for each of them. Which means that if you run the test a minute later, you may get a variation of 20%. And how does one reproduce a test on one's own hardware? Unfortunately, I can't find how one can do it.

<!-- principles1 -->
## Testing principles
<!-- principles2 -->

Our belief is that a fair database benchmark should follow some key principles:

✅ Test different databases on exactly the same hardware

> Otherwise, you should acknowledge an error margin when there are small differences.

✅ Test with full OS cache purged before each test

> Otherwise you can't test cold queries.

✅ Database which is being tested should have all its internal caches disabled

> Otherwise you'll measure cache performance.

✅ Best if you measure a cold run too. It's especially important for analytical queries where cold queries may happen often

> Otherwise you completely hide how the database can handle I/O.

✅ Nothing else should be running during testing

> Otherwise your test results may be very unstable.

✅ You need to restart the database before each query

> Otherwise, previous queries can still impact current query's response time, despite clearing internal caches.

✅ You need to wait until the database warms up completely after it's started

> Otherwise, you may end up competing with the database's warm-up process for I/O which can severely spoil your test results.

✅ Best if you provide a coefficient of variation, so everyone understands how stable your results are and make sure yourself it's low enough

> [Coefficient of variation](https://en.wikipedia.org/wiki/Coefficient_of_variation) is a very good metric which shows how stable your test results are. If it's higher than N%, you can't say one database is N% faster than another.

✅ Best if you test on a fixed CPU frequency

> Otherwise, if you are using "on-demand" CPU governor (which is normally a default) it can easily turn your 500ms response time into a 1000+ ms.

✅ Best if you test on SSD/NVME rather than HDD

> Otherwise, depending on where your files are located on HDD you can get up to 2x lower/higher I/O performance (we tested), which can make at least your cold queries results wrong.


<!-- framework -->
## Test framework

The test framework which is used on the backend of https://db-benchmarks.com is fully Open Source (AGPLv3 license) and can be found at https://github.com/db-benchmarks/db-benchmarks . Here's what it does:

* Automates data loading to the databases/search engines included in the repository.
* Can run a database/search engine in Docker with a particular CPU/RAM constraint.
* While testing:
  * Purges OS cache automatically
  * Automates purging database caches before each cold run
  * Restarts the database before each cold run
  * Looks after your CPU temperature to avoid throttling
  * Looks after the coefficient of variation while making queries and can stop as soon as:
    - The CV is low enough
    - And the number of queries made is sufficient
  * After starting a database/search engine, lets it do its warm-up stage (pre-read needed data from disk), stops waiting as soon as:
    - There's no IO for a few seconds
    - And it can connect to the database/search engine
  * After stopping a database/search engine waits until it fully stops
  * Can accept different timeouts: start, warm-up, initial connection, getting info about the database/search engine, query
  * Can emulate one physical core which allows benchmarking algorithmic capabilities of databases more objectively (`--limited`)
  * Can accept all the values as command line arguments as well as environment variables for easier integration with CI systems
  * `--test` saves test results to file
  * `--save` saves test results from files to a remote database (neither of those that have been tested)
  * Tracks a lot of things while testing:
    - Server info: CPU, memory, running processes, filesystem, hostname
    - Current repository info to make sure there's no local changes
    - Performance metrics: each query response time in microseconds, aggregated stats:
      - Coefficient of variation of all queries
      - Coefficient of variation of 80% fastest queries
      - Cold query's response time
      - Avg(response times)
      - Avg(80% fastest queries' response times)
      - Slowest query's response time
    - Database/search engine info:
      - `select count(*)` and `select * limit 1` to make sure the data collections are similar in different databases
      - internal database/search engine data structures status (chunks, shards, segments, partitions, parts, etc.)
* Makes it easy to limit CPU/RAM consumption inside or outside the test (using environment variables `cpuset` and `mem`).
* Allows to start each database/search engine easily the same way it's started by the framework for manual testing and preparation of test queries.

## Installation

Before you deploy the test framework, make sure you have the following:
* Linux server fully dedicated to testing
* Fresh CPU thermal paste to make sure your CPUs don't throttle down
* `PHP 8` and:
  - `curl` module
  - `mysqli` module
* `docker`
* `docker-compose`
* `sensors` to control CPU temperature to prevent throttling
* `dstat`
* `cgroups v2`

To install:

1. git clone from the repository:
   ```bash
   git clone git@github.com:db-benchmarks/db-benchmarks.git
   cd db-benchmarks
   ```
2. Copy `.env.example` to `.env` 
3. Update `mem` and `cpuset` in `.env` with the default value of the memory (in megabytes) and CPUs the test framework can use for secondary tasks (data loading, getting info about databases)
4. Tune JVM limits `ES_JAVA_OPTS` for your tests. Usually it's size of allocated memory for Docker Machine


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

This will:

* download the data collection from the Internet
* build the tables/indices
* Measure the time spent uploading the dataset to the database and add a {engine_name}_init file to the corresponding results folder
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
	--skip_calm - avoid waiting until discs become calm
----------------------
Environment variables:
	All the options can be specified as environment variables, but you can't use the same option as an environment variables and as a command line argument at the same time.
```

And run the test:

```bash
../../test --test=hn_small --engines=elasticsearch,clickhouse --memory=16384
```

If you run your tests in local mode (development) and don't care about tests inaccuracy you can avoid discs calm and cpu checks by setting parameter `--skip_inaccuracy`
```bash
../../test --test=hn_small --engines=elasticsearch,clickhouse --memory=16384 --skip_inaccuracy
```

Now you have test results in `./results/` (in the root of the repository), for example:

```bash
# ls results/
220401_054753
```

### Save to db to visualize

You can now upload the results to the database for further visualization. The visualization tool, which is used on https://db-benchmarks.com/ , is also open source and can be found at https://github.com/db-benchmarks/ui.

Here's how you can save the results:

```bash
username=login password=pass host=db.db-benchmarks.com port=443 save=./results ./test
```

or 

```
./test --username=login --password=pass --host=db.db-benchmarks.com --port=443 --save=./results
```

### Make pull request

We are eager to see your test results. If you believe they should be added to https://db-benchmarks.com, please make a pull request of your results to this repository.

Please keep the following in mind:
* Your results should be located in the directory `./results`.
* If it's a new test/engine, any other changes should be included in the same pull request.
* It is important that we, and anyone else, should be able to reproduce your test and hopefully get similar results.

We will then:

* Review your results to ensure they follow the testing principles.
* Reproduce your test on our hardware to ensure they are comparable with other tests.
* Discuss any arising questions with you.
* And, if everything checks out, we will merge your pull request.

## Directory structure

```
.
  |-core                                    <- Core directory, contains base files required for tests.
  |  |-engine.php                           <- Abstract class Engine. Manages test execution, result saving, and parsing of test attributes.
  |  |-EsCompatible.php                     <- Helper for ElasticSearch compatible engines
  |  |-helpers.php                          <- Helper file with logging functions, attribute parsing, exit functions, etc.
  |-misc                                    <- Miscellaneous directory, intended for storing files useful during the initialization step.
  |  |-func.sh                              <- Meilisearch initialization helper script.
  |  |-ResultsUpdater.php                   <- Helper that allows to update results (It should be unserialized and serialized again)
  |-plugins                                 <- Plugins directory: if you want to extend the framework by adding another database or search engine for testing, place it here.
  |  |-clickhouse.php                       <- ClickHouse plugin.
  |  |-elasticsearch.php                    <- Elasticsearch plugin.
  |  |-manticoresearch.php                  <- Manticore Search plugin.
  |  |-meilisearch.php                      <- Meilisearch plugin.
  |  |-mysql.php                            <- MySQL plugin.
  |  |-mysql_percona.php                    <- MySQL (Percona) plugin.
  |  |-postgres.php                         <- Postgres plugin.
  |  |-quickwit.php                         <- Quickwit plugin.
  |  |-typesense.php                        <- Typesense plugin.
  |-results                                 <- Test results directory. The results shown on https://db-benchmarks.com/ are found here. You can also use `./test --save` to visualize them locally.
  |-tests                                   <- Directory containing test suites.
  |  |-hn                                   <- Hackernews test suite.
  |  |  |-clickhouse                        <- Directory for "Hackernews test -> ClickHouse".
  |  |  |  |-inflate_hook                   <- Engine initialization script. Handles data ingestion into the database.
  |  |  |  |-post_hook                      <- Engine verification script. Ensures the correct number of documents have been ingested and verifies data consistency.
  |  |  |  |-pre_hook                       <- Engine pre-check script. Determines if tables need to be rebuilt, starts the engine, and ensures availability.
  |  |  |-data                              <- Prepared data collection for the tests.
  |  |  |-elasticsearch                     <- Directory for "Hackernews test -> Elasticsearch".
  |  |  |  |-config                         <- Elasticsearch configuration directory. 
  |  |  |  |  |-elasticsearch.yml
  |  |  |  |  |-jvm.options
  |  |  |  |  |-log4j2.properties
  |  |  |  |-config_tuned                   <- Elasticsearch configuration directory for the "tuned" type.
  |  |  |  |-logstash                       <- Logstash configuration directory.
  |  |  |  |  |-logstash.conf
  |  |  |  |  |-template.json
  |  |  |  |-logstash_tuned                 <- Logstash configuration directory for the "tuned" type.
  |  |  |  |  |-logstash.conf
  |  |  |  |  |-template.json
  |  |  |  |-inflate_hook                   <- Engine initialization script for data ingestion.
  |  |  |  |-post_hook                      <- Verifies document count and data consistency.
  |  |  |  |-pre_hook                       <- Pre-check script for table rebuilding and engine initialization.
  |  |  |-manticoresearch                   <- Directory for testing Manticore Search in the Hackernews test suite.
  |  |  |  |-generate_manticore_config.php  <- Script for dynamically generating Manticore Search configuration.
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Verifies document count and consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine availability.
  |  |  |-meilisearch                       <- Directory for "Hackernews test -> Meilisearch".
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Ensures correct document count and data consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine start.
  |  |  |-mysql                             <- Directory for "Hackernews test -> MySQL".
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Ensures document count and consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine start.
  |  |  |-postgres                          <- Directory for "Hackernews test -> Postgres".
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Verifies document count and data consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine availability.
  |  |  |-prepare_csv                       <- Prepares the data collection, handled in `./tests/hn/init`.
  |  |  |-quickwit                          <- Directory for "Hackernews test -> Quickwit".
  |  |  |  |-csv_jsonl.php                  <- CSV to JSONl modification script
  |  |  |  |-index-config.yaml              <- Quickwit index config
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Verifies document count and data consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine availability.
  |  |  |  |-quickwit.yaml                  <- Quickwit config
  |  |  |-typesense                          <- Directory for "Hackernews test -> Typesense".
  |  |  |  |-csv_jsonl.php                  <- CSV to JSONl modification script
  |  |  |  |-inflate_hook                   <- Data ingestion script.
  |  |  |  |-post_hook                      <- Verifies document count and data consistency.
  |  |  |  |-pre_hook                       <- Pre-check for table rebuilds and engine availability.
  |  |  |-description                       <- Test description, included in test results and used during result visualization.
  |  |  |-init                              <- Main initialization script for the test.
  |  |  |-test_info_queries                 <- Contains queries to retrieve information about the data collection.
  |  |  |-test_queries                      <- Contains all test queries for the current test.
  |  |-taxi                                 <- Taxi rides test suite, with a similar structure.
  |  |-hn_small                             <- Test for a smaller, non-multiplied Hackernews dataset, similar structure.
  |  |-logs10m                              <- Test for Nginx logs, similar structure.
  |-.env.example                            <- Example environment file. Update the "mem" and "cpuset" values as needed.
  |-LICENSE                                 <- License file.
  |-NOTICE                                  <- Notice file.
  |-README.md                               <- You're reading this file.
  |-docker-compose.yml                      <- Docker Compose configuration for starting and stopping databases and search engines.
  |-important_tests.sh
  |-init                                    <- Initialization script. Handles data ingestion and tracks the time taken.
  |-logo.svg                                <- Logo file.
  |-test                                    <- The executable file to run and save test results.
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

These all are waiting for your contribution!

### Features wishlist: 
* Measure not only response time, but also resource consumption, such as:
  - RAM consumption for each query
  - CPU consumption
  - IO consumption
* Measure not only response time, but also throughput.
* Make it easy to use in CI, so that each new commit is tested and if it's slower than previously, the test is not passed.
* Make it mobile-friendly.
* Improve the quality of cold query tests (currently, only one cold run is made per query, which makes the metric usable for informational purposes only, it's not as high quality as Fast avg").
