#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
LAB_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)
EVIDENCE_ROOT=${GNMI_SOAK_EVIDENCE_ROOT:-"$LAB_ROOT/.state/evidence"}
mkdir -p "$EVIDENCE_ROOT"
rm -f "$EVIDENCE_ROOT/soak-complete.txt"

"$SCRIPT_DIR/soak-validate.sh" \
  --duration 3600 --interval 60 --output "$EVIDENCE_ROOT/soak-one-hour"
"$SCRIPT_DIR/soak-validate.sh" \
  --duration 86400 --interval 60 --output "$EVIDENCE_ROOT/soak-24-hour"

printf 'Both soak phases passed at %s\n' "$(date -u +%FT%TZ)" \
  >"$EVIDENCE_ROOT/soak-complete.txt"
