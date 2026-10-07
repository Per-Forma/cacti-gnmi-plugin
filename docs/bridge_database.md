# Poller bridge database configuration

Cacti remains the only place to configure database credentials. During normal
collection the PHP poller exports the metadata for its selected PDO connection
once and sends it to each Python bridge through a private stdin pipe. The
bridge does not reread `config.php`, put credentials in arguments or environment
variables, or save a second credential file. Changes take effect when Cacti
establishes the updated connection for the next poller invocation.

## Transport and TLS

The bridge supports MySQL/MariaDB TCP and Unix sockets, including nondefault
ports. As in Cacti, `localhost` with a port other than 3306 uses `127.0.0.1`.
Default-port `localhost` uses the calling PHP interpreter's absolute
`pdo_mysql.default_socket`; configure this explicitly if PHP reports an empty
value. An absolute socket pathname in `database_hostname` selects that socket.
An unresolved socket fails instead of selecting a different database via TCP.

| Cacti setting | Bridge behavior |
| --- | --- |
| `database_ssl = false` | TLS is disabled explicitly, including PyMySQL's automatic PREFERRED mode. Unused certificate paths are ignored. |
| `database_ssl = true`, no CA | Encryption is required; server identity is not verified. A server without TLS is rejected before authentication. |
| `database_ssl = true`, CA | Encryption, CA chain validation and hostname validation are required. |
| TLS with client cert/key | Both files are required and loaded as the client identity. Encrypted keys are unsupported and never prompt for a passphrase. |

Database CA/cert/key paths can be outside gNMI's certificate directory. Relative
paths resolve against the PHP poller's working directory. All configured TLS
files must be readable by that account. Do not make plugin code or its virtual
environment writable to address a certificate permission failure.

CA and hostname validation is stricter than some PDO deployments. Correct a
certificate's DNS/IP subject alternative name or configure its matching hostname;
verification is never disabled automatically. Explicitly disabling TLS may also
expose an installation that previously worked only because PyMySQL automatically
negotiated TLS: enable `database_ssl` for a TLS-only server.

Socket transport does not satisfy an explicit TLS requirement by itself. The
acceptance environment found that PDO/mysqlnd on supported Cacti versions rejected
socket TLS while the pinned PyMySQL driver could negotiate it. Test your complete
Cacti connection first; prefer a matching DNS hostname over TCP when using
verified database TLS. This change adds no distributed/offline poller support.

## Standalone testing

For a main-poller local database, use the installed bridge:

```bash
sudo -u www-data /var/www/html/cacti/plugins/gnmi/venv/bin/python3 \
  /var/www/html/cacti/plugins/gnmi/scripts/gnmi_poller_bridge.py \
  --device-id 1 --local-data-id 6 \
  --config-path /var/www/html/cacti/include/config.php
```

`--config-path` defaults to the installed Cacti config location. `--php-binary`
can select a different CLI PHP executable. The bridge invokes its internal,
CLI-only PHP helper, evaluating the trusted config with Cacti defaults. Includes
resolve from the config directory; environment expressions require the same
service environment as Cacti. Configs that emit output, need unavailable Cacti
bootstrap state, or imply remote routing fail with a sanitized diagnostic. A
trusted PHP config is executable code; do not pass an untrusted file.

The helper refuses HTTP and interactive stdout. Do not invoke it to print its
internal payload. `--database-config-stdin` is reserved for the PHP poller and
cannot be combined with an explicit `--config-path`.

Normal collection requires argument-array `proc_open()` in the actual poller
PHP SAPI. Standalone mode additionally requires CLI PHP 8.1+ and readable config
and TLS files under the service account. A successful check in web PHP or a
shell using another interpreter does not establish poller readiness.

## Failures and polling time

Only successful metric stdout is used for RRD updates. Stale/empty results and
failed partial output cannot update graphs. Logs contain device/data-source IDs,
a phase, and an allowlisted diagnostic such as authentication, TLS identity,
file permissions, socket resolution, or metadata failure. Raw driver/config
exceptions, payloads and credentials are never logged, even in debug mode.
Correct the database configuration rather than restart a healthy gNMI daemon;
its buffers remain available for normal backfill after recovery, subject to
existing retention and staleness limits.

Collection shares a monotonic target from hook entry:
`min(0.8 * poller_interval, 30 seconds)`. Each bridge receives at most five
seconds from the remaining target, including pipe handling and teardown.
Database connect/read/write timeouts are at most two seconds. Standalone mode
has an eight-second total limit, including a three-second config resolver limit.
A shared configuration or connection failure stops repeated calls for that cycle.

Deferred sources resume in deterministic device/data-source order using the
nonsecret `.gnmi_bridge_cursor.json` in protected runtime storage. Updates are
atomic, mode 0640, and serialized by the existing poller lock. Unsafe paths or
unwritable storage produce a maintenance diagnostic. Unattempted devices keep
their previous poll status. Do not delete this installation state during routine
per-device cleanup.

The target bounds bridge execution and admits work between phases. Synchronous
Cacti database/RRD helpers and preceding daemon health/start/stop operations can
still exceed it; it is not a hard wall-clock guarantee for the entire hook.
