#!/usr/bin/env php
<?php
$options = getopt('', ['input:', 'output-dir:', 'shards:']);
if (!isset($options['input'], $options['output-dir'], $options['shards'])) {
    fwrite(STDERR, "Usage: split_jsonl_shards.php --input=data.jsonl --output-dir=dir --shards=N\n");
    exit(1);
}

$input = $options['input'];
$outputDir = rtrim($options['output-dir'], '/');
$shards = (int)$options['shards'];
$fields = [
    'id',
    'story_id',
    'story_text',
    'story_author',
    'comment_id',
    'comment_text',
    'comment_author',
    'comment_ranking',
    'author_comment_count',
    'story_comment_count',
];

if ($shards < 1) {
    fwrite(STDERR, "Shards must be greater than 0\n");
    exit(1);
}

if (!is_file($input)) {
    fwrite(STDERR, "Input file not found: $input\n");
    exit(1);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output dir: $outputDir\n");
    exit(1);
}

$sourceMtime = filemtime($input);
$completeMarker = "$outputDir/.complete";
$allShardsExist = is_file($completeMarker) && filemtime($completeMarker) >= $sourceMtime;
for ($n = 1; $n <= $shards && $allShardsExist; $n++) {
    if (!is_file(sprintf('%s/shard%d.csv', $outputDir, $n))) {
        $allShardsExist = false;
    }
}

if ($allShardsExist) {
    echo "JSONL shards already prepared\n";
    exit(0);
}

$tmpDir = "$outputDir.tmp." . getmypid();
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Failed to create temporary output dir: $tmpDir\n");
    exit(1);
}

$handles = [];
for ($n = 1; $n <= $shards; $n++) {
    $path = sprintf('%s/shard%d.csv', $tmpDir, $n);
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Failed to open shard for writing: $path\n");
        exit(1);
    }
    $handles[$n] = $handle;
}

$inputHandle = fopen($input, 'rb');
if ($inputHandle === false) {
    fwrite(STDERR, "Failed to open input file: $input\n");
    exit(1);
}

$record = 0;
while (($line = fgets($inputHandle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $row = json_decode($line, true);
    if (!is_array($row)) {
        fwrite(STDERR, "Invalid JSONL record at line " . ($record + 1) . "\n");
        exit(1);
    }

    $csvRow = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $row)) {
            fwrite(STDERR, "Missing field '$field' at JSONL line " . ($record + 1) . "\n");
            exit(1);
        }
        $csvRow[] = $row[$field];
    }

    $record++;
    $shard = (($record - 1) % $shards) + 1;
    fputcsv($handles[$shard], $csvRow, ',', '"', '');
}

fclose($inputHandle);
foreach ($handles as $handle) {
    fclose($handle);
}

touch("$tmpDir/.complete", $sourceMtime);

for ($n = 1; $n <= $shards; $n++) {
    $target = sprintf('%s/shard%d.csv', $outputDir, $n);
    if (is_file($target)) {
        unlink($target);
    }
}
if (is_file($completeMarker)) {
    unlink($completeMarker);
}

for ($n = 1; $n <= $shards; $n++) {
    $source = sprintf('%s/shard%d.csv', $tmpDir, $n);
    $target = sprintf('%s/shard%d.csv', $outputDir, $n);
    if (!rename($source, $target)) {
        fwrite(STDERR, "Failed to move $source to $target\n");
        exit(1);
    }
}
rename("$tmpDir/.complete", $completeMarker);
rmdir($tmpDir);

echo "Prepared $record JSONL records into $shards CSV shards\n";
