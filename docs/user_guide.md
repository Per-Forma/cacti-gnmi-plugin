# gNMI Plugin — User Guide

This guide covers day-to-day use of the gNMI Telemetry plugin: managing devices,
defining subscriptions and metrics, creating graphs, and reading the status
dashboard. For first-time setup, see [install.md](install.md).

---

## 1. Concepts

The plugin layers a few concepts on top of Cacti's normal device model:

- **Device** — a Cacti host with gNMI enabled. Connection settings live in the
  device edit form and are stored in `plugin_gnmi_devices`. Each enabled device
  gets one long-running daemon.
- **Subscription** — a gNMI path the daemon streams, scoped to one **instance**
  (e.g. a single interface). A device can have many subscriptions.
- **Metric** — a single counter/gauge leaf within a subscription (e.g.
  `in-octets`). Each metric maps to a Cacti field name and can auto-create a
  data source and graph.
- **Daemon** — the per-device Python process that holds the gNMI subscription,
  samples every 5 s, and writes JSON storage every collection interval.

Data flow: **device → daemon → JSON storage → poller bridge → RRD → graph.**
See [architecture.md](architecture.md) for the full pipeline.

---

## 2. Managing devices

gNMI is configured in Cacti's native device form — **Console → Configuration →
Devices → (create/edit a device)**.

1. Check **Enable gNMI Telemetry** to reveal the gNMI fields.
2. Connection settings:

   | Field | Meaning |
   |-------|---------|
   | Hostname/IP | gNMI target address (often the same as the SNMP host). |
   | Port | gNMI port (commonly `9339`; vendor-dependent). |
   | Username / Password | gNMI credentials. Stored in plaintext — see [security.md](security.md). |
   | Collection Interval | Seconds between JSON writes (5–300; `10` recommended). |
   | Encoding | Wire encoding. The plugin uses `JSON_IETF`. |

3. TLS / Advanced (collapsible): enable TLS, point to CA / client cert / client
   key paths, set a TLS name override, or skip verification. Covered in
   [security.md](security.md).
4. **Save.**

**What happens on save:**
- A new/updated row is written to `plugin_gnmi_devices` (linked to the Cacti
  host via `host_id`).
- If connection-affecting fields changed, the daemon is **restarted**
  automatically (hot reload — no manual action needed).
- If you uncheck **Enable gNMI Telemetry** and save, the daemon is stopped
  gracefully and the gNMI config row is removed.

> The daemon only starts once the device has at least one **enabled subscription
> with at least one metric**. A device with no subscriptions is saved but idle.

---

## 3. Managing subscriptions

The subscription manager is embedded in the device edit form, below the
connection settings.

### Add a subscription

1. Click **Add Subscription**.
2. Fill in:
   - **Subscription Path** — the gNMI path to stream. Must contain at least one
     `/`. Example (OpenConfig):
     `openconfig-interfaces:interfaces/interface[name=Ethernet1]/state/counters`
   - **Instance Identifier** — a short, unique label for the streamed instance
     (e.g. `Ethernet1`, `ettp-40`). This keys the daemon's per-instance buffers
     and the JSON storage, so it **must be unique per subscription on a device**.
   - **Auto-create data sources** (optional) — when set, the poller creates a
     Cacti data source for each metric automatically.
   - **Auto-create graphs** (optional) — when set, the poller also creates a
     graph per metric family: exact in/out graphs for traffic and packet
     counters, grouped integrity graphs for error/drop counters, and
     passthrough graphs for unsupported metric shapes.
3. Save the subscription.

### Add metrics to a subscription

For each leaf you want to collect, add a metric:
- **Metric Name** — the gNMI leaf name as it appears on the wire (e.g.
  `in-octets`, `out-octets`, `in-errors`).
- **Cacti Field Name** — auto-derived (lowercased, hyphens → underscores,
  truncated to 19 chars). You can override it; keep it unique within the
  subscription.
- **RRD Type** — `COUNTER` for monotonically increasing counters (octets,
  packets, errors), `GAUGE` for instantaneous values (utilization, temperature).

See [examples/](../examples/) for ready-to-paste path + metric sets for Arista,
Cisco, Juniper, and Ciena.

### Edit / disable / delete

Each subscription and metric row has edit/toggle/delete controls. Disabling a
subscription stops its metrics from being collected without deleting the
definition. Changing subscriptions triggers a daemon config reload on the next
poller cycle.

---

## 4. Creating graphs

There are two ways to get graphs:

**A. Automatic** — set **Auto-create graphs** on the subscription. On the next
poller cycle the plugin creates:
- A **paired traffic graph** for matching inbound/outbound octet or byte
  counters. Traffic graphs convert bytes to bits via the bundled CDEF.
- A **paired packet graph** for matching inbound/outbound packet families such
  as `in-broadcast-pkts` and `out-broadcast-pkts`.
- An **integrity packets/events graph** for error/drop counters that are packet
  or event counts, such as `in-undersize-pkts`, `in-dropped-pkts`, and
  `out-errors`.
- An **integrity octets graph** for error/drop counters measured in octets or
  bytes, such as `in-jabber-octets` and `out-discards-octets`.
- A **single-DS passthrough graph** for metrics that do not match a supported
  auto-grouping rule.

Traffic and normal packet metrics wait for their opposite-direction partner DS
before the graph is created. Integrity graphs are created as soon as the first
eligible metric has a data source; later matching metrics are attached to the
same graph.

**B. Manual per-metric** — in the subscription metric table, use the
**Create Graph** / **View Graph** button on a metric row to create or open its
graph on demand.

### ⚠️ Graphs are not added to a graph tree automatically

A created graph exists at `graph.php?local_graph_id=X` and is reachable via
**Console → Management → Graphs**, but it is **not** placed on any graph tree.
To see it in the normal tree view:

1. **Console → Management → Graph Trees** → open or create a tree.
2. Add the gNMI graph(s) to the tree.

This is by design — the plugin does not assume where in your tree hierarchy the
graphs belong.

---

## 5. The Status Dashboard

**Console → Plugins → gNMI Telemetry** opens the dashboard. It shows, per device:

- **Health** — `healthy` / `warning` / `critical` / `unknown`, based on daemon
  state and data freshness.
- **Daemon** — running/stopped, PID, uptime, memory usage.
- **Data freshness** — time since the last JSON write; stale data (older than
  ~2× the poller interval) is flagged.
- **Last poll status / error** — surfaced from the device record.
- **Orphan widget** — count of orphaned daemon processes with a manual cleanup
  button (orphans are also cleaned automatically each poller cycle).

The page auto-refreshes and supports drill-down into per-device detail and the
event audit trail (`plugin_gnmi_events`), which logs daemon lifecycle and config
changes.

---

## 6. Common tasks

| Task | Where |
|------|-------|
| Add a gNMI device | Device edit form → Enable gNMI Telemetry |
| Change credentials / port | Device edit form → Save (daemon auto-restarts) |
| Add/remove a metric | Device edit form → Subscription metric table |
| Create a graph now | Metric row → **Create Graph** |
| Put a graph on a tree | Management → Graph Trees (manual) |
| Check device health | Plugins → gNMI Telemetry (dashboard) |
| Restart a daemon | `docker_helpers/restart_daemon.sh <device_id>` |
| Inspect raw data | `cat plugins/gnmi/runtime/storage/device_<id>.json` |

---

## 7. Troubleshooting quick reference

| Symptom | Likely cause | First check |
|---------|--------------|-------------|
| Daemon won't start | No enabled subscription with metrics | Add a subscription + metric |
| Dashboard shows `critical`/stale | Connection or auth failure | Daemon log: `plugins/gnmi/runtime/logs/device_<id>.log` |
| Graph empty / NaN | Wrong RRD type, or metric name mismatch | Bridge output (see below) |
| "Unsaved Changes Detected" on device form | (Resolved) modal sub-forms now detached from host form | Re-deploy latest plugin |
| Banner about missing deps | venv/system packages missing | `SELECT * FROM settings WHERE name LIKE 'gnmi_req_%'` |

**Manual bridge test** (what Cacti would receive for one data source):
```bash
docker exec cacti_app /var/www/html/cacti/plugins/gnmi/venv/bin/python3 \
  /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6
```

For the full diagnostic playbook see [troubleshooting.md](troubleshooting.md).

---

## Related documentation

- [install.md](install.md) — installation and first-device setup
- [security.md](security.md) — credentials, TLS/mTLS, hardening
- [examples/](../examples/) — per-vendor subscription/metric templates
- [troubleshooting.md](troubleshooting.md) — detailed diagnostics
- [architecture.md](architecture.md) — data-flow internals
