# Contributing

Thank you for helping improve the Cacti gNMI Plugin.

## Before opening a change

- Use GitHub Discussions for questions and early design conversation.
- Search existing issues before filing a bug or feature request.
- Report security issues privately according to `SECURITY.md`.
- Keep changes focused and avoid unrelated formatting rewrites.

## Development setup

Use Python 3.12 or later:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install --upgrade pip
.venv/bin/python -m pip install -r scripts/requirements-dev.txt
.venv/bin/pytest -v
```

Run PHP syntax checks:

```bash
find . -path './.venv' -prune -o -name '*.php' -print0 |
  xargs -0 -n1 php -l
```

Tests that exercise Cacti application APIs must run from a deployed Cacti tree.
The reproducible environments under `tests/integration/` cover the supported
Cacti boundary and the SR Linux interoperability scenario.

For a running local Docker Cacti instance, deploy the plugin and bootstrap its
runtime dependencies with the container's Python interpreter:

```bash
./deploy_plugin.sh cacti_app:/var/www/html/cacti/plugins/gnmi/
./deployment/bootstrap_local_docker.sh
```

The bootstrap is idempotent. It leaves a current environment alone and replaces
`venv/` only after a fresh environment passes import and dependency checks when
the existing environment came from another host/interpreter or its pinned
dependencies changed. Override the defaults with `--container` and
`--plugin-path`.

## Pull requests

1. Create a branch from `main`.
2. Add or update tests for behavior changes.
3. Update user-facing documentation and `CHANGELOG.md` when appropriate.
4. Run the relevant checks locally.
5. Open a focused pull request and complete its checklist.

All required CI checks must pass before merge. By submitting a contribution,
you agree that it is licensed under the repository's
`GPL-2.0-or-later` license.

Project participation is governed by `CODE_OF_CONDUCT.md`.
