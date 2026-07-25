#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
	printf 'Usage: %s <destination|container:path>\n' "$0" >&2
}

if [[ $# -ne 1 || -z ${1:-} ]]; then
	usage
	exit 2
fi

PROJECT_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
DESTINATION=$1
STAGING_ROOT=$(mktemp -d)
trap 'rm -rf "$STAGING_ROOT"' EXIT
STAGED_PLUGIN="$STAGING_ROOT/gnmi"

mkdir -p "$STAGED_PLUGIN/scripts/gnmi_collector"

for item in \
	setup.php INFO index.php status.php ajax_handler.php test_connection.php \
	pages include README.md LICENSE; do
	if [[ -e "$PROJECT_ROOT/$item" ]]; then
		cp -R "$PROJECT_ROOT/$item" "$STAGED_PLUGIN/"
	fi
done

for item in \
	DAEMON_README.md README.md __init__.py analyze_daemon_logs.py \
	check_data_continuity.py gnmi_connection_test.py gnmi_daemon.py \
	gnmi_daemon_ctl.py gnmi_daemon_monitor.py gnmi_poller_bridge.py \
	gnmi_runtime.py requirements.txt requirements-installed.txt; do
	cp "$PROJECT_ROOT/scripts/$item" "$STAGED_PLUGIN/scripts/"
done
cp -R "$PROJECT_ROOT/scripts/gnmi_collector/." \
	"$STAGED_PLUGIN/scripts/gnmi_collector/"

if find "$STAGED_PLUGIN" -type f \( \
	-name '*.rrd' -o -name '*.pem' -o -name '*.key' -o -name '*.crt' -o \
	-name '*.p12' -o -name '*.pfx' -o -name '*.pyc' \
	\) -print -quit | grep -q .; then
	printf 'Refusing to deploy sensitive or generated files.\n' >&2
	exit 1
fi

if [[ "$DESTINATION" == *:* ]]; then
	CONTAINER=${DESTINATION%%:*}
	CONTAINER_PATH=${DESTINATION#*:}
	CONTAINER_PATH=${CONTAINER_PATH%/}
	docker inspect "$CONTAINER" >/dev/null
	docker exec "$CONTAINER" mkdir -p "$CONTAINER_PATH"
	docker cp "$STAGED_PLUGIN/." "$CONTAINER:$CONTAINER_PATH/"
	printf 'Deployed plugin to %s:%s\n' "$CONTAINER" "$CONTAINER_PATH"
else
	mkdir -p "$DESTINATION"
	cp -R "$STAGED_PLUGIN/." "$DESTINATION/"
	printf 'Deployed plugin to %s\n' "$DESTINATION"
fi
