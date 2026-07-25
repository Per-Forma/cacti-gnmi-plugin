#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file
[[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]] || \
	cacti_die "$CACTI_CONTAINER is not healthy"

bootstrap_source="$CACTI_LAB_ROOT/cacti/bootstrap-srl-device.php"
bootstrap_target=/tmp/bootstrap-srl-device.php
[[ -r "$bootstrap_source" ]] || cacti_die "missing bootstrap helper: $bootstrap_source"

docker cp "$bootstrap_source" "$CACTI_CONTAINER:$bootstrap_target"
docker exec -u www-data \
	-e GNMI_TARGET="${GNMI_TARGET:-clab-cacti-gnmi-srl-srl1}" \
	-e GNMI_PORT="${GNMI_PORT:-57400}" \
	-e GNMI_USERNAME="${GNMI_USERNAME:-admin}" \
	-e GNMI_PASSWORD="${GNMI_PASSWORD:-NokiaSrl1!}" \
	-e GNMI_USE_TLS="${GNMI_USE_TLS:-1}" \
	-e GNMI_SKIP_VERIFY="${GNMI_SKIP_VERIFY:-1}" \
	-e GNMI_CA_CERT="${GNMI_CA_CERT:-}" \
	-e GNMI_TLS_OVERRIDE="${GNMI_TLS_OVERRIDE:-}" \
	"$CACTI_CONTAINER" php "$bootstrap_target"
