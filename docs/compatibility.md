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

The same release-candidate code path passed install, enablement, regression,
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
