#!/usr/bin/php
<?php
if (count($argv) < 5) die("Usage: ".__FILE__." <csv file> <batch size> <concurrency> <columnar/rowwise>\n");

// This function waits for an idle mysql connection for the $query, runs it and exits
function process($query) {
    global $all_links;
    global $requests;
    foreach ($all_links as $k=>$link) {
        if (@$requests[$k]) continue;
        mysqli_query($link, $query, MYSQLI_ASYNC);
        @$requests[$k] = microtime(true);
        return true;
    }
    do {
        $links = $errors = $reject = array();
        foreach ($all_links as $link) {
            $links[] = $errors[] = $reject[] = $link;
        }
        $count = @mysqli_poll($links, $errors, $reject, 0, 1000);
        if ($count > 0) {
            foreach ($links as $j=>$link) {
                $res = @mysqli_reap_async_query($links[$j]);
                foreach ($all_links as $i=>$link_orig) if ($all_links[$i] === $links[$j]) break;
                if ($link->error) {
                    echo "ERROR: {$link->error}\n";
                    if (!mysqli_ping($link)) {
                        echo "ERROR: mysql connection is down, removing it from the pool\n";
                        unset($all_links[$i]); // remove the original link from the pool
                        unset($requests[$i]); // and from the $requests too
                    }
                    return false;
                }
                if ($res === false and !$link->error) continue;
                if (is_object($res)) {
                    mysqli_free_result($res);
                }
                $requests[$i] = microtime(true);
		mysqli_query($link, $query, MYSQLI_ASYNC); // making next query
                return true;
            }
        };
    } while (true);
    return true;
}

if (!file_exists($argv[1])) die("file {$argv[1]} doesn't exist\n");
$f = fopen($argv[1], "r");

$t = microtime(true);
$all_links = [];
$requests = [];
$c = 0;
for ($i=0;$i<$argv[3];$i++) {
  $m = @mysqli_connect('127.0.0.1', '', '', '', 9306);
      if (mysqli_connect_error()) die("Cannot connect to Manticore\n");
      $all_links[] = $m;
  }

// init
mysqli_query($all_links[0], "drop table if exists logs10m");
mysqli_query($all_links[0], "create table logs10m(remote_addr text, remote_user text, runtime int, time_local timestamp, request_type text, request_path text attribute indexed, request_protocol text, status int, size int, referer text, useragent text) engine='{$argv[4]}'");

$batch = [];
$query_start = "replace into logs10m(id,remote_addr,remote_user,runtime,time_local,request_type,request_path,request_protocol,status,size,referer,useragent) values ";

$error = false;
while (count($all_links) and ($data = fgetcsv($f, 10000, ",", "\"", "")) !== false) {
  foreach ($data as $k=>$el) {
      if (is_numeric($el)) continue;
//      if (preg_match('/^[\.\-,0-9]+$/', $el)) $data[$k] = "($el)";
      else $data[$k] = "'".mysqli_real_escape_string($all_links[0], $el)."'";
  }
  $batch[] = "(".implode(',', $data).")";
//  print_r($batch);exit;
  $c++;
  if (count($batch) == $argv[2]) {
    if (!process($query_start.implode(',', $batch))) {
      $error = true;
      break;
    }
    $batch = [];
  }
}
if (!$error and count($all_links) and $batch) process($query_start.implode(',', $batch));

// wait until all the workers finish
do {
  $links = $errors = $reject = array();
  foreach ($all_links as $link)  $links[] = $errors[] = $reject[] = $link;
  $count = @mysqli_poll($links, $errors, $reject, 0, 100);
} while (count($all_links) != count($links) + count($errors) + count($reject));

echo "finished inserting\n";
echo "Total time: ".(microtime(true) - $t)."\n";
echo round($c / (microtime(true) - $t))." docs per sec\n";
