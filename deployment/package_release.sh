#!/bin/bash
# Preferred entry point for prerelease packaging.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$SCRIPT_DIR/package_beta.sh" "$@"
