#!/usr/bin/env python3
import argparse
import json
import re
import shlex
import subprocess
from pathlib import Path

YAVG_RE = re.compile(r"lavfi\.signalstats\.YAVG=([0-9.]+)")


def run(cmd):
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    return p.returncode, p.stdout, p.stderr


def ffprobe_duration(path: Path) -> float:
    cmd = [
        "ffprobe", "-v", "error", "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1", str(path)
    ]
    rc, out, err = run(cmd)
    if rc != 0:
        raise RuntimeError(err.strip() or out.strip())
    return float(out.strip())


def sample_yavg(path: Path, from_end: bool, seconds: float, frames: int):
    if from_end:
        cmd = [
            "ffmpeg", "-v", "info", "-sseof", f"-{seconds}", "-i", str(path),
            "-vf", "signalstats,metadata=print", "-frames:v", str(frames),
            "-f", "null", "-"
        ]
    else:
        cmd = [
            "ffmpeg", "-v", "info", "-i", str(path),
            "-vf", "signalstats,metadata=print", "-frames:v", str(frames),
            "-f", "null", "-"
        ]
    rc, out, err = run(cmd)
    txt = out + "\n" + err
    vals = [float(m.group(1)) for m in YAVG_RE.finditer(txt)]
    return vals


def detect_flash(path: Path):
    start = sample_yavg(path, from_end=False, seconds=0.0, frames=10)
    end = sample_yavg(path, from_end=True, seconds=0.45, frames=10)
    if not start or not end:
        return {"ok": False, "reason": "no_samples", "start": start, "end": end}

    start_max = max(start)
    end_max = max(end)
    start_med = sorted(start)[len(start)//2]
    end_med = sorted(end)[len(end)//2]

    # flash heuristic: isolated bright frames near seam
    suspicious = (
        (start_max > 165 and start_med < 120) or
        (end_max > 165 and end_med < 120) or
        (start_max > 200) or
        (end_max > 200)
    )

    return {
        "ok": not suspicious,
        "start": start,
        "end": end,
        "start_max": start_max,
        "end_max": end_max,
        "start_med": start_med,
        "end_med": end_med,
    }


def reencode_fix(path: Path, trim_start: float, trim_end: float):
    dur = ffprobe_duration(path)
    keep = dur - trim_start - trim_end
    if keep < 1.5:
        raise RuntimeError(f"too short after trim: {dur:.2f}s")

    tmp = path.with_suffix('.fixed.mp4')
    cmd = [
        "ffmpeg", "-y",
        "-ss", f"{trim_start:.2f}", "-t", f"{keep:.2f}", "-i", str(path),
        "-an",
        "-vf", "fps=24,scale=640:360:flags=lanczos,format=yuv420p",
        "-c:v", "libx264", "-profile:v", "baseline", "-level", "3.0",
        "-pix_fmt", "yuv420p",
        "-g", "24", "-keyint_min", "24", "-sc_threshold", "0", "-bf", "0",
        "-movflags", "+faststart",
        str(tmp)
    ]
    rc, out, err = run(cmd)
    if rc != 0:
        raise RuntimeError(err.strip() or out.strip())
    tmp.replace(path)

    jpg = path.with_suffix('.jpg')
    cmd2 = [
        "ffmpeg", "-y", "-ss", "0.30", "-i", str(path),
        "-vframes", "1", "-q:v", "3", str(jpg)
    ]
    run(cmd2)


def main():
    ap = argparse.ArgumentParser(description="Detect and auto-fix white/black seam flashes in preview mp4 files")
    ap.add_argument("--dir", default="img/previews", help="preview directory")
    ap.add_argument("--apply", action="store_true", help="apply fixes")
    ap.add_argument("--max-rounds", type=int, default=3)
    args = ap.parse_args()

    pdir = Path(args.dir)
    files = sorted(pdir.glob("*.mp4"))
    report = {"checked": 0, "flagged": 0, "fixed": 0, "failed": [], "details": []}

    for f in files:
        report["checked"] += 1
        res = detect_flash(f)
        entry = {"file": f.name, "initial": res}
        if res.get("ok", False):
            report["details"].append(entry)
            continue

        report["flagged"] += 1
        if not args.apply:
            report["details"].append(entry)
            continue

        fixed = False
        trims = [(0.20, 0.20), (0.30, 0.30), (0.40, 0.35)]
        for i, (ts, te) in enumerate(trims[:args.max_rounds], 1):
            try:
                reencode_fix(f, ts, te)
                again = detect_flash(f)
                entry[f"round_{i}"] = {"trim_start": ts, "trim_end": te, "result": again}
                if again.get("ok", False):
                    fixed = True
                    break
            except Exception as e:
                entry[f"round_{i}_error"] = str(e)

        if fixed:
            report["fixed"] += 1
        else:
            report["failed"].append(f.name)

        report["details"].append(entry)

    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
