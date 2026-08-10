"""Repository-level checks for public documentation contracts."""

from __future__ import annotations

import re
import tempfile
import unittest
from pathlib import Path

from tests.public_docs_validator import (
    tracked_markdown_files,
    validate_markdown_links,
    validate_packaged_doc_sets,
)


ROOT = Path(__file__).resolve().parents[1]

class PublicDocumentationTests(unittest.TestCase):
    def test_relative_markdown_links_resolve(self) -> None:
        issues = validate_markdown_links(ROOT, tracked_markdown_files(ROOT))
        self.assertEqual([], issues, "Broken local Markdown links:\n" + "\n".join(issues))

    def test_operator_terms_match_current_prerelease_channel(self) -> None:
        info = (ROOT / "INFO").read_text(encoding="utf-8")
        version_match = re.search(r"^version\s*=\s*(\S+)$", info, re.MULTILINE)
        self.assertIsNotNone(version_match, "INFO must declare a version")
        version = version_match.group(1)

        operator_files = [
            ROOT / "README.md",
            ROOT / "BETA.md",
            ROOT / "SECURITY.md",
            ROOT / "SUPPORT.md",
            ROOT / "scripts/DAEMON_README.md",
            ROOT / "releases" / f"{version}.md",
            *sorted((ROOT / "docs").glob("*.md")),
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
        self.assertNotIn("created or updated", guide)
        self.assertIn("does not rewrite its stored classification metadata", guide)
        self.assertIn(
            "`discard`, `discards`, `drop`, `drops`, or `dropped`",
            guide,
        )
        self.assertIn(
            "`error`, `errors`, `err`, `errs`, `crc`, `jabber`, `oversize`, "
            "`undersize`, or `fragment`",
            guide,
        )
        self.assertNotIn("or a plural form", guide)

    def test_data_flow_docs_name_the_direct_rrd_writer(self) -> None:
        schema = (ROOT / "docs/database_schema.md").read_text(encoding="utf-8")
        architecture = (ROOT / "docs/architecture.md").read_text(encoding="utf-8")
        for document in (schema, architecture):
            self.assertIn("`rrdtool update`", document)
        self.assertNotIn("Cacti updates its managed RRD files", schema)

    def test_markdown_validator_covers_packaged_link_shapes(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            (root / "images").mkdir()
            (root / "images" / "status.png").write_bytes(b"png")
            (root / "target.md").write_text(
                "# Existing heading\n\n## Repeated\n\n## Repeated\n",
                encoding="utf-8",
            )
            valid = root / "valid.md"
            valid.write_text(
                "[inline](target.md#existing-heading)\n"
                "![image](images/status.png)\n"
                "[reference][target]\n"
                "[duplicate](target.md#repeated-1)\n\n"
                "[target]: target.md#repeated\n\n"
                "```md\n[ignored](missing.md)\n```\n",
                encoding="utf-8",
            )
            self.assertEqual([], validate_markdown_links(root, [valid, root / "target.md"]))

            invalid = root / "invalid.md"
            invalid.write_text(
                "[missing](missing.md)\n"
                "![missing image](images/missing.png)\n"
                "[missing anchor](target.md#absent)\n"
                "[missing reference][unknown]\n",
                encoding="utf-8",
            )
            issues = validate_markdown_links(root, [invalid, root / "target.md"])
            self.assertTrue(any("missing.md" in issue for issue in issues))
            self.assertTrue(any("images/missing.png" in issue for issue in issues))
            self.assertTrue(any("missing anchor" in issue for issue in issues))
            self.assertTrue(any("missing reference definition" in issue for issue in issues))

    def test_packaged_document_sets_must_match_source(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source_docs = root / "source"
            package = root / "extract" / "package"
            source_docs.mkdir()
            (package / "docs").mkdir(parents=True)
            (package / "gnmi" / "docs").mkdir(parents=True)
            for name in ("install.md", "security.md"):
                (source_docs / name).write_text(f"# {name}\n", encoding="utf-8")
                (package / "docs" / name).write_text(f"# {name}\n", encoding="utf-8")
                (package / "gnmi" / "docs" / name).write_text(f"# {name}\n", encoding="utf-8")
            (package / "gnmi" / "SECURITY.md").write_text("# Security\n", encoding="utf-8")
            (package / "gnmi" / "CONTRIBUTING.md").write_text("# Contributing\n", encoding="utf-8")

            self.assertEqual(
                [],
                validate_packaged_doc_sets(root / "extract", source_docs),
            )
            (package / "gnmi" / "docs" / "security.md").unlink()
            issues = validate_packaged_doc_sets(root / "extract", source_docs)
            self.assertTrue(any("security.md" in issue for issue in issues))


if __name__ == "__main__":
    unittest.main()
