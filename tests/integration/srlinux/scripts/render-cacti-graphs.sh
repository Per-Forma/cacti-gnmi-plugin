#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file
[[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]] || \
	cacti_die "$CACTI_CONTAINER is not healthy"

renderer_source="$CACTI_LAB_ROOT/cacti/render-srl-graphs.php"
renderer_target=/tmp/render-srl-graphs.php
container_output=/tmp/gnmi-srl-graphs
host_output=${1:-${GNMI_GRAPH_OUTPUT_DIR:-"$CACTI_LAB_ROOT/artifacts/cacti-graphs"}}

mkdir -p "$host_output"
docker cp "$renderer_source" "$CACTI_CONTAINER:$renderer_target"
docker exec -u root "$CACTI_CONTAINER" mkdir -p "$container_output"
docker exec -u root "$CACTI_CONTAINER" chown www-data:www-data "$container_output"
docker exec -u www-data "$CACTI_CONTAINER" php "$renderer_target" | tee "$host_output/manifest.json"
docker cp "$CACTI_CONTAINER:$container_output/." "$host_output/"

printf 'Rendered Cacti graphs to %s.\n' "$host_output"
