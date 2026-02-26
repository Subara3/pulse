#!/usr/bin/env python3
"""PULSE content integrity checker.

Checks:
1) Every page in data/contents.json has a corresponding <page>.php file
   (except guide pages like about/contents).
2) data/log/*.json day numbers are contiguous (no missing days).
3) Each log filename matches the embedded "day" field.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTENTS = ROOT / "data" / "contents.json"
LOG_DIR = ROOT / "data" / "log"
SKIP_PAGES = {"about", "contents"}


def load_json(path: Path):
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def check_pages() -> list[str]:
    errors: list[str] = []
    items = load_json(CONTENTS)
    for item in items:
        page = item.get("page", "").strip()
        if not page or page in SKIP_PAGES:
            continue
        php = ROOT / f"{page}.php"
        if not php.exists():
            errors.append(f"missing page file: {php.name} (from contents.json page='{page}')")
    return errors


def check_logs() -> list[str]:
    errors: list[str] = []
    day_nums: list[int] = []

    for path in sorted(LOG_DIR.glob("*.json")):
        stem = path.stem
        if not stem.isdigit():
            errors.append(f"invalid log filename (not numeric): {path.name}")
            continue
        file_day = int(stem)
        day_nums.append(file_day)

        try:
            obj = load_json(path)
        except Exception as e:
            errors.append(f"invalid json: {path.name}: {e}")
            continue

        body_day = obj.get("day")
        if body_day != file_day:
            errors.append(f"day mismatch: {path.name} has day={body_day}")

    if day_nums:
        missing = sorted(set(range(min(day_nums), max(day_nums) + 1)) - set(day_nums))
        if missing:
            errors.append("missing log days: " + ", ".join(f"{d:03d}" for d in missing))

    return errors


def main() -> int:
    errors = []
    errors.extend(check_pages())
    errors.extend(check_logs())

    if errors:
        print("❌ Integrity check failed")
        for e in errors:
            print(f" - {e}")
        return 1

    print("✅ Integrity check passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
