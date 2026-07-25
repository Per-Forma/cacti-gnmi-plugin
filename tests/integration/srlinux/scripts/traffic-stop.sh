#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

if_running=0
while (($#)); do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --if-running) if_running=1 ;;
    -h|--help) printf 'Usage: %s [--dry-run] [--if-running]\n' "${0##*/}"; exit 0 ;;
    *) exit 2 ;;
  esac
  shift
done

require_command docker
if ! container_running "$CLIENT1_CONTAINER"; then
  ((if_running == 1)) && exit 0
  die "$CLIENT1_CONTAINER is not running"
fi
prepare_artifacts traffic
acquire_lock

if [[ "$DRY_RUN" == 1 ]]; then
  printf 'DRY-RUN: stop only the PID recorded in %s:/tmp/gnmi-srl-beta-ping.pid\n' "$CLIENT1_CONTAINER"
  exit 0
fi

docker exec "$CLIENT1_CONTAINER" sh -eu -c '
  pid_file=/tmp/gnmi-srl-beta-ping.pid
  if ! test -s "$pid_file"; then
    echo "traffic already stopped"
    exit 0
  fi
  pid=$(cat "$pid_file")
  case "$pid" in *[!0-9]*|"") echo "invalid scoped PID file" >&2; exit 1;; esac
  if kill -0 "$pid" 2>/dev/null; then
    test "$(cat "/proc/$pid/comm" 2>/dev/null)" = ping || {
      echo "refusing to kill non-ping process pid=$pid" >&2
      exit 1
    }
    kill "$pid"
  fi
  rm -f "$pid_file"
  echo "traffic stopped pid=$pid"
' | tee "$ARTIFACT_DIR/traffic-stop.log"
