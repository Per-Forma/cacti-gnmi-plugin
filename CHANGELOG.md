# Changelog

All notable public changes to this project will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/Per-Forma/cacti-gnmi-plugin/compare/v1.0.0-beta.2...HEAD
[1.0.0-beta.2]: https://github.com/Per-Forma/cacti-gnmi-plugin/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/Per-Forma/cacti-gnmi-plugin/releases/tag/v1.0.0-beta.1
