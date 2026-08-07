"""Repository-level checks for public documentation contracts."""

from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[1]


def tracked_markdown_files() -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "*.md"],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    )
    return [ROOT / line for line in result.stdout.splitlines() if line]


class PublicDocumentationTests(unittest.TestCase):
    def test_relative_markdown_links_resolve(self) -> None:
        link_pattern = re.compile(r"(?<!!)\[[^\]]+\]\(([^)]+)\)")
        missing: list[str] = []

        for document in tracked_markdown_files():
            text = document.read_text(encoding="utf-8")
            for raw_target in link_pattern.findall(text):
                target = raw_target.strip().strip("<>")
                if not target:
                    continue
                parsed = urlsplit(target)
                if parsed.scheme or target.startswith(("#", "//")):
                    continue
                path_text = unquote(parsed.path)
                if not path_text:
                    continue
                resolved = (document.parent / path_text).resolve()
                if not resolved.exists():
                    missing.append(
                        f"{document.relative_to(ROOT)} -> {target}"
                    )

        self.assertEqual([], missing, "Broken local Markdown links:\n" + "\n".join(missing))

    def test_operator_terms_match_current_prerelease_channel(self) -> None:
        info = (ROOT / "INFO").read_text(encoding="utf-8")
        version_match = re.search(r"^version\s*=\s*(\S+)$", info, re.MULTILINE)
        self.assertIsNotNone(version_match, "INFO must declare a version")
        version = version_match.group(1)

        operator_files = [
            ROOT / "docs/install.md",
            ROOT / "docs/security.md",
            ROOT / "scripts/DAEMON_README.md",
        ]
        combined = "\n".join(path.read_text(encoding="utf-8") for path in operator_files)

        if "-beta." in version:
            self.assertNotRegex(combined, re.compile(r"\bRC\b|release-candidate", re.I))
            self.assertRegex(combined, re.compile(r"public[- ]beta", re.I))
        elif "-rc." in version:
            self.assertNotRegex(combined, re.compile(r"public[- ]beta", re.I))
            self.assertRegex(combined, re.compile(r"\bRC\b|release-candidate", re.I))
        else:
            self.fail(f"Unsupported prerelease channel in INFO version: {version}")

    def test_schema_document_matches_current_contract(self) -> None:
        schema_doc = (ROOT / "docs/database_schema.md").read_text(encoding="utf-8")
        setup = (ROOT / "setup.php").read_text(encoding="utf-8")

        current_tables = [
            "plugin_gnmi_devices",
            "plugin_gnmi_subscriptions",
            "plugin_gnmi_metrics",
            "plugin_gnmi_events",
        ]
        for table in current_tables:
            self.assertIn(f"## `{table}`", schema_doc)
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", setup)

        required_columns = [
            "hostname_source",
            "compatibility_mode",
            "auto_create_graphs",
            "subscription_id",
            "metric_group",
            "metric_direction",
            "metric_graph_key",
            "graph_local_id",
        ]
        for column in required_columns:
            self.assertIn(f"`{column}`", schema_doc)
            self.assertIn(f"`{column}`", setup)

        self.assertNotIn("## `plugin_gnmi_device_metrics`", schema_doc)
        self.assertNotIn(
            "CREATE TABLE IF NOT EXISTS `plugin_gnmi_device_metrics`",
            setup,
        )
        self.assertNotIn("DROP TABLE IF EXISTS plugin_gnmi_metrics", setup)

    def test_metric_guide_describes_implemented_behavior(self) -> None:
        guide = (ROOT / "docs/metric_groups.md").read_text(encoding="utf-8")
        stale_markers = [
            "Phase 2 MVP",
            "OpenConfig Paths (Future)",
            "instance_override",
            "docs/cacti_data_input_methods.md",
            "plugins/gnmi/scripts/gnmi_poller_bridge.py",
        ]
        for marker in stale_markers:
            self.assertNotIn(marker, guide)

        for rrd_type in ("COUNTER", "GAUGE", "DERIVE", "ABSOLUTE"):
            self.assertIn(f"`{rrd_type}`", guide)
        for group in ("traffic", "packets", "errors", "discards", "generic"):
            self.assertIn(f"`{group}`", guide)
        self.assertIn(
            "[Subscription examples](https://github.com/Per-Forma/cacti-gnmi-plugin/tree/main/examples)",
            guide,
        )
        self.assertIn(
            "[Poller bridge source](https://github.com/Per-Forma/cacti-gnmi-plugin/blob/main/scripts/gnmi_poller_bridge.py)",
            guide,
        )

    def test_release_package_copies_complete_documentation_set(self) -> None:
        package_script = (ROOT / "deployment/package_beta.sh").read_text(encoding="utf-8")
        self.assertIn('cp "$SOURCE_ROOT"/docs/*.md "$PACKAGE_DIR/docs/"', package_script)
        self.assertIn('cp "$SOURCE_ROOT"/docs/*.md "$PACKAGE_DIR/gnmi/docs/"', package_script)
        self.assertIn('cp "$SOURCE_ROOT/SECURITY.md" "$PACKAGE_DIR/gnmi/"', package_script)
        self.assertIn('cp "$SOURCE_ROOT/CONTRIBUTING.md" "$PACKAGE_DIR/gnmi/"', package_script)


if __name__ == "__main__":
    unittest.main()
