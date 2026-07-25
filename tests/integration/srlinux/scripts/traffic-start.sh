#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

usage() {
  printf 'Usage: %s [--dry-run] [--interval SECONDS]\n' "${0##*/}"
}

interval=${PING_INTERVAL_SECONDS:-0.02}
while (($#)); do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --interval) shift; (($#)) || die '--interval requires a value'; interval=$1 ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
  shift
done

[[ "$interval" =~ ^0\.[0-9]+$|^[1-9][0-9]*(\.[0-9]+)?$ ]] || die "invalid ping interval: $interval"
require_command docker
container_running "$CLIENT1_CONTAINER" || die "$CLIENT1_CONTAINER is not running"
container_running "$CLIENT2_CONTAINER" || die "$CLIENT2_CONTAINER is not running"
prepare_artifacts traffic
acquire_lock

if [[ "$DRY_RUN" == 1 ]]; then
  printf 'DRY-RUN: start a scoped ping from %s to 198.51.100.2 every %s seconds\n' "$CLIENT1_CONTAINER" "$interval"
  exit 0
fi

docker exec -e PING_INTERVAL="$interval" "$CLIENT1_CONTAINER" sh -eu -c '
  pid_file=/tmp/gnmi-srl-beta-ping.pid
  if test -s "$pid_file"; then
    pid=$(cat "$pid_file")
    if kill -0 "$pid" 2>/dev/null && test "$(cat "/proc/$pid/comm" 2>/dev/null)" = ping; then
      echo "traffic already running pid=$pid"
      exit 0
    fi
  fi
  rm -f "$pid_file"
  nohup ping -n -i "$PING_INTERVAL" 198.51.100.2 >/tmp/gnmi-srl-beta-ping.log 2>&1 &
  echo "$!" >"$pid_file"
  echo "traffic started pid=$! interval=$PING_INTERVAL target=198.51.100.2"
' | tee "$ARTIFACT_DIR/traffic-start.log"

sleep 2
"$SCRIPT_DIR/traffic-status.sh" | tee -a "$ARTIFACT_DIR/traffic-start.log"
