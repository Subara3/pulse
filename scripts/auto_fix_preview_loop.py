#!/usr/bin/env python3
import json
import math
import subprocess
from pathlib import Path


def run(cmd, text=True):
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=text)
    return p.returncode, p.stdout, p.stderr


def ffprobe_duration(path: Path) -> float:
    rc, out, err = run([
        'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1', str(path)
    ])
    if rc != 0:
        raise RuntimeError(err.strip() or out.strip())
    return float(out.strip())


def frame_raw_gray(path: Path, t: float):
    rc, out, err = run([
        'ffmpeg', '-v', 'error', '-ss', f'{t:.3f}', '-i', str(path),
        '-vframes', '1', '-f', 'rawvideo', '-pix_fmt', 'gray', '-'
    ], text=False)
    if rc != 0 or not out:
        return None
    return out


def luma_at(path: Path, t: float):
    rc, out, err = run([
        'ffmpeg', '-v', 'info', '-ss', f'{t:.3f}', '-i', str(path),
        '-vf', 'signalstats,metadata=print', '-frames:v', '1', '-f', 'null', '-'
    ])
    txt = (out or '') + '\n' + (err or '')
    key = 'lavfi.signalstats.YAVG='
    i = txt.find(key)
    if i == -1:
        return None
    j = i + len(key)
    k = j
    while k < len(txt) and (txt[k].isdigit() or txt[k] in '.-'):
        k += 1
    try:
        return float(txt[j:k])
    except Exception:
        return None


def mean_abs_diff(a: bytes, b: bytes) -> float:
    n = min(len(a), len(b))
    if n == 0:
        return 0.0
    return sum(abs(a[i] - b[i]) for i in range(n)) / n


def analyze(path: Path):
    dur = ffprobe_duration(path)
    if dur < 2.0:
        return {'ok': True, 'reason': 'short', 'duration': dur}

    t0 = 0.00
    t1 = min(0.20, dur * 0.15)
    t2 = max(0.0, dur - 0.20)
    t3 = max(0.0, dur - 0.02)

    y0 = luma_at(path, t0)
    y1 = luma_at(path, t1)
    y2 = luma_at(path, t2)
    y3 = luma_at(path, t3)

    f0 = frame_raw_gray(path, t0)
    f3 = frame_raw_gray(path, t3)
    seam_diff = mean_abs_diff(f0 or b'', f3 or b'')

    start_spike = (y0 is not None and y1 is not None and (y0 - y1 > 45 or y0 > 170))
    end_spike = (y3 is not None and y2 is not None and (y3 - y2 > 45 or y3 > 170))
    hard_seam = seam_diff > 22

    flagged = start_spike or end_spike or hard_seam

    return {
        'ok': not flagged,
        'duration': round(dur, 3),
        'y0': y0, 'y1': y1, 'y2': y2, 'y3': y3,
        'seam_diff': round(seam_diff, 3),
        'start_spike': start_spike,
        'end_spike': end_spike,
        'hard_seam': hard_seam,
    }


def reencode_mp4(path: Path, trim_start: float, trim_end: float):
    dur = ffprobe_duration(path)
    keep = dur - trim_start - trim_end
    if keep < 2.0:
        trim_start, trim_end = 0.10, 0.10
        keep = dur - 0.20

    tmp = path.with_suffix('.tmp.mp4')
    rc, out, err = run([
        'ffmpeg', '-y',
        '-ss', f'{trim_start:.2f}', '-t', f'{keep:.2f}', '-i', str(path),
        '-an',
        '-vf', 'fps=24,scale=640:360:flags=lanczos,format=yuv420p',
        '-c:v', 'libx264', '-profile:v', 'baseline', '-level', '3.0',
        '-pix_fmt', 'yuv420p', '-g', '24', '-keyint_min', '24', '-sc_threshold', '0', '-bf', '0',
        '-movflags', '+faststart',
        str(tmp)
    ])
    if rc != 0:
        raise RuntimeError(err.strip() or out.strip())
    tmp.replace(path)


def build_webm(path: Path):
    webm = path.with_suffix('.webm')
    rc, out, err = run([
        'ffmpeg', '-y', '-i', str(path), '-an',
        '-vf', 'fps=24,scale=640:360:flags=lanczos,format=yuv420p',
        '-c:v', 'libvpx-vp9', '-b:v', '0', '-crf', '36',
        '-row-mt', '1', '-deadline', 'good',
        str(webm)
    ])
    if rc != 0:
        raise RuntimeError(err.strip() or out.strip())


def build_poster(path: Path):
    jpg = path.with_suffix('.jpg')
    run(['ffmpeg', '-y', '-ss', '0.35', '-i', str(path), '-vframes', '1', '-q:v', '3', str(jpg)])


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default='img/previews')
    ap.add_argument('--pages', default='', help='comma-separated page slugs (default: all)')
    args = ap.parse_args()

    base = Path(args.dir)
    targets = [p.strip() for p in args.pages.split(',') if p.strip()]
    if targets:
        files = sorted([base / f'{p}.mp4' for p in targets if (base / f'{p}.mp4').exists()])
    else:
        files = sorted(base.glob('*.mp4'))
    report = {'checked': 0, 'flagged': 0, 'fixed': 0, 'failed': [], 'details': []}

    for mp4 in files:
        report['checked'] += 1
        a = analyze(mp4)
        item = {'file': mp4.name, 'initial': a}
        if a.get('ok', False):
            report['details'].append(item)
            continue

        report['flagged'] += 1
        trim_start = 0.30 if a.get('start_spike') else 0.15
        trim_end = 0.30 if a.get('end_spike') else 0.15
        try:
            reencode_mp4(mp4, trim_start, trim_end)
            build_webm(mp4)
            build_poster(mp4)
            b = analyze(mp4)
            item['fixed'] = b
            item['trim_start'] = trim_start
            item['trim_end'] = trim_end
            if b.get('ok', False):
                report['fixed'] += 1
            else:
                report['failed'].append(mp4.name)
        except Exception as e:
            item['error'] = str(e)
            report['failed'].append(mp4.name)

        report['details'].append(item)

    Path('reports_preview_loop_autofix.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
