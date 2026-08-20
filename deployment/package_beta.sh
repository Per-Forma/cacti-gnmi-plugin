#!/bin/bash
#
# Build a fresh-install prerelease artifact for the Cacti gNMI plugin.
#
# Usage:
#   deployment/package_release.sh [--allow-dirty] <version>
#
# Output:
#   dist/cacti-gnmi-plugin-<version>.tar.gz
#   dist/cacti-gnmi-plugin-<version>.tar.gz.sha256

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ALLOW_DIRTY=0

if [ "${1:-}" = "--allow-dirty" ]; then
	ALLOW_DIRTY=1
	shift
fi

VERSION="${1:-}"
if [ -z "$VERSION" ] || [ "$#" -ne 1 ]; then
	echo "Usage: $0 [--allow-dirty] <version>" >&2
	echo "Example: $0 1.0.0-beta.2" >&2
	exit 2
fi

RELEASE_VERSION="$VERSION"
case "$RELEASE_VERSION" in
	*-rc.*) RELEASE_CHANNEL="rc"; RELEASE_LABEL="RC" ;;
	*-beta.*) RELEASE_CHANNEL="beta"; RELEASE_LABEL="BETA" ;;
	*)
		echo "ERROR: version must use an -rc.N or -beta.N prerelease suffix." >&2
		exit 2
		;;
esac
TAG="v$VERSION"
SOURCE_ROOT="$PROJECT_ROOT"
SOURCE_COMMIT=""
SOURCE_DESCRIPTION=""
WORK_DIR="$(mktemp -d)"

cleanup() {
	rm -rf "$WORK_DIR"
}
trap cleanup EXIT

if [ "$ALLOW_DIRTY" -eq 1 ]; then
	SOURCE_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse HEAD 2>/dev/null || printf 'uncommitted')"
	SOURCE_DESCRIPTION="$SOURCE_COMMIT (dirty development working tree)"
	VERSION="$VERSION-dirty"
else
	if ! git -C "$PROJECT_ROOT" diff --quiet || ! git -C "$PROJECT_ROOT" diff --cached --quiet ||
		[ -n "$(git -C "$PROJECT_ROOT" ls-files --others --exclude-standard)" ]; then
		echo "ERROR: release packaging requires a clean working tree." >&2
		echo "Use --allow-dirty only for a non-release development smoke package." >&2
		exit 1
	fi

	if ! git -C "$PROJECT_ROOT" rev-parse --verify --quiet "$TAG^{commit}" >/dev/null; then
		echo "ERROR: release tag '$TAG' does not exist." >&2
		exit 1
	fi
	if [ "$(git -C "$PROJECT_ROOT" cat-file -t "$TAG")" != "tag" ]; then
		echo "ERROR: release tag '$TAG' must be annotated." >&2
		exit 1
	fi

	SOURCE_COMMIT="$(git -C "$PROJECT_ROOT" rev-parse "$TAG^{commit}")"
	if [ "$SOURCE_COMMIT" != "$(git -C "$PROJECT_ROOT" rev-parse HEAD)" ]; then
		echo "ERROR: release tag '$TAG' does not resolve to HEAD." >&2
		exit 1
	fi

	SOURCE_ROOT="$WORK_DIR/source"
	mkdir -p "$SOURCE_ROOT"
	git -C "$PROJECT_ROOT" archive "$TAG" | tar -x -C "$SOURCE_ROOT"
	SOURCE_DESCRIPTION="$TAG ($SOURCE_COMMIT)"
fi

PLUGIN_DIR="$SOURCE_ROOT"
PLUGIN_VERSION="$(grep -m1 "'version'" "$PLUGIN_DIR/setup.php" | sed -E "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\\1/")"
INFO_VERSION="$(awk -F ' *= *' '$1 == "version" { print $2; exit }' "$PLUGIN_DIR/INFO")"

if [ -z "$PLUGIN_VERSION" ] || [ "$PLUGIN_VERSION" != "$INFO_VERSION" ]; then
	echo "ERROR: plugin version mismatch: setup.php='$PLUGIN_VERSION', INFO='$INFO_VERSION'" >&2
	exit 1
fi

if [ "$RELEASE_VERSION" != "$PLUGIN_VERSION" ]; then
	echo "ERROR: release version '$RELEASE_VERSION' does not match plugin version '$PLUGIN_VERSION'." >&2
	exit 1
fi

PACKAGE_NAME="cacti-gnmi-plugin-$VERSION"
DIST_DIR="$PROJECT_ROOT/dist"
PACKAGE_DIR="$WORK_DIR/$PACKAGE_NAME"

mkdir -p "$PACKAGE_DIR/gnmi/docs" "$PACKAGE_DIR/docs" "$DIST_DIR"

copy_item() {
	local item="$1"
	if [ -e "$PLUGIN_DIR/$item" ]; then
		cp -R "$PLUGIN_DIR/$item" "$PACKAGE_DIR/gnmi/"
	fi
}

copy_item "setup.php"
copy_item "INFO"
copy_item "index.php"
copy_item "status.php"
copy_item "ajax_handler.php"
copy_item "test_connection.php"
copy_item "pages"
copy_item "include"
copy_item "README.md"
cp "$SOURCE_ROOT/SECURITY.md" "$PACKAGE_DIR/gnmi/"
cp "$SOURCE_ROOT/CONTRIBUTING.md" "$PACKAGE_DIR/gnmi/"

mkdir -p "$PACKAGE_DIR/gnmi/scripts/gnmi_collector"
for script_item in \
	DAEMON_README.md README.md __init__.py analyze_daemon_logs.py \
	check_data_continuity.py gnmi_connection_test.py gnmi_daemon.py \
	gnmi_daemon_ctl.py gnmi_daemon_monitor.py gnmi_poller_bridge.py \
	gnmi_runtime.py gnmi_tls.py requirements.txt requirements-installed.txt; do
	cp "$PLUGIN_DIR/scripts/$script_item" "$PACKAGE_DIR/gnmi/scripts/"
done
cp -R "$PLUGIN_DIR/scripts/gnmi_collector/." "$PACKAGE_DIR/gnmi/scripts/gnmi_collector/"

cp "$SOURCE_ROOT"/docs/*.md "$PACKAGE_DIR/docs/"
cp "$SOURCE_ROOT"/docs/*.md "$PACKAGE_DIR/gnmi/docs/"
cp "$SOURCE_ROOT/LICENSE" "$PACKAGE_DIR/LICENSE"
cp "$SOURCE_ROOT/LICENSE" "$PACKAGE_DIR/gnmi/LICENSE"

cp "$SOURCE_ROOT/BETA.md" "$PACKAGE_DIR/${RELEASE_LABEL}_README.md"

if [ -f "$SOURCE_ROOT/releases/$RELEASE_VERSION.md" ]; then
	cp "$SOURCE_ROOT/releases/$RELEASE_VERSION.md" "$PACKAGE_DIR/RELEASE_NOTES.md"
elif [ "$ALLOW_DIRTY" -eq 0 ]; then
	echo "ERROR: releases/$RELEASE_VERSION.md is required for a release artifact." >&2
	exit 1
fi

for required_file in \
	"LICENSE" "gnmi/LICENSE" "gnmi/INFO" "gnmi/setup.php" \
	"gnmi/scripts/requirements.txt" "gnmi/scripts/gnmi_tls.py" "RELEASE_NOTES.md"; do
	if [ ! -f "$PACKAGE_DIR/$required_file" ]; then
		echo "ERROR: required package file is missing: $required_file" >&2
		exit 1
	fi
done

find "$PACKAGE_DIR/gnmi" -type d -name "__pycache__" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name ".pytest_cache" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "htmlcov" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "venv" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "runtime" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "storage" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "certs" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type d -name "logs" -prune -exec rm -rf {} +
find "$PACKAGE_DIR/gnmi" -type f -name "*.pyc" -delete
find "$PACKAGE_DIR/gnmi" -type f -name "test_*.py" -delete
find "$PACKAGE_DIR/gnmi" -type f -name "test_*.json" -delete
find "$PACKAGE_DIR/gnmi" -type f \( -name "*.rrd" -o -name "*.pem" -o -name "*.key" -o -name "*.crt" -o -name "*.p12" -o -name "*.pfx" \) -delete

cat > "$PACKAGE_DIR/MANIFEST.md" << EOF
# $PACKAGE_NAME

Created: $(date -u '+%Y-%m-%d %H:%M:%S UTC')
Source: $SOURCE_DESCRIPTION

## Contents

- \`gnmi/\`: deployable Cacti plugin directory.
- \`docs/\`: complete operator, architecture, schema, and troubleshooting documentation.
- \`${RELEASE_LABEL}_README.md\`: prerelease installation and test flow.
- \`LICENSE\`: GNU General Public License version 2 or later.
- \`RELEASE_NOTES.md\`: release-specific changes, validation, and limitations.
- \`CHECKSUMS.txt\`: SHA-256 checksums for files inside this package.

## Prerelease Policy

This $RELEASE_CHANNEL is fresh-install-only and is not production-ready. Do not
install it over an existing gNMI plugin deployment.
EOF

if [ "$ALLOW_DIRTY" -eq 1 ]; then
	cat >> "$PACKAGE_DIR/MANIFEST.md" << EOF

## Development Artifact Warning

This archive was built from a dirty working tree for local smoke testing. It is
not a release artifact and cannot be reconstructed from the recorded commit.
EOF
fi

(
	cd "$PACKAGE_DIR"
	find . -type f ! -name "CHECKSUMS.txt" | LC_ALL=C sort | xargs shasum -a 256 > CHECKSUMS.txt
	shasum -a 256 -c CHECKSUMS.txt >/dev/null
)

FORBIDDEN_PATTERN='/(venv|runtime|htmlcov|\.pytest_cache|tests)/|\.rrd$|\.pem$|\.key$|\.crt$|\.p12$|\.pfx$|\.pyc$|requirements-dev\.txt$|gnmi_poller\.py$|validate_bridge_|validate_schema\.sql$|^gnmi/test_ajax\.php$|^gnmi/status_test\.php$|^gnmi/subscription_ajax\.php$|^gnmi/subscription_actions(_minimal)?\.php$'
FORBIDDEN_FILE="/tmp/gnmi_release_forbidden.$$"
if find "$PACKAGE_DIR" -type f | sed "s|$PACKAGE_DIR/||" | grep -E "$FORBIDDEN_PATTERN" >"$FORBIDDEN_FILE"; then
	echo "ERROR: forbidden files detected in prerelease package:" >&2
	cat "$FORBIDDEN_FILE" >&2
	rm -f "$FORBIDDEN_FILE"
	exit 1
fi
rm -f "$FORBIDDEN_FILE"

TARBALL="$DIST_DIR/$PACKAGE_NAME.tar.gz"
rm -f "$TARBALL" "$TARBALL.sha256"

(
	cd "$WORK_DIR"
	COPYFILE_DISABLE=1 tar czf "$TARBALL" \
		--exclude='._*' \
		--exclude='.DS_Store' \
		--exclude='.AppleDouble' \
		--exclude='.LSOverride' \
		"$PACKAGE_NAME"
)

(
	cd "$DIST_DIR"
	shasum -a 256 "$(basename "$TARBALL")" > "$(basename "$TARBALL").sha256"
	shasum -a 256 -c "$(basename "$TARBALL").sha256" >/dev/null
)

tar tzf "$TARBALL" >/dev/null

echo "Prerelease package created:"
echo "  $TARBALL"
echo "  $TARBALL.sha256"
echo "  source, internal checksums, archive integrity, and archive checksum verified"
echo ""
echo "Inspect:"
echo "  tar tzf $TARBALL"
