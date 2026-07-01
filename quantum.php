<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('quantum');

$pageName = 'quantum';
$pageTitle = 'QUANTUM - PULSE';
$pageDesc = "量子波束のトンネル効果。障壁に当たると反射と透過に分かれる、シュレーディンガー方程式の体験。";
$pageCanonical = 'https://pulse.subara3.com/quantum.php';
$extraStyle = '
body { overflow:hidden; cursor:crosshair; }
canvas {
  display:block; position:fixed; top:0; left:0;
  width:100%; height:100%; z-index:0;
  image-rendering:pixelated;
  image-rendering:crisp-edges;
}
.controls {
  position:fixed; bottom:1.5rem; right:1.5rem;
  display:flex; gap:0.5rem; flex-direction:column; align-items:flex-end; z-index:10;
}
.btn {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); padding:0.35rem 0.8rem;
  font-size:0.65rem; letter-spacing:0.15em; cursor:pointer;
  font-family:inherit; transition:all 0.2s;
}
.btn:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="controls drawer-controls">
  <button class="btn" id="btnReset">RESET</button>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'wave'],
  ['type' => 'drag',  'label' => 'barrier'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<noscript><p style="color:#fff;padding:2rem;">量子波動シミュレーション。二重スリットによる干渉パターン。</p></noscript>

<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx    = canvas.getContext('2d');

// ── Grid (adaptive cell size targeting ~150k cells) ─────────────────────
const MAX_CELLS = 150000;
const screenW = window.innerWidth;
const screenH = window.innerHeight;
const CELL = Math.max(2, Math.ceil(Math.sqrt(screenW * screenH / MAX_CELLS)));
const NX = Math.floor(screenW / CELL);
const NY = Math.floor(screenH / CELL);
canvas.width  = NX;
canvas.height = NY;

let u       = new Float32Array(NX * NY);
let v       = new Float32Array(NX * NY);
let barrier = new Uint8Array(NX * NY);
const imgData = ctx.createImageData(NX, NY);

// ── Physics constants ───────────────────────────────────────────────────
const C2           = 0.20;
const DAMP         = 0.998;
const BARRIER_DAMP = 0.82;
const ABSORB       = Math.max(8, Math.floor(Math.min(NX, NY) * 0.04));
const FREQ         = 0.15;
const SRC_AMP      = 0.25;
let   phase        = 0;

// ── Double-slit barrier ─────────────────────────────────────────────────
function placeDoubleSlit() {
  barrier.fill(0);
  const wallX  = Math.floor(NX * 0.42);
  const wallW  = Math.max(2, Math.floor(NX * 0.006));
  const slitH  = Math.max(3, Math.floor(NY * 0.045));
  const slitD  = Math.floor(NY * 0.18);
  const cy     = Math.floor(NY / 2);
  const slit1  = cy - Math.floor(slitD / 2);
  const slit2  = cy + Math.floor(slitD / 2);

  for (let dx = 0; dx < wallW; dx++) {
    const x = wallX + dx;
    if (x >= NX) continue;
    for (let y = 0; y < NY; y++) {
      if (Math.abs(y - slit1) > slitH / 2 && Math.abs(y - slit2) > slitH / 2) {
        barrier[y * NX + x] = 1;
      }
    }
  }
}

function resetSim() {
  u.fill(0);
  v.fill(0);
  phase = 0;
  placeDoubleSlit();
}

// ── Physics step (2D wave equation, leapfrog) ───────────────────────────
function step() {
  const tmp = new Float32Array(NX * NY);

  for (let y = 1; y < NY - 1; y++) {
    for (let x = 1; x < NX - 1; x++) {
      const i   = y * NX + x;
      const lap = u[i - 1] + u[i + 1] + u[i - NX] + u[i + NX] - 4 * u[i];
      const d   = barrier[i] ? BARRIER_DAMP : DAMP;
      tmp[i]    = d * (2 * u[i] - v[i] + C2 * lap);
    }
  }

  // Absorbing boundaries (all 4 edges)
  for (let y = 0; y < NY; y++) {
    for (let d = 0; d < ABSORB; d++) {
      const f  = (d / ABSORB);
      const f2 = f * f;
      // left
      tmp[y * NX + d] *= f2;
      // right
      tmp[y * NX + (NX - 1 - d)] *= f2;
    }
  }
  for (let x = 0; x < NX; x++) {
    for (let d = 0; d < ABSORB; d++) {
      const f2 = (d / ABSORB) * (d / ABSORB);
      tmp[d * NX + x]           *= f2;
      tmp[(NY - 1 - d) * NX + x] *= f2;
    }
  }

  // Plane-wave source (left edge, inside absorb zone)
  phase += FREQ;
  const a = Math.sin(phase) * SRC_AMP;
  const srcX = ABSORB + 1;
  for (let y = ABSORB; y < NY - ABSORB; y++) {
    tmp[y * NX + srcX]     = a;
    tmp[y * NX + srcX + 1] = a;
  }

  v = u;
  u = tmp;
}

// ── Add circular wave pulse ─────────────────────────────────────────────
function addPulse(gx, gy) {
  const r   = Math.max(4, Math.floor(Math.min(NX, NY) * 0.025));
  const amp = 2.0;
  for (let dy = -r; dy <= r; dy++) {
    for (let dx = -r; dx <= r; dx++) {
      const x = gx + dx, y = gy + dy;
      if (x < 0 || x >= NX || y < 0 || y >= NY) continue;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > r) continue;
      const g = Math.cos(dist / r * Math.PI * 0.5) * amp;
      const i = y * NX + x;
      if (!barrier[i]) u[i] += g;
    }
  }
}

// ── Paint / erase barrier ───────────────────────────────────────────────
function paintBarrier(gx, gy, erase) {
  const r = Math.max(2, Math.ceil(Math.min(NX, NY) * 0.012));
  for (let dy = -r; dy <= r; dy++) {
    for (let dx = -r; dx <= r; dx++) {
      const x = gx + dx, y = gy + dy;
      if (x < 0 || x >= NX || y < 0 || y >= NY) continue;
      if (dx * dx + dy * dy > r * r) continue;
      barrier[y * NX + x] = erase ? 0 : 1;
    }
  }
}

// ── Render ───────────────────────────────────────────────────────────────
function draw() {
  const data = imgData.data;
  const BG_R = 10, BG_G = 10, BG_B = 15;

  for (let i = 0; i < NX * NY; i++) {
    const pi = i << 2;

    if (barrier[i]) {
      data[pi]     = 50;
      data[pi + 1] = 25;
      data[pi + 2] = 90;
      data[pi + 3] = 255;
      continue;
    }

    const val = u[i];
    const a   = Math.abs(val);
    const t   = Math.min(a * 5.5, 1);
    const t2  = t * t;

    // Base color: teal for positive, purple for negative
    let cr, cg, cb;
    if (val >= 0) {
      cr = 0; cg = 245; cb = 212;
    } else {
      cr = 130; cg = 50; cb = 200;
    }

    let r = BG_R + t2 * (cr - BG_R);
    let g = BG_G + t2 * (cg - BG_G);
    let b = BG_B + t2 * (cb - BG_B);

    // White glow bloom at peaks
    if (t > 0.55) {
      const glow = (t - 0.55) * 2.2;
      const g2   = glow * glow;
      r += g2 * (255 - cr);
      g += g2 * (255 - cg);
      b += g2 * (255 - cb);
    }

    data[pi]     = Math.min(255, r) | 0;
    data[pi + 1] = Math.min(255, g) | 0;
    data[pi + 2] = Math.min(255, b) | 0;
    data[pi + 3] = 255;
  }

  ctx.putImageData(imgData, 0, 0);
}

// ── Main loop ───────────────────────────────────────────────────────────
function loop() {
  step(); step(); step();
  draw();
  requestAnimationFrame(loop);
}

// ── Input: click = wave, drag = barrier, right = erase ──────────────────
let mouseDown = false;
let hasMoved  = false;
let erasing   = false;
let startX    = 0, startY = 0;
const MOVE_THRESHOLD = 4;

function toGrid(cx, cy) {
  return [Math.floor(cx / CELL), Math.floor(cy / CELL)];
}

canvas.addEventListener('mousedown', function(e) {
  e.preventDefault();
  mouseDown = true;
  hasMoved  = false;
  startX    = e.clientX;
  startY    = e.clientY;
  erasing   = (e.button === 2);

  if (erasing) {
    const g = toGrid(e.clientX, e.clientY);
    paintBarrier(g[0], g[1], true);
    if (typeof SFX !== 'undefined') SFX.play('pop', 60);
  }
});

canvas.addEventListener('mousemove', function(e) {
  if (!mouseDown) return;
  const dx = e.clientX - startX;
  const dy = e.clientY - startY;
  if (!hasMoved && Math.abs(dx) < MOVE_THRESHOLD && Math.abs(dy) < MOVE_THRESHOLD) return;

  hasMoved = true;
  const g  = toGrid(e.clientX, e.clientY);
  paintBarrier(g[0], g[1], erasing);
  if (typeof SFX !== 'undefined') SFX.play(erasing ? 'pop' : 'tick', 90);
});

canvas.addEventListener('mouseup', function(e) {
  if (!hasMoved && !erasing) {
    const g = toGrid(e.clientX, e.clientY);
    addPulse(g[0], g[1]);
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }
  mouseDown = false;
  hasMoved  = false;
  erasing   = false;
});
canvas.addEventListener('mouseleave', function() { mouseDown = false; hasMoved = false; erasing = false; });
canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

// ── Touch: tap = wave, drag = barrier ───────────────────────────────────
let touchStart   = null;
let touchMoved   = false;

canvas.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 1) {
    touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    touchMoved = false;
  }
}, { passive: false });

canvas.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!touchStart || e.touches.length !== 1) return;
  const dx = e.touches[0].clientX - touchStart.x;
  const dy = e.touches[0].clientY - touchStart.y;
  if (!touchMoved && Math.abs(dx) < MOVE_THRESHOLD && Math.abs(dy) < MOVE_THRESHOLD) return;

  touchMoved = true;
  const g = toGrid(e.touches[0].clientX, e.touches[0].clientY);
  paintBarrier(g[0], g[1], false);
  if (typeof SFX !== 'undefined') SFX.play('tick', 90);
}, { passive: false });

canvas.addEventListener('touchend', function(e) {
  if (touchStart && !touchMoved) {
    const g = toGrid(touchStart.x, touchStart.y);
    addPulse(g[0], g[1]);
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }
  touchStart = null;
  touchMoved = false;
});

// ── Buttons ─────────────────────────────────────────────────────────────
document.getElementById('btnReset').addEventListener('click', function() {
  resetSim();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

// ── Boot ────────────────────────────────────────────────────────────────
resetSim();
loop();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
