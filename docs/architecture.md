# Architecture

The plugin separates continuous gNMI streaming from Cacti's interval-based
poller.

```text
gNMI target
    |
    | Subscribe stream
    v
per-device Python daemon
    |
    | atomic JSON snapshots and buffered samples
    v
runtime storage
    |
    | poller bridge
    v
PHP poller hook
    |
    | rrdtool update
    v
Cacti-managed RRD files
    |
    v
graphs and status dashboard
```

## Components

### Cacti integration

`setup.php` registers plugin hooks, manages schema installation and removal,
and exposes plugin metadata. PHP modules under `include/` handle device forms,
subscriptions, daemon lifecycle, data-source provisioning, graph creation, and
dashboard rendering.

The plugin uses Cacti's database and application interfaces. It does not patch
Cacti core files.

### Subscription daemons

One Python daemon runs for each enabled gNMI device. It maintains persistent
subscriptions, reconnects with backoff, normalizes received values, and writes
runtime state atomically. Daemon control is provided by
`scripts/gnmi_daemon_ctl.py`.

### Poller bridge

`scripts/gnmi_poller_bridge.py` reads the decoupled runtime data and emits the
timestamped values expected by Cacti data sources. Metric definitions come from
the Cacti database rather than being hardcoded into the bridge. The PHP poller
hook validates that output and writes it directly to Cacti-managed RRD files
with `rrdtool update`; Cacti owns the data-source and graph metadata and renders
the graphs.

### Runtime storage

By default, runtime state lives under `runtime/` inside the installed plugin:

```text
runtime/
  certs/
  logs/
  storage/
```

The plugin creates access-deny files and restrictive permissions, but this
directory still contains credentials and operational data. See `security.md`
for the threat model and deployment guidance.

## Configuration flow

1. An administrator enables gNMI for a Cacti device.
2. The device form stores connection, transport, compatibility, and collection
   settings in plugin tables.
3. Subscription and metric definitions are created for the device.
4. The poller lifecycle hook creates or updates the daemon configuration.
5. Configuration changes trigger a controlled daemon restart.
6. The bridge selects timestamped samples from runtime storage, and the PHP
   poller hook writes them to Cacti-managed RRD files with `rrdtool update`.

## Compatibility behavior

Standard mode uses native gNMI Capabilities and `JSON_IETF`. The opt-in Ciena
SAOS 10 mode applies a narrowly scoped pygnmi compatibility shim and uses the
encoding behavior required by the validated target.

TLS cipher policy is vendor-neutral and independent of protocol compatibility.
Each device daemon receives its own process environment: the default policy
uses gRPC's cipher defaults, while explicit legacy compatibility adds the
tested older TLS 1.2 cipher without changing certificate verification or mTLS.
The poller bridge does not establish gNMI connections and therefore does not
apply transport policy.

## Concurrency and failure isolation

- Atomic file replacement prevents the bridge from reading partial snapshots.
- Advisory locking prevents overlapping poller lifecycle work.
- Per-device daemons isolate target failures.
- Staleness checks distinguish a running daemon from fresh telemetry.
- Backoff prevents tight reconnect loops.

Multi-poller deployments must place runtime storage on a filesystem that
provides compatible advisory locking semantics.
