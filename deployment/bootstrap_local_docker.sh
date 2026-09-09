#!/usr/bin/env bash
# Build the gNMI runtime virtualenv with the Python interpreter in local Cacti Docker.

set -Eeuo pipefail

usage() {
	cat >&2 <<'EOF'
Usage: deployment/bootstrap_local_docker.sh [--container NAME] [--plugin-path PATH]

Defaults:
  container:   cacti_app
  plugin path: /var/www/html/cacti/plugins/gnmi
EOF
}

CONTAINER="${GNMI_CACTI_CONTAINER:-cacti_app}"
PLUGIN_PATH="${GNMI_CACTI_PLUGIN_PATH:-/var/www/html/cacti/plugins/gnmi}"

while [[ $# -gt 0 ]]; do
	case "$1" in
		--container)
			[[ $# -ge 2 ]] || { usage; exit 2; }
			CONTAINER=$2
			shift 2
			;;
		--plugin-path)
			[[ $# -ge 2 ]] || { usage; exit 2; }
			PLUGIN_PATH=${2%/}
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			usage
			exit 2
			;;
	esac
done

if [[ ! "$CONTAINER" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
	printf 'ERROR: invalid container name: %s\n' "$CONTAINER" >&2
	exit 2
fi
if [[ "$PLUGIN_PATH" != /* || "$PLUGIN_PATH" == / || ! "$PLUGIN_PATH" =~ ^/[A-Za-z0-9_./-]+$ ]]; then
	printf 'ERROR: plugin path must be a specific absolute container path: %s\n' "$PLUGIN_PATH" >&2
	exit 2
fi

VENV_PATH="$PLUGIN_PATH/venv"
REQUIREMENTS_PATH="$PLUGIN_PATH/scripts/requirements.txt"
STAMP_PATH="$VENV_PATH/.gnmi-requirements.sha256"
BUILD_PATH="$VENV_PATH.bootstrap.$$"
BUILD_STAMP_PATH="$BUILD_PATH/.gnmi-requirements.sha256"
BUILD_STARTED=0

if ! command -v docker >/dev/null 2>&1; then
	echo 'ERROR: docker CLI not found on host.' >&2
	exit 1
fi
if [[ "$(docker inspect --format '{{.State.Running}}' "$CONTAINER" 2>/dev/null || true)" != true ]]; then
	printf "ERROR: Cacti container '%s' is not running.\n" "$CONTAINER" >&2
	exit 1
fi
if ! docker exec "$CONTAINER" test -f "$REQUIREMENTS_PATH"; then
	printf 'ERROR: requirements file not found in container: %s\n' "$REQUIREMENTS_PATH" >&2
	exit 1
fi

cleanup() {
	if [[ "$BUILD_STARTED" -eq 1 ]]; then
		docker exec -u root "$CONTAINER" rm -rf -- "$BUILD_PATH" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

requirements_hash() {
	docker exec "$CONTAINER" sha256sum "$REQUIREMENTS_PATH" | awk '{print $1}'
}

venv_is_compatible() {
	docker exec "$CONTAINER" "$VENV_PATH/bin/python3" -c \
		'import sys; assert sys.prefix != sys.base_prefix; assert sys.version_info >= (3, 12); import pip' \
		>/dev/null 2>&1
}

dependencies_are_current() {
	local expected_hash=$1
	local installed_hash
	installed_hash=$(docker exec "$CONTAINER" sh -c 'test -f "$1" && cat "$1"' sh "$STAMP_PATH" 2>/dev/null || true)
	[[ "$installed_hash" == "$expected_hash" ]] || return 1
	docker exec "$CONTAINER" "$VENV_PATH/bin/python3" -c \
		'import grpc, pygnmi, pymysql, rrdtool, yaml' >/dev/null 2>&1 || return 1
	docker exec "$CONTAINER" "$VENV_PATH/bin/python3" -m pip check >/dev/null 2>&1
}

EXPECTED_HASH=$(requirements_hash)
if venv_is_compatible && dependencies_are_current "$EXPECTED_HASH"; then
	printf 'gNMI Docker virtualenv is already current: %s:%s\n' "$CONTAINER" "$VENV_PATH"
	exit 0
fi

if venv_is_compatible; then
	echo 'The container virtualenv is compatible, but its pinned dependencies are stale or incomplete.'
else
	echo 'The existing gNMI virtualenv is missing or incompatible with the Cacti container.'
fi
echo 'Building a fresh environment inside the container...'

if ! docker exec "$CONTAINER" python3 -c \
	'import sys; assert sys.version_info >= (3, 12), sys.version'; then
	echo 'ERROR: the Cacti container must provide Python 3.12 or later.' >&2
	exit 1
fi

# Installing an already-present OS package is idempotent. This only runs on the
# rebuild path, keeping the normal no-op path fast and offline-friendly.
if docker exec "$CONTAINER" sh -c 'command -v apt-get >/dev/null'; then
	docker exec -u root "$CONTAINER" sh -c \
		'apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y python3-venv python3-dev librrd-dev'
elif docker exec "$CONTAINER" sh -c 'command -v dnf >/dev/null'; then
	docker exec -u root "$CONTAINER" dnf install -y python3-devel rrdtool-devel
elif docker exec "$CONTAINER" sh -c 'command -v yum >/dev/null'; then
	docker exec -u root "$CONTAINER" yum install -y python3-devel rrdtool-devel
else
	echo 'ERROR: unsupported container package manager; install Python venv/development and RRDtool development packages.' >&2
	exit 1
fi

BUILD_STARTED=1
docker exec -u root "$CONTAINER" rm -rf -- "$BUILD_PATH"
docker exec -u root "$CONTAINER" python3 -m venv "$BUILD_PATH"
docker exec -u root "$CONTAINER" "$BUILD_PATH/bin/python3" -m pip install -r "$REQUIREMENTS_PATH"
docker exec "$CONTAINER" "$BUILD_PATH/bin/python3" -c \
	'import grpc, pygnmi, pymysql, rrdtool, yaml'
docker exec "$CONTAINER" "$BUILD_PATH/bin/python3" -m pip check
docker exec -u root "$CONTAINER" sh -c 'printf "%s\n" "$1" > "$2"' sh "$EXPECTED_HASH" "$BUILD_STAMP_PATH"

# Swap only after the new environment passes imports and dependency checks.
docker exec -u root "$CONTAINER" rm -rf -- "$VENV_PATH"
docker exec -u root "$CONTAINER" mv -- "$BUILD_PATH" "$VENV_PATH"
BUILD_STARTED=0

printf 'gNMI Docker virtualenv rebuilt and verified: %s:%s\n' "$CONTAINER" "$VENV_PATH"
