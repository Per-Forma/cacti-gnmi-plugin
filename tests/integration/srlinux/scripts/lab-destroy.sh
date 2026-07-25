#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

usage() {
  printf 'Usage: %s [--status|--dry-run] --confirm\n' "${0##*/}"
}

confirmed=0
while (($#)); do
  case "$1" in
    --confirm) confirmed=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --status) exec "$SCRIPT_DIR/lab-status.sh" ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
  shift
done

((confirmed == 1)) || die "refusing to destroy without --confirm"
require_command containerlab
prepare_artifacts containerlab
acquire_lock

run "$SCRIPT_DIR/traffic-stop.sh" --if-running
if [[ "$DRY_RUN" == 1 ]]; then
  run containerlab destroy --topo "$TOPOLOGY_FILE"
else
  containerlab inspect --topo "$TOPOLOGY_FILE" --format json >"$ARTIFACT_DIR/pre-destroy-inspect.json" 2>/dev/null || true
  containerlab destroy --topo "$TOPOLOGY_FILE" 2>&1 | tee "$ARTIFACT_DIR/destroy.log"
fi

printf 'Lab containers were targeted by exact topology path. The lab directory, TLS material, shared network, images, and Cacti data were preserved.\n'
