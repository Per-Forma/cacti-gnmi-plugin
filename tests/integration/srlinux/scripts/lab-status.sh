#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

require_command docker
printf '%-42s %s\n' CONTAINER STATE
for container in "$SRL_CONTAINER" "$CLIENT1_CONTAINER" "$CLIENT2_CONTAINER"; do
  state=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null || printf absent)
  printf '%-42s %s\n' "$container" "$state"
done

if command -v containerlab >/dev/null 2>&1 && [[ -f "$TOPOLOGY_FILE" ]]; then
  containerlab inspect --topo "$TOPOLOGY_FILE" || true
fi

if docker network inspect "$MGMT_NETWORK" >/dev/null 2>&1; then
  docker network inspect --format 'network={{.Name}} subnet={{(index .IPAM.Config 0).Subnet}} endpoints={{len .Containers}}' "$MGMT_NETWORK"
else
  printf 'network=%s state=absent\n' "$MGMT_NETWORK"
fi
