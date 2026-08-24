#!/usr/bin/env python3
"""PULSE 情報設計リフォーム（2026-08-25 一度きりの移行スクリプト）

やること:
  1. 概念が重複するページを contents.json から削除（.htaccess で 301 リダイレクト）
  2. 全コンテンツを 12 のコレクション(group)に再分類
  3. category / category_en は group から機械的に導出して整合させる

このスクリプトは記録として残すが、通常運用で再実行する必要はない。
日々の運用チェックは scripts/check_policy.py が担当する。
"""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONTENTS = os.path.join(ROOT, 'data', 'contents.json')

# ---------------------------------------------------------------- collections
# key -> (日本語ラベル, 英語ラベル, 一行の約束, Font Awesome アイコン)
GROUPS = {
    'chaos':   ('予測できないもの', 'CHAOS',    'ほんのわずかな差が、まったく違う結末になる。', 'fa-hurricane'),
    'grow':    ('育つもの',        'GROWTH',   '誰も形を決めていないのに、形が決まっていく。', 'fa-seedling'),
    'flow':    ('流れるもの',      'FLOW',     '流れが場所を覚えていて、渦になって残る。',     'fa-water'),
    'swarm':   ('群れるもの',      'SWARM',    '指揮者がいないのに、全体が揃ってしまう。',     'fa-crow'),
    'lattice': ('格子のもの',      'LATTICE',  'ごく単純な規則だけで、模様が湧いてくる。',     'fa-border-all'),
    'number':  ('数えるもの',      'NUMBER',   '数字が並ぶだけで、そこに形が見えてくる。',     'fa-hashtag'),
    'curve':   ('描くもの',        'CURVE',    '式がそのまま一本の線になって出てくる。',       'fa-bezier-curve'),
    'fractal': ('繰り返すもの',    'FRACTAL',  'どこまで拡大しても、まだ続いている。',         'fa-snowflake'),
    'light':   ('光るもの',        'LIGHT',    '波が重なって、明暗の縞に変わる。',             'fa-lightbulb'),
    'force':   ('引き合うもの',    'FORCE',    '見えない力に、はっきりした形がある。',         'fa-magnet'),
    'tile':    ('敷き詰めるもの',  'TILING',   '隙間なく埋まるのに、二度と同じ並びが来ない。', 'fa-shapes'),
    'mind':    ('探すもの',        'SEARCH',   '手探りのくせに、ちゃんと最短を見つける。',     'fa-compass'),
}
GROUP_ORDER = list(GROUPS.keys())

# --------------------------------------------------------------- deletions
# 概念がほぼ完全に重複しているページ。削除して 301 で統合先へ飛ばす。
MERGES = {
    'mazegen':      ('maze',        '迷路生成 ⊂ 迷路'),
    'ripple':       ('waves',       '波紋 ⊂ 波動場'),
    'interference': ('waves',       '干渉縞 ⊂ 波動場'),
    'console':      ('waves',       '信号ダッシュボードは他ページの寄せ集め'),
    'doublepend':   ('pendulum',    'pendulum に二重振り子モードあり'),
    'spring':       ('pendulum',    'バネ振り子カオス ⊂ 振り子カオス'),
    'worley':       ('voronoi',     'セルラーノイズ = ボロノイ距離場'),
    'chaosgame':    ('ifs',         'カオスゲームは IFS の描画法そのもの'),
    'drift':        ('levydrift',   '単純ランダムウォーク ⊂ レヴィフライト'),
    'orbit':        ('nbody',       'ケプラー2体 ⊂ N体重力'),
    'lissajous':    ('harmonograph', 'リサジュー = 減衰なしハーモノグラフ'),
    'cycloid':      ('spirograph',  '転がる円の軌跡 ⊂ スピログラフ'),
    'wildfire':     ('percolation', '森林火災 = 動的パーコレーション'),
    'xymodel':      ('ising',       'XYモデル = 連続スピンのイジング'),
    'drumhead':     ('chladni',     '円形膜モード ⊂ 振動の節線'),
    'optics':       ('refraction',  '幾何光線の反射/屈折で二重'),
    'vortex':       ('karman',      '点渦 ⊂ カルマン渦列'),
    'isofield':     ('metaball',    '等高線場 = メタボールの等値面'),
    'turmite':      ('langton',     'ターマイト = ラングトンのアリの一般化'),
    'cyclicca':     ('excitable',   '循環CA と興奮性媒質はどちらも渦巻き波'),
    'scatter':      ('billiard',    'ローレンツガス = シナイ・ビリヤード'),
}

# --------------------------------------------------------------- assignment
ASSIGN = {
    'chaos': [
        'lorenz', 'henon', 'clifford', 'strange', 'chirikov', 'catmap', 'bifurcation',
        'lyapunov', 'pendulum', 'magnetpendulum', 'tongues', 'billiard', 'feedback',
        'phase', 'stochastic', 'boolean',
    ],
    'grow': [
        'dla', 'diffgrow', 'crystal', 'crystallize', 'mycelium', 'venation', 'dune',
        'erosion', 'polymerize', 'tissue', 'fracture', 'diffusion', 'tree',
        'lsystemgarden', 'metaball', 'saw', 'atoms',
    ],
    'flow': [
        'waves', 'karman', 'viscous', 'convection', 'soliton', 'fluidveil', 'flowfield',
        'chladni', 'granular', 'sand', 'ferrofluid', 'marbling', 'coriolis', 'transport',
        'morse', 'breathe', 'lattice', 'wavemachine', 'plasma',
    ],
    'swarm': [
        'flock', 'antcolony', 'crowd', 'particlelife', 'motile', 'swarmalator', 'predprey',
        'braitenberg', 'traffic', 'evolution', 'epidemic', 'gametheory', 'kuramoto',
        'termite', 'schelling', 'clock',
    ],
    'lattice': [
        'life', 'langton', 'rule', 'wireworld', 'toothpick', 'lenia', 'excitable', 'cml',
        'sandpile', 'turing', 'reaction', 'ising', 'percolation', 'domino',
    ],
    'number': [
        'primes', 'pi', 'fibonacci', 'collatz', 'recaman', 'ulam', 'pascal', 'modular',
        'ford', 'fraction', 'galton', 'montecarlo', 'markov', 'levydrift', 'fourier',
    ],
    'curve': [
        'spirograph', 'harmonograph', 'superformula', 'bezier', 'catenary', 'knot',
        'winding', 'string', 'rose', 'pedal', 'pursuit', 'mobiusflow', 'conformal',
        'inversion', 'geodesic', 'tesseract', 'eigen',
    ],
    'fractal': [
        'fractal', 'julia', 'buddhabrot', 'newton', 'koch', 'dragon', 'ifs', 'apollonian',
        'hilbert', 'biomorph', 'flame', 'symicon',
    ],
    'light': [
        'caustics', 'refraction', 'diffract', 'slit', 'shadow', 'gravlens', 'doppler',
        'quantum', 'orbital', 'moire', 'stereo', 'tomography', 'lightning', 'kaleidoscope',
        'chamber',
    ],
    'force': [
        'efield', 'magnetic', 'coulomb', 'spacetime', 'nbody', 'galaxy', 'lagrange',
        'roche', 'iss', 'physics', 'cloth', 'rope', 'softbody', 'elastic', 'gears',
        'linkage', 'ratchet', 'reach', 'pendulumwave',
    ],
    'tile': [
        'penrose', 'monotile', 'quasicrystal', 'wallpaper', 'truchet', 'voronoi',
        'delaunay', 'packing', 'foam', 'hyperbolic', 'stipple', 'sdf', 'collapse',
    ],
    'mind': [
        'pathfind', 'maze', 'anneal', 'pso', 'qlearn', 'neural', 'hopfield', 'kohonen',
        'sort', 'spanning', 'quadtree', 'hull', 'rips', 'steiner', 'hough', 'gradient',
        'network', 'scalefree', 'pid',
    ],
}
# プレースホルダの除去
ASSIGN['force'] = [p for p in ASSIGN['force'] if not p.endswith('_placeholder')]

SPECIAL = {'log': ('読み物', 'Read'), 'contents': ('ガイド', 'Guide'), 'about': ('ガイド', 'Guide')}


def main():
    items = json.load(open(CONTENTS, encoding='utf-8'))
    by_page = {c['page']: c for c in items}

    page2group = {}
    for g, pages in ASSIGN.items():
        for p in pages:
            if p in page2group:
                sys.exit(f'ERROR: {p} は {page2group[p]} と {g} に重複割当')
            page2group[p] = g

    # --- 整合性チェック -----------------------------------------------------
    known = set(by_page)
    assigned = set(page2group)
    deleted = set(MERGES)

    unknown = assigned - known
    if unknown:
        sys.exit(f'ERROR: contents.json に無いページを割当: {sorted(unknown)}')

    bad_target = {t for t, _ in MERGES.values() if t not in known or t in deleted}
    if bad_target:
        sys.exit(f'ERROR: リダイレクト先が不正: {sorted(bad_target)}')

    overlap = assigned & deleted
    if overlap:
        sys.exit(f'ERROR: 削除対象を分類にも入れている: {sorted(overlap)}')

    missing = known - assigned - deleted - set(SPECIAL)
    if missing:
        sys.exit(f'ERROR: 未分類のページ: {sorted(missing)}')

    # --- 適用 ---------------------------------------------------------------
    out = []
    for c in items:
        p = c['page']
        if p in MERGES:
            continue
        if p in SPECIAL:
            c['category'], c['category_en'] = SPECIAL[p]
            c['group'] = 'guide' if p != 'log' else 'log'
        else:
            g = page2group[p]
            ja, en, _, _ = GROUPS[g]
            c['group'] = g
            c['category'] = ja
            c['category_en'] = en
        out.append(c)

    json.dump(out, open(CONTENTS, 'w', encoding='utf-8'),
              ensure_ascii=False, indent=2)
    with open(CONTENTS, 'a', encoding='utf-8') as f:
        f.write('\n')

    print(f'kept={len(out)} removed={len(MERGES)}')
    for g in GROUP_ORDER:
        print(f'  {g:9s} {len(ASSIGN[g]):3d}  {GROUPS[g][0]}')


if __name__ == '__main__':
    main()
