# Cacti compatibility harness

This Compose stack starts one exact Cacti release with a fresh MariaDB database
for gNMI plugin compatibility validation. Each run must use a version-specific
project name, container names, image tag, state directory, port, Cacti commit,
and verified source-archive SHA-256 value.

The Cacti image build context is shared with the SR Linux integration harness.
State uses bind mounts so container removal does not destroy test evidence.
The private application network does not publish MariaDB, while allowing the
Cacti container outbound access to install checksum-pinned Python packages.
Do not reuse a database directory between Cacti versions; Cacti downgrades are
not supported.

Example lifecycle:

```bash
docker compose --env-file .env -f compose.yaml build cacti
docker compose --env-file .env -f compose.yaml up -d
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml down --remove-orphans
```
