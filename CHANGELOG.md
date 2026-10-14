# Changelog

All notable public changes to this project will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Make archive installation self-contained: verify both checksum layers, reject
  occupied/symlink destinations, build pinned dependencies at their final Linux
  path, and keep code/venv administrator-owned with service-owned runtime.
- Exercise the shipped filesystem/Docker commands, protected dependency repair,
  runtime HTTP denial, first graphs and ownership after container recreation.

- Filter dashboard rows, details, statistics, and events using Cacti's effective
  device permissions before diagnostic reads. Require the dedicated daemon
  realm and target access for explicit dashboard and AJAX restarts.
- Restrict global orphan status to Users/Groups administrators and manual
  cleanup/recovery to administrators with daemon management. Record manual
  cleanup at installation level with the actor and outcome counts.
- Keep dashboard PID inspection and dependency diagnosis read-only; protected
  code and venv require administrator shell repair.
- Use the poller's actual database connection metadata through private pipes,
  preserving PHP password bytes, ports, Unix sockets and explicit database TLS.
- Replace standalone PHP-text parsing with a bounded CLI-only resolver and
  contain configuration/driver errors without leaking secrets into metrics or logs.
- Share collection deadlines across all sources, drain subprocess streams,
  discard failed output, and resume deferred sources with protected fair progress.

### Added

- Restricted-session packaged HTTP acceptance and effective user/group,
  graph/device, guest/no-auth, and revocation coverage on Cacti 1.2.25 and 1.2.31.

## [1.0.0-beta.3] - 2026-09-09

### Added

- Added an idempotent local-Docker virtualenv bootstrap and hardened package
  filtering for virtualenv variants and build remnants.
- Added archive-driven Cacti acceptance with checksum verification, authenticated
  management requests, and disable/uninstall/reinstall checks in CI.
- Added an explicit per-subscription option for automatically creating missing
  Cacti data sources for enabled metrics.

### Changed

- Updated the runtime gRPC and protobuf pins and the recorded cffi and
  cryptography versions.
- Updated checkout, Python setup, dependency review, and CodeQL Actions.

### Fixed

- Stop collector daemons when Cacti disables the plugin, including CLI disable,
  and prevent an in-flight poller from restarting them after disablement.
- Restored subscription, metric, data-source, graph, and daemon-restart AJAX
  management through a tracked, device-authorized action service.
- Ensured management failures return structured JSON instead of allowing a
  missing include or unexpected exception to disable the plugin.
- Added deployment, release-package, and Cacti integration checks for the AJAX
  runtime dependency contract.

## [1.0.0-beta.2] - 2026-08-13

### Added

- Added a vendor-neutral, per-device TLS cipher policy with an explicit Legacy
  TLS compatibility option for gNMI targets that cannot negotiate gRPC's
  secure cipher defaults.
- Added connection-test diagnostics and dashboard visibility for the selected
  TLS cipher policy.

### Fixed

- Aligned the fresh-install schema, database reference, metric-classification
  guide, and operator documentation with the current public beta, and added
  artifact-level documentation and schema-lifecycle regression coverage.
- Replaced references to nonexistent operator helper scripts with the supported
  dashboard restart action and diagnostic-tool workflow.

## [1.0.0-beta.1] - 2026-07-24

### Added

- Initial public beta of the Cacti gNMI Plugin.
- Persistent gNMI subscription daemons with lifecycle management.
- Cacti device-form, data-source, graph, and status-dashboard integration.
- TLS and mutual TLS configuration.
- Explicit Ciena SAOS 10 compatibility mode.
- Automated Python, PHP, lifecycle, and integration test suites.

[Unreleased]: https://github.com/Per-Forma/cacti-gnmi-plugin/compare/v1.0.0-beta.3...HEAD
[1.0.0-beta.3]: https://github.com/Per-Forma/cacti-gnmi-plugin/compare/v1.0.0-beta.2...v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/Per-Forma/cacti-gnmi-plugin/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/Per-Forma/cacti-gnmi-plugin/releases/tag/v1.0.0-beta.1
