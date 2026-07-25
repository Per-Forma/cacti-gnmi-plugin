#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

timeout_seconds=${LAB_READY_TIMEOUT_SECONDS:-600}
deadline=$((SECONDS + timeout_seconds))
require_command docker
prepare_artifacts containerlab

while ((SECONDS < deadline)); do
  if container_running "$SRL_CONTAINER" &&
     container_running "$CLIENT1_CONTAINER" &&
     container_running "$CLIENT2_CONTAINER" &&
     docker exec "$CLIENT1_CONTAINER" ping -n -c 3 -W 2 198.51.100.2 >/dev/null 2>&1; then
    {
      printf 'ready_utc=%s\n' "$(date -u +%FT%TZ)"
      docker exec "$CLIENT1_CONTAINER" ip -brief address show dev eth1
      docker exec "$CLIENT1_CONTAINER" ip route show
      docker exec "$CLIENT2_CONTAINER" ip -brief address show dev eth1
      docker exec "$CLIENT2_CONTAINER" ip route show
      docker exec "$CLIENT1_CONTAINER" ping -n -c 5 -W 2 198.51.100.2
    } | tee "$ARTIFACT_DIR/readiness.log"
    exit 0
  fi
  sleep 5
done

"$SCRIPT_DIR/lab-status.sh" >&2 || true
docker logs --tail 200 "$SRL_CONTAINER" >&2 || true
die "lab did not become ready within $timeout_seconds seconds"
