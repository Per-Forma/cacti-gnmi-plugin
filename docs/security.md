# gNMI Plugin — Security Guide

This document describes how the plugin stores credentials, how to configure
TLS/mTLS for gNMI connections, the network requirements, and hardening
recommendations. Read it before deploying against production network devices.

---

## 1. Credential storage

> **⚠️ Current behaviour: gNMI credentials are stored in plaintext.**

Device username and password are saved as plaintext columns in the
`plugin_gnmi_devices` table and are written, also in plaintext, into the
per-device daemon config JSON under `plugins/gnmi/runtime/storage/` by default
so the Python daemon can authenticate. This matches Cacti's own handling of
SNMP/device credentials, but it means:

- **Anyone with database read access can read gNMI passwords.** Restrict access
  to the Cacti database (dedicated DB user, least privilege, no shared
  accounts).
- **Anyone who can read `plugins/gnmi/runtime/storage/` can read the daemon config.**
  Lock down filesystem permissions (see §4).
- Credentials may appear in database backups and in any copy of the storage
  directory. Treat those artifacts as secrets.

**Recommendations:**
- Use a **dedicated, least-privilege gNMI account** on each device — read-only
  telemetry scope, not an admin login. If the credential leaks, the blast radius
  is limited to telemetry reads.
- Rotate the gNMI account password on a schedule; update it via the device edit
  form (the daemon hot-reloads on save).
- Keep database and host backups encrypted and access-controlled.

> This public beta does not provide credential encryption or credential-store
> integration. The controls above are required mitigations.

---

## 2. TLS and mTLS

gNMI typically runs over TLS. The plugin supports plain TLS (server cert only)
and mutual TLS (client certificate), configured per device in the **TLS /
Advanced** section of the device edit form.

### Fields

| Field (DB column) | Purpose |
|-------------------|---------|
| `use_tls` | Enable TLS for the gNMI connection. |
| `ca_cert_path` | Path to the CA cert that signed the device's server certificate. |
| `client_cert_path` | Client certificate (mTLS). |
| `client_key_path` | Client private key (mTLS). |
| `tls_override` | TLS server-name override — the name to validate the server cert against (use when the cert CN/SAN differs from the connection IP/host). |
| `skip_verify` | Disable server certificate verification. **Insecure — see below.** |
| `tls_cipher_policy` | Per-device gRPC cipher policy. `default` is recommended; `legacy_compatibility` permits an older TLS 1.2 CBC/SHA-1 cipher. |

Certificate/key fields are optional, but blank fields remain blank. The daemon
does **not** fall back to bundled sample certificates or private keys. Place lab
or production TLS material manually under `plugins/gnmi/runtime/certs/`, then
select it in the device form or enter its absolute path beneath that directory.
Paths outside the protected runtime certificate directory are rejected.

### Recommended configurations

- **Mutual TLS (preferred):** set `use_tls`, provide `ca_cert_path`,
  `client_cert_path`, and `client_key_path`. Leave `skip_verify` off. Use
  `tls_override` if the device certificate's name doesn't match how you address
  it.
- **Server-auth TLS:** `use_tls` + `ca_cert_path`, no client cert.
- **`skip_verify` (lab only):** disables certificate validation, which removes
  protection against man-in-the-middle attacks. Acceptable for a closed lab; do
  **not** use it on a production or shared network.
- **Legacy TLS compatibility:** keeps certificate validation and mTLS active,
  but adds `ECDHE-RSA-AES128-SHA` after a modern cipher. Enable it only for a
  target that cannot negotiate gRPC defaults, and prefer modernizing the
  target's TLS profile.

### Certificate handling

- Store certificates in the protected runtime cert directory
  (`plugins/gnmi/runtime/certs/` by default, or `GNMI_RUNTIME_DIR/certs`).
- Private keys must be readable by the user the Cacti poller/daemon runs as
  (commonly `www-data`) and by no one else (`chmod 600`, correct owner).
- The deploy script does not copy certificate or private-key files; private
  keys are never bundled into deployments automatically.
- Track certificate expiry — an expired device cert breaks the subscription and
  the dashboard will show the device as `critical`.

---

## 3. Network requirements

- **Outbound from the poller host to each device** on the gNMI port (commonly
  `9339/tcp`, vendor-dependent). The daemon holds a **persistent gRPC/HTTP2
  connection** per device.
- Allow the connection to stay open long-term; do not place idle-timeout
  middleboxes between the poller and the devices, or the daemon will churn
  through reconnects (it backs off 1→60 s on failure).
- Prefer an **out-of-band / management network** between the Cacti poller and
  device management interfaces. gNMI telemetry should not traverse untrusted
  segments in plaintext or with `skip_verify`.
- Each daemon reads device counters only; it does not write configuration to the
  device. A read-only gNMI scope on the device is sufficient.

---

## 4. Filesystem and host hardening

On a fresh install the plugin creates and protects this runtime tree:

```text
plugins/gnmi/runtime/
  storage/  daemon config JSON, telemetry JSON, PID files, poller lock
  certs/    operator-installed CA/client certs and private keys
  logs/     daemon stdout/stderr logs
```

Set `GNMI_RUNTIME_DIR` to override the runtime root. When the runtime tree lives
under the Cacti web path, the plugin writes Apache-compatible `.htaccess` deny
files in the runtime root and each subdirectory. Non-Apache installs must
configure equivalent deny rules and verify direct HTTP requests return `403` or
`404` before live testing.

The plugin's runtime directories carry sensitive data:

| Path | Contents | Sensitivity |
|------|----------|-------------|
| `runtime/storage/` | Per-device daemon config (incl. plaintext credentials), JSON telemetry, PID files, poller lock | **High** |
| `runtime/certs/` | CA / client certs and **private keys** | **High** |
| `runtime/logs/` | Per-device daemon logs | Medium (may contain hostnames, errors) |

Recommendations:
- Keep plugin code and venv administrator-owned and unwritable by the service
  account. Build the venv on the poller host/container at its final path using
  the [archive installation flow](install.md); preserve its executable modes.
- Use the same verified web/poller service UID for this beta path. Runtime
  directories are service-owned `0750`, ordinary runtime files `0640` and private
  keys `0600`. Check actual access; a mode listing alone does not prove denial.
- Test HTTP denial using existing nonsecret JSON/key/log probes before adding
  credentials. Configure server-level denial when `.htaccess` is ineffective.
- Ensure these directories are owned by the poller/web user and are **not
  world-readable**; private keys `chmod 600`.
- Confirm the web server does not serve `runtime/storage/`, `runtime/certs/`, or `runtime/logs/`
  directly (no directory indexing; deny access at the vhost level if they sit
  under the docroot).
- The poller advisory lock (`runtime/storage/.gnmi_poller_bottom.lock`) serializes
  concurrent `poller_bottom` runs. On multi-node pollers sharing storage over
  NFS, use a lock-compatible shared filesystem.

---

## 5. Cacti permissions

Plugin pages are gated by Cacti's realm system
(`api_plugin_register_realm('gnmi', 'status.php', ...)`). Grant the **gNMI Status
Dashboard** realm only to roles that should view telemetry health. Device gNMI
configuration is part of the standard device edit form, so it inherits Cacti's
existing device-management permissions — restrict who can edit devices
accordingly, since that is where credentials are entered.

---

## 6. Hardening checklist

- [ ] Dedicated, read-only gNMI account per device (or per device group).
- [ ] Database access restricted to a least-privilege Cacti DB user.
- [ ] `runtime/storage/`, `runtime/certs/`, `runtime/logs/` not world-readable; private keys `chmod 600`.
- [ ] Web server denies direct access to `runtime/storage/`, `runtime/certs/`, `runtime/logs/`.
- [ ] Production devices use real CA/client certs; no private key deployed automatically.
- [ ] `skip_verify` **off** for all production devices.
- [ ] gNMI traffic confined to a management/out-of-band network.
- [ ] Backups (DB + host) encrypted and access-controlled.
- [ ] Cacti gNMI dashboard realm granted only to appropriate roles.
- [ ] Credential rotation schedule defined; rotate via the device edit form.

---

## Related documentation

- [install.md](install.md) — installation and prerequisites
- [user_guide.md](user_guide.md) — device and subscription management
- [troubleshooting.md](troubleshooting.md) — TLS/auth failure diagnostics
- [compatibility.md](compatibility.md) — validated software and gNMI platforms

## Dashboard and daemon permissions

Dashboard visibility follows Cacti's effective device access, including graph
policies, enabled groups, and hide-disabled preferences. Page realm access does
not grant access to every device. Hidden rows and their diagnostic details are
filtered before runtime inspection or event retrieval.

Explicit dashboard and AJAX restarts require an authenticated non-guest user,
**Manage gNMI Daemons**, access to the target's actual Cacti host, an enabled
gNMI device, POST, and valid CSRF. The submitting page/endpoint retains its
existing realm. General device management cannot substitute for daemon
management. Administrators must explicitly grant the dedicated realm where
needed; existing assignments and the default grant policy remain unchanged.

Installation-wide orphan status requires the core **Users/Groups** permission.
Manual cleanup and dependency recovery additionally require **Manage gNMI
Daemons**. Manual cleanup writes an actor-attributed installation audit entry
to the Cacti log, rather than attaching maintenance to a device. Trusted poller,
configuration-save, install, disable, and uninstall operations retain their
existing authorization contracts.

Configured guest and authentication-disabled dashboards follow Cacti's own
page/device read policy. They cannot execute explicit daemon mutations or
recover dependencies. Dashboard PID inspection and ordinary dependency
checks do not repair runtime files or install dependencies. Administrator-owned
code/venv must be repaired from a shell; web realm grants do not make it writable.

Both tested Cacti versions reset authentication-disabled mode to local
authentication and password repair. The plugin preserves that behavior and
denies explicit mutations from the unfinished password-change session. This
acceptance does not establish an independent no-auth dashboard mode.
