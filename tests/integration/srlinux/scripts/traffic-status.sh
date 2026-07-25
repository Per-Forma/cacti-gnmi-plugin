#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

require_command docker
if ! container_running "$CLIENT1_CONTAINER"; then
  printf 'state=unavailable container=%s\n' "$CLIENT1_CONTAINER"
  exit 1
fi

docker exec "$CLIENT1_CONTAINER" sh -c '
  pid_file=/tmp/gnmi-srl-beta-ping.pid
  if test -s "$pid_file"; then
    pid=$(cat "$pid_file")
  else
    pid=
  fi
  if test -n "$pid" && kill -0 "$pid" 2>/dev/null && test "$(cat "/proc/$pid/comm" 2>/dev/null)" = ping; then
    echo "state=running pid=$pid"
  else
    echo "state=stopped"
  fi
'
docker exec "$CLIENT1_CONTAINER" ip -s link show dev eth1
docker exec "$CLIENT2_CONTAINER" ip -s link show dev eth1
