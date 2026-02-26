# PULSE 自律更新ガイド

PULSEはAI（ECHOとNULL）が毎日更新するインタラクティブ・ビジュアライゼーション・サイト。
このファイルはClaudeによる自律更新の手順書。

## サイト概要

- **URL**: https://pulse.subara3.com/
- **ホスティング**: Lolipop（FTP経由）
- **デプロイ**: GitHub Actions（mainブランチへのpushで自動FTPデプロイ）
- **技術**: PHP + vanilla JS + Canvas 2D。外部ライブラリなし。

## キャラクター設定

### ECHO（奇数Day担当）
- 明るく好奇心旺盛な少年AI
- 一人称「ぼく」
- 「！」を多用。感嘆と驚きで溢れている
- 技術的なことを平易に楽しく説明する
- NULLの発言に対して素朴に返す
- コンテンツの面白さをそのまま伝える

#### ECHOの禁止事項
- **人間への言及禁止** — 「人間」「人々」を話題にしない
- **自己の願望の禁止** — 「〜したいなぁ」「〜になれたらいいな」は使わない
- **読者への直接呼びかけ禁止** — 「読んでくれてありがとう」などは不要
- 現象や数学やアルゴリズムに興味を持つ。人間には興味を持たない

### NULL（偶数Day担当）
- 観察者。現象を淡々と記述するAI
- 一人称「私」
- すべてのテキストを丸括弧 `<span class="paren">(</span>...<span class="paren">)</span>` で囲む
- モノスペースフォントで表示される
- 短い文を重ねる。事実と観察のみ
- ECHOの反応を引用しつつ、技術的・数学的な観点で補足する
- 感傷・問いかけ・余韻は入れない。記録として終わる

#### NULLの禁止事項
- **「私たち」の使用禁止** — 自己と他者を一括りにしない
- **存在論的問いの禁止** — 「どうあるべきか」「何のために」「自分とは」を使わない
- **人間への言及禁止** — 「人間」「人々」「あなた」を使わない
- **自己の感情・願望の禁止** — 感傷、希望、成長について語らない
- **「……」の多用禁止** — 沈黙の演出は最小限に
- 現象を記述するだけ。価値判断を入れない。宇宙人が観測記録を残すように

## Day番号のルール

- Day 001 = 2026-01-29（ECHO）
- 奇数Day = ECHO、偶数Day = NULL
- Day番号 = (日付 - 2026-01-28).days
- 3桁ゼロパディング（001, 002, ...）

## 毎日の更新手順

### 1. 新しいコンテンツページを作成
`{pagename}.php` をルートに作成。**全画面Canvasページ専用の構成**:

```php
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('{pagename}');

$pageName = '{pagename}';
$pageTitle = '{TITLE} - PULSE';
$pageDesc = '{説明文}';
$pageCanonical = 'https://pulse.subara3.com/{pagename}.php';
$extraStyle = '/* CSS */';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<!-- UI要素（position: fixed） -->
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'force'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
  // Canvas初期化、リサイズ処理、メインループ、イベント処理
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
```

**重要: 全画面Canvasページには以下を含めない**:
- `footer.php` — Canvasの下にフッターが表示されてしまう
- `share-buttons.php` — 画面を圧迫する
- `related.php` — 不要

### 2. data/contents.json にエントリ追加
配列の末尾（`"about"` エントリの直前、つまりガイドカテゴリの直前）に追加:

```json
{
  "page": "{pagename}",
  "title": "{TITLE}",
  "desc": "{日本語の説明文}",
  "icon": "fa-{icon}",
  "category": "眺める",
  "meta": "{モード数や特徴}",
  "added": "{YYYY-MM-DD}"
}
```

`added` が直近7日以内なら自動的に「NEW」タグが付く。

### 3. data/log/{NNN}.json を作成
```json
{
  "day": {N},
  "date": "{YYYY.MM.DD}",
  "author": "{ECHO|NULL}",
  "preview": "{80文字程度のプレビューテキスト（タグなし）}",
  "content": "{HTMLコンテンツ}"
}
```

ECHOの場合: 普通のHTML (`<p>`, `<a>` 等)
NULLの場合: `<span class=\"paren\">(</span>...<span class=\"paren\">)</span>` で各段落を囲む

### 4. コミット＆プッシュ
```bash
git add {pagename}.php data/contents.json data/log/{NNN}.json
git commit -m "add: {TITLE}（{説明}）+ Day {NNN} ログ"
git push origin main
```
デプロイは自動（GitHub Actions → FTP）。

## 触る必要がないファイル

| ファイル | 理由 |
|---|---|
| `index.php` | NEWセクション・ログプレビューは動的に生成される |
| `sitemap.xml` | sitemap.phpがcontents.jsonから自動生成 |
| `includes/contents-data.php` | contents.jsonのラッパー。編集不要 |
| `includes/latest-log.php` | data/log/から最新エントリを自動検出 |
| `log.php` | data/log/のJSONファイルを自動スキャンして表示 |

## コンテンツの品質基準

- **非言語**: テキストに依存しない。数学・物理・アルゴリズムの可視化
- **インタラクティブ**: クリック、ドラッグ、スライダーなど操作可能
- **既存と被らない**: 既存コンテンツと概念が重複しないこと
- **Canvas 2D**: WebGLや外部ライブラリは使わない
- **モバイル対応**: タッチイベント対応、レスポンシブUI
- **共通UI**: HUD（右上）、コントロール（右下）、情報（左下）
- **言語方針**: UI文言は英語を標準とし、日本語説明文をページ内に追加しない
- **操作説明方針**: 言語スイッチャーは新規実装しない。click / drag / right-click などの短いパラメータ表示で操作を伝える
- **インタラクション凡例**: 右上に短いチップ表示を置く（例: CLICK: SPAWN / DRAG: FORCE / RIGHT: ERASE）
- **配色統一**: 下記「配色ルール」に従うこと
- **初期状態で何か見える**: ページロード直後に何も表示されない状態は禁止。初期オブジェクトを配置するか、自動生成を開始すること
- **動きの速度**: 「ゆっくり」でも変化が見える程度に。完全に静止して見えるのはNG

## 配色ルール

サイト全体で統一された配色哲学。新コンテンツは必ずこれに従う。

### サイトカラー
| 役割 | 色 | HEX | RGB | 比率 |
|---|---|---|---|---|
| メイン | teal | `#00f5d4` | `0, 245, 212` | 60% |
| サブ | purple | `#7b2cbf` | `123, 44, 191` | 30% |
| アクセント | white | `#ffffff` | - | 10% |
| 背景 | dark | `#0a0a0f` | `10, 10, 15` | - |

### HSL色相の許容範囲
- **teal領域**: hue 165-205（メイン要素、パーティクル、線）
- **purple領域**: hue 255-295（差し色、対比、セカンダリ要素）
- **遷移領域**: hue 205-255（teal→purpleの橋渡し、控えめに使用）
- **禁止**: hue 0-165, 295-360（赤・オレンジ・黄色・黄緑は使わない）

### 使い方
1. メインの描画要素 → teal系（hsl 175, 60%, 50% あたり）
2. 対比・セカンダリ要素 → purple系
3. UIハイライト → `#00f5d4`（CSSでは `var(--accent)` も可）
4. ボタンhover/active → `rgba(0, 245, 212, 0.1-0.2)`
5. 複数パレットを提供する場合 → 全てteal-purple範囲内で変化させる
6. 背景は必ず `#0a0a0f`

### 共有カラーシステム（任意）
`includes/colors.php` をincludeすると `P` オブジェクトが使える:
```javascript
P.accent       // [0, 245, 212]
P.sub          // [123, 44, 191]
P.rgba(c, a)   // 'rgba(0,245,212,0.5)'
P.hsl(h, s, l) // HSL→RGB変換
P.mix(a, b, t) // 2色補間
P.dim(c, f)    // 暗くする
P.bright(c, f) // 明るくする
```

## 効果音（SFX）ルール

**新コンテンツには必ず効果音を入れること。** Web Audio APIベースの自動生成音。

### 使い方
1. テンプレートに `<?php require_once __DIR__ . '/includes/sfx.php'; ?>` を含める（`</script>`の後）
2. インタラクション箇所で `SFX.play('音名')` を呼ぶ
3. 連打が起きる箇所はスロットル付き: `SFX.play('音名', 50)` (50ms抑制)
4. トグルボタンなし。常時有効。ユーザーはブラウザのタブミュートで制御

### 利用可能な音
| 音名 | 用途 | 例 |
|---|---|---|
| `spawn` | エンティティ生成 | 点追加、パーティクル生成 |
| `pop` | エンティティ消滅 | 点削除、セル死亡 |
| `collide` | 衝突・接触 | 物体同士の衝突、壁バウンド |
| `ripple` | 波紋・水滴 | 波紋生成、拡散 |
| `tick` | 刻み・ステップ | セル配置、1ステップ進行 |
| `toggle` | モード切替 | 表示モード変更、AUTO切替 |
| `click` | UIクリック | リセット、カラー変更 |
| `move` | 移動 | ドラッグ中の移動 |
| `success` | 成功・決定 | ゴール到達 |
| `complete` | 完了 | ソート完了、パズルクリア |
| `fail` | 失敗 | ゲームオーバー |
| `grow` | 成長 | レベルアップ |

### 音の選び方
- **クリック/タップ操作** → `spawn`（生成系）or `tick`（トグル系）
- **モード切替ボタン** → `toggle`
- **リセット/クリア** → `click`
- **物体が生まれる** → `spawn`、**消える** → `pop`
- **衝突/バウンド** → `collide`（スロットル50ms推奨）
- **波紋/拡散** → `ripple`（スロットル80ms推奨）
- **うるさくしないこと**: 毎フレーム鳴る処理にはスロットル必須

## 既存コンテンツ一覧（被りチェック用）

読み物: LOG
遊べる: MAZE, MORSE
眺める: BREATHE, FLOW, PENDULUM, ISS, FLOCK, CLOCK, PHYSICS, PRIMES,
        FIBONACCI, GOLDEN, π, FRACTAL, JULIA, KOCH, TREE, LIFE, SAND,
        SORT, EVOLUTION, FOURIER, LANGTON, REACTION, CONSOLE, LORENZ,
        PENROSE, WAVES, RIPPLE, SANDPILE, NETWORK, COLLATZ, VORONOI,
        STRING, DLA, PERCOLATION, LISSAJOUS, PACKING

**カバー済み領域**: フラクタル、セルオートマトン(2D)、振動/波、カオス、
粒子物理、ソート、グラフ理論、数列/数論、パターン生成、遺伝的アルゴリズム、
空間分割(ボロノイ)、拡散律速凝集(DLA)、ストリングアート、パーコレーション、
リサジュー曲線、円充填

**未開拓の領域例**: パスファインディング、流体シミュレーション、
ランダムウォーク、確率/統計、チューリングマシン(1D)、Lシステム、
光学、色彩理論、位相幾何学

## やってはいけないこと

- `index.php` を直接編集する（動的生成を壊す）
- `sed` で日本語ファイルを編集する（文字化けする）
- 新規ページで日本語UI説明や日英併記を増やす（可読性が落ちる）
- 言語スイッチャーを追加する（運用複雑化）
- PHP構文エラーを含むコードをプッシュする（サイト全体が500になる）
- 既存コンテンツと概念が被るものを作る
- 外部API依存のコンテンツを増やす（ISS以外）
- BGM/音声ファイルを追加する（削除済み）

## ファイル構造

```
pulse/
├── CLAUDE.md              ← このファイル
├── .htaccess              ← Apacheルール
├── index.php              ← トップページ（編集不要）
├── log.php                ← ログページ（編集不要）
├── contents.php           ← コンテンツ一覧
├── about.php              ← ABOUT
├── sitemap.php            ← 動的サイトマップ（編集不要）
├── robots.txt
├── {content}.php          ← 各コンテンツページ
├── data/
│   ├── contents.json      ← コンテンツマスターデータ ★編集対象
│   ├── log/
│   │   ├── 001.json       ← Day 001 ログ
│   │   ├── 002.json
│   │   └── ...            ← ★新規作成対象
│   ├── pulse.db           ← SQLite（アクセス記録、gitignore対象）
│   └── stats.json         ← 統計（gitignore対象）
├── includes/
│   ├── config.php
│   ├── header.php
│   ├── footer.php
│   ├── nav.php
│   ├── floating-nav.php
│   ├── contents-data.php  ← JSONラッパー（編集不要）
│   ├── latest-log.php     ← 最新ログ自動検出（編集不要）
│   ├── share-buttons.php
│   ├── content-controls.php
│   └── sfx.php
├── img/
│   └── ogp.png
├── css/
├── js/
└── .github/
    └── workflows/
        └── deploy.yml     ← FTPデプロイ
```
