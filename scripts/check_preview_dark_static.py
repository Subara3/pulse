#!/usr/bin/env python3
import argparse
import json
import subprocess
from pathlib import Path


def run(cmd):
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.returncode, p.stdout, p.stderr


def probe_wh(path: Path):
    cmd = [
        'ffprobe', '-v', 'error', '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', str(path)
    ]
    rc, out, err = run(cmd)
    if rc != 0:
        raise RuntimeError(err.decode('utf-8', 'ignore'))
    w, h = out.decode().strip().split('x')
    return int(w), int(h)


def frame_gray(path: Path, t: float, w: int, h: int):
    cmd = [
        'ffmpeg', '-v', 'error', '-ss', f'{t:.2f}', '-i', str(path),
        '-vframes', '1', '-f', 'rawvideo', '-pix_fmt', 'gray', '-'
    ]
    rc, out, err = run(cmd)
    if rc != 0 or len(out) < w * h:
        return None
    return out[: w * h]


def mean_luma(buf: bytes):
    return sum(buf) / max(1, len(buf))


def mean_abs_diff(a: bytes, b: bytes):
    n = min(len(a), len(b))
    if n == 0:
        return 0.0
    return sum(abs(a[i] - b[i]) for i in range(n)) / n


def analyze(path: Path):
    w, h = probe_wh(path)
    ts = [0.5, 2.0, 3.5]
    frames = [frame_gray(path, t, w, h) for t in ts]
    if any(f is None for f in frames):
        return {'ok': False, 'reason': 'decode_failed'}

    lumas = [mean_luma(f) for f in frames]
    d01 = mean_abs_diff(frames[0], frames[1])
    d12 = mean_abs_diff(frames[1], frames[2])
    d02 = mean_abs_diff(frames[0], frames[2])
    motion = max(d01, d12, d02)

    too_dark = max(lumas) < 18
    too_static = motion < 1.8
    ok = not (too_dark or too_static)
    return {
        'ok': ok,
        'too_dark': too_dark,
        'too_static': too_static,
        'luma': [round(x, 2) for x in lumas],
        'motion': round(motion, 3),
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='img/previews')
    args = ap.parse_args()

    base = Path(args.dir)
    results = []
    flagged = []
    for mp4 in sorted(base.glob('*.mp4')):
        r = analyze(mp4)
        row = {'file': mp4.name, **r}
        results.append(row)
        if not r.get('ok', False):
            flagged.append(mp4.stem)

    report = {
        'checked': len(results),
        'flagged_count': len(flagged),
        'flagged_pages': flagged,
        'results': results,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
