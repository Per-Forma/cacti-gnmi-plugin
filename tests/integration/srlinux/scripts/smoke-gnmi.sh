#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

usage() {
  printf 'Usage: %s [--tls-skip-verify|--tls-verified|--plaintext|--all]\n' "${0##*/}"
}

mode=all
while (($#)); do
  case "$1" in
    --tls-skip-verify) mode=tls-skip-verify ;;
    --tls-verified) mode=tls-verified ;;
    --plaintext) mode=plaintext ;;
    --all) mode=all ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
  shift
done

: "${GNMI_USERNAME:?Set GNMI_USERNAME in the protected remote environment}"
: "${GNMI_PASSWORD:?Set GNMI_PASSWORD in the protected remote environment}"
require_command docker
require_command jq
container_running "$SRL_CONTAINER" || die "$SRL_CONTAINER is not running"
docker network inspect "$MGMT_NETWORK" >/dev/null 2>&1 || die "network missing: $MGMT_NETWORK"
prepare_artifacts gnmic
acquire_lock

ca_file="$LAB_DIR/.tls/ca/ca.pem"
paths=(
  '/interface[name=ethernet-1/1]/statistics'
  '/interface[name=ethernet-1/2]/statistics'
)

run_check() {
  local check_mode=$1
  local port output capabilities
  local -a transport mounts common
  mounts=()
  case "$check_mode" in
    tls-skip-verify)
      port=57400
      transport=(--skip-verify)
      ;;
    tls-verified)
      port=57400
      [[ -r "$ca_file" ]] || die "Containerlab CA not found: $ca_file"
      transport=(--tls-ca /clab-ca.pem)
      mounts=(-v "$ca_file:/clab-ca.pem:ro")
      ;;
    plaintext)
      port=57401
      transport=(--insecure)
      ;;
    *) die "unknown smoke mode: $check_mode" ;;
  esac

  output="$ARTIFACT_DIR/$check_mode-get.json"
  capabilities="$ARTIFACT_DIR/$check_mode-capabilities.txt"
  common=(
    --rm --network "$MGMT_NETWORK"
    --env GNMIC_USERNAME
    --env GNMIC_PASSWORD
    "${mounts[@]}"
    "$GNMIC_IMAGE"
    --address "$SRL_TARGET:$port"
    --encoding json_ietf
    "${transport[@]}"
  )

  printf 'Running %s gNMI Capabilities and Get against %s:%s (credentials redacted)\n' "$check_mode" "$SRL_TARGET" "$port" |
    tee "$ARTIFACT_DIR/$check_mode-command.log"
  GNMIC_USERNAME="$GNMI_USERNAME" GNMIC_PASSWORD="$GNMI_PASSWORD" \
    docker run "${common[@]}" --format json capabilities >"$capabilities"
  GNMIC_USERNAME="$GNMI_USERNAME" GNMIC_PASSWORD="$GNMI_PASSWORD" \
    docker run "${common[@]}" --format json get --type state --path "${paths[0]}" --path "${paths[1]}" >"$output"

  jq -e '.. | objects | to_entries[]? | select(.key | test("(^|/)(in|out)-(octets|unicast-packets|error-packets)$")) | .value | select(type == "number" or (type == "string" and test("^[0-9]+$")))' "$output" >/dev/null ||
    die "$check_mode Get returned no expected numeric interface counters"
  grep -Eqi 'json[_-]?ietf' "$capabilities" ||
    die "$check_mode Capabilities did not advertise JSON_IETF"
  printf '%s gNMI smoke check passed\n' "$check_mode"
}

case "$mode" in
  all)
    run_check tls-skip-verify
    run_check plaintext
    run_check tls-verified
    ;;
  *) run_check "$mode" ;;
esac

write_image_evidence "$ARTIFACT_DIR/image-inspect.jsonl"
