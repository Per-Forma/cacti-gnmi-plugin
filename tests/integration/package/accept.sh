#!/usr/bin/env bash
# Destructive lifecycle acceptance, restricted to a labeled disposable Cacti stack.
set -Eeuo pipefail
if [[ $# != 4 ]]; then
  echo 'Usage: accept.sh ARCHIVE CHECKSUM CONTAINER CACTI_URL' >&2
  exit 2
fi
archive=$1
checksum=$2
container=$3
url=$4
script_dir=$(cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(cd -- "$script_dir/../../.." && pwd)
plugin=/var/www/html/cacti/plugins/gnmi
evidence=${PACKAGE_EVIDENCE_DIR:-"$repo_root/dist/package-acceptance"}
mkdir -p "$evidence"
work=$(mktemp -d)
chmod 700 "$work"
stage=verify
finish() {
  result=$?
  python3 - "$evidence/result.json" "$result" "$stage" <<'PY'
import json, sys
from pathlib import Path
Path(sys.argv[1]).write_text(json.dumps({'status': 'passed' if sys.argv[2] == '0' else 'failed',
    'exit_code': int(sys.argv[2]), 'stage': sys.argv[3]}, indent=2) + '\n')
PY
  rm -rf -- "$work"
  exit "$result"
}
trap finish EXIT
package=$(python3 "$script_dir/verify_archive.py" "$archive" "$checksum" "$work/extracted")
cp "$checksum" "$evidence/archive.sha256"
cp "$package/MANIFEST.md" "$evidence/MANIFEST.md"
stage=disposable_preflight
[[ $(docker inspect --format '{{index .Config.Labels "com.cacti.gnmi.test"}}' "$container") == compatibility ]] || {
  echo 'Refusing lifecycle acceptance on a container without the compatibility-test label' >&2
  exit 1
}
# Never replace a populated plugin tree. Use a fresh stack/state directory.
[[ -z $(docker exec "$container" find "$plugin" -mindepth 1 -maxdepth 1 -print -quit) ]] || {
  echo 'Package acceptance requires an empty plugin directory' >&2
  exit 1
}
stage=deploy
docker cp "$package/gnmi/." "$container:$plugin/"
# Test fixtures are separate from the archive and add no runtime implementation.
docker cp "$repo_root/tests" "$container:$plugin/tests"
docker cp "$script_dir/fixture.php" "$container:/tmp/package-fixture.php"
docker exec -u root "$container" chown -R www-data:www-data "$plugin"
stage=legacy_preflight
docker exec -u www-data "$container" php "$plugin/tests/integration/cacti_compat/test_legacy_schema_guard_real.php"
manage() {
  docker exec -u www-data "$container" php /var/www/html/cacti/cli/plugin_manage.php --plugin=gnmi "$@"
}
fixture() {
  docker exec -u www-data -e PACKAGE_FIXTURE_MODE="$1" -e CACTI_ADMIN_PASSWORD "$container" php /tmp/package-fixture.php
}
stage=install
manage --install --allperms
manage --enable --allperms
fixture installed
docker exec "$container" php -v >"$evidence/php-version.txt"
docker exec "$container" "$plugin/venv/bin/python3" --version >"$evidence/python-version.txt"
docker exec "$container" "$plugin/venv/bin/python3" -m pip list --format=json >"$evidence/python-packages.json"
docker exec -u www-data "$container" "$plugin/venv/bin/python3" -c 'import grpc, pygnmi, pymysql, rrdtool, yaml'
docker exec -u www-data "$container" "$plugin/venv/bin/python3" -m pip check
stage=php_harnesses
docker exec -u www-data "$container" sh -c '
  set -e
  for suite in daemon_lifecycle status_dashboard ajax_actions; do
    for test in /var/www/html/cacti/plugins/gnmi/tests/$suite/*.php; do php "$test"; done
  done
' >"$evidence/php-harnesses.txt" 2>&1
stage=http
export CACTI_ADMIN_PASSWORD
CACTI_ADMIN_PASSWORD=$(python3 -c 'import secrets; print(secrets.token_urlsafe(32))')
fixture prepare >"$work/fixture.json"
python3 "$script_dir/http_acceptance.py" "$url" "$work/fixture.json" >"$evidence/http.txt" 2>&1
fixture snapshot >"$work/resources.json"
docker cp "$work/resources.json" "$container:/tmp/package-resources.json"
stage=disable
manage --disable
assert_stopped() {
  if docker exec "$container" pgrep -f '[/]scripts/gnmi_daemon.py --device-id'; then
    echo 'Plugin daemon remains running' >&2
    exit 1
  else
    status=$?
    [[ $status == 1 ]] || { echo 'Unable to verify daemon shutdown' >&2; exit "$status"; }
  fi
}
assert_stopped
manage --enable --allperms
fixture installed
stage=uninstall
manage --disable
manage --uninstall
assert_stopped
fixture uninstalled >"$evidence/lifecycle.txt"
stage=reinstall
manage --install --allperms
manage --enable --allperms
fixture installed >>"$evidence/lifecycle.txt"
stage=complete
echo 'PASS: packaged install, HTTP management, disable, uninstall and reinstall'
