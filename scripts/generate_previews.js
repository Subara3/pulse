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

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { stdio: 'pipe', encoding: 'utf8', ...opts });
  if (r.status !== 0) {
    const err = (r.stderr || r.stdout || '').trim();
    throw new Error(`${cmd} failed (${r.status}): ${err}`);
  }
  return r;
}

function ensureDeps() {
  run('ffmpeg', ['-version']);
}

function pagesFromContents() {
  const all = JSON.parse(fs.readFileSync(CONTENTS_PATH, 'utf8'));
  return all
    .map((c) => c.page)
    .filter((p) => !['about', 'contents', 'log'].includes(p));
}

(async () => {
  ensureDeps();
  fs.mkdirSync(TMP_DIR, { recursive: true });
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const pages = pagesFromContents();
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
      await page.waitForTimeout(3800);

      const v = page.video();
      await context.close();
      const webm = await v.path();

      run('ffmpeg', [
        '-y',
        '-i',
        webm,
        '-an',
        '-vf',
        'fps=24,scale=640:360:force_original_aspect_ratio=increase,crop=640:360',
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
