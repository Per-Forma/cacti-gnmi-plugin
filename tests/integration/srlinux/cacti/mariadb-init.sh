#!/usr/bin/env bash

set -Eeuo pipefail

root_client=(mariadb --protocol=socket -uroot "-p${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD is required}")

printf 'Populating MariaDB timezone tables for Cacti.\n'
mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | "${root_client[@]}" mysql

escaped_user=${MARIADB_USER//\'/\'\'}
"${root_client[@]}" -e \
	"GRANT SELECT ON mysql.time_zone_name TO '${escaped_user}'@'%'; FLUSH PRIVILEGES;"
