#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTENTS = ROOT / 'data' / 'contents.json'
PREVIEWS = ROOT / 'img' / 'previews'


def main():
    items = json.loads(CONTENTS.read_text())
    missing = []
    for c in items:
        page = c.get('page')
        if not page or page in ('about', 'contents'):
            continue
        mp4 = PREVIEWS / f'{page}.mp4'
        webm = PREVIEWS / f'{page}.webm'
        jpg = PREVIEWS / f'{page}.jpg'
        if not mp4.exists() or not webm.exists() or not jpg.exists():
            missing.append({
                'page': page,
                'mp4': mp4.exists(),
                'webm': webm.exists(),
                'jpg': jpg.exists(),
            })

    report = {
        'ok': len(missing) == 0,
        'checked': sum(1 for c in items if c.get('page') not in ('about', 'contents')),
        'missingCount': len(missing),
        'missing': missing,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))

    if missing:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
