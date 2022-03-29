<p align="center">
  <a href="https://db-benchmarks.com" target="_blank" rel="noopener">
    <img src="./logo.svg" width="50%" alt="db-benchmarks logo" style="color: white">
  </a>
</p>

<h3 align="center">
  <a href="https://db-benchmarks.com">Benchmarks</a> •
  <a href="#introduction">Intro</a> •
  <a href="#why-is-this-important">Importance</a> •
  <a href="#what-we-do">What we do</a> •
  <a href="#testing-principles">Testing principles</a> •
  <a href="#installation">Installation</a> •
  <a href="#roadmap">Roadmap</a>
</h3>

<p>&nbsp;</p>

# Introduction

Our goal is to make database and search engines benchmarks:

⚖️ **Fair and transparent** - it should be clear under what circumstances this or that database is faster than another

🚀 **High quality** - control over coefficient of variation allows producing results that remain the same if you run a query today, tomorrow or next week

👪 **Easily reproducible** - anyone can reproduce any test on his own hardware

📊 **Easy to understand** - the charts are very simple

➕ **Extendable** - pluggable architecture allows adding more databases to test

And keep it all **100% open source!**


# Why is this important?

Many database benchmarks are non-objective. Few examples:

### Druid vs Clickhouse vs Rockset

https://imply.io/blog/druid-nails-cost-efficiency-challenge-against-clickhouse-and-rockset/ :

> We actually wanted to do the benchmark on the same hardware, an m5.8xlarge, but the only pre-baked configuration we have for m5.8xlarge is actually the m5d.8xlarge ... Instead, we run on a c5.9xlarge instance

Bad news, guys: when you run benchmarks on different hardware, at the very least you can't then say that something is "106.76%" and "103.13%" of something else. Even when you test on the same bare-metal server it's quite difficult to get coefficient of variation lower than 5%. 3% difference on different servers can be highly likley ignored. Provided all that, how can one make sure the final conclusion is true?

-------

### Lots of databases and engines

https://tech.marksblogg.com/benchmarks.html

Mark did a great job making the taxi rides test on so many different databases and search engines. But since the tests are made on different hardware the numbers in the resulting table don't make much sense to compare one with another. You always need to remember about it when you evaluate the results in the table.

-------

### Clickhouse vs others

https://clickhouse.com/benchmark/dbms/

When you run each query just 3 times most likely you'll get very high coefficient of variation for each of them. Which means that if you make the same a minute later you may get some 20% different results. 
And how does one reproduce it if he wants to test on his own hardware? Unfortunately, I can't find how one can do it.

# What we do

We want to introduce some best practices to the industry of databases and search engines by providing the testing suite which makes testing fair, transparent, high quality, reproducible, easy to understand, extendable and fun!

# Testing principles

Our belief is that a fair database benchmark should follow some key principles:

1. Test different databases on exactly same hardware
   > Otherwise you at least can't appeal to little percents differences.
2. Test with full OS cache purged before each test
   > Otherwise you can't test cold queries.
3. Database which is being tested should have all it's internal caches disabled
   > Otherwise you'll measure cache performance.
4. Best if you measure a cold run too. It's especially important for analytical queries where cold queries may happen often
   > Otherwise you completely hide how the database can handle I/O.
5. Nothing else should be running during testing
   > Otherwise your test results may be very unstable.
6. You need to restart database before each query
   > Otherwise previous queries can still impact current query's response time, despite clearing internal caches
7. You need to wait until the database warms up completely after it's started
   > Otherwise you can at least end up competing with db's warmup process for I/O which can spoil your test results severely.
8. Best if you provide a coefficient of variation, so everyone understands how stable your resutls are and make sure yourself it's low enough
   > [Coefficient of variation](https://en.wikipedia.org/wiki/Coefficient_of_variation) is a very good metric which shows how stable your test results are. If it's higher than N% you can't say one database is N% faster than another.
9. Best if you test on a fixed CPU frequency
   > Otherwise if you are using "on-demand" cpu governor (which is normally a default) it can easily turn your 500ms response time into a 1000+ ms.
10. Best if you test on SSD/NVME rather than HDD
   > Otherwise depending on where your files are located on HDD you can get up to 2x lower/higher I/O performance (we tested), which can make at least your cold queries results wrong.

# Installation

Make sure you have the following:
* Linux server fully dedicated to testing
* Fresh CPU thermal paste to make sure your CPUs don't throttle down
* `PHP` and:
  - `curl` module
  - `mysqli` module
* `docker`
* `docker-compose`
* `sensors` to control CPU temperature to prevent throttling
* `dstat`


```bash
git clone git@github.com:db-benchmarks/db-benchmarks.git
```

# Get started

### prepare test

First you need to prepare a test:

Go to a particular test's directory (all tests must be in directory `./tests`)
```bash
cd db-benchmarks/tests/hn_small
```

Run the init script:
```bash
./init
```

to:

* download the data collection from the internet
* build tables and indexes

### run test

Then run `../../test` (it's in the project root's folder) to see the options:

```bash
To run a particular test with specified engines, memory constraints and number of attempts and save the results locally:
        /home/snikolaev/db-benchmarks/test
        --test=test_name
        --engines={engine1:type,...,engineN}
        [--times=N] - max number of times to test each query, 100 by default
        [--memory=1024,2048,...,1048576]
        [--dir=path] - if path is omitted - save to /tmp/benchmarks/
        [--probe_timeout=N] - how long to wait for an initial connection, 30 seconds by default
        [--start_timeout=N] - how long to wait for a db/engine to start, 120 seconds by default
        [--warmup_timeout=N] - how long to wait for a db/engine to warmup after start, 120 seconds by default
        [--query_timeout=N] - max time a query can run, 900 seconds by default
        [--info_timeout=N] - how long to wait for getting info from a db/engine
        [--limited] - emulate one physical CPU core
        [--queries=/path/to/queries] - queries to test, ./tests/<test name>/test_queries by default
To save to db all results it finds by path
        /home/snikolaev/db-benchmarks/test
        --save=path/to/file/or/dir
        --host=HOSTNAME
        --port=PORT
        --username=USERNAME
        --password=PASSWORD
To dump from db all results or a particular one by id:
        /home/snikolaev/db-benchmarks/test
        --dump {test id}
----------------------
Environment vairables:
        All the options can be specified as environment variables, but you can't use the same option as an environment variables and an command line argument at the same time.
```

And run the test:

```bash
root@perf3 /home/snikolaev/db-benchmarks/tests/hn_small # ../../test --test=hn_small --engines=elasticsearch,clickhouse --memory=16384 --dir=cache
Tue, 29 Mar 2022 12:48:56 +0200 Preparing environment for test
Tue, 29 Mar 2022 12:48:58 +0200 Getting general server info
...
Tue, 29 Mar 2022 14:14:44 +0200    Attempting to kill clickhouse in case it's still running
Tue, 29 Mar 2022 14:14:45 +0200    Saving data for engine "clickhouse"
```

Now you have test results in `./cache/`, for example:

```bash
root@perf3 /home/snikolaev/db-benchmarks/tests/hn_small # ls -la cache/220329_124856/
total 1384
drwxr-xr-x 2 root root   4096 Mar 29 14:14 .
drwxr-xr-x 3 root root   4096 Mar 29 14:02 ..
-rw-r--r-- 1 root root 595984 Mar 29 14:14 hn_small_clickhouse__16384
-rw-r--r-- 1 root root 810241 Mar 29 14:02 hn_small_elasticsearch__16384
```

### save to db

You can now upload the results to db for further visualization:

```bash
root@perf3 /home/snikolaev/db-benchmarks/tests/hn_small # username=bench password=bench host=manticore.benchmarks.manticoresearch.com port=443 save=./cache ../../test
Tue, 29 Mar 2022 14:37:27 +0200 Saving from file /home/snikolaev/db-benchmarks/tests/hn_small/./cache/220329_124856/hn_small_elasticsearch__16384
Tue, 29 Mar 2022 14:37:27 +0200    Saving results for elasticsearch
Tue, 29 Mar 2022 14:37:30 +0200 Saving from file /home/snikolaev/db-benchmarks/tests/hn_small/./cache/220329_124856/hn_small_clickhouse__16384
Tue, 29 Mar 2022 14:37:30 +0200    Saving results for clickhouse
```

# Roadmap

* Measure not only response time, but resource consumption:
  - RAM consumption for each query
  - CPU consumption
  - IO consumption
* Measure not only response time, but throughput
* Make it easy to use the system in CI, so each new commit is tested and if it's slower than previously the test fails
