# Database schema

The gNMI plugin stores connection settings, subscriptions, metrics, and event
history in four plugin-owned tables. Cacti continues to own the data sources,
RRD definitions, graphs, profiles, and settings that the plugin provisions.

The authoritative installation SQL is in
[`setup.php`](https://github.com/Per-Forma/cacti-gnmi-plugin/blob/main/setup.php).
This document describes the schema produced by a clean installation of the
current public beta.

## Fresh-install policy

The public beta does not migrate private or development schemas. Installation
stops before making changes when it finds either of the retired
`plugin_gnmi_device_metrics` or `plugin_gnmi_device_settings` tables, or a
`plugin_gnmi_metrics` table without the current `subscription_id` column.

Back up and remove the earlier plugin installation before retrying. The guard
does not drop or rewrite legacy tables.

## Relationships

```text
Cacti host
    |
    +-- plugin_gnmi_devices
            |
            +-- plugin_gnmi_subscriptions
            |       |
            |       +-- plugin_gnmi_metrics
            |
            +-- plugin_gnmi_events
```

Deleting a Cacti host cascades through its plugin device, subscriptions,
metrics, and events. Deleting a subscription removes its metrics. Plugin
uninstall drops child tables before parent tables.

## `plugin_gnmi_devices`

One row stores the gNMI connection and daemon state for one Cacti host.
`host_id` is unique, so a Cacti host can have at most one gNMI device record.

| Column | Type/default | Purpose |
| --- | --- | --- |
| `id` | unsigned integer, auto-increment | Plugin device and daemon identifier |
| `host_id` | unsigned integer, required and unique | Foreign key to Cacti `host.id` |
| `enabled` | boolean, `TRUE` | Enables collection for the device |
| `hostname` | varchar(255), required | gNMI target hostname or address |
| `hostname_source` | `device` or `custom`, default `device` | Tracks whether the target follows the Cacti hostname |
| `port` | unsigned integer, `9339` | gNMI TCP port |
| `username`, `password` | varchar/text | Plaintext gNMI credentials; see [Security](security.md) |
| `use_tls` | boolean, `TRUE` | Enables TLS |
| `compatibility_mode` | `standard` or `ciena_saos10`, default `standard` | Selects standard behavior or the explicit Ciena shim |
| `ca_cert_path`, `client_key_path`, `client_cert_path` | nullable varchar(255) | TLS and mTLS material beneath the protected certificate directory |
| `tls_override` | nullable varchar(255) | TLS server-name override |
| `skip_verify` | boolean, `FALSE` | Disables certificate verification when explicitly selected |
| `collection_interval` | unsigned integer, `10` | Requested daemon collection interval in seconds |
| `encoding` | varchar(50), `JSON_IETF` | gNMI wire encoding |
| `last_poll_time` | nullable timestamp | Most recent successful poll time |
| `last_poll_status` | varchar(50), `never` | Current poll status |
| `last_error_message` | nullable text | Most recent poll error |
| `created_on`, `modified_on` | timestamps | Creation and last-modification times |

Indexes cover the primary key, unique `host_id`, `enabled`, and `created_on`.
The `host_id` foreign key uses `ON DELETE CASCADE`.

## `plugin_gnmi_subscriptions`

Each row defines one subscription path and instance identifier for a plugin
device.

| Column | Type/default | Purpose |
| --- | --- | --- |
| `id` | unsigned integer, auto-increment | Subscription identifier |
| `device_id` | unsigned integer, required | Foreign key to `plugin_gnmi_devices.id` |
| `subscription_path` | text, required | Path sent in the gNMI subscription |
| `instance_identifier` | varchar(100), required | Unique runtime-buffer label chosen by the operator |
| `enabled` | boolean, `TRUE` | Enables the subscription |
| `discovery_mode` | `manual`, `discovered`, or `template`; default `manual` | Records how the definition was created |
| `auto_create_datasources` | boolean, `TRUE` | Provisions Cacti data sources for metrics |
| `auto_create_graphs` | boolean, `TRUE` | Provisions compatible graph instances |
| `last_discovery_time` | nullable timestamp | Most recent discovery time |
| `discovery_status` | nullable `pending`, `success`, or `failed` | Most recent discovery result |
| `notes` | nullable text | Operator notes |
| `created_on`, `modified_on` | timestamps | Creation and last-modification times |

Indexes cover the primary key, `device_id`, `enabled`, and `discovery_mode`.
The `device_id` foreign key uses `ON DELETE CASCADE`.

## `plugin_gnmi_metrics`

Each row defines one gNMI leaf collected by a subscription and its Cacti/RRD
mapping.

| Column | Type/default | Purpose |
| --- | --- | --- |
| `id` | unsigned integer, auto-increment | Metric identifier |
| `subscription_id` | unsigned integer, required | Foreign key to `plugin_gnmi_subscriptions.id` |
| `metric_name` | varchar(255), required | Metric name received from the target |
| `cacti_field_name` | varchar(19), required | Sanitized Cacti/RRD field name |
| `rrd_type` | `COUNTER`, `GAUGE`, `DERIVE`, or `ABSOLUTE`; default `COUNTER` | RRD data-source type |
| `rrd_heartbeat` | unsigned integer, `600` | SQL fallback; runtime uses twice the effective poll interval |
| `rrd_min`, `rrd_max` | varchar(20), `0` and `U` | RRD bounds |
| `enabled` | boolean, `TRUE` | Enables collection |
| `discovered` | boolean, `FALSE` | Records automatic discovery |
| `datasource_created` | boolean, `FALSE` | Tracks Cacti data-source provisioning |
| `metric_group` | `traffic`, `packets`, `errors`, `discards`, or `generic` | Automatic graph classification |
| `metric_direction` | `inbound`, `outbound`, or `none` | Automatic graph direction |
| `metric_graph_key` | nullable varchar(128) | Deterministic graph-family key |
| `graph_created` | boolean, `FALSE` | Tracks graph provisioning |
| `local_data_id` | nullable unsigned integer | Cacti `data_local.id` once provisioned |
| `graph_local_id` | nullable unsigned integer | Cacti `graph_local.id` once provisioned |
| `created_on`, `modified_on` | timestamps | Creation and last-modification times |

The pair `(subscription_id, metric_name)` is unique. Indexes support enabled
and provisioning queries plus graph grouping. The `subscription_id` foreign key
uses `ON DELETE CASCADE`.

See [Metric classification and graph grouping](metric_groups.md) for the rules
that populate the classification columns.

## `plugin_gnmi_events`

The event table records device configuration changes, daemon lifecycle events,
errors, and orphan cleanup.

| Column | Type/default | Purpose |
| --- | --- | --- |
| `id` | unsigned integer, auto-increment | Event identifier |
| `device_id` | unsigned integer, required | Foreign key to `plugin_gnmi_devices.id` |
| `event_type` | varchar(50), required | Event category |
| `event_data` | nullable JSON | Structured event details |
| `created_at` | timestamp, current time | Event time |

Indexes cover `device_id`, `event_type`, and `created_at`. The device foreign
key uses `ON DELETE CASCADE`.

## Cacti-owned resources

The plugin provisions related objects through Cacti APIs and tables rather than
duplicating them in plugin-owned schema:

- a `gNMI - 10 Second Collection` data-source profile, consolidation-function
  links, and RRA definitions;
- the passthrough data input and data-source template;
- traffic, packet, integrity, and passthrough graph templates;
- local data-source and graph rows referenced by metric `local_data_id` and
  `graph_local_id`; and
- `gnmi_*` settings that cache provisioned object identifiers and dependency
  status.

The profile step is 10 seconds. Its heartbeat is reconciled to twice Cacti's
effective poller interval. RRA definitions retain high-resolution data for six
hours and consolidate longer daily, weekly, and monthly windows.

## Data flow

1. The Cacti device form writes connection settings to `plugin_gnmi_devices`.
2. The operator creates subscriptions and metrics in the corresponding child
   tables.
3. The poller lifecycle hook renders daemon configuration from those rows.
4. The per-device daemon streams gNMI data into protected JSON runtime storage.
5. The poller bridge resolves expected metrics from the database and emits the
   selected instance values to Cacti.
6. Cacti updates its managed RRD files and renders graphs. Lifecycle and error
   activity is recorded in `plugin_gnmi_events`.

For the complete runtime path, see [Architecture](architecture.md) and the
[poller bridge API](poller_api.md).
