#!/usr/bin/env python3
"""Build checks for the PULSE site."""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SKIP_PAGE_FILES = {"about", "contents", "log"}


def test_php_syntax() -> bool:
    print("Checking PHP syntax...")
    php_files = sorted(set(ROOT.glob("**/*.php")))
    php_files = [
        path
        for path in php_files
        if "vendor" not in path.parts and "node_modules" not in path.parts
    ]

    errors: list[str] = []
    for php_file in php_files:
        result = subprocess.run(
            ["php", "-l", str(php_file)],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        if result.returncode != 0:
            msg = (result.stdout or result.stderr).strip()
            errors.append(f"{php_file.relative_to(ROOT)}: {msg}")

    if errors:
        print("PHP syntax errors:")
        for error in errors:
            print(f"  - {error}")
        return False

    print(f"OK: {len(php_files)} PHP files")
    return True


def test_json_syntax() -> bool:
    print("Checking JSON syntax...")
    json_files = sorted(set((ROOT / "data").glob("**/*.json")))
    errors: list[str] = []

    for json_file in json_files:
        try:
            with json_file.open("r", encoding="utf-8") as handle:
                json.load(handle)
        except json.JSONDecodeError as exc:
            errors.append(f"{json_file.relative_to(ROOT)}: {exc}")

    if errors:
        print("JSON syntax errors:")
        for error in errors:
            print(f"  - {error}")
        return False

    print(f"OK: {len(json_files)} JSON files")
    return True


def test_contents_json() -> bool:
    print("Checking contents.json integrity...")
    contents_path = ROOT / "data" / "contents.json"
    if not contents_path.exists():
        print("Missing data/contents.json")
        return False

    with contents_path.open("r", encoding="utf-8") as handle:
        contents = json.load(handle)

    missing: list[str] = []
    for item in contents:
        page = (item.get("page") or "").strip()
        if not page or page in SKIP_PAGE_FILES:
            continue
        if not (ROOT / f"{page}.php").exists():
            missing.append(page)

    if missing:
        print("Missing PHP files referenced by contents.json:")
        for page in missing:
            print(f"  - {page}.php")
        return False

    print(f"OK: contents.json ({len(contents)} entries)")
    return True


def test_required_files() -> bool:
    print("Checking required files...")
    required = [
        "index.php",
        "includes/config.php",
        "includes/header.php",
        "includes/footer.php",
        "data/contents.json",
    ]
    missing = [path for path in required if not (ROOT / path).exists()]

    if missing:
        print("Missing required files:")
        for path in missing:
            print(f"  - {path}")
        return False

    print("OK: required files")
    return True


def test_policy() -> bool:
    """scripts/check_policy.py をビルドの一部として必ず通す。

    配色・必須include・分類・リンク切れは、CLAUDE.md に書くだけでは守られない。
    ここで落とす。"""
    print("Checking site policy...")
    result = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "check_policy.py")],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        cwd=str(ROOT),
    )
    output = (result.stdout or "") + (result.stderr or "")
    for line in output.strip().splitlines():
        print("  " + line)
    return result.returncode == 0


def main() -> int:
    print("=" * 50)
    print("PULSE build checks")
    print("=" * 50)

    results: list[bool] = []
    if shutil.which("php"):
        results.append(test_php_syntax())
    else:
        print("WARNING: php not found; skipping PHP syntax check")

    results.append(test_json_syntax())
    results.append(test_contents_json())
    results.append(test_required_files())
    results.append(test_policy())

    print("=" * 50)
    if all(results):
        print("All checks passed")
        return 0

    print("Some checks failed")
    return 1


if __name__ == "__main__":
    sys.exit(main())
