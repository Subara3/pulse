#!/usr/bin/env python3
"""
Preview pipeline runner

Phase 1 goal:
- One-command flow for preview generation + QA checks
- Unified report output: reports/preview-health.json

Usage:
  python3 scripts/run_preview_pipeline.py
  python3 scripts/run_preview_pipeline.py --pages pathfind,network
  python3 scripts/run_preview_pipeline.py --skip-generate
"""

import argparse
import json
import os
import subprocess
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORTS_DIR = ROOT / "reports"
REPORTS_DIR.mkdir(exist_ok=True)


def run(cmd, env=None):
    p = subprocess.run(cmd, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env)
    return p.returncode, p.stdout, p.stderr


def load_json(path: Path):
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except Exception:
        return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pages", default="", help="comma-separated page names for partial generation; also limits QA scope")
    ap.add_argument("--skip-generate", action="store_true")
    ap.add_argument("--base-url", default=os.getenv("PULSE_PREVIEW_BASE_URL", "http://127.0.0.1:8787"))
    args = ap.parse_args()

    pages_arg = [args.pages] if args.pages.strip() else []

    summary = {
        "generatedAt": datetime.utcnow().isoformat() + "Z",
        "baseUrl": args.base_url,
        "steps": [],
    }

    env = os.environ.copy()
    env["PULSE_PREVIEW_BASE_URL"] = args.base_url

    if not args.skip_generate:
        cmd = ["node", "scripts/generate_previews.js"]
        if args.pages.strip():
            cmd.extend([p.strip() for p in args.pages.split(",") if p.strip()])
        rc, out, err = run(cmd, env=env)
        summary["steps"].append({
            "name": "generate_previews",
            "ok": rc == 0,
            "stdoutTail": out[-2000:],
            "stderrTail": err[-2000:],
        })

    pages_flag = (["--pages", args.pages] if args.pages.strip() else [])

    rc, out, err = run(["python3", "scripts/check_and_fix_preview_flash.py", "--apply", *pages_flag])
    flash_json = None
    try:
        flash_json = json.loads(out)
    except Exception:
        pass
    summary["steps"].append({"name": "check_and_fix_preview_flash", "ok": rc == 0, "result": flash_json})

    rc, out, err = run(["python3", "scripts/check_preview_dark_static.py", *pages_flag])
    dark_json = None
    try:
        dark_json = json.loads(out)
    except Exception:
        pass
    summary["steps"].append({"name": "check_preview_dark_static", "ok": rc == 0, "result": dark_json})

    rc, out, err = run(["python3", "scripts/auto_fix_preview_loop.py", *pages_flag])
    loop_json = None
    try:
        loop_json = json.loads(out)
    except Exception:
        pass
    summary["steps"].append({"name": "auto_fix_preview_loop", "ok": rc == 0, "result": loop_json})

    out_path = REPORTS_DIR / "preview-health.json"
    out_path.write_text(json.dumps(summary, ensure_ascii=False, indent=2))

    print(json.dumps({
        "ok": all(s.get("ok") for s in summary["steps"]),
        "report": str(out_path),
        "steps": [{"name": s["name"], "ok": s["ok"]} for s in summary["steps"]],
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
