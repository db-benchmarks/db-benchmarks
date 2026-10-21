#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
source "$repo/core/nightly_results.sh"
VERSION=27.1.5
SHORT_HASH=5a1cf
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cd "$work"

row='{"engine":"manticoresearch:rowwise","memory":110000}'
col='{"engine":"manticoresearch:columnar","memory":110000}'
default='{"engine":"manticoresearch","memory":110000}'
small='{"engine":"manticoresearch:rowwise","memory":1024}'
limited='{"engine":"manticoresearch:rowwise","memory":110000,"limited":true}'

expect() {
  local want=$1 label=$2 config=$3 phase=${4:-initial} test=${5:-hn_small_vector}
  local actual=miss
  if has_config_result "$test" "$config" "$phase"; then actual=hit; fi
  if [[ $actual != "$want" ]]; then
    printf 'FAIL: %s expected %s, got %s\n' "$label" "$want" "$actual" >&2
    exit 1
  fi
  printf 'PASS: %s\n' "$label"
}

# Generate exactly the writer's directory/name pattern and serialized fields.
make_result() {
  local dir=$1 test=$2 type=$3 memory=$4 limit=$5 phase=$6 version=${7:-$VERSION} hash=${8:-$SHORT_HASH} note=${9:-clean}
  local filename="${test}_manticoresearch_${type}_${memory}"
  [[ $limit == true ]] && filename+=_limited
  [[ $phase == retest ]] && filename+=_retest
  mkdir -p "$dir/260101_000000"
  php -r '
    [$file, $test, $type, $memory, $limit, $phase, $version, $hash, $note] = array_slice($argv, 1);
    $result = [
      "testName" => $test, "engine" => "manticoresearch",
      "type" => ltrim($type . "_" . $version, "_"),
      "memory" => $memory, "limited" => (int) ($limit === "true"),
      "info" => ["version" => $version, "manticoreCommit" => $hash . "_94c10"],
      "queries" => [["retest" => $phase === "retest", "result" => [["id" => 1]], "times" => [123]]],
      "testInfo" => $note,
    ];
    file_put_contents($file, serialize($result));
  ' "$dir/260101_000000/$filename" "$test" "$type" "$memory" "$limit" "$phase" "$version" "$hash" "$note"
}

expect miss 'no result' "$row"
make_result results/nightly/hn_small_vector/manticoresearch_rowwise hn_small_vector rowwise 110000 false initial
expect hit 'current nightly rowwise result' "$row"
expect miss 'rowwise cannot satisfy columnar' "$col"
expect miss 'initial cannot satisfy retest' "$row" retest
expect miss 'memory is part of identity' "$small"
expect miss 'limited is part of identity' "$limited"
expect miss 'untyped is distinct from typed' "$default"
expect miss 'test suite is part of identity' "$row" initial hn
make_result results/nightly/hn_small_vector/manticoresearch_rowwise hn_small_vector rowwise 110000 false retest
expect hit 'retest result' "$row" retest
make_result results/hn_small_vector/manticoresearch hn_small_vector columnar 110000 false initial
expect hit 'legacy common directory' "$col"
make_result results/nightly/hn_small_vector/manticoresearch_columnar hn_small_vector columnar 110000 false retest
expect hit 'current nightly columnar retest directory' "$col" retest
make_result results/hn_small_vector/manticoresearch hn_small_vector '' 110000 false initial
expect hit 'untyped result' "$default"
make_result results/hn_small_vector/manticoresearch_rowwise hn_small_vector rowwise 1024 false initial
expect hit 'second memory' "$small"
make_result results/hn_small_vector/manticoresearch_rowwise hn_small_vector rowwise 110000 true initial
expect hit 'limited result' "$limited"

all_configured() {
  local phase=$1 config
  while IFS= read -r config; do
    has_config_result hn_small_vector "$config" "$phase" || return 1
  done < <(jq -c '.tests.hn_small_vector[]' "$repo/configs/nightly.json")
}
all_configured initial
all_configured retest
printf 'PASS: every configured vector variant is present for both phases\n'
rm results/nightly/hn_small_vector/manticoresearch_columnar/260101_000000/hn_small_vector_manticoresearch_columnar_110000_retest
if all_configured retest; then
  printf 'FAIL: missing columnar retest incorrectly completes suite\n' >&2
  exit 1
fi
printf 'PASS: missing columnar retest prevents suite-wide skip\n'
make_result results/nightly/hn_small_vector/manticoresearch_columnar hn_small_vector columnar 110000 false retest
rm results/nightly/hn_small_vector/manticoresearch_rowwise/260101_000000/hn_small_vector_manticoresearch_rowwise_110000
if all_configured initial; then
  printf 'FAIL: missing rowwise initial incorrectly completes suite\n' >&2
  exit 1
fi
printf 'PASS: missing rowwise initial prevents suite-wide skip\n'

# Correct-looking filenames must not mask incorrect or damaged result records.
for case in version hash engine type memory limited testName queries phase error secondError missingResult emptyTimes invalidTimes corrupt; do
  dir="results/bad_$case/manticoresearch_columnar/260101_000000"
  mkdir -p "$dir"
  file="$dir/bad_${case}_manticoresearch_columnar_110000"
  php -r '
    [$file, $case] = array_slice($argv, 1);
    $d = ["testName" => "bad_" . $case, "engine" => "manticoresearch", "type" => "columnar_27.1.5", "memory" => 110000, "limited" => 0,
      "info" => ["version" => "27.1.5", "manticoreCommit" => "5a1cf_94c10"], "queries" => [["retest" => false, "result" => [["id" => 1]], "times" => [123]]],
      "testInfo" => "27.1.5 5a1cf (decoy text)"];
    switch ($case) {
      case "version": $d["info"]["version"] = "127.1.5"; break;
      case "hash": $d["info"]["manticoreCommit"] = "other_94c10"; break;
      case "engine": $d["engine"] = "elasticsearch"; break;
      case "type": $d["type"] = "rowwise_27.1.5"; break;
      case "memory": $d["memory"] = 1024; break;
      case "limited": $d["limited"] = 1; break;
      case "testName": $d["testName"] = "other"; break;
      case "queries": $d["queries"] = []; break;
      case "phase": $d["queries"][0]["retest"] = true; break;
      case "error": $d["queries"][0]["result"] = ["error" => ["message" => "engine crashed"]]; break;
      case "secondError": $d["queries"][] = ["retest" => false, "result" => ["error" => ["message" => "query failed"]], "times" => [1]]; break;
      case "missingResult": unset($d["queries"][0]["result"]); break;
      case "emptyTimes": $d["queries"][0]["times"] = []; break;
      case "invalidTimes": $d["queries"][0]["times"] = ["not a duration"]; break;
      case "corrupt": file_put_contents($file, "not serialized"); exit;
    }
    file_put_contents($file, serialize($d));
  ' "$file" "$case"
  expect miss "reject $case mismatch despite decoy text" "$col" initial "bad_$case"
done

# A malformed earlier candidate must not hide a later valid timestamp.
mkdir -p results/recovery/manticoresearch_columnar/260102_000000
php -r '$d=["testName"=>"recovery","engine"=>"manticoresearch","type"=>"columnar_27.1.5","memory"=>110000,"limited"=>0,"info"=>["version"=>"27.1.5","manticoreCommit"=>"5a1cf_94c10"],"queries"=>[["retest"=>false,"result"=>[["id"=>1]],"times"=>[123]]]];file_put_contents($argv[1],serialize($d));' results/recovery/manticoresearch_columnar/260102_000000/recovery_manticoresearch_columnar_110000
expect hit 'later valid candidate' "$col" initial recovery

# The test's fixture shape is also checked against an actual saved result.
cd "$repo"
if [[ -f results/hn/manticoresearch/260811_115131/hn_manticoresearch_rowwise_tuned_110000_retest ]]; then
  tuned_row='{"engine":"manticoresearch:rowwise_tuned","memory":110000}'
  tuned_col='{"engine":"manticoresearch:columnar_tuned","memory":110000}'
  expect hit 'real saved legacy rowwise initial artifact' "$tuned_row" initial hn
  expect hit 'real saved legacy retest artifact' "$tuned_row" retest hn
  expect hit 'real saved legacy columnar initial artifact' "$tuned_col" initial hn
  expect hit 'real saved legacy columnar retest artifact' "$tuned_col" retest hn
  SHORT_HASH=00000
  expect miss 'real saved artifact with wrong image hash' "$tuned_row" initial hn
fi
printf 'All nightly result lookup checks passed.\n'
