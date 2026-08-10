"""Validate local links in repository and packaged Markdown documents."""

from __future__ import annotations

import argparse
import html
import re
import subprocess
import sys
import unicodedata
from collections.abc import Iterable, Sequence
from pathlib import Path
from urllib.parse import unquote, urlsplit


MARKDOWN_SUFFIXES = {".md", ".markdown", ".mdown"}
INLINE_LINK = re.compile(
    r"!?\[[^\]\n]*\]\(\s*(?P<target><[^>\n]+>|[^\s)]+)"
    r"(?:\s+(?:\"[^\"\n]*\"|'[^'\n]*'|\([^()\n]*\)))?\s*\)"
)
REFERENCE_DEFINITION = re.compile(
    r"^[ \t]{0,3}\[(?P<label>[^\]]+)\]:\s*"
    r"(?P<target><[^>\n]+>|\S+)",
    re.MULTILINE,
)
REFERENCE_USE = re.compile(
    r"!?\[(?P<text>[^\]\n]+)\]\[(?P<label>[^\]\n]*)\]"
)
HEADING = re.compile(r"^[ \t]{0,3}#{1,6}[ \t]+(?P<text>.+?)[ \t]*#*[ \t]*$", re.MULTILINE)
EXPLICIT_ANCHOR = re.compile(
    r"<a\s+[^>]*(?:id|name)\s*=\s*['\"](?P<id>[^'\"]+)['\"][^>]*>",
    re.IGNORECASE,
)
FENCE = re.compile(r"^[ \t]{0,3}(?P<marker>`{3,}|~{3,})")


def tracked_markdown_files(root: Path) -> list[Path]:
    """Return Markdown paths tracked by Git beneath *root*."""
    result = subprocess.run(
        ["git", "ls-files", "*.md"],
        cwd=root,
        check=True,
        capture_output=True,
        text=True,
    )
    return [root / line for line in result.stdout.splitlines() if line]


def packaged_markdown_files(root: Path) -> list[Path]:
    """Return every Markdown document in an extracted release tree."""
    return sorted(
        path for path in root.rglob("*")
        if path.is_file() and path.suffix.lower() in MARKDOWN_SUFFIXES
    )


def _without_fenced_code(text: str) -> str:
    """Blank fenced code while preserving line boundaries."""
    output: list[str] = []
    fence_character: str | None = None
    fence_length = 0

    for line in text.splitlines(keepends=True):
        match = FENCE.match(line)
        marker = match.group("marker") if match else ""
        if fence_character is None and marker:
            fence_character = marker[0]
            fence_length = len(marker)
            output.append("\n" if line.endswith("\n") else "")
            continue
        if (
            fence_character is not None
            and marker
            and marker[0] == fence_character
            and len(marker) >= fence_length
        ):
            fence_character = None
            fence_length = 0
            output.append("\n" if line.endswith("\n") else "")
            continue
        if fence_character is not None:
            output.append("\n" if line.endswith("\n") else "")
        else:
            output.append(line)

    return "".join(output)


def _reference_label(label: str) -> str:
    return " ".join(label.casefold().split())


def _heading_slug(value: str) -> str:
    value = re.sub(r"!?\[([^\]]+)\]\([^)]*\)", r"\1", value)
    value = re.sub(r"<[^>]+>", "", value)
    value = html.unescape(value).replace("`", "")
    value = value.casefold()
    characters: list[str] = []
    for character in value:
        category = unicodedata.category(character)
        if category[0] in {"L", "N"} or character in {"_", "-", " "}:
            characters.append(character)
        elif character.isspace():
            characters.append(" ")
    return re.sub(r"-+", "-", re.sub(r"\s+", "-", "".join(characters))).strip("-")


def _document_anchors(path: Path) -> set[str]:
    text = _without_fenced_code(path.read_text(encoding="utf-8"))
    anchors = {unquote(match.group("id")) for match in EXPLICIT_ANCHOR.finditer(text)}
    duplicates: dict[str, int] = {}
    for match in HEADING.finditer(text):
        base = _heading_slug(match.group("text"))
        if not base:
            continue
        count = duplicates.get(base, 0)
        anchors.add(base if count == 0 else f"{base}-{count}")
        duplicates[base] = count + 1
    return anchors


def _target_issue(source: Path, raw_target: str, root: Path) -> str | None:
    target = raw_target.strip().strip("<>")
    if not target:
        return f"{source.relative_to(root)} -> empty target"

    parsed = urlsplit(target)
    if parsed.scheme or target.startswith("//"):
        return None

    path_text = unquote(parsed.path)
    destination = source if not path_text else (source.parent / path_text).resolve()
    try:
        destination.relative_to(root)
    except ValueError:
        return f"{source.relative_to(root)} -> {target} (escapes validation root)"

    if not destination.exists():
        return f"{source.relative_to(root)} -> {target} (missing target)"

    fragment = unquote(parsed.fragment)
    if fragment and destination.is_file() and destination.suffix.lower() in MARKDOWN_SUFFIXES:
        if fragment not in _document_anchors(destination):
            return f"{source.relative_to(root)} -> {target} (missing anchor)"

    return None


def validate_markdown_links(root: Path, documents: Iterable[Path]) -> list[str]:
    """Return human-readable issues for local Markdown links."""
    root = root.resolve()
    issues: list[str] = []

    for document in sorted({path.resolve() for path in documents}):
        try:
            document.relative_to(root)
        except ValueError:
            issues.append(f"{document} is outside validation root {root}")
            continue

        text = _without_fenced_code(document.read_text(encoding="utf-8"))
        definitions: dict[str, str] = {}
        definition_spans: list[tuple[int, int]] = []
        for match in REFERENCE_DEFINITION.finditer(text):
            definitions[_reference_label(match.group("label"))] = match.group("target")
            definition_spans.append(match.span())

        targets = [match.group("target") for match in INLINE_LINK.finditer(text)]
        targets.extend(definitions.values())
        for target in targets:
            issue = _target_issue(document, target, root)
            if issue:
                issues.append(issue)

        for match in REFERENCE_USE.finditer(text):
            if any(start <= match.start() < end for start, end in definition_spans):
                continue
            label = match.group("label") or match.group("text")
            normalized = _reference_label(label)
            if normalized not in definitions:
                issues.append(
                    f"{document.relative_to(root)} -> [{label}] (missing reference definition)"
                )

    return sorted(set(issues))


def validate_packaged_doc_sets(extract_root: Path, source_docs: Path) -> list[str]:
    """Verify that both packaged documentation layouts contain every source guide."""
    extract_root = extract_root.resolve()
    package_roots = sorted(
        path for path in extract_root.iterdir()
        if path.is_dir() and (path / "gnmi").is_dir()
    )
    if len(package_roots) != 1:
        return [
            f"expected one package root containing gnmi/, found {len(package_roots)}"
        ]

    expected = sorted(path.name for path in source_docs.glob("*.md") if path.is_file())
    issues: list[str] = []
    for relative in (Path("docs"), Path("gnmi/docs")):
        directory = package_roots[0] / relative
        actual = sorted(path.name for path in directory.glob("*.md")) if directory.is_dir() else []
        if actual != expected:
            missing = sorted(set(expected) - set(actual))
            unexpected = sorted(set(actual) - set(expected))
            issues.append(
                f"{relative}: missing={missing or 'none'}, unexpected={unexpected or 'none'}"
            )
    for relative in (Path("gnmi/SECURITY.md"), Path("gnmi/CONTRIBUTING.md")):
        if not (package_roots[0] / relative).is_file():
            issues.append(f"missing packaged file: {relative}")
    return issues


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "root",
        type=Path,
        help="Root of an extracted package; every Markdown file beneath it is validated",
    )
    parser.add_argument(
        "--source-docs",
        type=Path,
        help="Source docs directory whose Markdown filenames must exist in both package layouts",
    )
    args = parser.parse_args(argv)
    root = args.root.resolve()
    issues = validate_markdown_links(root, packaged_markdown_files(root))
    if args.source_docs is not None:
        issues.extend(validate_packaged_doc_sets(root, args.source_docs.resolve()))
    if issues:
        print("Broken packaged Markdown links:", file=sys.stderr)
        for issue in issues:
            print(f"- {issue}", file=sys.stderr)
        return 1
    print(f"Validated packaged Markdown links in {root}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
