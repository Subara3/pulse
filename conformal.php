<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('conformal');

$pageName = 'conformal';
$pageTitle = 'CONFORMAL - PULSE';
$pageDesc = "複素関数による等角写像。格子が滑らかに歪む数学的変換を体験する。";
$pageCanonical = 'https://pulse.subara3.com/conformal.php';
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
.eq {
  position:fixed; top:50%; left:1.5rem; transform:translateY(-50%);
  font-size:0.6rem; color:rgba(255,255,255,0.12);
  letter-spacing:0.08em; pointer-events:none; line-height:2.0;
  font-family:monospace; writing-mode:vertical-rl; text-orientation:mixed;
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="controls drawer-controls">
  <button class="btn" id="btnTransform">SQUARE</button>
  <button class="btn" id="btnAuto">AUTO: ON</button>
</div>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<div class="info" id="info">
  <div id="infoEq">f(z) = z²</div>
  <div id="infoBlend">BLEND: 0%</div>
</div>

<div class="eq" id="eqLabel">Re ← → Im</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'blend'],
  ['type' => 'click', 'label' => 'transform'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function() {
'use strict';

const canvas = document.getElementById('canvas');
const ctx    = canvas.getContext('2d');
const btnTransform = document.getElementById('btnTransform');
const btnAuto      = document.getElementById('btnAuto');
const infoEq       = document.getElementById('infoEq');
const infoBlend    = document.getElementById('infoBlend');

// Complex number helpers
function cadd(a, b) { return { re: a.re + b.re, im: a.im + b.im }; }
function cinv(z) {
  const d = z.re * z.re + z.im * z.im;
  if (d < 1e-9) return null;
  return { re: z.re / d, im: -z.im / d };
}
function clerp(a, b, t) {
  return { re: a.re + (b.re - a.re) * t, im: a.im + (b.im - a.im) * t };
}
function cabs2(z) { return z.re * z.re + z.im * z.im; }

const TRANSFORMS = [
  {
    label: 'SQUARE',
    eq: 'f(z) = z²',
    fn: z => ({ re: z.re * z.re - z.im * z.im, im: 2 * z.re * z.im }),
    viewScale: 2.8
  },
  {
    label: 'INVERT',
    eq: 'f(z) = 1/z',
    fn: z => cinv(z),
    viewScale: 2.2
  },
  {
    label: 'SINE',
    eq: 'f(z) = sin(z)',
    fn: z => ({
      re: Math.sin(z.re) * Math.cosh(z.im),
      im: Math.cos(z.re) * Math.sinh(z.im)
    }),
    viewScale: 3.0
  },
  {
    label: 'EXP',
    eq: 'f(z) = exp(z)',
    fn: z => {
      const r = Math.exp(Math.min(z.re, 3.5));
      return { re: r * Math.cos(z.im), im: r * Math.sin(z.im) };
    },
    viewScale: 3.5
  },
  {
    label: 'JOUKOWSKI',
    eq: 'f(z) = z + 1/z',
    fn: z => { const inv = cinv(z); return inv ? cadd(z, inv) : null; },
    viewScale: 2.8
  }
];

let trIdx   = 0;
let blend   = 0;
let autoOn  = true;
let autoT   = 0;
let dragging = false;
let lastY   = 0;

function lerp(a, b, t) { return a + (b - a) * t; }

function resize() {
  canvas.width  = window.innerWidth;
  canvas.height = window.innerHeight;
}
window.addEventListener('resize', resize);
resize();

// Convert complex number to canvas pixel coordinates
function toScreen(z, scale) {
  const W = canvas.width, H = canvas.height;
  const s = Math.min(W, H) / (scale * 2);
  return [W / 2 + z.re * s, H / 2 - z.im * s];
}

const GRID_RANGE  = 2.4;
const GRID_STEP   = 0.4;
const SAMPLES     = 120;
const CLIP_DIST2  = 400; // |z|² > 20² → skip

function strokePath(pts) {
  ctx.beginPath();
  let penDown = false;
  for (const p of pts) {
    if (p === null) { penDown = false; continue; }
    if (!penDown) { ctx.moveTo(p[0], p[1]); penDown = true; }
    else ctx.lineTo(p[0], p[1]);
  }
  ctx.stroke();
}

function drawGrid(t) {
  const W = canvas.width, H = canvas.height;
  const tr = TRANSFORMS[trIdx];

  ctx.clearRect(0, 0, W, H);

  // Viewport scale blends between identity scale and transform scale
  const baseScale = GRID_RANGE + 0.3;
  const scale = lerp(baseScale, tr.viewScale, t);

  const n = Math.round(GRID_RANGE / GRID_STEP);
  const maxAbs = GRID_RANGE + GRID_STEP;

  // Horizontal lines: Im = const
  for (let k = -n; k <= n; k++) {
    const imVal = k * GRID_STEP;
    const pts = [];

    for (let i = 0; i <= SAMPLES; i++) {
      const reVal = lerp(-GRID_RANGE, GRID_RANGE, i / SAMPLES);
      const z  = { re: reVal, im: imVal };
      const fz = tr.fn(z);

      if (!fz || !isFinite(fz.re) || !isFinite(fz.im)) { pts.push(null); continue; }

      const b = (t < 1e-4) ? z : (t > 1 - 1e-4) ? fz : clerp(z, fz, t);

      if (cabs2(b) > CLIP_DIST2) { pts.push(null); continue; }

      pts.push(toScreen(b, scale));
    }

    const fade = 1 - Math.abs(imVal) / maxAbs;
    const alpha = 0.18 + 0.52 * fade;
    ctx.strokeStyle = `rgba(0,220,195,${alpha.toFixed(2)})`;
    ctx.lineWidth = (k === 0) ? 1.6 : 0.75;
    strokePath(pts);
  }

  // Vertical lines: Re = const
  for (let k = -n; k <= n; k++) {
    const reVal = k * GRID_STEP;
    const pts = [];

    for (let i = 0; i <= SAMPLES; i++) {
      const imVal = lerp(-GRID_RANGE, GRID_RANGE, i / SAMPLES);
      const z  = { re: reVal, im: imVal };
      const fz = tr.fn(z);

      if (!fz || !isFinite(fz.re) || !isFinite(fz.im)) { pts.push(null); continue; }

      const b = (t < 1e-4) ? z : (t > 1 - 1e-4) ? fz : clerp(z, fz, t);

      if (cabs2(b) > CLIP_DIST2) { pts.push(null); continue; }

      pts.push(toScreen(b, scale));
    }

    const fade = 1 - Math.abs(reVal) / maxAbs;
    const alpha = 0.15 + 0.45 * fade;
    ctx.strokeStyle = `rgba(150,70,220,${alpha.toFixed(2)})`;
    ctx.lineWidth = (k === 0) ? 1.6 : 0.75;
    strokePath(pts);
  }
}

// Animation state
let lastTime = 0;

function frame(now) {
  if (!lastTime) lastTime = now;
  const dt = Math.min((now - lastTime) / 1000, 0.05);
  lastTime = now;

  if (autoOn && !dragging) {
    autoT += dt;
    const period = 8.0;
    const tp = (autoT % period) / period;
    // 0→3s ramp up, 3→4s hold, 4→7s ramp down, 7→8s hold
    if      (tp < 0.375) blend = tp / 0.375;
    else if (tp < 0.5)   blend = 1.0;
    else if (tp < 0.875) blend = 1.0 - (tp - 0.5) / 0.375;
    else                 blend = 0.0;
  }

  drawGrid(blend);

  infoBlend.textContent = 'BLEND: ' + Math.round(blend * 100) + '%';

  requestAnimationFrame(frame);
}

// Mouse / Touch controls
canvas.addEventListener('mousedown', e => {
  dragging = true;
  lastY = e.clientY;
  if (!autoOn) blend = Math.max(0, Math.min(1, e.clientY / canvas.height));
});

canvas.addEventListener('mousemove', e => {
  if (!dragging) return;
  blend = Math.max(0, Math.min(1, e.clientY / canvas.height));
});

canvas.addEventListener('mouseup',    () => { dragging = false; });
canvas.addEventListener('mouseleave', () => { dragging = false; });

canvas.addEventListener('touchstart', e => {
  e.preventDefault();
  dragging = true;
  blend = Math.max(0, Math.min(1, e.touches[0].clientY / canvas.height));
}, { passive: false });

canvas.addEventListener('touchmove', e => {
  e.preventDefault();
  if (!dragging) return;
  blend = Math.max(0, Math.min(1, e.touches[0].clientY / canvas.height));
}, { passive: false });

canvas.addEventListener('touchend', () => { dragging = false; });

// Button: cycle transform
btnTransform.addEventListener('click', () => {
  trIdx = (trIdx + 1) % TRANSFORMS.length;
  blend = 0;
  autoT = 0;
  const tr = TRANSFORMS[trIdx];
  btnTransform.textContent = tr.label;
  infoEq.textContent = tr.eq;
  SFX.play('toggle');
});

// Button: toggle auto
btnAuto.addEventListener('click', () => {
  autoOn = !autoOn;
  btnAuto.textContent = 'AUTO: ' + (autoOn ? 'ON' : 'OFF');
  SFX.play('click');
});

requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
