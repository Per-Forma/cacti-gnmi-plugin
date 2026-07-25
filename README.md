# Cacti gNMI Plugin

[![CI](https://github.com/Per-Forma/cacti-gnmi-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/Per-Forma/cacti-gnmi-plugin/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL_v2%2B-blue.svg)](LICENSE)

The Cacti gNMI Plugin collects streaming network telemetry through persistent
gNMI subscriptions and integrates the resulting metrics with Cacti data
sources, RRD files, graphs, and operational dashboards.

## Project status

**Version:** 1.0.0-beta.1

This is a functionally complete public beta intended for fresh test
installations. Do not install it over an earlier private or development build.
Production use is not yet recommended.

## Features

- gNMI configuration integrated into the Cacti device form
- Persistent per-device subscription daemons
- TLS and mutual TLS support
- Automatic daemon lifecycle and configuration-change handling
- Subscription, metric, data-source, and graph creation
- Health dashboard, freshness monitoring, and event history
- Standard `JSON_IETF` operation with an explicit Ciena SAOS 10 compatibility
  mode
- Automated Python, PHP, lifecycle, and integration test suites

## Requirements

| Component | Requirement |
| --- | --- |
| Cacti | 1.2.25 or later; tested through 1.2.31 |
| PHP | 8.1 or later |
| Python | 3.12 or later |
| Database | MySQL 5.7+ or MariaDB 10.2+ |
| Operating system | Linux with `/proc` available |

Python 3.12 and 3.14 are the public CI targets. Cacti 1.2.24 and earlier are
unsupported because they lack the data-input removal API required for a safe
plugin uninstall.

## Development and validation platforms

The plugin was developed against a physical **Ciena SAOS 10.8** device and
compatibility-tested against **Nokia SR Linux 26.3.3** in Containerlab. These
are validation records, not vendor support guarantees. Other
standards-compliant gNMI implementations may work but have not yet been
verified.

See [Compatibility](docs/compatibility.md) for the tested Cacti matrix and
scope of the platform claims.

## Installation

Install required operating-system packages first. On Debian or Ubuntu:

```bash
sudo apt install python3-venv python3-dev librrd-dev
```

Place this repository at `<cacti-root>/plugins/gnmi`, then install and enable
**gNMI Telemetry** from Cacti's Plugin Management page. The plugin creates its
isolated Python environment and runtime directories during setup.

Read the complete [installation guide](docs/install.md) before configuring a
device, especially when using TLS or mutual TLS.

## Documentation

- [Installation](docs/install.md)
- [User guide](docs/user_guide.md)
- [Compatibility](docs/compatibility.md)
- [Security](SECURITY.md)
- [Operational security](docs/security.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Architecture](docs/architecture.md)
- [Database schema](docs/database_schema.md)
- [Test plan](docs/test_plan.md)

## Development

Create a Python 3.12+ virtual environment and install development
dependencies:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install -r scripts/requirements-dev.txt
.venv/bin/pytest -v
```

Run PHP syntax checks:

```bash
find . -path './.venv' -prune -o -name '*.php' -print0 |
  xargs -0 -n1 php -l
```

Some PHP harnesses and all Cacti lifecycle tests require a deployed Cacti
tree. See [Contributing](CONTRIBUTING.md) and the integration harnesses under
`tests/integration/`.

## Communication

- Use [GitHub Discussions](https://github.com/Per-Forma/cacti-gnmi-plugin/discussions)
  for questions and general project conversation.
- Use [GitHub Issues](https://github.com/Per-Forma/cacti-gnmi-plugin/issues)
  for reproducible bugs and actionable feature requests.
- Report vulnerabilities privately as described in [Security](SECURITY.md).

## License

Copyright © 2026 Jarred Masterson.

This project is licensed under the GNU General Public License, version 2 or,
at your option, any later version. See [LICENSE](LICENSE).
