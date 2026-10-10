#!/usr/bin/env python3
"""実際に描画された色でパレット違反を検出する。

ソースに書いてある色リテラルを見るだけでは、`hsl(${h},...)` のように
実行時に決まる色を取りこぼす。ここではプレビュー動画の実ピクセルを見る。

  python3 scripts/check_palette_render.py            # 全ページ
  python3 scripts/check_palette_render.py --limit 20 # 悪い順に20件
  python3 scripts/check_palette_render.py page1 ...

判定:
  彩度 > 0.30 かつ 明度 > 0.18 のピクセルを「色のあるピクセル」とみなし、
  その色相が 185-228 度の外にある割合を out率 とする。
  out率 > 0.15 なら配色ルール違反。
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile

try:
    from PIL import Image
except ImportError:
    sys.exit('Pillow が必要です: pip install pillow')

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PREV = os.path.join(ROOT, 'img', 'previews')
CONTENTS = os.path.join(ROOT, 'data', 'contents.json')
REPORT = os.path.join(ROOT, 'reports', 'palette-render.json')

# H.264/JPEG のクロマ間引きで teal(172deg) が数度ぶれるため、
# 判定窓はソース側ルール(165-295)より少し広く取る。
HUE_MIN, HUE_MAX = 185, 228          # 度（2026-10 青焼き: 藍とシアン）
SAT_FLOOR, VAL_FLOOR = 0.30, 0.18
FAIL_RATIO = 0.15


def frames(page: str, out_png: str) -> bool:
    src = os.path.join(PREV, f'{page}.mp4')
    if not os.path.exists(src):
        return False
    cmd = ['ffmpeg', '-nostdin', '-loglevel', 'error', '-y', '-ss', '0.9', '-i', src,
           '-vf', 'fps=1,scale=192:108,tile=4x1', '-frames:v', '1', out_png]
    return subprocess.run(cmd, capture_output=True).returncode == 0 and os.path.exists(out_png)


def measure(png: str):
    im = Image.open(png).convert('HSV')
    h, s, v = im.split()
    hs, ss, vs = h.tobytes(), s.tobytes(), v.tobytes()
    total = 0
    out = 0
    hist = [0] * 36
    blue = 0
    for i in range(len(hs)):
        if ss[i] / 255 < SAT_FLOOR or vs[i] / 255 < VAL_FLOOR:
            continue
        deg = hs[i] * 360 / 256
        total += 1
        hist[int(deg // 10) % 36] += 1
        if not (HUE_MIN <= deg <= HUE_MAX):
            out += 1
        elif 195 <= deg <= 205:
            blue += 1
    return total, out, hist, blue


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('pages', nargs='*')
    ap.add_argument('--limit', type=int, default=0, help='悪い順に N 件だけ表示')
    ap.add_argument('--fail-ratio', type=float, default=FAIL_RATIO)
    args = ap.parse_args()

    items = json.load(open(CONTENTS, encoding='utf-8'))
    pages = args.pages or [c['page'] for c in items
                           if c['page'] not in ('about', 'contents', 'log')]

    rows = []
    with tempfile.TemporaryDirectory() as td:
        for i, p in enumerate(pages, 1):
            png = os.path.join(td, f'{p}.png')
            if not frames(p, png):
                continue
            total, out, hist, blue = measure(png)
            if total < 400:      # ほぼ無彩色のページは判定対象外
                continue
            ratio = out / total
            top = max(range(36), key=lambda k: hist[k]) * 10
            rows.append({'page': p, 'ratio': round(ratio, 4),
                         'blue_ratio': round(blue / total, 4),
                         'colored_px': total, 'dominant_hue': top})
            if i % 25 == 0:
                print(f'  … {i}/{len(pages)}', file=sys.stderr)

    rows.sort(key=lambda r: -r['ratio'])
    os.makedirs(os.path.dirname(REPORT), exist_ok=True)
    json.dump(rows, open(REPORT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

    # out率 = 許容色相の外。これは明確な違反。
    # blue率 = シアン(195-205)の占有率。シアンは触ったところと焦点だけに使う決まりなので、
    #          色のある画素の 55% を超えていたら「シアンで塗っている」疑いとして目視に回す。
    for r in rows:
        r['violation'] = r['ratio'] > args.fail_ratio
        r['review'] = not r['violation'] and r['blue_ratio'] > 0.55
    bad = [r for r in rows if r['violation']]
    review = [r for r in rows if r['review']]
    show = rows[:args.limit] if args.limit else (bad + review)

    print(f'判定対象 {len(rows)} ページ / 違反 {len(bad)} / 要確認 {len(review)}'
          f'  (違反: out率 > {args.fail_ratio:.0%} / 要確認: 青 > 55%)')
    for r in show:
        mark = 'x' if r['violation'] else ('?' if r['review'] else '-')
        print(f"  {mark} {r['page']:<16} out={r['ratio']:6.1%} blue={r['blue_ratio']:6.1%}"
              f"  主要色相={r['dominant_hue']:>3}deg")
    print(f'\n詳細: {os.path.relpath(REPORT, ROOT)}')
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
