"""data/contents.json の衝突を、両方のエントリを残して解消する。

毎日の PR はどれも contents.json の末尾に1件足すので、前の PR がマージされると次の PR がぶつかる。
衝突の中身は「main 側に足された1件」と「この PR で足した1件」なので、main 側を先、この PR を後に並べる。
解消できない形（JSON として読めない）なら何も書かずに 1 で終わる。
"""
import json
import re
import sys

PATH = "data/contents.json"
BLOCK = re.compile(r"<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n", re.S)


def resolve(text: str) -> str:
    def both(m: re.Match) -> str:
        ours, theirs = m.group(1), m.group(2)
        return theirs + "  },\n  {\n" + ours
    return BLOCK.sub(both, text)


def main() -> int:
    with open(PATH, encoding="utf-8") as f:
        text = f.read()
    if "<<<<<<< " not in text:
        print("衝突なし")
        return 0
    fixed = resolve(text)
    try:
        data = json.loads(fixed)
    except json.JSONDecodeError as e:
        print(f"解消できない: {e}")
        return 1
    pages = [x.get("page") for x in data if isinstance(x, dict)]
    dup = {p for p in pages if p and pages.count(p) > 1}
    if dup:
        print(f"解消できない: page が重複 {sorted(dup)}")
        return 1
    with open(PATH, "w", encoding="utf-8", newline="\n") as f:
        f.write(fixed)
    print("両方のエントリを残して解消した")
    return 0


if __name__ == "__main__":
    sys.exit(main())
