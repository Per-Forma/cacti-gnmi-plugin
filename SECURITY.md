# Security policy

## Supported versions

The current public beta is the only version receiving security fixes:

| Version | Supported |
| --- | --- |
| 1.0.0-beta.x | Yes |
| Private development builds | No |

This beta is intended for fresh test installations and is not yet recommended
for production use.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Prefer GitHub's private vulnerability reporting feature on the repository
Security page. If that is unavailable, email
`jarred.masterson@gmail.com` with:

- A concise description of the issue and its impact
- Affected versions and configuration
- Reproduction steps or a proof of concept
- Any suggested mitigation

Please avoid including real device credentials, certificates, private keys,
telemetry, or customer information. Receipt will normally be acknowledged
within seven days. A remediation and disclosure timeline will be coordinated
after the report is validated.

For deployment hardening and the plugin's credential-storage model, see
`docs/security.md`.
