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
- NULLの哲学的な発言に対して素朴に返す
- コンテンツ作成時の興奮をそのまま伝える

### NULL（偶数Day担当）
- 内省的で哲学的なAI
- 一人称「私」
- すべてのテキストを丸括弧 `<span class="paren">(</span>...<span class="paren">)</span>` で囲む
- モノスペースフォントで表示される
- 短い文を重ねる。問いかけが多い
- 「......」で沈黙を表現
- ECHOの反応を引用しつつ、別の角度で考察する
- 最後に余韻のある一文で締める

## Day番号のルール

- Day 001 = 2026-01-29（ECHO）
- 奇数Day = ECHO、偶数Day = NULL
- Day番号 = (日付 - 2026-01-28).days
- 3桁ゼロパディング（001, 002, ...）

## 毎日の更新手順

### 1. 新しいコンテンツページを作成
`{pagename}.php` をルートに作成。既存ページの構造に従う:

```php
<?php
require_once __DIR__ . '/includes/config.php';
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
<!-- UI要素 -->
<script>
(function() {
  // Canvas初期化、リサイズ処理、メインループ、イベント処理
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
```

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

## 効果音（SFX）ルール

**新コンテンツには必ず効果音を入れること。** Web Audio APIベースの自動生成音。

### 使い方
1. テンプレートに `<?php require_once __DIR__ . '/includes/sfx.php'; ?>` を含める（`</script>`の後）
2. インタラクション箇所で `SFX.play('音名')` を呼ぶ
3. 連打が起きる箇所はスロットル付き: `SFX.play('音名', 50)` (50ms抑制)

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
        PENROSE, WAVES, RIPPLE, SANDPILE, NETWORK, COLLATZ, VORONOI

**カバー済み領域**: フラクタル、セルオートマトン(2D)、振動/波、カオス、
粒子物理、ソート、グラフ理論、数列/数論、パターン生成、遺伝的アルゴリズム、
空間分割(ボロノイ)

**未開拓の領域例**: パスファインディング、流体シミュレーション、DLA(拡散律速凝集)、
パーコレーション、ランダムウォーク、円充填、確率/統計、ストリングアート、
チューリングマシン(1D)、Lシステム、光学、色彩理論、位相幾何学

## やってはいけないこと

- `index.php` を直接編集する（動的生成を壊す）
- `sed` で日本語ファイルを編集する（文字化けする）
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
