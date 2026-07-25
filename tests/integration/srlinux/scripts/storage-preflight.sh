#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file
cacti_require_command docker
cacti_require_command df

LAB_STATE_DIR=${LAB_STATE_DIR:-$(cacti_env_value LAB_STATE_DIR)}
MIN_DOCKER_FREE_GB=${MIN_DOCKER_FREE_GB:-$(cacti_env_value MIN_DOCKER_FREE_GB 15)}
MIN_STATE_FREE_GB=${MIN_STATE_FREE_GB:-$(cacti_env_value MIN_STATE_FREE_GB 5)}

[[ "$LAB_STATE_DIR" == /* ]] || cacti_die 'LAB_STATE_DIR must be an absolute host path'
[[ "$MIN_DOCKER_FREE_GB" =~ ^[0-9]+$ ]] || cacti_die 'MIN_DOCKER_FREE_GB must be an integer'
[[ "$MIN_STATE_FREE_GB" =~ ^[0-9]+$ ]] || cacti_die 'MIN_STATE_FREE_GB must be an integer'

case "${DOCKER_HOST:-unix:///var/run/docker.sock}" in
	unix://*) ;;
	*) cacti_die 'storage preflight requires a local unix Docker socket so its data-root filesystem can be measured' ;;
esac

mkdir -p "$LAB_STATE_DIR"/{db,rra,log,cache,plugin}

docker_root=$(docker info --format '{{.DockerRootDir}}')
[[ -n "$docker_root" ]] || cacti_die 'Docker did not report a data-root'
if [[ ! -e "$docker_root" ]]; then
	docker_root=$(dirname -- "$docker_root")
fi

free_kb() {
	df -Pk "$1" | awk 'NR == 2 { print $4 }'
}

state_free_kb=$(free_kb "$LAB_STATE_DIR")
docker_free_kb=$(free_kb "$docker_root")
state_required_kb=$((MIN_STATE_FREE_GB * 1024 * 1024))
docker_required_kb=$((MIN_DOCKER_FREE_GB * 1024 * 1024))

printf 'Storage preflight:\n'
printf '  Docker data root: %s (%s GiB free; minimum %s GiB)\n' \
	"$docker_root" "$((docker_free_kb / 1024 / 1024))" "$MIN_DOCKER_FREE_GB"
printf '  Lab state:        %s (%s GiB free; minimum %s GiB)\n' \
	"$LAB_STATE_DIR" "$((state_free_kb / 1024 / 1024))" "$MIN_STATE_FREE_GB"

((docker_free_kb >= docker_required_kb)) || \
	cacti_die 'insufficient Docker data-root space; do not pull or build images'
((state_free_kb >= state_required_kb)) || \
	cacti_die 'insufficient LAB_STATE_DIR space; do not create the Cacti stack'

docker system df
printf 'Storage preflight passed.\n'
