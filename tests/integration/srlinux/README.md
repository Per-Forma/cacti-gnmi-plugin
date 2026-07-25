# SR Linux integration harness

This harness validates the plugin against Nokia SR Linux 26.3.3 in
Containerlab. It creates an isolated Cacti and MariaDB stack, an SR Linux
node, traffic endpoints, and reproducible smoke and soak checks.

## Prerequisites

- Docker with Compose
- Containerlab 0.77 or later
- Sufficient resources to run SR Linux; 8 GB of available memory is recommended

## Setup

```bash
cp .env.example .env
```

Replace every placeholder credential in `.env`. Do not reuse production
credentials. The local `.env` and `.state/` directory are ignored by Git.

The scripts under `scripts/` provide scoped lifecycle operations:

```bash
scripts/lab-deploy.sh
scripts/cacti-up.sh
scripts/deploy-plugin.sh
scripts/smoke-gnmi.sh
scripts/soak-runner.sh
```

Use `scripts/lab-destroy.sh` and `scripts/cacti-down.sh` to stop the disposable
environment. Review each script and its configured state paths before running
destructive lifecycle commands.
