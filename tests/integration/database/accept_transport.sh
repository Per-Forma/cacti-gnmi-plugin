#!/usr/bin/env bash
# Real transport/TLS acceptance using bytes from a labeled packaged Cacti installation.
set -Eeuo pipefail
[[ $# == 1 ]] || { echo 'Usage: accept_transport.sh DISPOSABLE_CACTI_CONTAINER' >&2; exit 2; }
source_container=$1
[[ $(docker inspect --format '{{index .Config.Labels "com.cacti.gnmi.test"}}' "$source_container") == compatibility ]] || {
  echo 'Refusing an unlabeled source installation' >&2; exit 1;
}
script_dir=$(cd -- "$(dirname -- "$0")" && pwd)
plugin=/var/www/html/cacti/plugins/gnmi
python=$plugin/venv/bin/python3
image=$(docker inspect --format '{{.Config.Image}}' "$source_container")
work=$(mktemp -d); chmod 700 "$work"
prefix=gnmi-bridge-$(date +%s)-$$
network=$prefix-net; socket_volume=$prefix-sockets; tls_volume=$prefix-tls; code_volume=$prefix-code
client=$prefix-client; database=$prefix-db; socket_db=$prefix-socket-db
cleanup(){
  docker rm -f -v "$client" "$database" "$socket_db" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  docker volume rm "$socket_volume" "$tls_volume" "$code_volume" >/dev/null 2>&1 || true
  rm -rf -- "$work"
}
trap cleanup EXIT
for volume in "$socket_volume" "$tls_volume" "$code_volume"; do docker volume create "$volume" >/dev/null; done
docker network create "$network" >/dev/null
docker create --name "$client" --user www-data --entrypoint sleep --network "$network" \
  -e GNMI_TLS_MISMATCH_HOST="$database" \
  -v "$socket_volume:/sock" -v "$tls_volume:/tls:ro" -v "$code_volume:$plugin" \
  "$image" infinity >/dev/null
# Only immutable packaged runtime and the container-built venv; no telemetry or config files.
mkdir "$work/plugin"
for part in include scripts venv setup.php INFO; do docker cp "$source_container:$plugin/$part" "$work/plugin/$part"; done
docker cp "$work/plugin/." "$client:$plugin/"
docker cp "$script_dir/transport_acceptance.py" "$client:/tmp/transport-acceptance.py"
docker start "$client" >/dev/null
docker exec -u root "$client" chown -R root:root "$plugin"
docker exec -u www-data "$client" sh -c 'test ! -w /var/www/html/cacti/plugins/gnmi/scripts/gnmi_database_config.php && test ! -w /var/www/html/cacti/plugins/gnmi/venv'
docker run --rm --entrypoint "$python" -v "$code_volume:$plugin:ro" -v "$tls_volume:/tls" \
  -v "$script_dir/certificate_fixture.py:/tmp/certificate-fixture.py:ro" "$image" /tmp/certificate-fixture.py
docker run -d --name "$database" --network "$network" --network-alias bridge-db \
  -e MARIADB_ROOT_PASSWORD=disposable-bridge-root -v "$socket_volume:/sock" -v "$tls_volume:/tls:ro" \
  mariadb:10.6.21 --port=4406 --socket=/sock/mysql.sock --ssl-ca=/tls/ca.crt \
  --ssl-cert=/tls/server.crt --ssl-key=/tls/server.key >/dev/null
docker run -d --name "$socket_db" --network none \
  -e MARIADB_ROOT_PASSWORD=disposable-bridge-root -v "$socket_volume:/sock" \
  mariadb:10.6.21 --skip-networking --socket=/sock/only.sock >/dev/null
for spec in "$database:/sock/mysql.sock" "$socket_db:/sock/only.sock"; do
  container=${spec%%:*}; socket=${spec#*:}
  ready=false
  for attempt in $(seq 1 60); do
    if docker logs "$container" 2>&1 | grep -q "init process done" && docker exec "$container" mariadb --socket="$socket" -uroot -pdisposable-bridge-root -e "SELECT 1" >/dev/null 2>&1; then ready=true; break; fi
    sleep 1
  done
  [[ "$ready" == true ]] || { echo 'Disposable DB startup failed' >&2; exit 1; }
done
docker exec -u www-data "$client" "$python" /tmp/transport-acceptance.py

# Exercise the complete Cacti collector/RRD path with genuinely unwritable native code.
# Bootstrap writes only Cacti's canonical config/DB and the explicitly writable runtime.
docker cp "$script_dir/setup_native_database.py" "$client:/tmp/setup-native-database.py"
docker cp "$script_dir/native_parent_config.php" "$client:/tmp/native-parent-config.php"
docker exec -u www-data "$client" "$python" /tmp/setup-native-database.py
docker exec -u root "$client" mkdir -p "$plugin/tests/integration/cacti_compat" "$plugin/runtime/storage" "$plugin/runtime/logs" "$plugin/runtime/certs"
docker cp "$script_dir/../cacti_compat/test_bridge_database_real.php" "$client:$plugin/tests/integration/cacti_compat/test_bridge_database_real.php"
docker exec -u root "$client" chown -R www-data:www-data "$plugin/runtime"
for route in tls socket; do
  if [[ "$route" == tls ]]; then server=$database; db_socket=/sock/mysql.sock; else server=$socket_db; db_socket=/sock/only.sock; fi
  docker exec "$client" cat /var/www/html/cacti/cacti.sql | docker exec -i "$server" mariadb --socket="$db_socket" -uroot -pdisposable-bridge-root cacti
  docker exec -u www-data "$client" php /tmp/native-parent-config.php "$route"
  docker exec -u www-data "$client" php /var/www/html/cacti/cli/install_cacti.php --accept-eula --install --force --mode=1 --cron=60 >"$work/native-install-$route.txt" 2>&1
  docker exec -u www-data "$client" php /var/www/html/cacti/cli/plugin_manage.php --plugin=gnmi --install --enable >"$work/native-plugin-$route.txt" 2>&1
  docker exec -u www-data "$client" php -r 'chdir("/var/www/html/cacti");require "./include/cli_check.php";api_plugin_enable("gnmi");'
  docker exec -u www-data "$client" sh -c 'test ! -w /var/www/html/cacti/plugins/gnmi && test ! -w /var/www/html/cacti/plugins/gnmi/scripts && test ! -w /var/www/html/cacti/plugins/gnmi/venv'
  docker exec -u www-data "$client" php "$plugin/tests/integration/cacti_compat/test_bridge_database_real.php" >"$work/native-flow-$route.json" 2>"$work/native-flow-$route.stderr"
  python3 - "$work/native-flow-$route.json" <<'PYVALIDATE'
import json, sys
from pathlib import Path
result=json.loads(Path(sys.argv[1]).read_text())
assert result['result']=='passed' and result['exact_rrd_samples']==12
print(json.dumps(result))
PYVALIDATE
done
