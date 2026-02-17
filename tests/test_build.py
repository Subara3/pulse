#!/usr/bin/env python3
"""
PULSE ビルドテスト
デプロイ前に実行して問題を検出する
"""

import subprocess
import sys
import os
from pathlib import Path

# プロジェクトルート
ROOT = Path(__file__).parent.parent

def test_php_syntax():
    """全PHPファイルの構文チェック"""
    print("🔍 PHP構文チェック...")
    errors = []
    
    php_files = list(ROOT.glob("*.php")) + list(ROOT.glob("**/*.php"))
    # 除外: vendor, node_modules
    php_files = [f for f in php_files if "vendor" not in str(f) and "node_modules" not in str(f)]
    
    for php_file in php_files:
        result = subprocess.run(
            ["php", "-l", str(php_file)],
            capture_output=True,
            text=True
        )
        if result.returncode != 0:
            errors.append(f"{php_file.name}: {result.stdout.strip()}")
    
    if errors:
        print("❌ PHP構文エラー:")
        for e in errors:
            print(f"  - {e}")
        return False
    
    print(f"✅ {len(php_files)}個のPHPファイル OK")
    return True

def test_json_syntax():
    """JSONファイルの構文チェック"""
    print("🔍 JSON構文チェック...")
    import json
    errors = []
    
    json_files = list(ROOT.glob("data/*.json")) + list(ROOT.glob("data/**/*.json"))
    
    for json_file in json_files:
        try:
            with open(json_file, "r", encoding="utf-8") as f:
                json.load(f)
        except json.JSONDecodeError as e:
            errors.append(f"{json_file.name}: {e}")
    
    if errors:
        print("❌ JSON構文エラー:")
        for e in errors:
            print(f"  - {e}")
        return False
    
    print(f"✅ {len(json_files)}個のJSONファイル OK")
    return True

def test_contents_json():
    """contents.jsonの整合性チェック"""
    print("🔍 contents.json整合性チェック...")
    import json
    
    contents_path = ROOT / "data" / "contents.json"
    if not contents_path.exists():
        print("⚠️ data/contents.json が見つかりません")
        return True
    
    with open(contents_path, "r", encoding="utf-8") as f:
        contents = json.load(f)
    
    errors = []
    for item in contents:
        page = item.get("page", "")
        php_file = ROOT / f"{page}.php"
        if not php_file.exists() and page not in ["contents", "about", "log"]:
            # contents, about, log は特殊なので除外しない
            if not (ROOT / f"{page}.php").exists():
                errors.append(f"'{page}' のPHPファイルが存在しません")
    
    if errors:
        print("⚠️ 警告（PHPファイル不足）:")
        for e in errors:
            print(f"  - {e}")
        # 警告のみ、エラーにはしない
    
    print(f"✅ contents.json OK ({len(contents)}エントリ)")
    return True

def test_required_files():
    """必須ファイルの存在チェック"""
    print("🔍 必須ファイルチェック...")
    
    required = [
        "index.php",
        "includes/config.php",
        "includes/header.php",
        "includes/footer.php",
        "data/contents.json",
    ]
    
    missing = []
    for f in required:
        if not (ROOT / f).exists():
            missing.append(f)
    
    if missing:
        print("❌ 必須ファイルが不足:")
        for f in missing:
            print(f"  - {f}")
        return False
    
    print(f"✅ 必須ファイル OK")
    return True

def main():
    print("=" * 50)
    print("PULSE ビルドテスト")
    print("=" * 50)
    
    results = []
    
    # PHP構文チェック（phpがインストールされている場合のみ）
    php_check = subprocess.run(["which", "php"], capture_output=True)
    if php_check.returncode == 0:
        results.append(test_php_syntax())
    else:
        print("⚠️ PHPがインストールされていません（構文チェックをスキップ）")
    
    results.append(test_json_syntax())
    results.append(test_contents_json())
    results.append(test_required_files())
    
    print("=" * 50)
    
    if all(results):
        print("✅ 全テスト合格!")
        return 0
    else:
        print("❌ テスト失敗あり")
        return 1

if __name__ == "__main__":
    sys.exit(main())
