<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'CRYSTALLIZE - PULSE';
$pageDesc = '結晶成長シミュレーション。拡散律速凝集に六方対称を課したデンドライト構造の生成。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';
$extraStyle = '
body { overflow:hidden; cursor:crosshair; background:#0a0a0f; }
canvas {
  display:block; position:fixed; top:0; left:0;
  width:100%; height:100%; z-index:0;
  image-rendering:pixelated;
  image-rendering:crisp-edges;
}
.hud {
  position:fixed; top:1rem; left:1.5rem;
  font-family:"JetBrains Mono",monospace; font-size:0.7rem;
  color:rgba(255,255,255,0.35); line-height:1.9; z-index:10; pointer-events:none;
}
.hud .va { color:#00f5d4; }
.hud .vb { color:#c455ff; }
.ctrl {
  position:fixed; bottom:1.5rem; right:1.5rem; z-index:10;
  display:flex; flex-direction:column; gap:0.45rem; align-items:flex-end;
}
.ctrl button {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); font-family:"JetBrains Mono",monospace;
  font-size:0.6rem; padding:0.3rem 0.7rem; cursor:pointer;
  letter-spacing:0.12em; transition:all 0.2s;
}
.ctrl button:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
.ctrl button.active { background:rgba(0,245,212,0.22); color:#00f5d4; border-color:#00f5d4; }
.ctrl label {
  font-family:"JetBrains Mono",monospace; font-size:0.6rem;
  color:rgba(255,255,255,0.35); display:flex; align-items:center; gap:0.5rem;
}
.ctrl input[type=range] { width:90px; accent-color:#00f5d4; }
.mode-row { display:flex; gap:0.35rem; flex-wrap:wrap; justify-content:flex-end; }
.title {
  position:fixed; bottom:1.5rem; left:1.5rem;
  font-family:"Orbitron",sans-serif; font-size:0.7rem;
  color:rgba(255,255,255,0.1); letter-spacing:0.2em; z-index:10; pointer-events:none;
}
@media (max-width:768px) {
  .hud { font-size:0.55rem; top:0.8rem; left:1rem; }
  .ctrl { bottom:1rem; right:1rem; gap:0.35rem; }
  .ctrl button { font-size:0.5rem; padding:0.25rem 0.5rem; }
  .ctrl input[type=range] { width:70px; }
  .ctrl label { font-size:0.5rem; }
  .title { font-size:0.5rem; bottom:0.8rem; left:0.8rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  <div>MORPH <span class="va" id="hMode">BRANCH</span></div>
  <div>SYMM <span class="vb" id="hSymm">6</span></div>
  <div>CELLS <span class="va" id="hCells">0</span></div>
  <div>RADIUS <span class="va" id="hRadius">0</span></div>
</div>

<div class="ctrl">
  <div class="mode-row">
    <button class="active" data-mode="0">BRANCH</button>
    <button data-mode="1">STELLAR</button>
    <button data-mode="2">PLATE</button>
    <button data-mode="3">NEEDLE</button>
  </div>
  <div class="mode-row">
    <button class="active" data-symm="6">6-FOLD</button>
    <button data-symm="4">4-FOLD</button>
    <button data-symm="8">8-FOLD</button>
    <button data-symm="5">5-FOLD</button>
  </div>
  <label>SPEED <input type="range" id="slSpeed" min="5" max="80" step="5" value="25"></label>
  <label>PALETTE <input type="range" id="slPalette" min="0" max="3" step="1" value="0"></label>
  <button id="btnPause">PAUSE</button>
  <button id="btnReset">RESET</button>
</div>

<div class="title">CRYSTALLIZE</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag',  'label' => 'barrier'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// ── Adaptive grid ──────────────────────────────────────────────────
const TARGET_CELLS = 220000;
const screenMin = Math.min(window.innerWidth, window.innerHeight);
const GSIZE = Math.min(500, Math.max(280, Math.round(Math.sqrt(TARGET_CELLS))));
const GCX = GSIZE >> 1, GCY = GSIZE >> 1;

let frozen   = new Uint8Array(GSIZE * GSIZE);
let frozenT  = new Uint32Array(GSIZE * GSIZE);
let frozenD  = new Float32Array(GSIZE * GSIZE);  // distance from center at freeze time
let stepCount = 0;
let iceCount  = 0;
let maxRadius = 0;
let growing   = true;
let paused    = false;
let fadeAlpha = 0;

// ── Barrier array for user-drawn walls ─────────────────────────────
let walls = new Uint8Array(GSIZE * GSIZE);

// ── Parameters ─────────────────────────────────────────────────────
const MODES = ['BRANCH', 'STELLAR', 'PLATE', 'NEEDLE'];
const STICK = [1.0, 0.45, 0.10, 0.85];
const STICK_NEIGHBOR = [1, 2, 3, 1];  // min neighbors to stick for each mode
let modeIdx = 0;
let symmetry = 6;
let walkersPerFrame = 25;
let paletteIdx = 0;

// ── Color palettes (start → end) ──────────────────────────────────
const PALETTES = [
  { name:'AURORA',  a:[123,44,191], b:[0,245,212] },   // purple → teal (classic)
  { name:'DEEP',    a:[20,60,180],  b:[0,245,212] },    // deep blue → teal
  { name:'CRYSTAL', a:[80,30,160],  b:[180,220,255] },  // violet → ice white
  { name:'EMBER',   a:[123,44,191], b:[60,200,180] },   // purple → muted teal
];

// ── Helpers ────────────────────────────────────────────────────────
const idx   = (x, y) => y * GSIZE + x;
const valid = (x, y) => x >= 0 && x < GSIZE && y >= 0 && y < GSIZE;

function countAdj(x, y) {
  let count = 0;
  for (let dy = -1; dy <= 1; dy++)
    for (let dx = -1; dx <= 1; dx++) {
      if (!dx && !dy) continue;
      const nx = x + dx, ny = y + dy;
      if (valid(nx, ny) && frozen[idx(nx, ny)]) count++;
    }
  return count;
}

// ── Freeze with n-fold rotational symmetry ─────────────────────────
function freeze(x, y) {
  stepCount++;
  const dx = x - GCX, dy = y - GCY;
  const r  = Math.sqrt(dx * dx + dy * dy);
  if (r > maxRadius) maxRadius = r;

  for (let k = 0; k < symmetry; k++) {
    const a  = k * Math.PI * 2 / symmetry;
    const nx = Math.round(GCX + dx * Math.cos(a) - dy * Math.sin(a));
    const ny = Math.round(GCY + dx * Math.sin(a) + dy * Math.cos(a));
    if (valid(nx, ny)) {
      const i = idx(nx, ny);
      if (!frozen[i] && !walls[i]) {
        frozen[i]  = 1;
        frozenT[i] = stepCount;
        frozenD[i] = r;
        iceCount++;
      }
    }
  }
}

function reset() {
  frozen.fill(0); frozenT.fill(0); frozenD.fill(0); walls.fill(0);
  stepCount = 0; iceCount = 0; maxRadius = 0;
  growing = true; fadeAlpha = 0;
  freeze(GCX, GCY);
  // pre-warm: show initial crystal immediately
  for (let i = 0; i < 300; i++) stepWalker();
}

// ── DLA walker ─────────────────────────────────────────────────────
function stepWalker() {
  if (maxRadius >= GCX - 8) { growing = false; return; }

  const p   = STICK[modeIdx];
  const minN = STICK_NEIGHBOR[modeIdx];
  const spR = maxRadius + 3 + Math.random() * 8;
  const a0  = Math.random() * Math.PI * 2;
  let wx = GCX + spR * Math.cos(a0);
  let wy = GCY + spR * Math.sin(a0);
  const kR2 = (spR + 50) * (spR + 50);

  for (let s = 0; s < 6000; s++) {
    const ddx = wx - GCX, ddy = wy - GCY;
    if (ddx * ddx + ddy * ddy > kR2) return;

    // Adaptive step size: big steps far away, small near crystal
    const dist = Math.sqrt(ddx * ddx + ddy * ddy);
    const gap  = dist - maxRadius;
    const step = Math.min(10, Math.max(1, (gap - 1.5) | 0));
    const a    = Math.random() * Math.PI * 2;
    wx += step * Math.cos(a);
    wy += step * Math.sin(a);

    const ix = Math.round(wx), iy = Math.round(wy);
    if (!valid(ix, iy)) return;
    if (walls[idx(ix, iy)]) return;  // walker dies on wall
    if (!frozen[idx(ix, iy)] && countAdj(ix, iy) >= minN && Math.random() < p) {
      freeze(ix, iy);
      SFX.play('tick', 80);
      return;
    }
  }
}

// ── Offscreen buffer ───────────────────────────────────────────────
const off  = document.createElement('canvas');
off.width  = GSIZE;
off.height = GSIZE;
const octx = off.getContext('2d');

// ── Render ─────────────────────────────────────────────────────────
function render() {
  const W  = canvas.width, H = canvas.height;
  const sc = Math.min(W, H) / GSIZE;
  const ox = (W - GSIZE * sc) / 2;
  const oy = (H - GSIZE * sc) / 2;

  const img = octx.createImageData(GSIZE, GSIZE);
  const d   = img.data;
  const mx  = stepCount || 1;
  const pal = PALETTES[paletteIdx];
  const maxD = maxRadius || 1;

  for (let y = 0; y < GSIZE; y++)
    for (let x = 0; x < GSIZE; x++) {
      const i  = idx(x, y);
      const p4 = i * 4;

      if (walls[i]) {
        // User-drawn walls: subtle purple
        d[p4] = 40; d[p4+1] = 20; d[p4+2] = 60; d[p4+3] = 255;
        continue;
      }

      if (frozen[i]) {
        // Color by distance from center (gives concentric ring feel)
        const td = frozenD[i] / maxD;           // 0=center, 1=edge
        const tt = frozenT[i] / mx;             // 0=early, 1=late
        const t  = td * 0.7 + tt * 0.3;         // blend spatial + temporal
        const tc = Math.min(1, Math.max(0, t));

        d[p4]   = Math.round(pal.a[0] + (pal.b[0] - pal.a[0]) * tc);
        d[p4+1] = Math.round(pal.a[1] + (pal.b[1] - pal.a[1]) * tc);
        d[p4+2] = Math.round(pal.a[2] + (pal.b[2] - pal.a[2]) * tc);
        d[p4+3] = 255;

        // Bright tip glow: recently frozen cells near edge shine
        if (tt > 0.92 && td > 0.8) {
          const glow = (tt - 0.92) / 0.08;
          d[p4]   = Math.min(255, d[p4]   + glow * (255 - d[p4])   * 0.5) | 0;
          d[p4+1] = Math.min(255, d[p4+1] + glow * (255 - d[p4+1]) * 0.5) | 0;
          d[p4+2] = Math.min(255, d[p4+2] + glow * (255 - d[p4+2]) * 0.5) | 0;
        }
      } else {
        d[p4] = 10; d[p4+1] = 10; d[p4+2] = 15; d[p4+3] = 255;
      }
    }
  octx.putImageData(img, 0, 0);

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  // Glow layer (blur underneath)
  const blurPx = Math.min(8, Math.round(sc * 2.5));
  ctx.filter      = 'blur(' + blurPx + 'px)';
  ctx.globalAlpha = 0.45;
  ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);
  ctx.filter      = 'none';
  ctx.globalAlpha = 1.0;
  ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);

  // Fade overlay
  if (fadeAlpha > 0) {
    ctx.fillStyle = 'rgba(10,10,15,' + fadeAlpha + ')';
    ctx.fillRect(0, 0, W, H);
  }
}

// ── Resize ─────────────────────────────────────────────────────────
function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
window.addEventListener('resize', resize);
resize();

// ── HUD ────────────────────────────────────────────────────────────
var hMode   = document.getElementById('hMode');
var hSymm   = document.getElementById('hSymm');
var hCells  = document.getElementById('hCells');
var hRadius = document.getElementById('hRadius');
var frameN  = 0;

function updateHUD() {
  if (++frameN % 8 !== 0) return;
  hMode.textContent   = MODES[modeIdx];
  hSymm.textContent   = symmetry;
  hCells.textContent  = iceCount;
  hRadius.textContent = maxRadius.toFixed(0);
}

// ── Animation loop ─────────────────────────────────────────────────
function animate() {
  if (!paused) {
    if (!growing) {
      fadeAlpha = Math.min(1, fadeAlpha + 0.008);
      if (fadeAlpha >= 1) reset();
    } else {
      for (var i = 0; i < walkersPerFrame; i++) stepWalker();
    }
  }
  render();
  updateHUD();
  requestAnimationFrame(animate);
}

// ── Coordinate helper ──────────────────────────────────────────────
function toGrid(clientX, clientY) {
  var sc = Math.min(canvas.width, canvas.height) / GSIZE;
  var ox = (canvas.width  - GSIZE * sc) / 2;
  var oy = (canvas.height - GSIZE * sc) / 2;
  return [Math.round((clientX - ox) / sc), Math.round((clientY - oy) / sc)];
}

// ── Mouse: click=seed, drag=wall, right=erase ──────────────────────
var mouseDown = false, hasMoved = false, erasing = false;
var startX = 0, startY = 0;
var MOVE_TH = 4;

canvas.addEventListener('mousedown', function(e) {
  e.preventDefault();
  mouseDown = true; hasMoved = false;
  startX = e.clientX; startY = e.clientY;
  erasing = (e.button === 2);
  if (erasing) {
    var g = toGrid(e.clientX, e.clientY);
    paintWall(g[0], g[1], true);
    SFX.play('pop', 60);
  }
});

canvas.addEventListener('mousemove', function(e) {
  if (!mouseDown) return;
  if (!hasMoved && Math.abs(e.clientX - startX) < MOVE_TH && Math.abs(e.clientY - startY) < MOVE_TH) return;
  hasMoved = true;
  var g = toGrid(e.clientX, e.clientY);
  paintWall(g[0], g[1], erasing);
  SFX.play(erasing ? 'pop' : 'tick', 90);
});

canvas.addEventListener('mouseup', function(e) {
  if (!hasMoved && !erasing) {
    var g = toGrid(e.clientX, e.clientY);
    if (valid(g[0], g[1]) && !frozen[idx(g[0], g[1])] && !walls[idx(g[0], g[1])]) {
      freeze(g[0], g[1]);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  }
  mouseDown = false; hasMoved = false; erasing = false;
});

canvas.addEventListener('mouseleave', function() { mouseDown = false; hasMoved = false; erasing = false; });
canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

// ── Touch: tap=seed, drag=wall ─────────────────────────────────────
var touchStart = null, touchMoved = false;

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
  if (!touchMoved && Math.abs(e.touches[0].clientX - touchStart.x) < MOVE_TH
      && Math.abs(e.touches[0].clientY - touchStart.y) < MOVE_TH) return;
  touchMoved = true;
  var g = toGrid(e.touches[0].clientX, e.touches[0].clientY);
  paintWall(g[0], g[1], false);
  SFX.play('tick', 90);
}, { passive: false });

canvas.addEventListener('touchend', function(e) {
  if (touchStart && !touchMoved) {
    var g = toGrid(touchStart.x, touchStart.y);
    if (valid(g[0], g[1]) && !frozen[idx(g[0], g[1])] && !walls[idx(g[0], g[1])]) {
      freeze(g[0], g[1]);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  }
  touchStart = null; touchMoved = false;
});

// ── Paint / erase walls ────────────────────────────────────────────
function paintWall(gx, gy, erase) {
  var r = Math.max(2, Math.ceil(GSIZE * 0.012));
  for (var dy = -r; dy <= r; dy++) {
    for (var dx = -r; dx <= r; dx++) {
      var x = gx + dx, y = gy + dy;
      if (!valid(x, y)) continue;
      if (dx * dx + dy * dy > r * r) continue;
      var i = idx(x, y);
      if (erase) { walls[i] = 0; }
      else if (!frozen[i]) { walls[i] = 1; }
    }
  }
}

// ── Controls ───────────────────────────────────────────────────────
// Morphology mode
document.querySelectorAll('[data-mode]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    modeIdx = parseInt(btn.dataset.mode);
    reset();
    SFX.play('toggle');
  });
});

// Symmetry
document.querySelectorAll('[data-symm]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-symm]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    symmetry = parseInt(btn.dataset.symm);
    reset();
    SFX.play('toggle');
  });
});

// Speed slider
document.getElementById('slSpeed').addEventListener('input', function(e) {
  walkersPerFrame = parseInt(e.target.value);
});

// Palette slider
document.getElementById('slPalette').addEventListener('input', function(e) {
  paletteIdx = parseInt(e.target.value);
});

// Pause
document.getElementById('btnPause').addEventListener('click', function() {
  paused = !paused;
  this.textContent = paused ? 'RESUME' : 'PAUSE';
  SFX.play('toggle');
});

// Reset
document.getElementById('btnReset').addEventListener('click', function() {
  reset();
  SFX.play('click');
});

// ── Start ──────────────────────────────────────────────────────────
reset();
requestAnimationFrame(animate);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
