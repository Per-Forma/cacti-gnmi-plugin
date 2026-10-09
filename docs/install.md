# Release archive installation

This public beta is for **fresh test installations only**. Do not install over an
existing gNMI plugin, migrate an older schema, or use it in production. These
steps need only the official archive, its checksum, and system prerequisites.
Contributor deployment and development tests belong in
[the contribution guide](https://github.com/Per-Forma/cacti-gnmi-plugin/blob/main/CONTRIBUTING.md).

## 1. Choose the host and service identity

Require Cacti 1.2.25+, PHP 8.1+ (web/poller and CLI), Python 3.12+, Linux with
`/proc`, and Cacti's working MySQL/MariaDB connection. See
[compatibility](compatibility.md) for the tested versions. The package recipe
below targets **Debian 13**, whose `/usr/bin/python3` is Python 3.13. Other
systems need a separately verified Python 3.12+ interpreter, matching venv and
Python development packages, RRDtool headers, and a compiler; do not apply this
package list to an older Debian, CentOS, or an arbitrary container image.

Identify the actual PHP-FPM/Apache worker UID and the Cacti poller scheduler UID
before choosing `GNMI_SERVICE_USER`. This guide assumes both use the same UID;
`www-data` is the Debian example. `docker exec` defaults to root and does not
identify the application user. Different web/poller UIDs need a separately
validated access policy; group write or `0777` is not a supported shortcut.

The administrator owns plugin code and its venv. The service account owns only
runtime state. Preserve existing Cacti database and RRD ownership; verify the
poller can create/update RRDs without recursively reowning Cacti's `rra/` tree.
Back up the Cacti database and RRDs before testing. One persistent Python daemon
runs per enabled device with an enabled subscription and metric. Keep Cacti's
accepted poller interval; 10-second polling is optional and changes a global
setting affecting all devices and plugins.

## 2. Download, verify and stage outside the webroot

Download the archive and matching `.sha256` from the
[official releases](https://github.com/Per-Forma/cacti-gnmi-plugin/releases).
Choose the published version; the example below uses beta.3. These instructions
also describe the next candidate; an existing published download does not
acquire later fixes. A `-dirty` archive is a development test artifact, never a
release. Checksums detect byte changes relative to the downloaded record; obtain
both files from the trusted release source.

Run as an ordinary shell user in the download directory. GNU tar and GNU
`sha256sum` are used below. On macOS, `shasum -a 256 -c FILE.sha256` can verify the
outer checksum, but perform installation and build the venv on the Linux host.

```bash
GNMI_RELEASE_VERSION=1.0.0-beta.3
```

<!-- install-guide:verify -->
```bash
set -eu
GNMI_ARCHIVE="cacti-gnmi-plugin-${GNMI_RELEASE_VERSION}.tar.gz"
sha256sum -c "$GNMI_ARCHIVE.sha256"
GNMI_STAGE=$(mktemp -d)
chmod 700 "$GNMI_STAGE"
tar --no-same-owner -xzf "$GNMI_ARCHIVE" -C "$GNMI_STAGE"
GNMI_PACKAGE_DIR="$GNMI_STAGE/cacti-gnmi-plugin-${GNMI_RELEASE_VERSION}"
(cd "$GNMI_PACKAGE_DIR" && sha256sum -c CHECKSUMS.txt)
for GNMI_REQUIRED in setup.php INFO scripts/requirements.txt scripts/gnmi_database_config.php include/database_config.php include/poller_bridge.php; do
  test -f "$GNMI_PACKAGE_DIR/gnmi/$GNMI_REQUIRED" || {
    echo "Missing archive member: gnmi/$GNMI_REQUIRED" >&2
    exit 1
  }
done
```

Stop on any error, before copying or enabling code. Extract only the official,
verified archive into a new private staging directory. Keep `MANIFEST.md`,
`CHECKSUMS.txt`, release notes and the archive checksum outside the webroot with
your installation record. Install only **`gnmi/.`**, never the outer package
folder. Continue with either the Linux filesystem or Docker instructions.

## 3. Linux filesystem installation

Run the following as the **shell administrator (root)** on the Linux Cacti host.
For example, use `sudo -i`, then set these variables in that root shell. Set
`GNMI_PACKAGE_DIR` to the verified staging folder from step 2; if staging was on
another host, transfer and repeat verification on this host first.

```bash
GNMI_PACKAGE_DIR=/replace/with/verified/staging/cacti-gnmi-plugin-1.0.0-beta.3
GNMI_PLUGIN_DIR=/var/www/html/cacti/plugins/gnmi
GNMI_SERVICE_USER=www-data
GNMI_SERVICE_GROUP=www-data
```

Reject a symlink, ordinary file or populated destination. A deliberately
prepared empty directory is allowed. Keep its parent under administrator control
throughout copying; this is not a merge-copy or an upgrade procedure.

<!-- install-guide:destination -->
```bash
set -eu
if [ -L "$GNMI_PLUGIN_DIR" ]; then
  echo 'Plugin destination must not be a symlink.' >&2
  exit 1
fi
if [ -e "$GNMI_PLUGIN_DIR" ] && [ ! -d "$GNMI_PLUGIN_DIR" ]; then
  echo 'Plugin destination must be a directory.' >&2
  exit 1
fi
mkdir -p "$GNMI_PLUGIN_DIR"
if [ -n "$(find "$GNMI_PLUGIN_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  echo 'Fresh installation requires an empty plugin directory.' >&2
  exit 1
fi
```

<!-- install-guide:native-copy -->
```bash
set -eu
cp -a "$GNMI_PACKAGE_DIR/gnmi/." "$GNMI_PLUGIN_DIR/"
chown -R root:root "$GNMI_PLUGIN_DIR"
find "$GNMI_PLUGIN_DIR" -type d -exec chmod 0755 {} +
find "$GNMI_PLUGIN_DIR" -type f -exec chmod 0644 {} +
```

Build dependencies **at the final absolute path**, before installing/enabling
in Cacti. Do not build on macOS, move a venv, or copy one between hosts or
containers: installed launchers retain absolute paths. The archive's
`scripts/requirements.txt` is the authority for all pins; do not install a
system-only RRDtool Python binding or upgrade pip independently.

<!-- install-guide:native-dependencies -->
```bash
set -eu
apt-get update
apt-get install -y python3 python3-venv python3-dev librrd-dev build-essential pkg-config
GNMI_PYTHON=/usr/bin/python3
"$GNMI_PYTHON" -c 'import sys; assert sys.version_info >= (3, 12), "Python 3.12+ required: " + sys.version'
"$GNMI_PYTHON" -c 'import pathlib, sysconfig; assert (pathlib.Path(sysconfig.get_path("include")) / "Python.h").is_file(), "Install matching Python development headers"'
test -f /usr/include/rrd.h || { echo 'Install librrd-dev before building dependencies.' >&2; exit 1; }
umask 022
"$GNMI_PYTHON" -m venv "$GNMI_PLUGIN_DIR/venv"
"$GNMI_PLUGIN_DIR/venv/bin/python3" -m pip install -r "$GNMI_PLUGIN_DIR/scripts/requirements.txt"
"$GNMI_PLUGIN_DIR/venv/bin/python3" -m pip check
runuser -u "$GNMI_SERVICE_USER" -- "$GNMI_PLUGIN_DIR/venv/bin/python3" -c 'import sys; assert sys.version_info >= (3, 12); import grpc, pygnmi, pymysql, rrdtool, yaml'
runuser -u "$GNMI_SERVICE_USER" -- "$GNMI_PLUGIN_DIR/venv/bin/python3" -m pip check
```

A failed package download/build, unsupported Python, missing headers or failed
service-account import means stop here. The plugin must remain disabled until
administrator shell repair succeeds. Do not normalize venv files to `0644`;
its launchers and interpreter links must remain executable.

Pre-create only the runtime root; the install hook creates/protects its children.
The CLI check below prints no configuration or credentials. Also inspect the
effective PHP configuration in the **actual web/poller context**; its ini and
`disable_functions` may differ from CLI. The PHP process/stream APIs must be
callable there too. Do not expose a public `phpinfo()` or config dump.

<!-- install-guide:native-runtime -->
```bash
set -eu
install -d -o "$GNMI_SERVICE_USER" -g "$GNMI_SERVICE_GROUP" -m 0750 "$GNMI_PLUGIN_DIR/runtime"
runuser -u "$GNMI_SERVICE_USER" -- test ! -w "$GNMI_PLUGIN_DIR"
runuser -u "$GNMI_SERVICE_USER" -- test ! -w "$GNMI_PLUGIN_DIR/venv"
runuser -u "$GNMI_SERVICE_USER" -- test -w "$GNMI_PLUGIN_DIR/runtime"
runuser -u "$GNMI_SERVICE_USER" -- test -r "$(dirname "$(dirname "$GNMI_PLUGIN_DIR")")/include/config.php"
runuser -u "$GNMI_SERVICE_USER" -- php -r '
if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "PHP CLI 8.1+ required\n"); exit(1); }
foreach (["proc_open","proc_get_status","proc_terminate","proc_close","stream_select","stream_set_blocking","stream_get_contents","fread","fwrite","feof","hrtime","stream_isatty"] as $api) {
  if (!is_callable($api)) { fwrite(STDERR, "Required PHP API unavailable: $api\n"); exit(1); }
}
echo "PHP CLI process/stream prerequisites OK\n";
'
```

## 4. Docker installation

Use a **Debian 13 Cacti image with PHP CLI 8.1+** for this package recipe. Verify
its Python and actual service UID as in step 1. For another image, obtain matching
interpreter/venv/header packages first. Verify and stage on the host using step 2,
then run the following from that host shell:

```bash
GNMI_CONTAINER=cacti_app
GNMI_PLUGIN_DIR=/var/www/html/cacti/plugins/gnmi
GNMI_SERVICE_USER=www-data
GNMI_SERVICE_GROUP=www-data
```

<!-- install-guide:docker-copy -->
```bash
set -eu
docker exec -i -u root "$GNMI_CONTAINER" sh -s -- "$GNMI_PLUGIN_DIR" <<'SH'
set -eu
GNMI_PLUGIN_DIR=$1
if [ -L "$GNMI_PLUGIN_DIR" ]; then echo 'Plugin destination must not be a symlink.' >&2; exit 1; fi
if [ -e "$GNMI_PLUGIN_DIR" ] && [ ! -d "$GNMI_PLUGIN_DIR" ]; then echo 'Plugin destination must be a directory.' >&2; exit 1; fi
mkdir -p "$GNMI_PLUGIN_DIR"
if [ -n "$(find "$GNMI_PLUGIN_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  echo 'Fresh installation requires an empty plugin directory.' >&2
  exit 1
fi
SH
docker cp "$GNMI_PACKAGE_DIR/gnmi/." "$GNMI_CONTAINER:$GNMI_PLUGIN_DIR/"
docker exec -u root "$GNMI_CONTAINER" sh -c '
set -eu
chown -R root:root "$1"
find "$1" -type d -exec chmod 0755 {} +
find "$1" -type f -exec chmod 0644 {} +
' sh "$GNMI_PLUGIN_DIR"
```

<!-- install-guide:docker-dependencies -->
```bash
set -eu
docker exec -u root "$GNMI_CONTAINER" sh -c '
set -eu
apt-get update
apt-get install -y python3 python3-venv python3-dev librrd-dev build-essential pkg-config
GNMI_PYTHON=/usr/bin/python3
"$GNMI_PYTHON" -c "import sys; assert sys.version_info >= (3, 12), \"Python 3.12+ required: \" + sys.version"
"$GNMI_PYTHON" -c "import pathlib, sysconfig; assert (pathlib.Path(sysconfig.get_path(\"include\")) / \"Python.h\").is_file(), \"Install matching Python development headers\""
test -f /usr/include/rrd.h || { echo "Install librrd-dev before building dependencies." >&2; exit 1; }
umask 022
"$GNMI_PYTHON" -m venv "$1/venv"
"$1/venv/bin/python3" -m pip install -r "$1/scripts/requirements.txt"
"$1/venv/bin/python3" -m pip check
' sh "$GNMI_PLUGIN_DIR"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" "$GNMI_PLUGIN_DIR/venv/bin/python3" -c 'import sys; assert sys.version_info >= (3, 12); import grpc, pygnmi, pymysql, rrdtool, yaml'
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" "$GNMI_PLUGIN_DIR/venv/bin/python3" -m pip check
```

<!-- install-guide:docker-runtime -->
```bash
set -eu
docker exec -u root "$GNMI_CONTAINER" install -d -o "$GNMI_SERVICE_USER" -g "$GNMI_SERVICE_GROUP" -m 0750 "$GNMI_PLUGIN_DIR/runtime"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" test ! -w "$GNMI_PLUGIN_DIR"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" test ! -w "$GNMI_PLUGIN_DIR/venv"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" test -w "$GNMI_PLUGIN_DIR/runtime"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" test -r "$(dirname "$(dirname "$GNMI_PLUGIN_DIR")")/include/config.php"
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" php -r '
if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "PHP CLI 8.1+ required\n"); exit(1); }
foreach (["proc_open","proc_get_status","proc_terminate","proc_close","stream_select","stream_set_blocking","stream_get_contents","fread","fwrite","feof","hrtime","stream_isatty"] as $api) {
  if (!is_callable($api)) { fwrite(STDERR, "Required PHP API unavailable: $api\n"); exit(1); }
}
echo "PHP CLI process/stream prerequisites OK\n";
'
```

Packages/venv in a container's disposable layer disappear on replacement. Put
this administrator preparation into the site's image build at the **final path**,
or deliberately persist code/venv and reproduce their ownership. Persist runtime
separately according to the site's storage policy. Review the entrypoint: it must
not recursively chown plugin code to the service account on restart/recreation.
Recheck imports, code/venv nonwritability, runtime protection and collection after
recreation. Never copy a host venv into the image.

## 5. Install, enable and protect runtime

All prerequisite steps must pass first. Log in as an authorized Cacti
administrator, open **Console → Configuration → Plugin Management**, then
**Install** and **Enable** gNMI Telemetry. Schema installation alone does not
prove dependencies or collection work. Install creates plugin tables, hooks,
realms, templates and protected `runtime/storage`, `runtime/logs`, and
`runtime/certs`. It aborts if required runtime directories/deny files cannot be
created. Their service ownership permits later reinstall without changing code.

| Path | Ownership and access |
| --- | --- |
| Code/docs | `root:root`; directories `0755`, files `0644` |
| Venv | root-owned; preserve executable modes and links; service can read/execute, cannot write |
| Runtime directories | service UID/group, `0750` |
| Runtime deny/config/telemetry/log files | service-owned, normally `0640` |
| gNMI private keys | service-readable, `0600`; provision manually after HTTP denial is verified |

Before adding secrets, create **existing nonsecret probe files** as the service
account: `runtime/storage/install-probe.json`, `runtime/certs/install-probe.key`,
and `runtime/logs/install-probe.log`, each containing only `gnmi-install-probe`.
Request each corresponding URL from an unauthenticated browser/curl and require
403 or 404 without the marker in the body. A missing-file 404 does not prove
protection. Delete the probes afterward. If Apache ignores `.htaccess`, configure
vhost denial; Nginx requires explicit location denial. See [security](security.md).

Use plugin-local runtime for this guide. Optional `GNMI_RUNTIME_DIR` must be one
absolute path configured consistently in web PHP, CLI installer, scheduler,
spawned daemons and bridge; an interactive-shell export is insufficient.
Python-only directory overrides do not configure the whole plugin. External
runtime still needs installation protection and access checks. Account for OS
confinement (for example SELinux) without disabling it or widening permissions.

Dashboard visits diagnose dependencies without creating a venv or downloading
packages. Even an administrator with daemon management cannot repair root-owned
code/venv from the web. **Administrator shell repair required** means disable
collection, confirm daemons stopped, repair at the final path using the shipped
requirements, and repeat service-account imports/`pip check`. Reinspect the page
after shell repair to clear dependency banners, then enable and verify collection.
Do not repair a venv while collectors still use it. See [troubleshooting](troubleshooting.md).

## 6. First device and populated graph

Grant the intended operator the relevant **gNMI Telemetry** / **gNMI Status
Dashboard** page realms and **gNMI Manage Daemons** for restart, together with
Cacti access to the target device. General device management does not substitute
for daemon management. Installation authority does not automatically imply daemon
permissions. Verify using a restricted operator, not an all-permissions account.

In **Console → Configuration → Devices**, enable gNMI on a test device, enter
its hostname, port, least-privilege device credentials and TLS settings, and save.
Add an **enabled subscription and metric** using the
[user guide](user_guide.md). Wait for scheduled polling to start the daemon and
create its data source/graph. Provision gNMI CA/client files manually in protected
`runtime/certs`; Cacti **database** TLS files follow Cacti configuration separately
and must be readable by the bridge UID. Do not change external private-key
ownership automatically. See [database settings](bridge_database.md).

The gNMI device ID is shown in the plugin's device/dashboard links. Obtain the
Cacti `local_data_id` from the corresponding Data Sources edit link (`id=`).
Replace both example IDs below. Run with the actual service UID and the venv:

```bash
# Docker host shell; for a Linux host, use runuser -u "$GNMI_SERVICE_USER" -- COMMAND.
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" "$GNMI_PLUGIN_DIR/venv/bin/python3" \
  "$GNMI_PLUGIN_DIR/scripts/gnmi_daemon_ctl.py" health --device-id 1
docker exec -u "$GNMI_SERVICE_USER" "$GNMI_CONTAINER" "$GNMI_PLUGIN_DIR/venv/bin/python3" \
  "$GNMI_PLUGIN_DIR/scripts/gnmi_poller_bridge.py" --device-id 1 --local-data-id 6
```

Require a connected daemon, fresh storage timestamps, numeric bridge output for
the chosen data source, **updated RRD samples and a populated graph**. Health
alone is insufficient. Inspect freshness without dumping daemon config JSON or
credentials into evidence. After a permitted restart and a controlled connection
interruption, verify reconnection and graph recovery. Record archive/source SHA,
versions, service identity, ownership and sanitized results.

## 7. Disable, uninstall and replace files

From Plugin Management, **Disable**, confirm collectors stopped, then
**Uninstall**. Lock/stop failures must be resolved before continuing. Uninstall
removes plugin schema/metadata and selected per-device runtime files; historical
RRDs remain. Root-owned code and venv also remain: physical removal/replacement is
an administrator shell operation after successful disable/uninstall and confirmed
shutdown. Never make code writable to let the web process remove it.

A same-build reinstall can reuse retained code/venv and service-owned runtime.
Installing a different archive still requires a deliberately empty destination
and an explicit administrator cleanup decision after uninstall. There is no
supported production migration, schema upgrade from a private build, merge-copy,
or automated downgrade. See [test plan](test_plan.md) for acceptance checks.
