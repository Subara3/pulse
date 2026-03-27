<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('quantum');

$pageName = 'quantum';
$pageTitle = 'QUANTUM - PULSE';
$pageDesc = '量子波束のトンネル効果。シュレーディンガー方程式で粒子が障壁をすり抜ける。';
$pageCanonical = 'https://pulse.subara3.com/quantum.php';
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
.info {
  position:fixed; bottom:1.5rem; left:1.5rem;
  font-size:0.65rem; color:rgba(0,245,212,0.3);
  letter-spacing:0.1em; pointer-events:none; line-height:1.9;
  font-family:monospace;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="controls">
  <button class="btn" id="btnMomentum">MOMENTUM: MID</button>
  <button class="btn" id="btnReset">RESET</button>
</div>

<div class="info">
  <div id="infoNorm">NORM: 1.000</div>
  <div id="infoK">k₀: 0.40</div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'barrier'],
  ['type' => 'drag',  'label' => 'draw'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx    = canvas.getContext('2d');
const btnMomentum = document.getElementById('btnMomentum');
const btnReset    = document.getElementById('btnReset');
const infoNorm    = document.getElementById('infoNorm');
const infoK       = document.getElementById('infoK');

// ── Simulation parameters ─────────────────────────────────────────────────
const N  = 512;   // grid points
const dx = 1.0;
const dt = 0.15;  // timestep (stable for staggered leapfrog with dx=1)
const STEPS_PER_FRAME = 3;

const MOMENTUM_PRESETS = [
  { label: 'SLOW', k0: 0.25 },
  { label: 'MID',  k0: 0.40 },
  { label: 'FAST', k0: 0.72 },
];
let momentumIdx = 1;

const SIGMA   = 22;       // wave packet width
const X0      = N / 4;    // starting position

const V_BARRIER  = 0.12;  // barrier height (> E_MID=0.08, < E_FAST=0.26)
const BARRIER_W  = 14;    // default barrier width in grid points

// ── State arrays ──────────────────────────────────────────────────────────
let R    = new Float64Array(N); // Re(ψ) at integer t
let I    = new Float64Array(N); // Im(ψ) at half-integer t
let I_tmp= new Float64Array(N);
let V    = new Float64Array(N); // potential

// ── Canvas ────────────────────────────────────────────────────────────────
let W, H, cy, probScale, psiScale;

function resize() {
  W = canvas.width  = window.innerWidth;
  H = canvas.height = window.innerHeight;
  cy        = H * 0.58;
  probScale = H * 19;    // |ψ|² peak ~ 0.016 → display at ~H*0.30 above cy
  psiScale  = H * 2.4;   // Re/Im amplitude ~ 0.13 → display at ~H*0.31 above cy
}
window.addEventListener('resize', resize);

// ── Init wave packet ──────────────────────────────────────────────────────
function initWave() {
  const k0 = MOMENTUM_PRESETS[momentumIdx].k0;
  R.fill(0); I.fill(0); I_tmp.fill(0);

  let norm = 0;
  for (let i = 0; i < N; i++) {
    const g = Math.exp(-(i - X0) ** 2 / (2 * SIGMA * SIGMA));
    R[i] = g * Math.cos(k0 * i);
    I[i] = g * Math.sin(k0 * i);
    norm += R[i] * R[i] + I[i] * I[i];
  }
  const invNorm = 1 / Math.sqrt(norm * dx);
  for (let i = 0; i < N; i++) { R[i] *= invNorm; I[i] *= invNorm; }

  // Stagger I to t = -dt/2
  for (let i = 1; i < N - 1; i++) {
    const lapR = R[i + 1] - 2 * R[i] + R[i - 1]; // dx=1
    I[i] -= (dt / 2) * (0.5 * lapR - V[i] * R[i]);
  }
  I[0] = I[N - 1] = 0;
}

// ── Place default barrier ─────────────────────────────────────────────────
function setDefaultBarrier() {
  V.fill(0);
  const bx = Math.round(N * 0.62);
  for (let i = bx; i < bx + BARRIER_W; i++) {
    if (i < N) V[i] = V_BARRIER;
  }
}

// ── Physics step (staggered leapfrog) ─────────────────────────────────────
function step() {
  // I(n+1/2) = I(n-1/2) + dt * ( ½∇²R - V·R )
  for (let i = 1; i < N - 1; i++) {
    const lapR = R[i + 1] - 2 * R[i] + R[i - 1];
    I_tmp[i] = I[i] + dt * (0.5 * lapR - V[i] * R[i]);
  }
  I_tmp[0] = I_tmp[N - 1] = 0;

  // R(n+1) = R(n) + dt * ( -½∇²I(n+½) + V·I(n+½) )
  for (let i = 1; i < N - 1; i++) {
    const lapI = I_tmp[i + 1] - 2 * I_tmp[i] + I_tmp[i - 1];
    R[i] = R[i] + dt * (-0.5 * lapI + V[i] * I_tmp[i]);
  }
  R[0] = R[N - 1] = 0;

  // swap I ↔ I_tmp
  let swap = I; I = I_tmp; I_tmp = swap;
}

// ── Draw ──────────────────────────────────────────────────────────────────
function draw() {
  ctx.clearRect(0, 0, W, H);

  const scx = W / N;

  // Potential barriers: purple vertical bands
  for (let i = 0; i < N; i++) {
    if (V[i] < 0.001) continue;
    const alpha = Math.min(V[i] / V_BARRIER, 1) * 0.32;
    ctx.fillStyle = `rgba(123,44,191,${alpha.toFixed(3)})`;
    ctx.fillRect(i * scx, 0, scx + 1, H);
  }
  // Barrier outlines (bright edge)
  let inBarrier = false;
  for (let i = 0; i < N; i++) {
    const here = V[i] > 0.001;
    if (here && !inBarrier) {
      ctx.fillStyle = 'rgba(160,80,240,0.5)';
      ctx.fillRect(i * scx - 1, 0, 2, H);
    }
    if (!here && inBarrier) {
      ctx.fillStyle = 'rgba(160,80,240,0.5)';
      ctx.fillRect((i - 1) * scx + scx - 1, 0, 2, H);
    }
    inBarrier = here;
  }

  // Axis line
  ctx.strokeStyle = 'rgba(255,255,255,0.07)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(0, cy); ctx.lineTo(W, cy);
  ctx.stroke();

  // Im(ψ) — thin indigo line
  ctx.beginPath();
  ctx.strokeStyle = 'rgba(100,80,240,0.35)';
  ctx.lineWidth = 1;
  for (let i = 0; i < N; i++) {
    const x = i * scx, y = cy - I[i] * psiScale;
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  }
  ctx.stroke();

  // Re(ψ) — purple line
  ctx.beginPath();
  ctx.strokeStyle = 'rgba(160,70,230,0.55)';
  ctx.lineWidth = 1.5;
  for (let i = 0; i < N; i++) {
    const x = i * scx, y = cy - R[i] * psiScale;
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  }
  ctx.stroke();

  // |ψ|² — filled teal area
  ctx.beginPath();
  ctx.moveTo(0, cy);
  for (let i = 0; i < N; i++) {
    const prob = R[i] * R[i] + I[i] * I[i];
    ctx.lineTo(i * scx, cy - prob * probScale);
  }
  ctx.lineTo(W, cy);
  ctx.closePath();

  const grad = ctx.createLinearGradient(0, cy - H * 0.42, 0, cy);
  grad.addColorStop(0,   'rgba(0,245,212,0.85)');
  grad.addColorStop(0.5, 'rgba(0,245,212,0.35)');
  grad.addColorStop(1,   'rgba(0,245,212,0.02)');
  ctx.fillStyle = grad;
  ctx.fill();

  // |ψ|² glow outline
  ctx.beginPath();
  ctx.moveTo(0, cy);
  for (let i = 0; i < N; i++) {
    const prob = R[i] * R[i] + I[i] * I[i];
    ctx.lineTo(i * scx, cy - prob * probScale);
  }
  ctx.strokeStyle = 'rgba(0,245,212,0.75)';
  ctx.lineWidth = 1.8;
  ctx.shadowColor = '#00f5d4';
  ctx.shadowBlur = 10;
  ctx.stroke();
  ctx.shadowBlur = 0;

  // Barrier strength indicator label
  for (let i = 0; i < N; i++) {
    if (V[i] > 0.001 && (i === 0 || V[i - 1] < 0.001)) {
      const bStart = i;
      let bEnd = i;
      while (bEnd < N && V[bEnd] > 0.001) bEnd++;
      const bx = (bStart + (bEnd - bStart) / 2) * scx;
      ctx.fillStyle = 'rgba(160,80,240,0.5)';
      ctx.font = '0.6rem monospace';
      ctx.textAlign = 'center';
      ctx.fillText('V', bx, cy + 14);
    }
  }

  // Norm (quality check)
  let norm = 0;
  for (let i = 0; i < N; i++) norm += R[i] * R[i] + I[i] * I[i];
  infoNorm.textContent = 'NORM: ' + (norm * dx).toFixed(3);
}

// ── Main loop ─────────────────────────────────────────────────────────────
function loop() {
  for (let s = 0; s < STEPS_PER_FRAME; s++) step();
  draw();
  requestAnimationFrame(loop);
}

// ── Input helpers ─────────────────────────────────────────────────────────
let painting = false;
let erasing  = false;

function gridIdx(clientX) {
  return Math.round(clientX / W * (N - 1));
}

function paintBarrier(clientX, erase) {
  const ci  = gridIdx(clientX);
  const rad = Math.ceil(N * 0.018); // ~9 grid points brush radius
  for (let i = ci - rad; i <= ci + rad; i++) {
    if (i >= 0 && i < N) V[i] = erase ? 0 : V_BARRIER;
  }
}

canvas.addEventListener('mousedown', e => {
  e.preventDefault();
  erasing = (e.button === 2);
  painting = true;
  paintBarrier(e.clientX, erasing);
  SFX.play(erasing ? 'pop' : 'tick', 60);
});
canvas.addEventListener('mousemove', e => {
  if (!painting) return;
  paintBarrier(e.clientX, erasing);
  SFX.play(erasing ? 'pop' : 'tick', 60);
});
canvas.addEventListener('mouseup',    () => { painting = false; });
canvas.addEventListener('mouseleave', () => { painting = false; });
canvas.addEventListener('contextmenu', e => e.preventDefault());

canvas.addEventListener('touchstart', e => {
  e.preventDefault();
  painting = true; erasing = false;
  paintBarrier(e.touches[0].clientX, false);
  SFX.play('tick', 60);
}, { passive: false });
canvas.addEventListener('touchmove', e => {
  e.preventDefault();
  if (!painting) return;
  paintBarrier(e.touches[0].clientX, false);
  SFX.play('tick', 80);
}, { passive: false });
canvas.addEventListener('touchend', () => { painting = false; });

// ── Buttons ───────────────────────────────────────────────────────────────
btnMomentum.addEventListener('click', () => {
  momentumIdx = (momentumIdx + 1) % MOMENTUM_PRESETS.length;
  const p = MOMENTUM_PRESETS[momentumIdx];
  btnMomentum.textContent = 'MOMENTUM: ' + p.label;
  infoK.textContent = 'k₀: ' + p.k0.toFixed(2);
  initWave();
  SFX.play('toggle');
});

btnReset.addEventListener('click', () => {
  setDefaultBarrier();
  initWave();
  SFX.play('click');
});

// ── Boot ──────────────────────────────────────────────────────────────────
resize();
setDefaultBarrier();
initWave();
infoK.textContent = 'k₀: ' + MOMENTUM_PRESETS[momentumIdx].k0.toFixed(2);
loop();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
