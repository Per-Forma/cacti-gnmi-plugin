#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file

if [[ ${1:-} != --confirm || ${CACTI_DOWN_CONFIRM:-} != gnmi-srl-beta ]]; then
	cat >&2 <<'EOF'
Refusing to stop the retained beta environment without both confirmations.

  CACTI_DOWN_CONFIRM=gnmi-srl-beta ./scripts/cacti-down.sh --confirm

This command removes only this Compose project's containers/private network.
It never removes LAB_STATE_DIR, the external gNMI network, images, or volumes.
EOF
	exit 2
fi

cacti_acquire_lock
cacti_compose down --remove-orphans

printf 'Cacti containers are down. Preserved state: %s\n' \
	"$(cacti_env_value LAB_STATE_DIR)"
printf 'No state-removal operation is provided; wait for explicit owner approval.\n'
