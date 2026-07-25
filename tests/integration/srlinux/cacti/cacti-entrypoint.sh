#!/usr/bin/env bash

set -Eeuo pipefail

CACTI_ROOT=/var/www/html/cacti

for directory in \
	"$CACTI_ROOT/rra" \
	"$CACTI_ROOT/log" \
	"$CACTI_ROOT/cache" \
	"$CACTI_ROOT/cache/boost" \
	"$CACTI_ROOT/cache/mibcache" \
	"$CACTI_ROOT/cache/realtime" \
	"$CACTI_ROOT/cache/spikekill" \
	"$CACTI_ROOT/plugins/gnmi"; do
	mkdir -p "$directory"
	chown -R www-data:www-data "$directory"
done

php /usr/local/lib/cacti-lab/render-config.php
chown www-data:www-data "$CACTI_ROOT/include/config.php"

export MYSQL_PWD=${DB_PASS:?DB_PASS is required}
db=(mariadb --protocol=tcp -h "${DB_HOST:?DB_HOST is required}" -P "${DB_PORT:-3306}" -u "${DB_USER:?DB_USER is required}" "${DB_NAME:?DB_NAME is required}")

printf 'Waiting for MariaDB at %s:%s' "$DB_HOST" "${DB_PORT:-3306}"
for _ in $(seq 1 90); do
	if "${db[@]}" -Nse 'SELECT 1' >/dev/null 2>&1; then
		printf '\n'
		break
	fi
	printf '.'
	sleep 2
done
"${db[@]}" -Nse 'SELECT 1' >/dev/null

version_table=$("${db[@]}" -Nse "SHOW TABLES LIKE 'version'")
if [[ "$version_table" != version ]]; then
	printf 'Importing the pinned Cacti baseline schema.\n'
	"${db[@]}" < "$CACTI_ROOT/cacti.sql"
fi

installed_version=$("${db[@]}" -Nse 'SELECT cacti FROM version LIMIT 1')
if [[ "$installed_version" == new_install && "${CACTI_AUTO_INSTALL:-1}" == 1 ]]; then
	printf 'Finalizing Cacti with the supported CLI installer.\n'
	su -s /bin/sh www-data -c \
		'php /var/www/html/cacti/cli/install_cacti.php --accept-eula --install --force --mode=1 --cron=60'
	installed_version=$("${db[@]}" -Nse 'SELECT cacti FROM version LIMIT 1')
fi

if [[ -z "$installed_version" || "$installed_version" == new_install ]]; then
	printf 'Cacti database initialization did not complete (version=%s).\n' "$installed_version" >&2
	exit 1
fi

printf 'Cacti database version: %s\n' "$installed_version"
unset MYSQL_PWD

cron
exec "$@"
