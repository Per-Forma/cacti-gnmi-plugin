#!/usr/bin/env bash

set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"

usage() {
  printf 'Usage: %s [--dry-run|--status]\n' "${0##*/}"
}

mode=deploy
while (($#)); do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --status) mode=status ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
  shift
done

if [[ "$mode" == status ]]; then
  exec "$SCRIPT_DIR/lab-status.sh"
fi

require_command containerlab
require_command docker
[[ -f "$TOPOLOGY_FILE" ]] || die "topology not found: $TOPOLOGY_FILE"

installed_version=$(containerlab version 2>&1 | sed -nE 's/.*version:[[:space:]]*v?([0-9.]+).*/\1/p' | head -n1)
[[ "$installed_version" == "$CONTAINERLAB_VERSION" ]] || die "Containerlab $CONTAINERLAB_VERSION is required; found ${installed_version:-unknown}"

docker network inspect "$MGMT_NETWORK" >/dev/null 2>&1 || die "external Docker network $MGMT_NETWORK does not exist"
actual_subnet=$(docker network inspect --format '{{(index .IPAM.Config 0).Subnet}}' "$MGMT_NETWORK")
[[ "$actual_subnet" == "$MGMT_SUBNET" ]] || die "$MGMT_NETWORK uses $actual_subnet; expected $MGMT_SUBNET"

available_kib=$(df -Pk "$LAB_ROOT" | awk 'NR == 2 {print $4}')
((available_kib >= 20 * 1024 * 1024)) || die "at least 20 GiB free is required on the lab filesystem"

for container in "$SRL_CONTAINER" "$CLIENT1_CONTAINER" "$CLIENT2_CONTAINER"; do
  if docker container inspect "$container" >/dev/null 2>&1; then
    die "$container already exists; inspect it and use lab-destroy.sh --confirm before a fresh deployment"
  fi
done

prepare_artifacts containerlab
acquire_lock
if [[ "$DRY_RUN" == 1 ]]; then
  run containerlab deploy --topo "$TOPOLOGY_FILE"
  exit 0
fi

{
  printf 'run_id=%s\n' "$RUN_ID"
  printf 'started_utc=%s\n' "$(date -u +%FT%TZ)"
  printf 'containerlab_version=%s\n' "$installed_version"
  printf 'topology_sha256='
  sha256sum "$TOPOLOGY_FILE" | awk '{print $1}'
  printf 'srl_config_sha256='
  sha256sum "$LAB_ROOT/srl1.cli" | awk '{print $1}'
  printf 'containerlab_grpc_config_sha256='
  sha256sum "$LAB_ROOT/containerlab-grpc.cli" | awk '{print $1}'
  printf 'metric_map_sha256='
  sha256sum "$LAB_ROOT/expected/metric-map.json" | awk '{print $1}'
} >"$ARTIFACT_DIR/deploy-manifest.txt"

containerlab deploy --topo "$TOPOLOGY_FILE" 2>&1 | tee "$ARTIFACT_DIR/deploy.log"

# SR Linux 26.3.3 creates its global CLI environment file without granting
# the built-in admin user's ntwkuser group read access. Containerlab's
# postdeploy CLI can therefore report EACCES before applying its generated
# defaults, including the plaintext gNMI listener. Correct only that generated
# file, then idempotently apply Containerlab's defaults and the lab CLI as the
# runtime numeric admin identity (Docker cannot resolve this NSS-backed user by
# name). Keep the extended retry because AAA authorization can lag container
# creation even after sr_mgmt_server accepts CLI connections.
docker exec --user 0 "$SRL_CONTAINER" chmod a+r /etc/opt/srlinux/srlinux.rc
admin_uid=$(docker exec --user 0 "$SRL_CONTAINER" id -u admin)
admin_gid=$(docker exec --user 0 "$SRL_CONTAINER" id -g admin)
tls_key="$LAB_DIR/.tls/srl1/srl1.key"
tls_cert="$LAB_DIR/.tls/srl1/srl1.pem"
[[ -r "$tls_key" && -r "$tls_cert" ]] ||
  die "Containerlab did not generate the SR Linux node certificate and key"
startup_config_applied=0
for _ in $(seq 1 90); do
  if {
      printf 'set / system tls profile clab-profile\n'
      printf 'set / system tls profile clab-profile key "'
      cat "$tls_key"
      printf '"\nset / system tls profile clab-profile certificate "'
      cat "$tls_cert"
      printf '"\nset / system tls profile clab-profile authenticate-client false\n'
      cat "$LAB_ROOT/containerlab-grpc.cli"
    } | docker exec --user "$admin_uid:$admin_gid" -i "$SRL_CONTAINER" \
      sr_cli -d -e -c >"$ARTIFACT_DIR/containerlab-default-config.log" 2>&1 &&
    docker exec --user "$admin_uid:$admin_gid" -i "$SRL_CONTAINER" \
      sr_cli -d -e -c <"$LAB_ROOT/srl1.cli" \
      >"$ARTIFACT_DIR/startup-config.log" 2>&1; then
    startup_config_applied=1
    break
  fi
  sleep 2
done
if [[ "$startup_config_applied" != 1 ]]; then
  cat "$ARTIFACT_DIR/startup-config.log" >&2 || true
  die "failed to apply SR Linux startup configuration after correcting CLI file permissions"
fi

listeners_ready=0
for _ in $(seq 1 30); do
  listener_output=$(docker exec "$SRL_CONTAINER" \
    nsenter --net=/var/run/netns/srbase-mgmt ss -lnt 2>/dev/null || true)
  if grep -Eq '[:.]57400[[:space:]]' <<<"$listener_output" &&
     grep -Eq '[:.]57401[[:space:]]' <<<"$listener_output"; then
    listeners_ready=1
    break
  fi
  sleep 2
done
if [[ "$listeners_ready" != 1 ]]; then
  printf '%s\n' "$listener_output" >&2
  die "SR Linux gNMI listeners 57400 and 57401 did not both become ready"
fi

# Persist the committed partial configuration so a scoped `docker restart` of
# the SR Linux node exercises daemon recovery without erasing the dataplane.
docker exec --user "$admin_uid:$admin_gid" "$SRL_CONTAINER" sr_cli 'save startup' \
  >"$ARTIFACT_DIR/save-startup.log" 2>&1

containerlab inspect --topo "$TOPOLOGY_FILE" --format json >"$ARTIFACT_DIR/inspect.json"
write_image_evidence "$ARTIFACT_DIR/image-inspect.jsonl"
"$SCRIPT_DIR/wait-lab-ready.sh"
