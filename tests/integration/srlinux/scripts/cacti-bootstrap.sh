#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=cacti-common.sh
source "$SCRIPT_DIR/cacti-common.sh"

cacti_require_env_file

admin_user=$(cacti_env_value CACTI_ADMIN_USER admin)
admin_password=$(cacti_env_value CACTI_ADMIN_PASSWORD)
[[ ${#admin_password} -ge 8 ]] || cacti_die 'CACTI_ADMIN_PASSWORD in .env must contain at least 8 characters'

cacti_compose exec -T \
	-e "CACTI_ADMIN_USER=$admin_user" \
	-e "CACTI_ADMIN_PASSWORD=$admin_password" \
	cacti php /usr/local/lib/cacti-lab/bootstrap-admin.php

printf 'Cacti bootstrap complete. UI: http://<server>:%s/cacti/\n' \
	"$(cacti_env_value CACTI_UI_PORT 7083)"
