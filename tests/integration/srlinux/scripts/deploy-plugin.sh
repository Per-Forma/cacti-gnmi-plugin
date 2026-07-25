#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file
[[ -x "$REPO_ROOT/deploy_plugin.sh" ]] || cacti_die "missing deploy helper: $REPO_ROOT/deploy_plugin.sh"
[[ $(cacti_container_health "$CACTI_CONTAINER") == healthy ]] || \
	cacti_die "$CACTI_CONTAINER is not healthy"

"$REPO_ROOT/deploy_plugin.sh" \
	"$CACTI_CONTAINER:/var/www/html/cacti/plugins/gnmi/"

docker exec -u root "$CACTI_CONTAINER" \
	chown -R www-data:www-data /var/www/html/cacti/plugins/gnmi

# Plugin installation can create the venv, but dependency installation is
# deliberately best-effort in the Cacti hook and may not finish before the CLI
# returns. Make the container deployment deterministic before enabling it.
venv_python=/var/www/html/cacti/plugins/gnmi/venv/bin/python3
system_python_version=$(docker exec "$CACTI_CONTAINER" python3 -c \
	'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')
venv_python_version=$(docker exec "$CACTI_CONTAINER" "$venv_python" -c \
	'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")' 2>/dev/null || true)
venv_pip_ok=0
if docker exec "$CACTI_CONTAINER" "$venv_python" -m pip --version >/dev/null 2>&1; then
	venv_pip_ok=1
fi
if [[ -n "$venv_python_version" ]] && \
	{ [[ "$venv_python_version" != "$system_python_version" ]] || [[ "$venv_pip_ok" != 1 ]]; }; then
	docker exec -u root "$CACTI_CONTAINER" \
		rm -rf /var/www/html/cacti/plugins/gnmi/venv
fi
if ! docker exec "$CACTI_CONTAINER" test -x "$venv_python"; then
	docker exec -u www-data "$CACTI_CONTAINER" \
		python3 -m venv /var/www/html/cacti/plugins/gnmi/venv
fi
docker exec -u www-data "$CACTI_CONTAINER" \
	"$venv_python" -m pip install \
		--disable-pip-version-check --no-cache-dir \
		-r /var/www/html/cacti/plugins/gnmi/scripts/requirements.txt

if [[ ${INSTALL_PLUGIN:-1} == 1 ]]; then
	cacti_compose exec -T -u www-data cacti \
		php /var/www/html/cacti/cli/plugin_manage.php \
		--plugin=gnmi --install --allperms
	cacti_compose exec -T -u www-data cacti \
		php /var/www/html/cacti/cli/plugin_manage.php \
		--plugin=gnmi --enable --allperms
fi

printf 'Deployed plugin from %s to %s.\n' "$REPO_ROOT" "$CACTI_CONTAINER"
