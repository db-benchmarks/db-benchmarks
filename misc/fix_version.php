<?php
// This script accepts results file at arg #1, then returns the engine version in it, asks the user provide a new version value and updated the results file
if (count($argv) < 2) die("Usage: ".__FILE__." <path to results file/dir>\n");
for ($n=1;$n<count($argv);$n++) {
  $path = $argv[$n];
  $o = []; $r = [];
  exec("find $path -type f", $o, $r);
  if (!$o) die("ERROR: no files found\n");
  foreach ($o as $file) {
    echo "File $file\n";
    $data = unserialize(file_get_contents($file));
    $engine = readline("  Edit \"engine\" [{$data['engine']}] (empty to skip): ");
    if ($engine) $data['engine'] = $engine;
    $type = readline("  Edit \"type\" [{$data['type']}] (empty to skip): ");
    if ($type) $data['type'] = $type;
    $version = readline("  Edit \"version\" [{$data['info']['version']}] (empty to skip): ");
    if ($version) $data['info']['version'] = $version;
    file_put_contents($file, serialize($data)); 
    echo "Saved $file\n\n";
  }
}
