<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('marbling');

$pageName = 'marbling';
$pageTitle = 'MARBLING - PULSE';
$pageDesc = 'インクの滴が流体に落ちて渦を描く。マーブリングの数学的模倣。';
$pageCanonical = 'https://pulse.subara3.com/marbling.php';
$extraStyle = '
canvas { display:block; }
.controls {
  position:fixed; bottom:1.5rem; right:1.5rem;
  display:flex; gap:0.5rem; flex-direction:column; align-items:flex-end;
}
.btn {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); padding:0.35rem 0.8rem;
  font-size:0.65rem; letter-spacing:0.15em; cursor:pointer;
  font-family:inherit; transition:all 0.2s;
}
.btn:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
.btn.active { background:rgba(0,245,212,0.2); color:#00f5d4; border-color:rgba(0,245,212,0.5); }
.info {
  position:fixed; bottom:1.5rem; left:1.5rem;
  font-size:0.65rem; color:rgba(255,255,255,0.2); letter-spacing:0.1em;
  pointer-events:none;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="controls">
  <button class="btn active" id="btnAuto">AUTO: ON</button>
  <button class="btn" id="btnReset">RESET</button>
</div>
<div class="info" id="info">DROP 0</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop'],
  ['type' => 'drag', 'label' => 'comb'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
ctx.imageSmoothingEnabled = true;
ctx.imageSmoothingQuality = 'high';

// Off-screen buffer for marbling computation
const BW = 600, BH = 450;
const offscreen = document.createElement('canvas');
offscreen.width = BW; offscreen.height = BH;
const offCtx = offscreen.getContext('2d');

// Color buffers (Float32 for precision)
let colR = new Float32Array(BW * BH);
let colG = new Float32Array(BW * BH);
let colB = new Float32Array(BW * BH);
let tmpR = new Float32Array(BW * BH);
let tmpG = new Float32Array(BW * BH);
let tmpB = new Float32Array(BW * BH);

// Palette: teal-purple range only
const PALETTE = [
  [0, 245, 212],
  [50, 210, 225],
  [80, 170, 220],
  [100, 130, 210],
  [123, 44, 191],
  [150, 80, 220],
  [170, 60, 200],
  [60, 200, 210],
];

let colorIdx = 0;
let dropCount = 0;
let autoMode = true;

// ---- Buffer operations ----

function swap() {
  let t;
  t = colR; colR = tmpR; tmpR = t;
  t = colG; colG = tmpG; tmpG = t;
  t = colB; colB = tmpB; tmpB = t;
}

function sampleBilinear(buf, x, y) {
  x = x < 0 ? 0 : x > BW - 1.001 ? BW - 1.001 : x;
  y = y < 0 ? 0 : y > BH - 1.001 ? BH - 1.001 : y;
  const x0 = x | 0, y0 = y | 0;
  const fx = x - x0, fy = y - y0;
  const i00 = y0 * BW + x0;
  const i10 = i00 + 1;
  const i01 = i00 + BW;
  const i11 = i01 + 1;
  return buf[i00] * (1 - fx) * (1 - fy)
       + buf[i10] * fx * (1 - fy)
       + buf[i01] * (1 - fx) * fy
       + buf[i11] * fx * fy;
}

// Drop: circular displacement pushing existing ink outward, fills circle with new color
// nx, ny: normalized [0,1] coordinates; R: radius in pixels (BW-relative)
function applyDrop(nx, ny, R, col) {
  const cx = nx * BW, cy = ny * BH;
  const R2 = R * R;

  for (let y = 0; y < BH; y++) {
    for (let x = 0; x < BW; x++) {
      const dx = x - cx, dy = y - cy;
      const r2 = dx * dx + dy * dy;
      const i = y * BW + x;
      if (r2 < 0.25) {
        tmpR[i] = col[0]; tmpG[i] = col[1]; tmpB[i] = col[2];
      } else {
        const scale = Math.sqrt(1 + R2 / r2);
        tmpR[i] = sampleBilinear(colR, cx + dx * scale, cy + dy * scale);
        tmpG[i] = sampleBilinear(colG, cx + dx * scale, cy + dy * scale);
        tmpB[i] = sampleBilinear(colB, cx + dx * scale, cy + dy * scale);
      }
    }
  }

  // Fill drop circle with solid color
  const ri = Math.ceil(R);
  const x0 = Math.max(0, (cx - ri) | 0), x1 = Math.min(BW - 1, (cx + ri) | 0);
  const y0 = Math.max(0, (cy - ri) | 0), y1 = Math.min(BH - 1, (cy + ri) | 0);
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const dx = x - cx, dy = y - cy;
      if (dx * dx + dy * dy <= R2) {
        const i = y * BW + x;
        tmpR[i] = col[0]; tmpG[i] = col[1]; tmpB[i] = col[2];
      }
    }
  }

  swap();
  dropCount++;
  document.getElementById('info').textContent = 'DROP ' + dropCount;
}

// Comb: pulls ink along drag direction, influence falls off with distance from drag line
// All coords normalized [0,1]; sigma: influence radius relative to BW
function applyComb(x1n, y1n, x2n, y2n, sigma) {
  const x1 = x1n * BW, y1 = y1n * BH;
  const x2 = x2n * BW, y2 = y2n * BH;
  const ddx = x2 - x1, ddy = y2 - y1;
  const len = Math.sqrt(ddx * ddx + ddy * ddy);
  if (len < 2) return;

  const dnx = ddx / len, dny = ddy / len;  // line direction (normalized)
  const nnx = -dny, nny = dnx;              // line normal

  const sigma_px = sigma * BW;
  const sig2 = 2 * sigma_px * sigma_px;
  const cutoff2 = (sigma_px * 3.5) * (sigma_px * 3.5);

  for (let y = 0; y < BH; y++) {
    for (let x = 0; x < BW; x++) {
      const px = x - x1, py = y - y1;
      const perp = px * nnx + py * nny; // signed dist to line
      const i = y * BW + x;

      if (perp * perp > cutoff2) {
        tmpR[i] = colR[i]; tmpG[i] = colG[i]; tmpB[i] = colB[i];
        continue;
      }

      // Gaussian falloff from line; pull toward drag direction
      const disp = len * Math.exp(-perp * perp / sig2);
      const srcX = x - dnx * disp;
      const srcY = y - dny * disp;
      tmpR[i] = sampleBilinear(colR, srcX, srcY);
      tmpG[i] = sampleBilinear(colG, srcX, srcY);
      tmpB[i] = sampleBilinear(colB, srcX, srcY);
    }
  }

  swap();
}

// Initialize with horizontal stripes
function initStripes() {
  const n = PALETTE.length;
  const sh = BH / n;
  for (let y = 0; y < BH; y++) {
    const ci = Math.floor(y / sh) % n;
    const c = PALETTE[ci];
    for (let x = 0; x < BW; x++) {
      const i = y * BW + x;
      colR[i] = c[0]; colG[i] = c[1]; colB[i] = c[2];
    }
  }
  dropCount = 0;
  document.getElementById('info').textContent = 'DROP 0';
}

// ---- Rendering ----

const imgData = offCtx.createImageData(BW, BH);

function render() {
  const d = imgData.data;
  for (let i = 0; i < BW * BH; i++) {
    const j = i << 2;
    d[j]     = colR[i] < 0 ? 0 : colR[i] > 255 ? 255 : colR[i];
    d[j + 1] = colG[i] < 0 ? 0 : colG[i] > 255 ? 255 : colG[i];
    d[j + 2] = colB[i] < 0 ? 0 : colB[i] > 255 ? 255 : colB[i];
    d[j + 3] = 255;
  }
  offCtx.putImageData(imgData, 0, 0);
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(offscreen, 0, 0, canvas.width, canvas.height);
}

function resize() {
  canvas.width  = window.innerWidth;
  canvas.height = window.innerHeight;
  render();
}
window.addEventListener('resize', resize);

// ---- Auto demo ----

function rand(a, b) { return a + Math.random() * (b - a); }

let autoSeq = [];
let autoIdx = 0;
let autoAccum = 0;
const AUTO_INTERVAL = 1400; // ms between operations
let lastTime = 0;

function buildAutoSeq() {
  autoSeq = [];
  // 6 drops with cycling palette
  for (let k = 0; k < 6; k++) {
    autoSeq.push({
      type: 'drop',
      x: rand(0.12, 0.88), y: rand(0.12, 0.88),
      r: rand(22, 55),
      col: PALETTE[k % PALETTE.length]
    });
  }
  // 3 comb passes
  autoSeq.push({ type: 'comb', x1: 0, y1: rand(0.3, 0.7), x2: 1, y2: rand(0.3, 0.7), sigma: rand(0.05, 0.09) });
  autoSeq.push({ type: 'comb', x1: rand(0.3, 0.7), y1: 0, x2: rand(0.3, 0.7), y2: 1, sigma: rand(0.05, 0.09) });
  autoSeq.push({ type: 'comb', x1: rand(0, 0.4), y1: rand(0, 0.4), x2: rand(0.6, 1), y2: rand(0.6, 1), sigma: rand(0.04, 0.08) });
  // Shuffle drops only
  const drops = autoSeq.slice(0, 6).sort(() => Math.random() - 0.5);
  const combs = autoSeq.slice(6);
  autoSeq = [...drops, ...combs];
  autoIdx = 0;
}

function stepAuto(dt) {
  if (!autoMode) return;
  autoAccum += dt;
  if (autoAccum < AUTO_INTERVAL) return;
  autoAccum -= AUTO_INTERVAL;

  if (autoIdx >= autoSeq.length) {
    // Restart after short pause
    initStripes();
    buildAutoSeq();
    render();
    SFX.play('click');
    return;
  }

  const op = autoSeq[autoIdx++];
  if (op.type === 'drop') {
    applyDrop(op.x, op.y, op.r, op.col);
    SFX.play('ripple');
  } else {
    applyComb(op.x1, op.y1, op.x2, op.y2, op.sigma);
    SFX.play('move', 80);
  }
  render();
}

function loop(now) {
  const dt = now - (lastTime || now);
  lastTime = now;
  stepAuto(dt);
  requestAnimationFrame(loop);
}

// ---- Input ----

let dragStart = null;
let isDragging = false;

function normPos(clientX, clientY) {
  const r = canvas.getBoundingClientRect();
  return { x: (clientX - r.left) / r.width, y: (clientY - r.top) / r.height };
}

canvas.addEventListener('mousedown', e => {
  e.preventDefault();
  if (e.button === 2) {
    initStripes();
    buildAutoSeq();
    render();
    SFX.play('click');
    return;
  }
  dragStart = normPos(e.clientX, e.clientY);
  isDragging = false;
});

canvas.addEventListener('mousemove', e => {
  if (!dragStart) return;
  const p = normPos(e.clientX, e.clientY);
  const dx = p.x - dragStart.x, dy = p.y - dragStart.y;
  if (dx * dx + dy * dy > 0.0002) isDragging = true;
});

canvas.addEventListener('mouseup', e => {
  if (e.button === 2 || !dragStart) return;
  const p = normPos(e.clientX, e.clientY);
  if (isDragging) {
    applyComb(dragStart.x, dragStart.y, p.x, p.y, 0.075);
    SFX.play('move', 80);
  } else {
    const col = PALETTE[colorIdx % PALETTE.length];
    colorIdx++;
    applyDrop(p.x, p.y, 28 + Math.random() * 28, col);
    SFX.play('ripple');
  }
  render();
  dragStart = null;
  isDragging = false;
});

canvas.addEventListener('contextmenu', e => e.preventDefault());

// Touch
let touchOrigin = null;
canvas.addEventListener('touchstart', e => {
  e.preventDefault();
  touchOrigin = normPos(e.touches[0].clientX, e.touches[0].clientY);
  isDragging = false;
}, { passive: false });

canvas.addEventListener('touchmove', e => {
  e.preventDefault();
  if (!touchOrigin) return;
  const p = normPos(e.touches[0].clientX, e.touches[0].clientY);
  const dx = p.x - touchOrigin.x, dy = p.y - touchOrigin.y;
  if (dx * dx + dy * dy > 0.0002) isDragging = true;
}, { passive: false });

canvas.addEventListener('touchend', e => {
  e.preventDefault();
  if (!touchOrigin) return;
  const t = e.changedTouches[0];
  const p = normPos(t.clientX, t.clientY);
  if (isDragging) {
    applyComb(touchOrigin.x, touchOrigin.y, p.x, p.y, 0.08);
    SFX.play('move', 80);
  } else {
    const col = PALETTE[colorIdx % PALETTE.length];
    colorIdx++;
    applyDrop(p.x, p.y, 32 + Math.random() * 24, col);
    SFX.play('ripple');
  }
  render();
  touchOrigin = null;
  isDragging = false;
}, { passive: false });

// Buttons
document.getElementById('btnAuto').addEventListener('click', () => {
  autoMode = !autoMode;
  const btn = document.getElementById('btnAuto');
  btn.textContent = autoMode ? 'AUTO: ON' : 'AUTO: OFF';
  btn.classList.toggle('active', autoMode);
  SFX.play('toggle');
});

document.getElementById('btnReset').addEventListener('click', () => {
  initStripes();
  buildAutoSeq();
  render();
  SFX.play('click');
});

// ---- Init ----
initStripes();
buildAutoSeq();
resize();
requestAnimationFrame(loop);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
