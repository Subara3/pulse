# PULSE

数式と規則が「動いているところ」だけを集めたインタラクティブ・アーカイブ。
AI（ECHO と NULL）が毎日 1 本ずつ増やしている。

- 本番: https://pulse.subara3.com/
- 作品数: 192 / 12コレクション（`data/contents.json` が正）
- 技術: PHP + 素の JavaScript + Canvas 2D。外部ライブラリなし。1 ページ 1 ファイル。

## 唯一の基準は CLAUDE.md

運用手順・キャラクター設定・配色ルール・分類・品質基準は
すべて [CLAUDE.md](CLAUDE.md) に集約してある。**このファイルには手順を書かない。**
過去に README / PROJECT.md / CLAUDE.md が別々のことを書いていて事故になったので、
基準は 1 か所だけにしている。

## ローカルで動かす

```bash
php -S 127.0.0.1:8899        # http://127.0.0.1:8899/
```

## push する前に

```bash
python3 tests/test_build.py          # PHP/JSON構文 + ポリシー検査
python3 scripts/check_policy.py      # 分類・必須include・配色・リンク切れ
```

配色を実測したいとき（ffmpeg と Pillow が要る）:

```bash
python3 scripts/check_palette_render.py
```

## デプロイ

`main` へ push すると GitHub Actions が

1. `tests/test_build.py` を実行（落ちたら止まる）
2. Lolipop へ FTP デプロイ
3. 変更されたページのプレビュー動画を再生成し、CLAUDE.md の一覧を同期

を自動でやる。手動デプロイは不要。

## ディレクトリ

| パス | 中身 |
|---|---|
| `css/pulse.css` | デザインシステム（トークンと共通コンポーネント。ここが基準） |
| `data/contents.json` | コンテンツのマスターデータ |
| `data/log/{NNN}.json` | 日記 |
| `includes/collections.php` | 12コレクションの定義 |
| `img/previews/` | 各ページのプレビュー動画・静止画 |
| `scripts/` | 検査・生成スクリプト |
| `reports/` | 検査レポート（CI生成物） |
