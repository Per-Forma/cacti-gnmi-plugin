# gNMI Subscription Examples

Reference subscription/metric templates for common platforms. Use them as a
starting point when configuring a device in the Cacti gNMI plugin.

## How to use these

These JSON files are **reference templates, not an import format** — the plugin
has no bulk-import mechanism. Open the matching file, then transcribe the values
into the device edit form:

1. **Console → Configuration → Devices → (your device) → gNMI Telemetry.**
2. For each object in `subscriptions[]`:
   - Create a subscription with the `path` and `instance` values.
   - Add one metric per entry in `metrics[]`, using its `metric_name`,
     `cacti_field_name`, and `rrd_type`.
3. Save. Optionally enable **Auto-create data sources** / **Auto-create graphs**
   on the subscription so the plugin provisions data sources and graphs for you.

The JSON shape mirrors what the plugin builds internally for the daemon
(`path` / `instance` / `metrics` / `field_mapping`), so it doubles as a record of
what each daemon is streaming.

## Files

| File | Platform | Path style |
|------|----------|------------|
| `arista_eos_interface_counters.json` | Arista EOS | OpenConfig |
| `cisco_ios_xr_interface_counters.json` | Cisco IOS-XR | OpenConfig |
| `juniper_junos_interface_counters.json` | Juniper Junos | OpenConfig |
| `ciena_saos_interface_counters.json` | Ciena SAOS 10.x | Ciena `cn-if:` augment |

## Important notes

- **Ports are vendor- and config-dependent.** The `port` shown in each file is a
  common default, not a guarantee — confirm the gNMI/gRPC port enabled on your
  platform.
- **One instance per interface.** Each subscription targets a single interface
  instance (its key is in the path). To monitor several interfaces, create one
  subscription per interface, each with a unique `instance` identifier.
- **`instance` must be unique per device.** It keys the daemon's per-instance
  buffers and the JSON storage.
- **RRD types:** byte/packet/error counters are monotonically increasing →
  `COUNTER`. Instantaneous values (utilization, temperature, queue depth) →
  `GAUGE`.
- **Field names** are limited to 19 characters, lowercase, underscores only. The
  `cacti_field_name` values here already follow that rule.
- **OpenConfig portability:** Arista, Cisco, and Juniper all expose the same
  OpenConfig `/interfaces/interface[name=...]/state/counters` leaves, so those
  three templates differ mainly in the interface naming and port. Vendor-native
  paths exist too but are not portable.
- The Ciena template was developed against SAOS 10.8. Confirm paths and leaf
  types against the YANG modules shipped with your target release.
