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
  ripple: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 360, y: 220 },
    { kind: 'click', x: 600, y: 320 },
    { kind: 'drag', from: [300, 180], to: [660, 340], steps: 22 },
  ],
  sand: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [300, 180], to: [660, 360], steps: 26 },
    { kind: 'drag', from: [660, 180], to: [300, 360], steps: 26 },
  ],
  physics: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [420, 260], to: [620, 300], steps: 16 },
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
  evolution: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'click', x: 420, y: 500 },
  ],
  string: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'drag', from: [400, 250], to: [620, 260], steps: 18 },
  ],
  lissajous: [
    { kind: 'drag', from: [350, 300], to: [610, 220], steps: 20 },
    { kind: 'drag', from: [610, 220], to: [350, 300], steps: 20 },
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
  pathfind: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'click', x: 420, y: 500 },
    { kind: 'drag', from: [300, 220], to: [640, 320], steps: 24 },
  ],
  spirograph: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'drag', from: [360, 260], to: [620, 260], steps: 18 },
  ],
  drift: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [320, 220], to: [640, 320], steps: 22 },
  ],
  tree: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'click', x: 420, y: 500 },
  ],
  sand: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'drag', from: [280, 180], to: [680, 340], steps: 30 },
    { kind: 'drag', from: [680, 180], to: [280, 340], steps: 30 },
  ],
  pi: [
    { kind: 'click', x: 520, y: 500 },
    { kind: 'click', x: 420, y: 500 },
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
  conformal: [
    { kind: 'drag', from: [320, 200], to: [640, 320], steps: 24 },
    { kind: 'click', x: 480, y: 270 },
  ],
  pursuit: [
    { kind: 'click', x: 480, y: 270 },
    { kind: 'click', x: 300, y: 200 },
    { kind: 'click', x: 650, y: 350 },
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

  // Optional target filter:
  // - CLI args: node scripts/generate_previews.js isofield flock
  // - env: PULSE_PREVIEW_PAGES=isofield,flock
  const cliPages = process.argv.slice(2).map((s) => s.trim()).filter(Boolean);
  const envPages = (process.env.PULSE_PREVIEW_PAGES || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
  const targets = cliPages.length ? cliPages : envPages;

  if (!targets.length) return base;

  const set = new Set(base);
  const valid = targets.filter((p) => set.has(p));
  const invalid = targets.filter((p) => !set.has(p));
  if (invalid.length) {
    console.warn(`warn unknown pages ignored: ${invalid.join(', ')}`);
  }
  return valid;
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
      await page.goto(`${BASE_URL}/${pageName}.php?preview=1`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      });
      await page.waitForTimeout(600);
      await runInteractions(page, pageName);
      await page.waitForTimeout(3600);

      const v = page.video();
      await context.close();
      const webm = await v.path();

      const vfParts = [
        'fps=24,scale=640:360:force_original_aspect_ratio=increase,crop=640:360',
        TONE_FILTERS[pageName] || '',
        // Fade in/out for smooth loop (black → content → black)
        'fade=t=in:st=0:d=0.3,fade=t=out:st=4.8:d=0.4',
      ];
      const vf = vfParts.filter(Boolean).join(',');

      run('ffmpeg', [
        '-y',
        '-i',
        webm,
        '-an',
        '-vf',
        vf,
        '-c:v',
        'libx264',
        '-profile:v',
        'baseline',
        '-level',
        '3.0',
        '-pix_fmt',
        'yuv420p',
        '-movflags',
        '+faststart',
        outMp4,
      ]);

      const outWebm = path.join(OUT_DIR, `${pageName}.webm`);
      const outJpg = path.join(OUT_DIR, `${pageName}.jpg`);

      run('ffmpeg', [
        '-y',
        '-i',
        outMp4,
        '-an',
        '-vf',
        'fps=24,scale=640:360:flags=lanczos,format=yuv420p',
        '-c:v',
        'libvpx-vp9',
        '-b:v',
        '0',
        '-crf',
        '36',
        '-row-mt',
        '1',
        '-deadline',
        'good',
        outWebm,
      ]);

      run('ffmpeg', [
        '-y',
        '-ss',
        '0.35',
        '-i',
        outMp4,
        '-vframes',
        '1',
        '-q:v',
        '3',
        outJpg,
      ]);

      ok.push(pageName);
      console.log(`ok ${pageName}`);
    } catch (e) {
      ng.push({ page: pageName, error: e.message });
      console.log(`fail ${pageName}: ${e.message}`);
    }
  }

  await browser.close();

  console.log(`\nDone. success=${ok.length}, failed=${ng.length}`);
  if (ng.length) {
    console.log('Failed pages:');
    for (const x of ng) console.log(`- ${x.page}`);
    process.exitCode = 1;
  }
})();
