#!/usr/bin/env bash

set -Eeuo pipefail

CACTI_SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
CACTI_LAB_ROOT=$(cd -- "$CACTI_SCRIPT_DIR/.." && pwd)
REPO_ROOT=$(cd -- "$CACTI_LAB_ROOT/../../.." && pwd)
COMPOSE_FILE=${COMPOSE_FILE:-"$CACTI_LAB_ROOT/compose.yaml"}
ENV_FILE=${ENV_FILE:-"$CACTI_LAB_ROOT/.env"}
CACTI_CONTAINER=gnmi_srl_beta_cacti
CACTI_DB_CONTAINER=gnmi_srl_beta_db
CACTI_MGMT_NETWORK=gnmi_srl_beta_mgmt
CACTI_MGMT_SUBNET=10.253.0.0/20
CACTI_LOCK_FILE=${LAB_LOCK_FILE:-/var/lock/gnmi-srl-beta.lock}

cacti_die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

cacti_require_command() {
	command -v "$1" >/dev/null 2>&1 || cacti_die "required command not found: $1"
}

cacti_require_env_file() {
	[[ -r "$ENV_FILE" ]] || cacti_die "missing $ENV_FILE; copy .env.example to .env and review its test-only values"
}

# Read a simple KEY=value from .env without evaluating it as shell code.
cacti_env_value() {
	local key=$1
	local fallback=${2:-}
	local value
	value=$(awk -v key="$key" '
		$0 !~ /^[[:space:]]*#/ && index($0, key "=") == 1 {
			print substr($0, length(key) + 2)
			exit
		}
	' "$ENV_FILE")
	value=${value%$'\r'}
	if [[ "$value" == \"*\" && "$value" == *\" ]]; then
		value=${value:1:${#value}-2}
	elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
		value=${value:1:${#value}-2}
	fi
	printf '%s\n' "${value:-$fallback}"
}

cacti_compose() {
	docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

cacti_acquire_lock() {
	cacti_require_command flock
	if ! exec 9>"$CACTI_LOCK_FILE"; then
		cacti_die "cannot open lifecycle lock $CACTI_LOCK_FILE"
	fi
	flock -n 9 || cacti_die "another worker holds $CACTI_LOCK_FILE"
}

cacti_container_health() {
	docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$1" 2>/dev/null || true
}
