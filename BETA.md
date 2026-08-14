# Public beta

Version 1.0.0-beta.2 is intended for evaluation in fresh Cacti test
environments. It is functionally complete, but production deployment is not
yet recommended.

## Before installation

- Confirm Cacti 1.2.25 or later, PHP 8.1 or later, and Python 3.12 or later.
- Back up the Cacti database and RRD directory.
- Use a dedicated gNMI account with the minimum required device privileges.
- Review the credential and certificate guidance in `docs/security.md`.
- Do not install over a private development build or an earlier plugin schema.

## Feedback

Keep project communication on GitHub:

- Use Discussions for questions and installation experiences.
- Use the bug-report issue form for reproducible defects.
- Use the feature-request issue form for improvements.
- Follow `SECURITY.md` for private vulnerability reports.
