import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_required_ajax_runtime_files_exist():
    required = [
        ROOT / "ajax_handler.php",
        ROOT / "include" / "subscription_actions.php",
        ROOT / "include" / "subscription_functions.php",
        ROOT / "include" / "subscription_display.php",
    ]

    missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
    assert not missing, f"missing required AJAX runtime files: {missing}"


def test_repo_local_literal_php_dependencies_exist():
    php_files = [ROOT / "setup.php", ROOT / "ajax_handler.php"]
    php_files.extend((ROOT / "include").glob("*.php"))

    dir_pattern = re.compile(
        r"(?:require|include)(?:_once)?\s*\(\s*__DIR__\s*\.\s*['\"]([^'\"]+)['\"]"
    )
    plugin_pattern = re.compile(
        r"(?:require|include)(?:_once)?\s*\([^\n;]*?/plugins/gnmi/([^'\"]+)['\"]"
    )

    missing = []
    for source in php_files:
        text = source.read_text(encoding="utf-8")
        candidates = [source.parent / match for match in dir_pattern.findall(text)]
        candidates.extend(ROOT / match for match in plugin_pattern.findall(text))

        for candidate in candidates:
            resolved = candidate.resolve()
            try:
                resolved.relative_to(ROOT)
            except ValueError:
                # Cacti core dependencies live outside the plugin repository.
                continue
            if not resolved.exists():
                missing.append(f"{source.relative_to(ROOT)} -> {resolved.relative_to(ROOT)}")

    assert not missing, "missing repo-local PHP dependencies:\n" + "\n".join(missing)


def test_legacy_pages_action_dependency_is_gone():
    sources = [ROOT / "setup.php", ROOT / "ajax_handler.php"]
    for source in sources:
        assert "pages/subscription_actions.php" not in source.read_text(encoding="utf-8")
