# Cacti integration container

This directory builds Cacti 1.2.31 from upstream commit
`1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1`. The source tarball is protected
by its pinned SHA-256. MariaDB is pinned to `10.6.21` in `../compose.yaml`.

The stack joins the external `gnmi_srl_beta_mgmt` network at `10.253.0.20` and
publishes Cacti on `0.0.0.0:7083`. Its database, RRA files, logs, cache, plugin,
plugin virtual environment, and plugin runtime all live below `LAB_STATE_DIR`.

On the Ubuntu lab server:

```bash
cp .env.example .env
export DOCKER_HOST=unix:///run/docker-beta.sock
./scripts/cacti-up.sh
```

The checked-in credentials are deliberately marked test-only. `.env` is
ignored by the repository and is the only file the scripts read for actual
credentials. `cacti-up.sh` first checks free space on both the Docker data root
and `LAB_STATE_DIR`; no image build or pull starts when either margin fails.

`cacti-down.sh` refuses to act without two explicit confirmations and always
preserves state. There is intentionally no state deletion helper: retain this
environment until the project owner approves its removal after testing.
