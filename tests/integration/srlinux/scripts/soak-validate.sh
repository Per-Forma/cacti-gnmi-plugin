#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lab-common.sh
source "$SCRIPT_DIR/lab-common.sh"
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

usage() {
  printf 'Usage: %s [--duration SECONDS] [--interval SECONDS] [--output DIRECTORY]\n' "${0##*/}"
}

duration=3600
interval=60
output=""
while (($#)); do
  case "$1" in
    --duration) shift; (($#)) || die '--duration requires seconds'; duration=$1 ;;
    --interval) shift; (($#)) || die '--interval requires seconds'; interval=$1 ;;
    --output) shift; (($#)) || die '--output requires a directory'; output=$1 ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
  esac
  shift
done

[[ "$duration" =~ ^[1-9][0-9]*$ ]] || die "invalid duration: $duration"
[[ "$interval" =~ ^[1-9][0-9]*$ ]] || die "invalid interval: $interval"
output=${output:-"$LAB_ROOT/artifacts/soak-$(date -u +%Y%m%dT%H%M%SZ)"}
mkdir -p "$output"

events="$output/events.jsonl"
summary="$output/summary.json"
failures="$output/failures.log"
: >"$events"
: >"$failures"
rm -f "$summary"

for command in docker jq; do
  require_command "$command"
done

daemon_ctl=/var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py
python=/var/www/html/cacti/plugins/gnmi/venv/bin/python3
started_epoch=$(date +%s)
deadline=$((started_epoch + duration))
initial_pid=""
initial_rrd_one=0
initial_rrd_two=0
samples=0

fail() {
  local message=$1
  printf '%s %s\n' "$(date -u +%FT%TZ)" "$message" | tee -a "$failures" >&2
  jq -n \
    --arg status failed \
    --arg reason "$message" \
    --argjson started_epoch "$started_epoch" \
    --argjson ended_epoch "$(date +%s)" \
    --argjson samples "$samples" \
    '{status:$status,reason:$reason,started_epoch:$started_epoch,ended_epoch:$ended_epoch,samples:$samples}' \
    >"$summary"
  exit 1
}

descriptor_source="$CACTI_LAB_ROOT/cacti/describe-srl-runtime.php"
descriptor_target=/tmp/describe-srl-runtime.php
[[ -r "$descriptor_source" ]] || fail "missing runtime descriptor: $descriptor_source"
docker cp "$descriptor_source" "$CACTI_CONTAINER:$descriptor_target" ||
  fail 'unable to copy the Cacti runtime descriptor'
runtime_contract=$(docker exec --user www-data "$CACTI_CONTAINER" php "$descriptor_target") ||
  fail 'unable to resolve dynamic Cacti runtime identifiers'
device_id=$(jq -er '.device_id | select(type == "number" and . > 0)' <<<"$runtime_contract") ||
  fail 'runtime descriptor returned an invalid device ID'
rrd_one_path=$(jq -er '.rrd_files[0] | select(type == "string" and length > 0)' <<<"$runtime_contract") ||
  fail 'runtime descriptor returned an invalid first RRD path'
rrd_two_path=$(jq -er '.rrd_files[1] | select(type == "string" and length > 0)' <<<"$runtime_contract") ||
  fail 'runtime descriptor returned an invalid second RRD path'
storage="/var/www/html/cacti/plugins/gnmi/runtime/storage/device_${device_id}.json"

while :; do
  now_epoch=$(date +%s)
  timestamp=$(date -u +%FT%TZ)

  for container in "$SRL_CONTAINER" "$CLIENT1_CONTAINER" "$CLIENT2_CONTAINER" "$CACTI_CONTAINER" "$CACTI_DB_CONTAINER"; do
    running=$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)
    [[ "$running" == true ]] || fail "container is not running: $container"
  done
  [[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]] || fail "$CACTI_CONTAINER is not healthy"
  [[ $(cacti_container_health "$CACTI_DB_CONTAINER") == healthy ]] || fail "$CACTI_DB_CONTAINER is not healthy"

  docker exec "$CLIENT1_CONTAINER" ping -n -c 2 -W 2 198.51.100.2 >/dev/null 2>&1 ||
    fail 'routed client ping failed'

  health=$(docker exec "$CACTI_CONTAINER" "$python" "$daemon_ctl" \
    health --device-id "$device_id" --staleness-threshold 20 2>&1) ||
    fail "daemon health command failed: $health"
  jq -e '.running == true and .status == "connected" and .stale == false' <<<"$health" >/dev/null ||
    fail "daemon is not healthy: $health"
  daemon_pid=$(jq -r '.pid' <<<"$health")
  if [[ -z "$initial_pid" ]]; then
    initial_pid=$daemon_pid
  elif [[ "$daemon_pid" != "$initial_pid" ]]; then
    fail "daemon PID changed from $initial_pid to $daemon_pid"
  fi

  process_count=$(docker exec "$CACTI_CONTAINER" pgrep -fc \
    "[/]var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon.py --device-id $device_id") ||
    fail "unable to find the device-$device_id daemon process"
  [[ "$process_count" -eq 1 ]] || fail "expected one device-$device_id daemon, found $process_count"

  state=$(docker exec "$CACTI_CONTAINER" cat "$storage") || fail 'telemetry storage is unreadable'
  jq -e '
    .daemon_status == "connected" and
    (.rejected_subscription_paths // [] | length) == 0 and
    (.metric_groups | length) == 2 and
    (.metric_groups["ethernet-1/1"] | has("in_octets") and has("out_octets")) and
    (.metric_groups["ethernet-1/2"] | has("in_octets") and has("out_octets"))
  ' <<<"$state" >/dev/null || fail 'telemetry storage is incomplete or contains rejected paths'

  rrd_one_last=$(docker exec "$CACTI_CONTAINER" rrdtool last "$rrd_one_path") ||
    fail "unable to read first RRD timestamp: $rrd_one_path"
  rrd_two_last=$(docker exec "$CACTI_CONTAINER" rrdtool last "$rrd_two_path") ||
    fail "unable to read second RRD timestamp: $rrd_two_path"
  ((now_epoch - rrd_one_last <= 30)) || fail "first RRD is stale: last=$rrd_one_last now=$now_epoch"
  ((now_epoch - rrd_two_last <= 30)) || fail "second RRD is stale: last=$rrd_two_last now=$now_epoch"
  for rrd_path in "$rrd_one_path" "$rrd_two_path"; do
    if ! docker exec "$CACTI_CONTAINER" rrdtool fetch "$rrd_path" AVERAGE \
        --start end-120s --end now | awk '
          NR == 1 { sources = NF; next }
          NR > 2 {
            for (field = 2; field <= sources + 1; field++) {
              if (tolower($field) !~ /nan/) finite[field] = 1
            }
          }
          END {
            for (field = 2; field <= sources + 1; field++) {
              if (!finite[field]) exit 1
            }
          }
        '; then
      fail "RRD contains no recent finite value for every data source: $rrd_path"
    fi
  done
  if ((samples == 0)); then
    initial_rrd_one=$rrd_one_last
    initial_rrd_two=$rrd_two_last
  fi

  jq -cn \
    --arg timestamp "$timestamp" \
    --argjson epoch "$now_epoch" \
    --argjson daemon_pid "$daemon_pid" \
    --argjson rrd_one "$rrd_one_last" \
    --argjson rrd_two "$rrd_two_last" \
    --argjson in_octets_one "$(jq -r '.metric_groups["ethernet-1/1"].in_octets' <<<"$state")" \
    --argjson in_octets_two "$(jq -r '.metric_groups["ethernet-1/2"].in_octets' <<<"$state")" \
    '{timestamp:$timestamp,epoch:$epoch,daemon_pid:$daemon_pid,rrd_one_last:$rrd_one,rrd_two_last:$rrd_two,ethernet_1_1_in_octets:$in_octets_one,ethernet_1_2_in_octets:$in_octets_two}' \
    >>"$events"
  samples=$((samples + 1))

  ((now_epoch >= deadline)) && break
  sleep "$interval"
done

((rrd_one_last > initial_rrd_one)) || fail 'first RRD did not advance during the soak'
((rrd_two_last > initial_rrd_two)) || fail 'second RRD did not advance during the soak'

jq -n \
  --arg status passed \
  --arg daemon_pid "$initial_pid" \
  --argjson started_epoch "$started_epoch" \
  --argjson ended_epoch "$(date +%s)" \
  --argjson duration "$duration" \
  --argjson interval "$interval" \
  --argjson samples "$samples" \
  '{status:$status,daemon_pid:$daemon_pid,started_epoch:$started_epoch,ended_epoch:$ended_epoch,duration_seconds:$duration,interval_seconds:$interval,samples:$samples}' \
  >"$summary"
cat "$summary"
