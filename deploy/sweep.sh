#!/bin/sh
set -eu

interval="${LEARNING_SWEEP_INTERVAL:-30}"
case "$interval" in
    ''|*[!0-9]*|0*) echo 'LEARNING_SWEEP_INTERVAL must be a positive integer.' >&2; exit 2 ;;
esac

sleeper=
trap 'if [ -n "$sleeper" ]; then kill "$sleeper" 2>/dev/null || true; fi; exit 0' TERM INT
while :; do
    php bin/learning.php exam-sweep
    php bin/learning.php credential-sweep
    sleep "$interval" &
    sleeper=$!
    wait "$sleeper"
    sleeper=
done
