# gNMI Plugin — Installation Guide

This guide walks through deploying the Cacti gNMI Telemetry plugin from a clean
state: installing system prerequisites, deploying the plugin files, installing
the Python environment, enabling the plugin in Cacti, and configuring your first
device.

> **Public-beta policy:** Beta artifacts are fresh-install only. Install into a
> clean test Cacti environment only; do not use them in production or upgrade an
> existing gNMI plugin deployment. Migration from an earlier plugin schema and
> automated downgrade are not supported by this public beta.

> **Audience:** Cacti administrators with shell access to the poller host (or
> the Cacti container). You need `root`/`sudo` to install system packages.

---

## 1. Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| Cacti     | 1.2.25+ | Cacti 1.2.25 introduces the data-input removal API required for a safe plugin uninstall. |
| PHP       | 8.1+    | JSON extension required (included with supported PHP versions). |
| Python    | 3.12+   | Used by the daemon, bridge, and collector modules. |
| Database  | MySQL 5.7+ / MariaDB 10.2+ | JSON column support required. |
| OS        | Linux with `/proc` | Daemon health metrics (uptime, memory) read from `/proc`. |

Cacti 1.2.24 and earlier are unsupported. They may run the collection path,
but they lack `api_data_input_remove()`, so this beta cannot guarantee a clean,
safe uninstall.

The plugin runs one **long-running Python daemon per gNMI device**. Plan for
roughly 50–100 MB RAM per daemon and a persistent gRPC connection per device.

### 10-second polling

gNMI is a streaming protocol. To benefit from high-resolution data, set Cacti's
poller interval to **10 seconds** (Console → Configuration → Settings → Poller).
This is a global Cacti setting that affects all devices, so review poller load
and capacity before changing it. The plugin still works at the default 60s
interval; you simply get coarser graphs.

---

## 2. Install system dependencies

These packages provide the runtime libraries and build headers needed to create
the Python virtual environment and compile the `rrdtool` bindings. They require
root.

**Debian / Ubuntu:**
```bash
sudo apt install python3-venv python3-dev librrd-dev
```

**RHEL / CentOS / Fedora:**
```bash
sudo yum install python3-venv python3-devel rrdtool-devel
```

**Docker (install as root inside the Cacti container):**
```bash
docker exec -u root cacti_app bash -lc \
  'apt-get update && apt-get install -y python3-venv python3-dev librrd-dev'
```

| Package | Provides | Needed for |
|---------|----------|------------|
| `python3-venv` (or `python3.X-venv`) | `venv` + `ensurepip` | Creating the isolated environment |
| `python3-dev` / `python3-devel` | `Python.h` headers | Compiling the `rrdtool` C extension |
| `librrd-dev` / `rrdtool-devel` | `rrd.h` + `librrd` | Building the `rrdtool` Python module |

---

## 3. Deploy the plugin files

The plugin must live at `<cacti>/plugins/gnmi/`. Use the bundled
`deploy_plugin.sh`, which stages only deployable plugin code (excludes
tests/docs/runtime data/caches/private certs) and supports both local and
Docker targets.

**Local filesystem:**
```bash
./deploy_plugin.sh /var/www/html/cacti/plugins/gnmi/
```

**Docker container** (`<container>:<path>`):
```bash
./deploy_plugin.sh cacti_app:/var/www/html/cacti/plugins/gnmi/
```

The script does **not** copy certificate or private-key material. After plugin
install creates the protected runtime tree, place lab/production TLS
material manually under `plugins/gnmi/runtime/certs/` (or
`GNMI_RUNTIME_DIR/certs`) and select those files in the device form. See
[security.md](security.md) §TLS.

Verify the files landed:
```bash
# Local
ls -la /var/www/html/cacti/plugins/gnmi/
# Docker
docker exec cacti_app ls -la /var/www/html/cacti/plugins/gnmi/
```

Before installing/enabling the plugin, the Cacti web/poller user must be able to
create the protected runtime tree at `plugins/gnmi/runtime/` (or at
`GNMI_RUNTIME_DIR`). If the plugin directory is `root:root 755`, either install
from a context with permission to create that directory or pre-create only the
runtime root with the Cacti runtime user as owner:

```bash
docker exec cacti_app mkdir -p /var/www/html/cacti/plugins/gnmi/runtime
docker exec cacti_app chown www-data:www-data /var/www/html/cacti/plugins/gnmi/runtime
docker exec cacti_app chmod 750 /var/www/html/cacti/plugins/gnmi/runtime
```

The plugin install step still creates/protects `runtime/storage`,
`runtime/certs`, and `runtime/logs`, and aborts if it cannot write the required
deny files.

---

## 4. Install the Python environment

The plugin manages a virtual environment at
`<cacti>/plugins/gnmi/venv/`. When system prerequisites (step 2) are present,
the plugin **auto-creates the venv and installs packages** during install and
on page load. You can also do it manually:

```bash
cd /var/www/html/cacti/plugins/gnmi
python3 -m venv venv
venv/bin/python3 -m pip install --upgrade pip
venv/bin/python3 -m pip install -r scripts/requirements.txt
```

Key pinned dependencies (`scripts/requirements.txt`): `pygnmi==0.8.15`,
`rrdtool-bindings==0.5.0`, `grpcio==1.83.0`, `protobuf==7.35.1`, `pymysql==1.2.0`,
`pyyaml==6.0.3`.

**Verify the environment:**
```bash
venv/bin/python3 -c "import rrdtool, pygnmi, pymysql; print('OK')"
```

> The `rrdtool` wheel is compiled from source against `librrd-dev`. If this step
> fails, re-check step 2 — a missing `librrd-dev`/`python3-dev` is the usual
> cause.

The release archive contains runtime dependencies only. Contributors running
the automated suite should additionally install the development requirements:

```bash
venv/bin/python3 -m pip install -r scripts/requirements-dev.txt
```

---

## 5. Enable the plugin in Cacti

1. Log in to Cacti as an admin.
2. Go to **Console → Configuration → Plugin Management**.
3. Find **gNMI Telemetry** and click **Install**, then **Enable**.

On install the plugin:
- Creates its database tables (`plugin_gnmi_devices`, `plugin_gnmi_subscriptions`,
  `plugin_gnmi_metrics`, and `plugin_gnmi_events`).
- Registers its poller and form hooks (inactive until the plugin is enabled).
- Registers the Status Dashboard realm so it appears under **Console → Plugins**.
- Provisions the `gNMI - Passthrough` data input/template and the graph
  templates + CDEF/colors used for auto-created graphs.

### Dependency banners

If any prerequisite is still missing, the plugin shows a banner on its pages with
the exact remediation command, and tracks state in the Cacti `settings` table:

```sql
SELECT name, value FROM settings WHERE name LIKE 'gnmi_req_%';
```

| Flag | `1` means |
|------|-----------|
| `gnmi_req_venv_module_missing` | `python3-venv` not available |
| `gnmi_req_rrdtool_dev_missing` | `librrd-dev`/`python3-dev` not available |
| `gnmi_req_rrdtool_missing` | `rrdtool` Python module not importable |
| `gnmi_req_pygnmi_missing` | `pygnmi` not importable |

Once dependencies are satisfied, refresh the plugin page; the banners clear and
the poller hook begins managing daemons.

---

## 6. Configure your first device

gNMI is configured **inside Cacti's native device edit form** — there is no
separate menu.

1. **Console → Configuration → Devices**, then create a new device or edit an
   existing one.
2. Scroll to the **gNMI Telemetry Configuration** section and check
   **Enable gNMI Telemetry**.
3. Fill in the connection settings:
   - **Hostname/IP** and **Port** (gNMI default is typically `9339`).
   - **Username** / **Password**.
   - **Collection Interval** — seconds (5–300; `10` recommended).
   - **TLS / mTLS** options if your device requires them (see
     [security.md](security.md)).
4. **Save.** The plugin starts a daemon for the device within one poller cycle.
5. Add at least one **subscription** with metrics so the daemon has something to
   collect (see [user_guide.md](user_guide.md) §Subscriptions). Without an
   enabled subscription that has metrics, the daemon has nothing to stream and
   will not start.

### Verify data is flowing

```bash
# Daemon health (replace 1 with the device's gNMI id)
docker exec cacti_app /var/www/html/cacti/plugins/gnmi/venv/bin/python3 \
  /var/www/html/cacti/plugins/gnmi/scripts/gnmi_daemon_ctl.py health --device-id 1

# JSON storage written by the daemon
docker exec cacti_app cat /var/www/html/cacti/plugins/gnmi/runtime/storage/device_1.json

# Bridge output for a data source (replace local-data-id)
docker exec cacti_app /var/www/html/cacti/plugins/gnmi/venv/bin/python3 \
  /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6
```

Then open the **Status Dashboard** (Console → Plugins → gNMI Telemetry) to see
per-device health, daemon uptime, and data freshness.

---

## 7. Upgrading

Upgrading an earlier private or development schema is not supported by this
public beta. The installer detects the retired assignment tables and older
metric layout before making changes, leaves them untouched, and reports that a
fresh installation is required.

The idempotent `plugin_gnmi_upgrade()` helpers reconcile additions within the
current public schema, but they are not a migration path from an older plugin
design. Back up the Cacti database and RRD directory, uninstall the earlier
build, and install this beta into a clean plugin schema.

---

## 8. Uninstalling

From **Plugin Management**, click **Disable** then **Uninstall**. Uninstall stops
all daemons, removes hooks, and drops the plugin tables in FK-safe order
(`plugin_gnmi_subscriptions` before `plugin_gnmi_devices`). RRD files already
created in `<cacti>/rra/` are **not** deleted — remove them manually if you no
longer need the historical data. Automated tests cover the dependency order and
verify that lock or daemon-stop failures abort before metadata or schema removal.
There is no automated schema downgrade.

---

## Related documentation

- [user_guide.md](user_guide.md) — day-to-day device, subscription, and graph management
- [security.md](security.md) — credential storage, TLS/mTLS, hardening
- [troubleshooting.md](troubleshooting.md) — diagnostics for common failures
- [test_plan.md](test_plan.md) — manual validation checklist
- [architecture.md](architecture.md) — how the daemon → JSON → bridge → RRD pipeline works
