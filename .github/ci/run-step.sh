#!/usr/bin/env bash
# Runs a command, keeps its output in a log, and on failure turns the useful lines into "::error::" annotations
# (at most 10) so they can be read through the GitHub API without opening the raw log.
#
#   run-step.sh <title> <command> [args...]
set -u

title="${1:?title}"
shift

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
log="$(mktemp "${RUNNER_TEMP:-/tmp}/step.XXXXXX.log")"

"$@" 2>&1 | tee "$log"
status="${PIPESTATUS[0]}"

if [ "$status" -ne 0 ]; then
  "$dir/annotate.sh" "$log" "$title"
fi

exit "$status"
