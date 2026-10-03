"""プレビュー動画のエンコード設定（Python 側の唯一の定義）。

scripts/generate_previews.js の X264_ARGS と同じ値にしておくこと。
2026-10: webm をやめて mp4(H.264) 1 本にし、ビットレートに上限をつけた。
ノイズの多いページ（ising / cyclic など）が 1 本 3MB に膨らみ、
repo の履歴が 4GB を超えたため。
"""

VF = "fps=20,scale=640:360:flags=lanczos,format=yuv420p"

X264_ARGS = [
    "-c:v", "libx264", "-preset", "slow",
    "-crf", "28", "-maxrate", "900k", "-bufsize", "1800k",
    "-profile:v", "main", "-level", "3.1", "-pix_fmt", "yuv420p",
    "-g", "40", "-keyint_min", "40", "-sc_threshold", "0",
    "-movflags", "+faststart",
]

JPG_ARGS = ["-vframes", "1", "-q:v", "5"]
