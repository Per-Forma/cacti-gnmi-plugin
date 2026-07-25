#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file

cacti_health=$(cacti_container_health "$CACTI_CONTAINER")
db_health=$(cacti_container_health "$CACTI_DB_CONTAINER")
printf 'Containers: cacti=%s db=%s\n' "${cacti_health:-missing}" "${db_health:-missing}"
[[ "$cacti_health" == healthy && "$db_health" == healthy ]] || exit 1

cacti_compose exec -T db sh -ec \
	'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -Nse "SELECT cacti FROM version LIMIT 1"'

cacti_compose exec -T cacti curl -fsS \
	http://127.0.0.1/cacti/index.php >/dev/null

if cacti_compose exec -T cacti test -f /var/www/html/cacti/plugins/gnmi/setup.php; then
	cacti_compose exec -T cacti php -l \
		/var/www/html/cacti/plugins/gnmi/setup.php
	cacti_compose exec -T cacti test -x \
		/var/www/html/cacti/plugins/gnmi/venv/bin/python3
	cacti_compose exec -T cacti \
		/var/www/html/cacti/plugins/gnmi/venv/bin/python3 -c \
		'import pygnmi, rrdtool; print("plugin Python imports: OK")'
	cacti_compose exec -T db sh -ec \
		'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -Nse "SELECT directory, status FROM plugin_config WHERE directory = '\''gnmi'\''"'
else
	printf 'Plugin is not deployed yet.\n'
fi

printf 'Cacti health checks passed.\n'
