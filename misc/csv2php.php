#!/usr/bin/env php
<?php
$f = fopen('php://stdin', 'r');
while (($row = fgetcsv($f, 0, ",", '"', "")) !== false) {
  $s = serialize($row);
  echo strlen($s)."\n".$s."\n";
}


