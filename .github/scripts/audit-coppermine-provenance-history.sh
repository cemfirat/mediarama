#!/usr/bin/env bash
set -euo pipefail

history_count="$(git rev-list --count HEAD)"
if [ "$history_count" -lt 2 ]; then
  echo "FAIL: shallow or incomplete Git history; provenance audit requires full history."
  exit 1
fi

patch_file="$(mktemp)"
match_file="$(mktemp)"
trap 'rm -f "$patch_file" "$match_file"' EXIT

# Scan every reachable revision of application/test code. Research documentation,
# CI plumbing and the synthetic Coppermine SQL fixtures are excluded because they
# intentionally contain source identifiers and schema facts.
git log HEAD --format= --patch --full-history --   src migrations config templates assets public bin tests   ':(exclude)tests/Fixtures/Coppermine/**'   > "$patch_file"

patterns=(
  'Coppermine Dev Team'
  'IN_COPPERMINE'
  'cpg_db_query[[:space:]]*\('
  'CPGPluginAPI::'
  'template_eval[[:space:]]*\('
  'cpg_die[[:space:]]*\('
  'get_pic_url[[:space:]]*\('
  'cpg_get_type[[:space:]]*\('
  'cpg_get_alb_keyword'
  'add_picture[[:space:]]*\('
  'cpgSanitize'
  'cpg_config_set[[:space:]]*\('
  'copyright.*Coppermine'
  'coppermine\.sourceforge\.net'
  'This program is free software'
)

failed=0
for pattern in "${patterns[@]}"; do
  if grep -Ein -- "$pattern" "$patch_file" > "$match_file"; then
    echo "FAIL: historical Coppermine implementation marker matched: $pattern"
    head -n 20 "$match_file"
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  exit 1
fi

echo "OK: scanned $history_count commits reachable from HEAD; no configured Coppermine implementation markers found in application/test history."
