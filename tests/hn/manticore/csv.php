#!/usr/bin/php
<?php
$options = getopt('', ["file:", "batch::", "host::", "port::", "index:", "fields:", "truncate::", "quiet::", "shards::", "limit::"]);
if (!isset($options['file'])) die("ERROR: specify --file\n");
$file = $options['file'];
if (!file_exists($file)) die("ERROR: $file does not exist\n");
if (!isset($options['index'])) die("ERROR: specify --index\n");
$index = $options['index'];
if (!isset($options['fields'])) die("ERROR: specify --fields\n");
$fields = explode(',', $options['fields']);
if (isset($options['host'])) $host = $options['host']; else $host = '127.0.0.1';
if (isset($options['port'])) $port = $options['port']; else $port = '9306';
if (isset($options['batch'])) $batch = $options['batch']; else $batch = 1000;
$truncate = isset($options['truncate']);
if (isset($options['quiet'])) $quiet = $options['quiet']; else $quiet = false;
if (isset($options['shards'])) $shards = $options['shards']; else $shards = 1;
if (isset($options['limit'])) $limit = $options['limit']; else $limit = 0;

// init
$insertedCount = 0;
$all_links = [];
$requests  = [];
$timeouts = [];
$m = new mysqli($host, '', '', '', $port);
if ($m->connect_error) die("ERROR: can't connect to Manticore at $host:$port\n");

if ($shards > 1) for ($i = 0; $i < $shards; $i++) {
  $m = new mysqli($host, '', '', '', $port);
  if ($m->connect_error) die("ERROR: can't connect to Manticore at $host:$port\n");
  $all_links[$i] = $m;
} else $all_links[-1] = new mysqli($host, '', '', '', $port);

// testing index before processing
$res = $m->query("desc $index");
if ($m->error) die("ERROR: ".$m->error."\n");
$desc = [];
$descEtalon = [];
while ($row = $res->fetch_row()) {
  $descEtalon[] = $row;
  if (in_array($row[1], ['uint', 'bigint', 'float'])) $desc['numbers'][$row[0]] = $row[0];
  else if (in_array($row[1], ['string', 'text'])) $desc['strings'][$row[0]] = $row[0];
  else if (in_array($row[1], ['mva', 'mva64'])) $desc['mvas'][$row[0]] = $row[0];
}
usort($descEtalon, create_function('$a,$b', 'if ($a[0] > $b[0]) return 1; else if ($a[0] < $b[0]) return -1; else return 0;'));

if ($truncate) $m->query("truncate table $index");

$distEls = [];
if ($shards > 1) {
  for ($n=0;$n<$shards;$n++) {
    if ($truncate) $m->query("drop table if exists {$index}_$n");
    $res = $m->query("desc {$index}_$n");
    if ($m->error) {
      if (strstr($m->error, "no such index") !== false) {
        $m->query("create table {$index}_$n like $index");
        $res = $m->query("desc {$index}_$n");
      } else die("ERROR: ".$m->error."\n");
    }
    $descToCheck = [];
    while ($row = $res->fetch_row()) $descToCheck[] = $row;
    usort($descToCheck, create_function('$a,$b', 'if ($a[0] > $b[0]) return 1; else if ($a[0] < $b[0]) return -1; else return 0;'));
    if ($descToCheck !== $descEtalon) die("ERROR: schema in shard {$index}_$n differs from the main index");
    if ($truncate) $m->query("truncate table {$index}_$n");
    $distEls[] = "local='{$index}_$n'";
  }
  $m->query("drop table if exists {$index}_dist");
  $m->query("create table if not exists {$index}_dist type='distributed' ".implode($distEls));
}

$f = fopen($file, 'r');
if (!$f) die("ERROR: no input found at stdin\n");

$c = []; $b = [];
$read = 0;
while (!feof($f)) {
  $len = trim(fgets($f));
  if (!is_numeric($len)) continue;
  $data = trim(fread($f, $len+1));
  $row = unserialize($data);
  if ($limit and $read == $limit) break;
  $read++;
  foreach ($row as $k=>$el) {
    if (isset($desc['strings'][$fields[$k]])) $row[$k] = "'".$m->real_escape_string($el)."'";
    else if (isset($desc['mvas'][$fields[$k]])) $row[$k] = "($el)";
    if ($fields[$k] == 'id') {
      $shard = ($shards > 1)?$el%$shards:-1;
    }
  }
  if (!isset($b[$shard])) { // init
    $b[$shard] = []; 
    $c[$shard] = 0;
  }
  $b[$shard][] = "(".implode(',', $row).")"; 
  $c[$shard]++;
// timeout was 3
  if (($c[$shard] >= $batch or (isset($timeouts[$shard]) and $timeouts[$shard] !== false and microtime(true) - $timeouts[$shard] > 30)) and (check($shard) or !isset($requests[$shard]))) {
    insert($shard, $index.($shard==-1?'':"_$shard"), $fields, $b[$shard], !$quiet);
    @$requests[$shard]++;
    $b[$shard] = [];
    $c[$shard] = 0;
  }
}
$leftShards = array_keys($b);
while (count($b)) {
  foreach ($b as $shard => $lastBatch) {
    if (!isset($requests[$shard]) or check($shard)) {
      insert($shard, $index.($shard==-1?'':"_$shard"), $fields, $lastBatch, !$quiet);
      unset($b[$shard]);
    }
  }
  usleep(1000);
}

while (count($leftShards)) {
  foreach ($leftShards as $k => $v) {
    if (check($v)) unset($leftShards[$k]);
  }
  usleep(1000);
}

// ---------------------- functions -----------------------

function insert($shard, $index, $fields, $batch, $progress=true) {
  global $insertedCount;
  global $all_links;
  global $timeouts;
  $q = "insert into $index (".implode(",", $fields).") values ".implode(",", $batch);
  $all_links[$shard]->query($q, MYSQLI_ASYNC);
  $timeouts[$shard] = false;
  $insertedCount += count($batch);
  if ($progress) {
    echo "\rSent $insertedCount docs to insert".str_repeat(' ', 20);
  }
}

// returns true if $shard connection is idling
function check($shard) {
  global $all_links;
  global $timeouts;
  $links = $errors = $reject = [];
  $links[] = $errors[] = $reject[] = $all_links[$shard];
  $count = mysqli::poll($links, $errors, $reject, 0, 1000);
  if ($count > 0) {
    $res = $links[0]->reap_async_query();
    if (is_object($res)) $res->free();
    if ($timeouts[$shard] === false) $timeouts[$shard] = microtime(true); // time of transition to free connectiom
    return true;  
  } else return false;
  // TODO reap and recreate conn in case of problem
}

// This function waits for an idle mysql connection for the $query, runs it and exits
function process($query) {
  global $all_links;
  global $requests;
  foreach ($all_links as $k => $link) {
    if (@$requests[$k]) continue; // link is busy
    mysqli_query($link, $query, MYSQLI_ASYNC);
    @$requests[$k] = ['time' => microtime(true), 'query' => $query]; // remember when we started the query
    return true;
  }
  // all links are busy, couldn't make a query, let's wait for at least one
  do {
   $links = $errors = $reject = array();
   foreach ($all_links as $link) {
     $links[] = $errors[] = $reject[] = $link;
   }
   $count = @mysqli_poll($links, $errors, $reject, 0, 1000);
    if ($count > 0) { // got some result, need to check what's there
      foreach ($links as $j => $link) {
        $res = @mysqli_reap_async_query($links[$j]);
        foreach ($all_links as $i => $link_orig) {
          if ($all_links[$i] === $links[$j]) break; // identifying which exactly link got the result - $i
        }
        if ($link->error) {
          echo "ERROR in '" . substr($query, 0, 100) . "...': {$link->error}\n";
          if ( !mysqli_ping($link)) {
            echo "ERROR: connection to Manticore is down, removing it from the pool\n";
            unset($all_links[$i], $requests[$i]); // remove the original link from the pool and from the $requests too
          }
          return false;
        }
        if ($res === false && ! $link->error) continue;
        if (is_object($res)) mysqli_free_result($res);
         
        $requests[$i] = ['time' => microtime(true), 'query' => $query];
        mysqli_query($link, $query, MYSQLI_ASYNC); // making next query
        return true;
      }
    }
  } while (true);
  return true;
}

