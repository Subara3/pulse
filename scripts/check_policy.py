#!/usr/bin/env python3
"""PULSE ポリシー検査

CLAUDE.md に書いてあるだけの決めごとは守られない。ここで機械的に落とす。

  python3 scripts/check_policy.py           # 全部検査（エラーがあれば exit 1）
  python3 scripts/check_policy.py --warn    # 警告も表示
  python3 scripts/check_policy.py page1 ... # 指定ページだけ

検査項目
  1. contents.json のスキーマ / group の妥当性 / 実ファイルとプレビューの存在
  2. 全画面Canvasページの必須 include と禁止 include
  3. 配色（色相 165-300 の外にある彩度の高い色を検出）
  4. 削除済みページへのリンクが残っていないか
  5. 廃止フォント（Orbitron / Noto Sans JP）の残骸
  6. data/log の整合性（Day重複・関連リンク切れ）
"""
from __future__ import annotations

import argparse
import colorsys
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENTS = os.path.join(ROOT, 'data', 'contents.json')

GROUPS = {
    'chaos', 'grow', 'flow', 'swarm', 'lattice', 'number',
    'curve', 'fractal', 'light', 'force', 'tile', 'mind',
}
SPECIAL_GROUPS = {'log', 'guide'}

REQUIRED_KEYS = {'page', 'title', 'desc', 'icon', 'category', 'added', 'group'}

# 全画面Canvasページに必須 / 禁止の include
MUST_INCLUDE = ['includes/config.php', 'includes/header.php', 'includes/floating-nav.php',
                'includes/page-end.php']
MUST_NOT_INCLUDE = ['includes/footer.php', 'includes/related.php', 'includes/share-buttons.php']

DOC_PAGES = {'index', 'contents', 'about', 'log', 'sitemap', 'random'}

# 許容色相（度）。teal 165-205 / 遷移 205-255 / purple 255-295 に少し余裕を持たせる
HUE_MIN, HUE_MAX = 160.0, 300.0
SAT_FLOOR = 0.28      # これ未満はグレー寄りなので無視
LIGHT_LO, LIGHT_HI = 0.10, 0.94   # ほぼ黒/ほぼ白は無視

HEX_RE = re.compile(r'(?<![&\w])#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})(?![0-9a-fA-F])')
RGB_RE = re.compile(r'rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})')
HSL_RE = re.compile(r'hsla?\(\s*(\d{1,3}(?:\.\d+)?)\s*[, ]')

ALLOW_COLOR_FILE = os.path.join(ROOT, 'scripts', 'palette_allowlist.json')


def load_allowlist() -> dict:
    if os.path.exists(ALLOW_COLOR_FILE):
        with open(ALLOW_COLOR_FILE, encoding='utf-8') as f:
            return json.load(f)
    return {}


def hex_to_hsl(h: str):
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    r, g, b = (int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))
    hh, ll, ss = colorsys.rgb_to_hls(r, g, b)
    return hh * 360, ss, ll


def rgb_to_hsl(r, g, b):
    hh, ll, ss = colorsys.rgb_to_hls(r / 255, g / 255, b / 255)
    return hh * 360, ss, ll


def bad_hue(hue, sat, light) -> bool:
    if sat < SAT_FLOOR:
        return False
    if not (LIGHT_LO < light < LIGHT_HI):
        return False
    return not (HUE_MIN <= hue <= HUE_MAX)


def scan_colors(text: str):
    """(色表記, 色相) の一覧を返す"""
    out = []
    for m in HEX_RE.finditer(text):
        hue, sat, light = hex_to_hsl(m.group(1))
        if bad_hue(hue, sat, light):
            out.append((m.group(0), round(hue)))
    for m in RGB_RE.finditer(text):
        vals = [int(x) for x in m.groups()]
        if max(vals) > 255:
            continue
        hue, sat, light = rgb_to_hsl(*vals)
        if bad_hue(hue, sat, light):
            out.append((m.group(0) + ')', round(hue)))
    for m in HSL_RE.finditer(text):
        hue = float(m.group(1)) % 360
        if not (HUE_MIN <= hue <= HUE_MAX):
            out.append((m.group(0).rstrip(', '), round(hue)))
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('pages', nargs='*')
    ap.add_argument('--warn', action='store_true', help='警告も出す')
    args = ap.parse_args()

    errors: list[str] = []
    warns: list[str] = []
    allow = load_allowlist()

    # ---------------------------------------------------------------- 1
    items = json.load(open(CONTENTS, encoding='utf-8'))
    seen = set()
    pages = []
    for c in items:
        p = c.get('page', '?')
        missing = REQUIRED_KEYS - set(c)
        if missing:
            errors.append(f'contents.json: {p} にキーがない: {sorted(missing)}')
        if p in seen:
            errors.append(f'contents.json: {p} が重複している')
        seen.add(p)
        g = c.get('group')
        if g not in GROUPS and g not in SPECIAL_GROUPS:
            errors.append(f'contents.json: {p} の group が不正: {g!r}')
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', str(c.get('added', ''))):
            errors.append(f'contents.json: {p} の added が YYYY-MM-DD でない')
        if not os.path.exists(os.path.join(ROOT, f'{p}.php')):
            errors.append(f'contents.json: {p}.php が存在しない')
        if p not in DOC_PAGES:
            pages.append(p)
            for ext in ('mp4', 'webm', 'jpg'):
                if not os.path.exists(os.path.join(ROOT, 'img', 'previews', f'{p}.{ext}')):
                    warns.append(f'preview欠落: {p}.{ext}')

    # contents.json に無いのに残っている .php
    on_disk = {os.path.basename(f)[:-4] for f in glob.glob(os.path.join(ROOT, '*.php'))}
    orphan = on_disk - seen - DOC_PAGES
    for o in sorted(orphan):
        errors.append(f'孤児ページ: {o}.php が contents.json に載っていない')

    targets = args.pages or pages

    # ---------------------------------------------------------------- 2,3,5
    for p in targets:
        path = os.path.join(ROOT, f'{p}.php')
        if not os.path.exists(path):
            errors.append(f'{p}.php が無い')
            continue
        src = open(path, encoding='utf-8').read()

        for inc in MUST_INCLUDE:
            if inc not in src:
                errors.append(f'{p}.php: {inc} が include されていない')
        for inc in MUST_NOT_INCLUDE:
            if inc in src:
                errors.append(f'{p}.php: {inc} は全画面Canvasページに入れない')
        if 'includes/sfx.php' not in src:
            warns.append(f'{p}.php: 効果音(sfx.php)が無い')
        if 'interaction-hints.php' not in src and 'drawer-controls.php' not in src \
                and 'content-controls.php' not in src:
            warns.append(f'{p}.php: 操作の手がかり(hints/controls)が無い')

        if 'Orbitron' in src:
            errors.append(f'{p}.php: 廃止フォント Orbitron が残っている')
        if 'Noto Sans JP' in src:
            errors.append(f'{p}.php: 廃止フォント Noto Sans JP が残っている')

        bad = scan_colors(src)
        allowed = set(allow.get(p, []))
        bad = [(t, h) for t, h in bad if t not in allowed]
        if bad:
            uniq = sorted({f'{t}(h{h})' for t, h in bad})
            msg = f'{p}.php: 配色ルール外の色 {len(bad)}箇所 — ' + ', '.join(uniq[:6])
            if len(uniq) > 6:
                msg += f' …ほか{len(uniq) - 6}種'
            errors.append(msg)

    # ---------------------------------------------------------------- 4
    link_re = re.compile(r'href="([a-z0-9_-]+)\.php"')
    for f in glob.glob(os.path.join(ROOT, '*.php')) + glob.glob(os.path.join(ROOT, 'includes', '*.php')):
        src = open(f, encoding='utf-8').read()
        for m in link_re.finditer(src):
            t = m.group(1)
            if t not in seen and t not in DOC_PAGES:
                errors.append(f'{os.path.basename(f)}: 存在しないページへのリンク {t}.php')

    # ---------------------------------------------------------------- 6
    days = {}
    for f in sorted(glob.glob(os.path.join(ROOT, 'data', 'log', '*.json'))):
        try:
            e = json.load(open(f, encoding='utf-8'))
        except Exception as ex:
            errors.append(f'{os.path.basename(f)}: JSONとして壊れている ({ex})')
            continue
        d = e.get('day')
        if d in days:
            errors.append(f'log: Day {d} が {days[d]} と {os.path.basename(f)} で重複')
        days[d] = os.path.basename(f)
        for it in (e.get('contentSpotlight') or {}).get('items') or []:
            if it.get('page') and it['page'] not in seen:
                errors.append(f"{os.path.basename(f)}: 関連リンク先 {it['page']} が存在しない")

    # ---------------------------------------------------------------- 出力
    if warns and args.warn:
        print(f'--- 警告 {len(warns)} 件 ---')
        for w in warns:
            print('  ?', w)
    if errors:
        print(f'--- エラー {len(errors)} 件 ---')
        for e in errors:
            print('  x', e)
        return 1
    print(f'OK: {len(targets)} ページ / {len(items)} エントリ、ポリシー違反なし'
          + (f'（警告 {len(warns)} 件）' if warns and not args.warn else ''))
    return 0


if __name__ == '__main__':
    sys.exit(main())
