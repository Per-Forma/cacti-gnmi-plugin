# Metric classification and graph grouping

The plugin does not ship a fixed inventory of vendor metrics. Operators create
subscriptions and add the metric leaves exposed by each gNMI target. The plugin
stores those definitions, creates Cacti data sources, and classifies metric
names only to choose an automatic graph layout.

## Paths and platform scope

Subscription paths are passed to the target as configured. They may use
OpenConfig, a vendor-native model, or another model supported by the device.
The collector does not require a Ciena-specific path format.

The repository includes ready-to-transcribe examples for OpenConfig on Arista
EOS, Cisco IOS XR, and Juniper Junos, plus a Ciena SAOS 10 path. See the
[subscription examples](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/examples).
Nokia SR Linux OpenConfig paths are exercised by the
[Containerlab integration harness](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/tests/integration/srlinux).

Ciena SAOS 10 targets that exhibit the validated capability/subscription issue
require the explicit `ciena_saos10` compatibility mode described in
[Compatibility](compatibility.md). Standard targets retain native pygnmi
capability discovery and subscription handling.

## Metric definition

Each metric belongs to one subscription and has:

- the exact `metric_name` received from the target;
- a Cacti field name, normalized to lowercase underscores and limited to 19
  characters;
- an RRD type and optional heartbeat/minimum/maximum values; and
- enabled, data-source, graph, and classification state.

Supported RRD types are:

| Type | Use |
| --- | --- |
| `COUNTER` | Monotonically increasing counters such as octets, packets, errors, and discards |
| `GAUGE` | Point-in-time values such as utilization, temperature, or queue depth |
| `DERIVE` | Signed rates of change where decreases are meaningful |
| `ABSOLUTE` | Counts that reset after each read interval |

Choose the type that matches the target leaf's semantics. The classifier does
not override the selected RRD type.

## Classification rules

Classification metadata is stored from the raw metric name when a metric is
created. When graph creation is attempted, the plugin classifies the current
raw name again in memory before choosing a graph path. Names are normalized to
lowercase underscore form before matching.

Renaming a metric does not rewrite its stored classification metadata or
remove and rebuild an existing graph association. If a renamed metric must
belong to a different established graph family, remove and recreate the
affected metric and graph instead of relying on the edit to regroup them.

### Direction

- `in_` and `rx_` prefixes classify as `inbound`.
- `out_` and `tx_` prefixes classify as `outbound`.
- Names without one of those prefixes classify as direction `none` and use the
  generic graph path.

### Group and graph key

After removing a recognized direction prefix, the classifier applies these
rules in order:

| Name contains | Group | Graph key |
| --- | --- | --- |
| `discard`, `discards`, `drop`, `drops`, or `dropped` | `discards` | `integrity_octets` for byte/octet metrics, otherwise `integrity_packets` |
| `error`, `errors`, `err`, `errs`, `crc`, `jabber`, `oversize`, `undersize`, or `fragment` | `errors` | `integrity_octets` for byte/octet metrics, otherwise `integrity_packets` |
| `packet`, `packets`, `pkt`, or `pkts` | `packets` | The normalized name without the direction prefix |
| `byte`, `bytes`, `octet`, or `octets` | `traffic` | The normalized name without the direction prefix |
| Anything else | `generic` | No graph key |

Error and discard matching takes precedence over ordinary packet or octet
matching. For example, `out-discards-octets` is classified as an outbound
discard with graph key `integrity_octets`, not ordinary traffic.

Examples:

| Metric | Group | Direction | Graph key |
| --- | --- | --- | --- |
| `in-octets` | `traffic` | `inbound` | `octets` |
| `tx-bytes` | `traffic` | `outbound` | `bytes` |
| `rx-unicast-packets` | `packets` | `inbound` | `unicast_packets` |
| `out-errors` | `errors` | `outbound` | `integrity_packets` |
| `in-dropped-octets` | `discards` | `inbound` | `integrity_octets` |
| `temperature` | `generic` | `none` | none |

## Automatic graph behavior

When automatic graph creation is enabled, the plugin first creates one Cacti
data source per enabled metric.

### Traffic and packet pairs

Traffic and ordinary packet graphs require matching inbound and outbound data
sources with the same graph key. For example, `in-octets` waits for
`out-octets`. The first metric remains available as a data source while the
graph waits; it is not paired with an unrelated metric.

Traffic graphs apply the bundled bytes-to-bits CDEF. Packet graphs display the
counter rate without that conversion.

### Integrity graphs

Error and discard metrics join an instance-level integrity graph. Packet/event
metrics use the `integrity_packets` graph, while byte/octet metrics use
`integrity_octets`. The graph is created when the first eligible data source is
available, and later matching metrics are attached to it.

### Generic metrics

A metric that does not match a supported directional family receives a
single-data-source passthrough graph. This includes GAUGE values such as CPU,
temperature, or memory utilization as well as vendor-specific names that do not
match the classifier.

Operators can also create or open an individual metric graph manually from the
subscription metric table.

## Runtime storage

Graph classification is database metadata; it does not change the JSON field
shape written by the daemon. Runtime samples remain keyed first by the
subscription's unique `instance_identifier`, then by the metric's Cacti field
name. See [Daemon storage format](daemon_storage_format.md) and the
[poller bridge API](poller_api.md).

## Related documentation

- [User guide](user_guide.md) — create subscriptions, metrics, data sources, and graphs
- [Subscription examples](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/examples) — OpenConfig and Ciena starting points
- [Architecture](architecture.md) — daemon, storage, bridge, and Cacti data flow
- [Poller bridge source](https://github.com/Per-Forma/cacti-gnmi-plugin/blob/main/scripts/gnmi_poller_bridge.py) — runtime metric lookup and output
