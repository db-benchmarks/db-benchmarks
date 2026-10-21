#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
converter="$repo/tests/hn_small_vector/manticoresearch/storage_dump.php"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

printf '%s\n' \
  'CREATE TABLE `hn_small_vector` (' \
  "-- engine='columnar' is an explanatory comment, not an option" \
  '`id` bigint' \
  ") min_infix_len='2';" \
  'INSERT INTO `hn_small_vector` VALUES (1);' > "$work/comment.sql"
php "$converter" "$work/comment.sql" rowwise > "$work/rowwise.sql"
grep -Fqx -e "-- engine='columnar' is an explanatory comment, not an option" "$work/rowwise.sql"
grep -Fqx ") min_infix_len='2' engine='rowwise';" "$work/rowwise.sql"
grep -Fqx 'INSERT INTO `hn_small_vector` VALUES (1);' "$work/rowwise.sql"
printf 'PASS: comment is untouched; rowwise option added; insert unchanged\n'

printf '%s\n' \
  'CREATE TABLE `hn_small_vector` (' \
  '`id` bigint' \
  ") engine='rowwise' min_infix_len='2';" > "$work/existing.sql"
php "$converter" "$work/existing.sql" columnar > "$work/columnar.sql"
grep -Fqx ") engine='columnar' min_infix_len='2';" "$work/columnar.sql"
printf 'PASS: existing table engine changed to columnar\n'

printf '%s\n' 'INSERT INTO `hn_small_vector` VALUES (1);' > "$work/missing.sql"
if php "$converter" "$work/missing.sql" columnar > "$work/no-create.sql" 2>/dev/null; then
  printf 'FAIL: accepted dump without CREATE TABLE\n' >&2
  exit 1
fi
printf 'PASS: missing CREATE rejected\n'
