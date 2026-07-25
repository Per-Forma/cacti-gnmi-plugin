#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file
cacti_require_command docker
docker compose version >/dev/null 2>&1 || cacti_die 'Docker Compose v2 is required'
cacti_acquire_lock

"$SCRIPT_DIR/storage-preflight.sh"

if docker network inspect "$CACTI_MGMT_NETWORK" >/dev/null 2>&1; then
	actual_subnet=$(docker network inspect --format \
		'{{range .IPAM.Config}}{{if .Subnet}}{{.Subnet}}{{end}}{{end}}' \
		"$CACTI_MGMT_NETWORK")
	[[ "$actual_subnet" == "$CACTI_MGMT_SUBNET" ]] || \
		cacti_die "$CACTI_MGMT_NETWORK uses $actual_subnet, expected $CACTI_MGMT_SUBNET"
else
	docker network create --driver bridge --subnet "$CACTI_MGMT_SUBNET" \
		--label com.cacti.gnmi.lab=cacti-gnmi-srl \
		"$CACTI_MGMT_NETWORK" >/dev/null
fi

if [[ ${CACTI_BUILD:-1} == 1 ]]; then
	cacti_compose build cacti
fi
cacti_compose up -d db cacti

printf 'Waiting for Cacti health'
for _ in $(seq 1 90); do
	if [[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]]; then
		printf '\n'
		break
	fi
	printf '.'
	sleep 5
done
[[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]] || {
	printf '\nCacti did not become healthy. Recent logs follow.\n' >&2
	cacti_compose logs --tail=200 cacti db >&2
	exit 1
}

if [[ ${BOOTSTRAP_ADMIN:-1} == 1 ]]; then
	"$SCRIPT_DIR/cacti-bootstrap.sh"
fi
if [[ ${DEPLOY_PLUGIN:-1} == 1 ]]; then
	"$SCRIPT_DIR/deploy-plugin.sh"
fi

"$SCRIPT_DIR/cacti-health.sh"
printf 'Cacti is ready at http://<server>:%s/cacti/. State is retained at %s.\n' \
	"$(cacti_env_value CACTI_UI_PORT 7083)" "$(cacti_env_value LAB_STATE_DIR)"
