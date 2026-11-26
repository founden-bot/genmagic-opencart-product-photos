#!/usr/bin/env bash
# Prints the lines that explain a failure from a log as GitHub "::error::" annotations, at most 10.
# The failing checks and PHP errors come first, then the last lines of the log, so the cause and the end both show.
#
#   annotate.sh <log-file> [title]
set -u

log="${1:?log file}"
title="${2:-install-test}"

[ -s "$log" ] || { echo "::error::${title}: the step failed and wrote no output"; exit 0; }

escape() {
  # GitHub workflow command escaping: % first, then CR (lines never contain LF).
  printf '%s' "$1" | sed -e 's/%/%25/g' -e 's/\r/%0D/g'
}

{
  grep -E '^(FAIL|ERROR|Error|PHP (Fatal|Parse|Warning)|Fatal|Parse error|Warning|Notice|Deprecated)' "$log" | tail -n 7
  tail -n 3 "$log"
} | tr -d '\000' | awk 'NF && !seen[$0]++' | tail -n 10 | while IFS= read -r line; do
  printf '::error::%s: %s\n' "$title" "$(escape "${line:0:400}")"
done
