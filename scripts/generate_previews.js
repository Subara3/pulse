#!/usr/bin/env node
/**
 * Generate motion previews for all PULSE content pages.
 *
 * Fixes / prevention:
 * - Uses ffmpeg scale with force_original_aspect_ratio=increase (not "cover", which errors).
 * - Captures from ?preview=1 mode to avoid ads/UI noise.
 * - Continues on per-page failure and prints a summary.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT = process.cwd();
const CONTENTS_PATH = path.join(ROOT, 'data', 'contents.json');
const TMP_DIR = path.join(ROOT, '.tmp', 'previews');
const OUT_DIR = path.join(ROOT, 'img', 'previews');
const BASE_URL = process.env.PULSE_PREVIEW_BASE_URL || 'http://127.0.0.1:8787';

// 録画の頭 WARMUP_SEC 秒（種まき + 成長）は捨て、そのあと CLIP_SEC 秒を使う。
const WARMUP_SEC = 5.6;
const CLIP_SEC = 5.4;

// エンコード設定。scripts/preview_codec.py と同じ値にしておくこと。
// 2026-10: webm をやめて mp4(H.264) 1 本にし、ビットレートに上限をつけた。
// ノイズの多いページが 1 本 3MB に膨らみ、repo の履歴が 4GB を超えたため。
const X264_ARGS = [
  '-c:v', 'libx264', '-preset', 'slow',
  '-crf', '28', '-maxrate', '900k', '-bufsize', '1800k',
  '-profile:v', 'main', '-level', '3.1', '-pix_fmt', 'yuv420p',
  '-g', '40', '-keyint_min', '40', '-sc_threshold', '0',
  '-movflags', '+faststart',
];

// 時間が経つほど画面が塗りつぶされるページは、早い時点を切り出す。
// 放置すると一覧に「単色のタイル」が並ぶ。
const WARMUP_OVERRIDES = {
  langton: 1.4, caustics: 1.6, erosion: 2.0, marbling: 2.0,
  quasicrystal: 2.2, newton: 2.0, quantum: 2.0, reaction: 3.0,
  cyclic: 2.0,
};

// Per-page tone fixes to reduce highlight clipping (白飛び) on specific renders.
const TONE_FILTERS = {
  newton: 'eq=brightness=-0.03:contrast=0.92:saturation=0.92:gamma=1.06',
  clifford: 'eq=brightness=-0.05:contrast=0.90:saturation=0.88:gamma=1.10',
  flock: 'eq=brightness=0.06:contrast=1.15:saturation=1.2:gamma=0.85',
};

const INTERACTION_PRESETS = {
  voronoi: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 320, y: 220 },
    { kind: 'click', x: 640, y: 320 },
  ],
  fluidveil: [
    { kind: 'drag', from: [320, 200], to: [640, 320], steps: 24 },
  ],
  cloth: [
    { kind: 'drag', from: [480, 180], to: [580, 300], steps: 20 },
  ],
  sort: [
    { kind: 'click', x: 520, y: 500 },
  ],
  string: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'drag', from: [400, 250], to: [620, 260], steps: 18 },
  ],
  sandpile: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [420, 220], to: [540, 320], steps: 16 },
  ],
  network: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 520, y: 240 },
    { kind: 'click', x: 440, y: 300 },
  ],
  dla: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [350, 220], to: [620, 320], steps: 20 },
  ],
  percolation: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'click', x: 420, y: 500 },
    { kind: 'click', x: 480, y: 270 },
  ],
  spirograph: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'drag', from: [360, 260], to: [620, 260], steps: 18 },
  ],
  flock: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [480, 270], to: [300, 180], steps: 20 },
    { kind: 'drag', from: [300, 180], to: [660, 320], steps: 24 },
    { kind: 'drag', from: [660, 320], to: [480, 200], steps: 20 },
  ],
  predprey: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 300, y: 200 },
    { kind: 'click', x: 600, y: 350 },
    { kind: 'drag', from: [200, 150], to: [700, 400], steps: 30 },
  ],
  quantum: [
    { kind: 'click', x: 300, y: 270 },
    { kind: 'click', x: 600, y: 200 },
    { kind: 'click', x: 450, y: 350 },
  ],
};

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { stdio: 'pipe', encoding: 'utf8', ...opts });
  if (r.error) {
    throw new Error(`${cmd} spawn error (${r.error.code || 'unknown'}): ${r.error.message}`);
  }
  if (r.status !== 0) {
    const err = (r.stderr || r.stdout || '').trim();
    throw new Error(`${cmd} failed (${r.status}): ${err}`);
  }
  return r;
}

function ensureDeps() {
  run('ffmpeg', ['-version']);
}

async function runInteractions(page, pageName) {
  const preset = INTERACTION_PRESETS[pageName] || [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [420, 240], to: [560, 300], steps: 12 },
  ];

  for (const act of preset) {
    if (act.kind === 'click') {
      await page.mouse.click(act.x, act.y, { delay: 20 });
      await page.waitForTimeout(120);
    } else if (act.kind === 'drag') {
      const [sx, sy] = act.from;
      const [ex, ey] = act.to;
      await page.mouse.move(sx, sy);
      await page.mouse.down();
      await page.mouse.move(ex, ey, { steps: act.steps || 12 });
      await page.mouse.up();
      await page.waitForTimeout(120);
    }
  }
}

function pagesFromContents() {
  const all = JSON.parse(fs.readFileSync(CONTENTS_PATH, 'utf8'));
  const base = all
    .map((c) => c.page)
    .filter((p) => !['about', 'contents', 'log'].includes(p));

  // Explicit targets win (CLI args > env PULSE_PREVIEW_PAGES)
  const cliPages = process.argv.slice(2).map((s) => s.trim()).filter(Boolean);
  const envPages = (process.env.PULSE_PREVIEW_PAGES || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  const targets = cliPages.length ? cliPages : envPages;

  if (targets.length) {
    const set = new Set(base);
    const valid = targets.filter((p) => set.has(p));
    const invalid = targets.filter((p) => !set.has(p));
    if (invalid.length) console.warn(`warn unknown pages ignored: ${invalid.join(', ')}`);
    return valid;
  }

  // No explicit targets. Default: missing-only. Set PULSE_PREVIEW_ALL=1 for full regen.
  if (process.env.PULSE_PREVIEW_ALL === '1') return base;

  const missing = base.filter((p) => {
    const mp4 = path.join(OUT_DIR, `${p}.mp4`);
    const jpg = path.join(OUT_DIR, `${p}.jpg`);
    return !fs.existsSync(mp4) || !fs.existsSync(jpg);
  });
  console.log(`mode=missing-only. ${missing.length}/${base.length} pages need rendering.`);
  return missing;
}

(async () => {
  ensureDeps();
  fs.mkdirSync(TMP_DIR, { recursive: true });
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const pages = pagesFromContents();
  if (!pages.length) {
    console.log('No target pages to render.');
    return;
  }

  const browser = await chromium.launch({ headless: true });

  const ok = [];
  const ng = [];
  const jsErrors = {};

  for (const pageName of pages) {
    const pageTmp = path.join(TMP_DIR, pageName);
    fs.mkdirSync(pageTmp, { recursive: true });
    const outMp4 = path.join(OUT_DIR, `${pageName}.mp4`);

    try {
      const context = await browser.newContext({
        viewport: { width: 960, height: 540 },
        recordVideo: { dir: pageTmp, size: { width: 960, height: 540 } },
      });

      const page = await context.newPage();

      // 描画ループが例外で止まると、プレビューは「ただの黒い動画」になる。
      // 黒さから推測するのではなく、原因そのものを記録する。
      const pageErrors = [];
      page.on('pageerror', (e) => pageErrors.push(String((e && e.message) || e)));
      page.on('console', (m) => { if (m.type() === 'error') pageErrors.push(m.text()); });

      await page.goto(`${BASE_URL}/${pageName}.php?preview=1`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      });
      // シミュレーションが育つ前を録っても「何も起きていない映像」になる。
      // 一度操作して 5 秒育て、もう一度操作してから残りを録る。
      // 出力側で頭 WARMUP_SEC を捨てるので、映るのは「育ったあとに触っている」ところ。
      await page.waitForTimeout(600);
      await runInteractions(page, pageName);
      await page.waitForTimeout(5000);
      await runInteractions(page, pageName);
      await page.waitForTimeout(4400);

      const v = page.video();
      await context.close();
      const webm = await v.path();

      const vfParts = [
        'fps=20,scale=640:360:force_original_aspect_ratio=increase,crop=640:360',
        TONE_FILTERS[pageName] || '',
        // Fade in/out for smooth loop (black → content → black)
        'fade=t=in:st=0:d=0.3,fade=t=out:st=4.8:d=0.4',
      ];
      const vf = vfParts.filter(Boolean).join(',');

      run('ffmpeg', [
        '-y',
        '-ss',
        String(WARMUP_OVERRIDES[pageName] !== undefined
          ? WARMUP_OVERRIDES[pageName] : WARMUP_SEC),
        '-t',
        String(CLIP_SEC),
        '-i',
        webm,
        '-an',
        '-vf',
        vf,
        ...X264_ARGS,
        outMp4,
      ]);

      const outJpg = path.join(OUT_DIR, `${pageName}.jpg`);
      // 旧形式の webm が残っていれば消す（2026-10 から mp4 のみ）
      fs.rmSync(path.join(OUT_DIR, `${pageName}.webm`), { force: true });

      // Pick a mid-preview frame for thumbnails; early frames are often still fading in.
      run('ffmpeg', [
        '-y',
        '-ss',
        '2.40',
        '-i',
        outMp4,
        '-vframes',
        '1',
        '-q:v',
        '5',
        outJpg,
      ]);

      if (pageErrors.length) {
        jsErrors[pageName] = Array.from(new Set(pageErrors)).slice(0, 5);
        console.log(`JSERROR ${pageName}: ${jsErrors[pageName][0]}`);
      }
      ok.push(pageName);
      console.log(`ok ${pageName}`);
    } catch (e) {
      ng.push({ page: pageName, error: e.message });
      console.log(`fail ${pageName}: ${e.message}`);
    }
  }

  await browser.close();

  const errPages = Object.keys(jsErrors);
  if (errPages.length) {
    fs.mkdirSync(path.join(ROOT, 'reports'), { recursive: true });
    fs.writeFileSync(
      path.join(ROOT, 'reports', 'page-errors.json'),
      JSON.stringify(jsErrors, null, 1) + '\n'
    );
    console.log(`\nJavaScript errors on ${errPages.length} page(s):`);
    for (const p of errPages) console.log(`- ${p}: ${jsErrors[p][0]}`);
  }

  console.log(`\nDone. success=${ok.length}, failed=${ng.length}, jsErrors=${errPages.length}`);
  if (ng.length) {
    console.log('Failed pages:');
    for (const x of ng) console.log(`- ${x.page}`);
    process.exitCode = 1;
  }
})();
