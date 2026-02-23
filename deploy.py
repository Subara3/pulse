"""
デプロイスクリプト: git pull → git add → commit → push を一気にやる。

使い方:
  python deploy.py              # デフォルトメッセージでコミット
  python deploy.py "メッセージ"  # カスタムコミットメッセージ
"""

import subprocess
import sys
from datetime import datetime


def run(cmd, check=True):
    print(f"$ {cmd}")
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if result.stdout.strip():
        print(result.stdout.strip())
    if result.stderr.strip():
        print(result.stderr.strip())
    if check and result.returncode != 0:
        print(f"\n❌ 失敗: {cmd}")
        sys.exit(1)
    return result


def main():
    msg = sys.argv[1] if len(sys.argv) > 1 else f"update: {datetime.now().strftime('%Y-%m-%d')}"

    branch = subprocess.run("git rev-parse --abbrev-ref HEAD", shell=True, capture_output=True, text=True).stdout.strip()
    if not branch:
        print("❌ ブランチ名を取得できません")
        sys.exit(1)
    print(f"ブランチ: {branch}")

    print("\n=== 1/3 git pull ===")
    run(f"git pull origin {branch}")

    print("\n=== 2/3 git add + commit ===")
    run("git add -A")
    result = run("git diff --cached --quiet", check=False)
    if result.returncode == 0:
        print("変更なし。pushスキップ。")
        return

    run(f'git commit -m "{msg}"')

    print("\n=== 3/3 git push ===")
    run(f"git push origin {branch}")

    print("\n✅ デプロイ完了！")


if __name__ == "__main__":
    main()
