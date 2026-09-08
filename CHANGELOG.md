# Changelog

All notable public changes to this project will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Added an idempotent local-Docker bootstrap that verifies the gNMI Python
  environment against the container interpreter and safely rebuilds stale or
  incompatible dependencies before replacing the active virtualenv.

### Changed

- Hardened prerelease packaging checks to reject virtualenv directories and
  build remnants under common venv naming variants.

## [1.0.0-beta.3] - 2026-08-20

### Added

- Added an explicit per-subscription option for automatically creating missing
  Cacti data sources for enabled metrics.

### Fixed

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
