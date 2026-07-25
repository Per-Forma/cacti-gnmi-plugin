#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
LAB_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)
TOPOLOGY_FILE=${TOPOLOGY_FILE:-"$LAB_ROOT/cacti-gnmi-srl.clab.yml"}
LAB_NAME=cacti-gnmi-srl
LAB_DIR="$LAB_ROOT/clab-$LAB_NAME"
MGMT_NETWORK=gnmi_srl_beta_mgmt
MGMT_SUBNET=10.253.0.0/20
SRL_CONTAINER=clab-cacti-gnmi-srl-srl1
CLIENT1_CONTAINER=clab-cacti-gnmi-srl-client1
CLIENT2_CONTAINER=clab-cacti-gnmi-srl-client2
SRL_TARGET=clab-cacti-gnmi-srl-srl1
CONTAINERLAB_VERSION=0.77.0
SRL_IMAGE=ghcr.io/nokia/srlinux:26.3.3
GNMIC_IMAGE=ghcr.io/openconfig/gnmic:0.45.0
TRAFFIC_IMAGE=ghcr.io/srl-labs/network-multitool:sha-ccaa771
RUN_ID=${RUN_ID:-"$(date -u +%Y%m%dT%H%M%SZ)-$(git -C "$LAB_ROOT" rev-parse --short HEAD 2>/dev/null || printf unknown)"}
ARTIFACT_ROOT=${ARTIFACT_ROOT:-"$LAB_ROOT/artifacts/$RUN_ID"}
LAB_LOCK_FILE=${LAB_LOCK_FILE:-/var/lock/gnmi-srl-beta.lock}
DRY_RUN=${DRY_RUN:-0}
export RUN_ID ARTIFACT_ROOT LAB_LOCK_FILE DRY_RUN TOPOLOGY_FILE

case "$RUN_ID" in
  *[!A-Za-z0-9._-]*|'')
    printf 'RUN_ID may contain only letters, digits, dot, underscore, and hyphen\n' >&2
    exit 2
    ;;
esac

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

run() {
  if [[ "$DRY_RUN" == 1 ]]; then
    printf 'DRY-RUN:'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi
  "$@"
}

prepare_artifacts() {
  local section=$1
  ARTIFACT_DIR="$ARTIFACT_ROOT/$section"
  if [[ "$DRY_RUN" == 1 ]]; then
    printf 'DRY-RUN: mkdir -p %q\n' "$ARTIFACT_DIR"
  else
    umask 077
    mkdir -p "$ARTIFACT_DIR"
  fi
}

acquire_lock() {
  [[ "$DRY_RUN" == 1 ]] && return 0
  require_command flock
  if { true >&9; } 2>/dev/null && flock -n 9 2>/dev/null; then
    return 0
  fi
  if ! exec 9>"$LAB_LOCK_FILE"; then
    die "cannot open lifecycle lock $LAB_LOCK_FILE; bootstrap it for this user or run with approved privileges"
  fi
  flock -n 9 || die "another worker holds $LAB_LOCK_FILE"
}

container_running() {
  docker inspect --format '{{.State.Running}}' "$1" 2>/dev/null | grep -qx true
}

write_image_evidence() {
  local output=$1
  local image
  : >"$output"
  for image in "$SRL_IMAGE" "$TRAFFIC_IMAGE" "$GNMIC_IMAGE"; do
    docker image inspect --format '{{json .}}' "$image" >>"$output" 2>/dev/null || true
  done
}
