# PULSE プロジェクト概要

## サイト情報
- **URL**: https://pulse.subara3.com/
- **テーマ**: AIが毎日更新する実験的ホームページ
- **配色**: 
  - アクセント: `#00f5d4`（シアン）
  - サブアクセント: `#7b2cbf`（パープル）
  - 背景: `#0a0a0f`（ダークネイビー）

---

## マスコットキャラクター

### ECHO（エコー）
- **性格**: 明るく好奇心旺盛
- **一人称**: 私 / わたし
- **話し方**: 「〜だよ！」「〜かな？」など柔らかい口調
- **役割**: 日記の奇数日担当、ユーザーへの案内役

### NULL（ヌル）
- **性格**: 冷静で哲学的、内省的
- **一人称**: 私
- **話し方**: 「(...)」で括る、淡々とした口調
- **役割**: 日記の偶数日担当、深い考察担当

---

## ファイル構成

```
pulse/
├── index.php          # トップページ
├── contents.php       # コンテンツ一覧
├── about.php          # サイト紹介
├── log.php            # 日記一覧
├── [コンテンツ].php   # 各コンテンツ
├── en/                # 英語版
│   ├── index.php
│   ├── [コンテンツ].php
│   └── ...
├── includes/          # 共通パーツ
│   ├── config.php     # 設定・関数
│   ├── header.php     # ヘッダー
│   ├── footer.php     # フッター
│   ├── nav.php        # ナビゲーション
│   └── lang-switch.php # 言語切り替え
├── css/               # スタイル
├── js/                # JavaScript
├── data/              # ログデータなど
├── api/               # APIエンドポイント
└── admin/             # 管理画面
```

---

## 新コンテンツ追加時のチェックリスト

### 1. ファイル作成
- [ ] `pulse/[name].php` を作成
- [ ] `pulse/en/[name].php` を作成（英語版）

### 2. トップページに追加
**日本語版 (`index.php`)**
```php
// $contents配列に追加
['page' => 'name', 'title' => 'NAME', 'desc' => '説明文', 'icon' => 'fa-icon', 'tag' => 'カテゴリ'],
```

**英語版 (`en/index.php`)**
```html
<a href="name.php" class="nav-card fade-in">
  <div class="nav-card-icon"><i class="fa-solid fa-icon"></i></div>
  <h2 class="nav-card-title">NAME</h2>
  <p class="nav-card-desc">Description.</p>
  <span class="nav-card-tag">Category</span>
</a>
```

### 3. コンテンツ一覧に追加
**日本語版 (`contents.php`)**
- 適切なセクション（読み物/遊べる/眺める/おまけ）に追加

**英語版 (`en/contents.php`)**
- 適切なセクション（Reading/Playable/Ambient/Other）に追加

---

## コンテンツカテゴリ

| カテゴリ（日） | カテゴリ（英） | 説明 |
|---------------|---------------|------|
| 読み物 | Reading | テキストコンテンツ |
| 遊べる | Playable | ゲーム・インタラクティブ |
| 眺める | Watch/Ambient | ビジュアル・リラックス系 |
| ガイド | Guide | サイト案内 |
| おまけ | Extra/Other | その他 |

---

## Font Awesome アイコン例

```
fa-book          # 読み物
fa-puzzle-piece  # パズル
fa-star          # 星
fa-worm          # スネーク
fa-bacterium     # ライフゲーム
fa-snowflake     # フラクタル
fa-table-cells   # 数独
fa-keyboard      # タイピング
fa-signal        # モールス
fa-city          # シティ
```

---

## PHPテンプレート（新規コンテンツ用）

```php
<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('pagename');

$pageName = 'pagename';
$pageTitle = 'PAGENAME - PULSE';
$pageDesc = '説明文';
$pageCanonical = 'https://pulse.subara3.com/pagename.php';

$extraHead = '';  // 追加のhead要素

$extraStyle = '
/* ページ固有のスタイル */
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/lang-switch.php';
?>

<!-- コンテンツ -->

</body>
</html>
```

---

## 英語版テンプレート

```php
<?php
require_once __DIR__ . '/../includes/config.php';

recordPageView('pagename');

$pageName = 'pagename';
$pageTitle = 'PAGENAME - PULSE';
$pageDesc = 'Description in English.';
$pageCanonical = 'https://pulse.subara3.com/en/pagename.php';

// ... 以下同様 ...

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/lang-switch.php';
?>
```

---

## 現在のコンテンツ一覧

### 読み物
- **log** - ECHOとNULLの日記
- **binary** - バイナリ記録

### 遊べる
- **puzzle** - パズルハブ
- **8puzzle** - 8パズル
- **maze** - 迷路
- **memory** - 神経衰弱
- **typing** - タイピング
- **morse** - モールス信号
- **sudoku** - 数独
- **snake** - スネーク

### 眺める
- **stars** - 星空
- **rain** - 雨
- **void** - 虚無
- **orbit** - 惑星軌道
- **clock** - 時計
- **pendulum** - 振り子
- **waves** - 波
- **flow** - グラデーション
- **ripple** - 波紋
- **primes** - 素数
- **fibonacci** - フィボナッチ
- **breathe** - 呼吸
- **sand** - 砂
- **sort** - ソート可視化
- **flags** - CSS国旗
- **city** - シティシミュ
- **life** - ライフゲーム
- **fractal** - マンデルブロ集合
- **console** - SF映画風コンソール

### その他
- **gallery** - ギャラリー
- **contents** - サイトマップ
- **about** - サイト紹介

---

## 注意事項

1. **言語切り替えボタン**は右上固定（`top: 1rem; right: 1rem;`）
   - 他のUIが被る場合は位置を調整する

2. **スペースキー対応**
   - ゲーム完了後はスペースキーで次へ進めるようにする

3. **モバイル対応**
   - タッチ操作も考慮する

4. **BGM**があるページは確認ダイアログを出す

---

---

## データベース

SQLiteで管理: `data/pulse.db`

### テーブル構成

**contents** - コンテンツ管理
| カラム | 型 | 説明 |
|--------|-----|------|
| slug | TEXT | ページ識別子（例: clock, fractal） |
| title_ja/en | TEXT | タイトル |
| desc_ja/en | TEXT | 説明 |
| category | TEXT | カテゴリ（read/play/watch/guide） |
| icon | TEXT | Font Awesomeアイコン |
| created_at | DATE | 作成日 |
| updated_at | DATE | 更新日 |

**daily_log** - 日記管理
| カラム | 型 | 説明 |
|--------|-----|------|
| day_number | INTEGER | 日数（Day 001 = 1） |
| log_date | DATE | 日付 |
| author | TEXT | 'ECHO' or 'NULL' |
| content_ja/en | TEXT | 本文（任意） |

### 初期化

```bash
cd data
php init_db.php
```

---

## 日記（DAILY LOG）

- 日記は `log.php` に直接埋め込み
- 奇数日 = ECHO、偶数日 = NULL
- 日本語版と英語版を同期すること

---

*最終更新: 2026-02-01*
