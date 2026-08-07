# gNMI Plugin — Manual Test Plan

A manual validation checklist for the gNMI Telemetry plugin, covering typical
user flows and error scenarios. Run it after a fresh install, after an upgrade,
or before a release.

> **Public-beta scope:** Run this plan against a clean test Cacti environment.
> Do not use this beta to upgrade an existing private or development plugin
> installation. The beta is not recommended for production and provides no
> automated downgrade.

This complements the automated Python, PHP, and integration suites. Manual
testing here focuses on real-device behavior and end-user flows.

## How to use

- Work top to bottom; later suites assume earlier ones passed.
- Record **Pass/Fail**, the date, Cacti version, and any notes per case.
- For each failure, capture the relevant log
  (`plugins/gnmi/runtime/logs/device_<id>.log`, `cacti.log`) before moving on.

**Disposable Docker example:** UI at `http://localhost:7070/cacti/`. Create a
unique test-only administrator password for each environment; no credential is
defined by this test plan.

---

## Suite 1 — Installation & enablement

| # | Step | Expected result |
|---|------|-----------------|
| 1.1 | Deploy with `deploy_plugin.sh`, install + enable in Plugin Management | Plugin installs without PHP errors; tables created; appears under Console → Plugins |
| 1.2 | With a system dependency missing (e.g. uninstall `librrd-dev`), load a plugin page | Banner shows the missing dependency + remediation command; `settings.gnmi_req_*` flag = `1` |
| 1.3 | Install the missing dependency, refresh the page | Banner clears; corresponding `gnmi_req_*` flag = `0` |
| 1.4 | Verify venv | `venv/bin/python3 -c "import rrdtool, pygnmi, pymysql; print('OK')"` prints `OK` |
| 1.5 | Disable then re-enable the plugin | Hooks deactivate/reactivate cleanly; no errors; daemons stop on disable |

---

## Suite 2 — Add a device (happy path)

| # | Step | Expected result |
|---|------|-----------------|
| 2.1 | Create a Cacti device, enable gNMI, enter valid host/port/credentials, save | Device saved; row in `plugin_gnmi_devices` with correct `host_id` |
| 2.2 | Add a subscription with a valid path + unique instance | Subscription saved and listed in the metric table |
| 2.3 | Add metrics (e.g. `in-octets`, `out-octets`) | Metrics saved with sanitized `cacti_field_name`s; RRD types as chosen |
| 2.4 | Wait one poller cycle, check daemon | `gnmi_daemon_ctl.py health --device-id <id>` → `running: true`, `status: connected` |
| 2.5 | Inspect JSON storage | `storage/device_<id>.json` updates every collection interval; instance-keyed `metric_groups` present |
| 2.6 | Run the bridge manually for a data source | Outputs `field:value` pairs with real, non-`U` values |

---

## Suite 3 — Graphs

| # | Step | Expected result |
|---|------|-----------------|
| 3.1 | Enable **Auto-create data sources** + **Auto-create graphs** on a subscription with in/out octets | Within a few cycles, a paired traffic graph is created (bytes→bits via CDEF, in + out on one graph) |
| 3.2 | Add a normal packet pair (e.g. `in-broadcast-pkts`, `out-broadcast-pkts`) with auto-create on | One paired packet graph is created without bytes→bits conversion |
| 3.3 | Add mixed error/drop packet counters (e.g. `in-undersize-pkts`, `in-dropped-pkts`, `out-errors`) | One integrity packets/events graph is created and all matching counters attach to it |
| 3.4 | Add error/drop octet counters (e.g. `in-jabber-octets`, `out-discards-octets`) | A separate integrity octets graph is created |
| 3.5 | Use **Create Graph** on a metric row manually | Graph created on demand; **View Graph** opens `graph.php?local_graph_id=X` |
| 3.6 | Confirm graph-tree behavior | Graph is **not** auto-added to any tree; it must be added via Management → Graph Trees (documented, expected) |
| 3.7 | Let graphs run ~10 min | Lines populate with data; no persistent NaN/gaps |

---

## Suite 4 — Edit & lifecycle

| # | Step | Expected result |
|---|------|-----------------|
| 4.1 | Change the device port/credentials and save | Config change detected; daemon auto-restarts within ~1–2 cycles; new config in `storage/device_<id>.json` |
| 4.2 | Disable a subscription | Its metrics stop updating; daemon reloads; other subscriptions unaffected |
| 4.3 | Add a second subscription/instance on the same device | Both instances stream independently; no cross-instance contamination in storage (each instance's history holds only its own counters) |
| 4.4 | Uncheck **Enable gNMI Telemetry**, save | Daemon stops gracefully (<~5 s); gNMI config row removed |
| 4.5 | Delete the Cacti host | CASCADE removes gNMI rows; daemon stopped; no orphan process remains |
| 4.6 | Manually orphan a daemon (kill DB row / leave PID), wait one cycle | Orphan detected and cleaned automatically; dashboard orphan widget reflects it |

---

## Suite 5 — Error scenarios

| # | Scenario | How to induce | Expected result |
|---|----------|---------------|-----------------|
| 5.1 | **Auth failure** | Enter a wrong username/password | Daemon logs auth/permission error; device shows `critical`/`warning` on dashboard; reconnect attempts logged with backoff; no crash of the poller cycle |
| 5.2 | **Unreachable device** | Use a bad IP or block the gNMI port | Connection times out; exponential backoff (1→60 s) visible in `device_<id>.log`; dashboard flags device; other devices keep collecting |
| 5.3 | **Wrong port** | Point to a closed/non-gNMI port | gRPC connect failure logged; no telemetry; device flagged; recovers automatically when corrected |
| 5.4 | **Malformed / unsupported path** | Subscribe to a path the device rejects | Subscription error logged; that subscription yields no data; valid subscriptions on the device still work |
| 5.5 | **Stale data** | Pause/kill the daemon process, leave the device enabled | Bridge stops emitting fresh values (outputs `U`/nothing past staleness threshold ≈ 2× poller interval); dashboard data-freshness flags stale; daemon auto-restarts next cycle |
| 5.6 | **Invalid config input** | Submit empty hostname, port out of 1–65535, interval out of 5–300, or a path with no `/` | Server-side validation rejects with a user-visible message; nothing persisted; no daemon started for invalid data |
| 5.7 | **Counter reset / wrap** | Clear counters on the device (or observe a 32-bit wrap) | COUNTER DS handles wrap; no sustained NaN spike on the graph after the fix for instance-keyed buffering |
| 5.8 | **Certificate mismatch (TLS)** | Use a CA/`tls_override` that doesn't match the device cert | TLS handshake failure logged clearly; device flagged; resolves when cert/override corrected (`skip_verify` only as a lab workaround) |

---

## Suite 6 — Dashboard & monitoring

| # | Step | Expected result |
|---|------|-----------------|
| 6.1 | Open the Status Dashboard | All gNMI devices listed with health, daemon state, uptime, memory, freshness |
| 6.2 | Trigger a config change and watch the event trail | `plugin_gnmi_events` records the lifecycle/config event; visible in the UI |
| 6.3 | Confirm auto-refresh and drill-down | Page refreshes; per-device detail expands |
| 6.4 | Permissions | A role without the gNMI realm cannot open the dashboard; with it granted, access works |

---

## Suite 7 — Schema lifecycle

The automated live schema suite covers defaults, constraints, cascades, field
boundaries, auto-increment behavior, repeatable application of the supported
schema helpers, and the uninstall dependency/abort contracts. This does not
validate migration from an earlier gNMI plugin schema.

| # | Step | Expected result |
|---|------|-----------------|
| 7.1 | Apply the currently supported schema helpers repeatedly in the automated schema suite | Schema definitions remain stable, no duplicate definitions appear, and fixture data is preserved |
| 7.2 | Uninstall the plugin | Daemons stopped; plugin-owned Cacti graph/data-source metadata removed; hooks removed; tables dropped in FK-safe order; no FK violation; physical RRD files left in place (documented) |

## Sign-off

| Field | Value |
|-------|-------|
| Tester | |
| Date | |
| Cacti version | |
| Plugin version | |
| Result (suites passed) | |
| Notes / defects filed | |

---

## Related documentation

- [install.md](install.md) · [user_guide.md](user_guide.md) · [security.md](security.md)
- [troubleshooting.md](troubleshooting.md) — diagnostics referenced by Suite 5
- [Cacti compatibility harness](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/tests/integration/cacti_compat)
  — Cacti compatibility harness
- [SR Linux integration harness](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/tests/integration/srlinux)
  — SR Linux interoperability harness
