# Compatibility

## Supported software

| Component | Declared requirement | Public validation |
| --- | --- | --- |
| Cacti | 1.2.25 or later | 1.2.25 through 1.2.31 |
| PHP | 8.1 or later | PHP 8.1 baseline; current PHP checked in CI |
| Python | 3.12 or later | Python 3.12 and 3.14 |
| Database | MySQL 5.7+ or MariaDB 10.2+ | MariaDB 10.6 |

The Cacti 1.2.25 minimum is a lifecycle requirement. Cacti 1.2.24 does not
provide `api_data_input_remove()`, which the plugin needs for a safe,
zero-residue uninstall.

## Cacti validation matrix

The same public-beta code path passed install, enablement, regression,
poller/UI, disablement, uninstall, and reinstall validation across the
following releases:

| Cacti version | Result | Notes |
| --- | --- | --- |
| 1.2.31 | Pass | Baseline; SR Linux interoperability and soak validation |
| 1.2.30 | Pass | Full lifecycle and live Ciena validation |
| 1.2.29 | Pass | Full lifecycle and live Ciena validation |
| 1.2.28 | Pass | Full lifecycle and live Ciena validation |
| 1.2.27 | Pass | Full lifecycle and live Ciena validation |
| 1.2.26 | Pass | Full lifecycle and live Ciena validation |
| 1.2.25 | Pass | Supported minimum |
| 1.2.24 | Unsupported | Missing required uninstall API |

Passing this matrix does not guarantee compatibility with future Cacti
releases. New Cacti releases will be added after validation.

## gNMI platforms used for development and validation

| Platform | How it was used |
| --- | --- |
| Ciena SAOS 10.8 | Primary physical development target and live compatibility validation |
| Nokia SR Linux 26.3.3 | Containerlab interoperability and soak validation |

These entries record engineering and compatibility testing; they are not
vendor support guarantees. Other standards-compliant gNMI targets may work but
remain unverified.

### Ciena SAOS 10 compatibility mode

The validated Ciena SAOS 10.8 target did not respond to the gNMI `Capabilities`
RPC and could return `None` responses while establishing a subscription. Select
the explicit `ciena_saos10` compatibility mode only for an affected Ciena
target. In that mode, the plugin applies
`scripts/gnmi_collector/pygnmi_patch.py` to:

- bypass the unresponsive `Capabilities` RPC and supply the minimal capability
  response required by the client; and
- tolerate `None` subscription responses while waiting for valid data.

Standard mode retains the native pygnmi capability discovery and subscription
handling. The compatibility mode does not change TLS, certificate verification,
or authentication settings.

### TLS cipher compatibility

TLS cipher policy is vendor-neutral and separate from the Ciena protocol shim.
The default policy retains gRPC's secure cipher defaults. An administrator may
explicitly select `legacy_compatibility` for a target that only negotiates the
older `ECDHE-RSA-AES128-SHA` TLS 1.2 suite. That selection is scoped to the
target's daemon process and does not disable certificate verification or mTLS.
Device-side support for modern AEAD ciphers remains preferred.
