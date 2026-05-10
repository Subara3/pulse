<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('pendulum');

$pageName = 'pendulum';
$pageTitle = 'PENDULUM - PULSE';
$pageDesc = '振り子が揺れる。衝突、共振、二重振り子。';
$pageCanonical = 'https://pulse.subara3.com/pendulum.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: grab; }
#canvas:active { cursor: grabbing; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: right; line-height: 1.6; }
.stats .v { color: var(--accent); }
.hint { top: 5rem; left: 1.5rem; color: rgba(255,255,255,0.25); font-size: 0.6rem; line-height: 1.8; transition: opacity 1s; }

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .stats { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
  .hint { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
  ['type' => 'drag', 'label' => 'drag'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';

?>

<canvas id="canvas"></canvas>
<div class="ui title">PENDULUM</div>
<div class="ui stats">
  <div>mode: <span class="v" id="modeDisplay">single</span></div>
  <div>count: <span class="v" id="countDisplay">5</span></div>
  <div>energy: <span class="v" id="energyDisplay">—</span></div>
</div>
<div class="ui hint" id="hint">
  Drag to swing<br>
  Double-click to add<br>
  Right-click to remove
</div>
<div id="controls" class="drawer-controls">
  <div class="slider-group">
    <label>Gravity <input type="range" id="gravitySlider" min="0.1" max="2" step="0.1" value="0.5"></label>
    <label>Damping <input type="range" id="dampingSlider" min="0.99" max="1" step="0.001" value="0.999"></label>
    <label>Length <input type="range" id="lengthSlider" min="0.5" max="1.5" step="0.05" value="1.0"></label>
  </div>
  <div class="mode-group">
    <button class="control-btn active" data-mode="single">SINGLE</button>
    <button class="control-btn" data-mode="newton">NEWTON</button>
    <button class="control-btn" data-mode="double">DOUBLE</button>
    <button class="control-btn" data-mode="wave">WAVE</button>
  </div>
  <div class="action-group">
    <button class="control-btn active" id="collisionBtn">COLLISION</button>
    <button class="control-btn active" id="soundBtn">SOUND</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(() => {
'use strict';

// ─── Canvas ───
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
let W, H;

function resize() {
  W = canvas.width = window.innerWidth;
  H = canvas.height = window.innerHeight;
}
resize();
window.addEventListener('resize', () => { resize(); reset(); });

// ─── State ───
let mode = 'single';
let gravity = 0.5;
let damping = 0.999;
let lengthScale = 1.0;
let collisionOn = true;
let soundOn = true;
let pendulums = [];
let doublePendulums = [];
let dragging = null;

const SUBSTEPS = 8;

// ─── Audio ───
let audioCtx = null;
let lastSndT = 0;

function ensureAudio() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  if (audioCtx.state === 'suspended') audioCtx.resume();
}
['click','keydown','touchstart'].forEach(e =>
  document.addEventListener(e, function f() { ensureAudio(); document.removeEventListener(e, f); }, { once: true })
);

function playHit(strength) {
  if (!soundOn || !audioCtx || audioCtx.state !== 'running') return;
  const now = audioCtx.currentTime;
  if (now - lastSndT < 0.06) return;
  lastSndT = now;
  const v = Math.min(strength, 1) * 0.03;
  const f = 250 + 250 * Math.min(strength, 1);
  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  osc.connect(gain); gain.connect(audioCtx.destination);
  osc.type = 'sine';
  osc.frequency.setValueAtTime(f, now);
  osc.frequency.exponentialRampToValueAtTime(80, now + 0.06);
  gain.gain.setValueAtTime(v, now);
  gain.gain.exponentialRampToValueAtTime(0.001, now + 0.06);
  osc.start(now); osc.stop(now + 0.06);
}

// ─── Colors ───
const PALETTE = [
  [0,245,212], [123,44,191], [255,100,100],
  [100,200,255], [255,200,100], [100,255,150], [255,150,200]
];
function rgb(c, a) { return `rgba(${c[0]},${c[1]},${c[2]},${a})`; }

// ═══════════════════════════════════════════
//  Single Pendulum (Verlet integration)
// ═══════════════════════════════════════════
class Pendulum {
  constructor(ox, len, angle, color) {
    this.ox = ox;
    this.oy = 80;
    this.len = len;
    this.angle = angle;
    this.prevAngle = angle;
    this.color = color;
    this.dragging = false;
    this.trail = [];
  }

  get bobX() { return this.ox + this.len * Math.sin(this.angle); }
  get bobY() { return this.oy + this.len * Math.cos(this.angle); }

  step(dt) {
    if (this.dragging) return;
    // Störmer-Verlet
    const accel = -(gravity / this.len) * Math.sin(this.angle);
    const next = 2 * this.angle - this.prevAngle + accel * dt * dt;
    this.prevAngle = this.angle + (1 - damping) * (this.angle - this.prevAngle);
    this.angle = next;
  }

  energy() {
    const omega = (this.angle - this.prevAngle);
    const h = this.len * (1 - Math.cos(this.angle));
    return gravity * h + 0.5 * this.len * omega * omega * this.len;
  }

  updateTrail() {
    this.trail.push({ x: this.bobX, y: this.bobY });
    if (this.trail.length > 50) this.trail.shift();
  }

  draw() {
    const bx = this.bobX, by = this.bobY;
    const c = this.color;
    const r = 16;

    // Trail
    if (this.trail.length > 2) {
      ctx.beginPath();
      ctx.moveTo(this.trail[0].x, this.trail[0].y);
      for (let i = 1; i < this.trail.length; i++) {
        ctx.lineTo(this.trail[i].x, this.trail[i].y);
      }
      ctx.strokeStyle = rgb(c, 0.12);
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }

    // String
    ctx.beginPath();
    ctx.moveTo(this.ox, this.oy);
    ctx.lineTo(bx, by);
    ctx.strokeStyle = 'rgba(255,255,255,0.2)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Pivot
    ctx.beginPath();
    ctx.arc(this.ox, this.oy, 3, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.35)';
    ctx.fill();

    // Glow
    const glow = ctx.createRadialGradient(bx, by, r * 0.3, bx, by, r * 2.5);
    glow.addColorStop(0, rgb(c, this.dragging ? 0.2 : 0.07));
    glow.addColorStop(1, rgb(c, 0));
    ctx.beginPath();
    ctx.arc(bx, by, r * 2.5, 0, Math.PI * 2);
    ctx.fillStyle = glow;
    ctx.fill();

    // Bob
    const grad = ctx.createRadialGradient(bx - r * 0.25, by - r * 0.25, r * 0.1, bx, by, r);
    grad.addColorStop(0, rgb(c, 1));
    grad.addColorStop(0.7, rgb(c, 0.8));
    grad.addColorStop(1, rgb([c[0]*0.3, c[1]*0.3, c[2]*0.3], 0.9));
    ctx.beginPath();
    ctx.arc(bx, by, r, 0, Math.PI * 2);
    ctx.fillStyle = grad;
    ctx.fill();

    // Highlight
    ctx.beginPath();
    ctx.arc(bx - r * 0.22, by - r * 0.22, r * 0.28, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.2)';
    ctx.fill();
  }
}

// ═══════════════════════════════════════════
//  Double Pendulum (RK4 integration)
// ═══════════════════════════════════════════
class DoublePendulum {
  constructor(ox, l1, l2, a1, a2, color) {
    this.ox = ox;
    this.oy = Math.min(120, H * 0.15);
    this.l1 = l1; this.l2 = l2;
    this.a1 = a1; this.a2 = a2;
    this.w1 = 0;  this.w2 = 0;
    this.color = color;
    this.trail = [];
  }

  // Equations of motion for double pendulum
  deriv(a1, a2, w1, w2) {
    const g = gravity * 20;
    const da = a1 - a2;
    const cd = Math.cos(da), sd = Math.sin(da);
    const denom = 2 - cd * cd; // simplified for m1=m2=1
    const alpha1 = (-g * (2 * Math.sin(a1) - cd * Math.sin(a2))
                    - sd * (w2 * w2 + w1 * w1 * cd)) / (this.l1 * denom);
    const alpha2 = (g * (2 * Math.cos(a1) * sd - 2 * Math.sin(a2) + Math.sin(a1) * cd * 0)
                    + sd * (2 * w1 * w1 + w2 * w2 * cd)) / (this.l2 * denom);
    // More stable formulation
    const den1 = this.l1 * (2 - cd * cd);
    const den2 = this.l2 * (2 - cd * cd);
    const aa1 = (-g * (2 * Math.sin(a1)) - g * Math.sin(a1 - 2*a2)
                 - 2 * sd * (w2*w2*this.l2 + w1*w1*this.l1*cd)) / den1;
    const aa2 = (2 * sd * (w1*w1*this.l1*2 + g*2*Math.cos(a1)
                 + w2*w2*this.l2*cd)) / den2;
    return [w1, w2, aa1, aa2];
  }

  step(dt) {
    // RK4
    const s = [this.a1, this.a2, this.w1, this.w2];
    const k1 = this.deriv(s[0], s[1], s[2], s[3]);
    const k2 = this.deriv(s[0]+k1[0]*dt/2, s[1]+k1[1]*dt/2, s[2]+k1[2]*dt/2, s[3]+k1[3]*dt/2);
    const k3 = this.deriv(s[0]+k2[0]*dt/2, s[1]+k2[1]*dt/2, s[2]+k2[2]*dt/2, s[3]+k2[3]*dt/2);
    const k4 = this.deriv(s[0]+k3[0]*dt, s[1]+k3[1]*dt, s[2]+k3[2]*dt, s[3]+k3[3]*dt);

    this.a1 += (k1[0] + 2*k2[0] + 2*k3[0] + k4[0]) * dt / 6;
    this.a2 += (k1[1] + 2*k2[1] + 2*k3[1] + k4[1]) * dt / 6;
    this.w1 += (k1[2] + 2*k2[2] + 2*k3[2] + k4[2]) * dt / 6;
    this.w2 += (k1[3] + 2*k2[3] + 2*k3[3] + k4[3]) * dt / 6;

    // Light damping
    const d = Math.pow(damping, dt);
    this.w1 *= d;
    this.w2 *= d;
  }

  pos() {
    const x1 = this.ox + this.l1 * Math.sin(this.a1);
    const y1 = this.oy + this.l1 * Math.cos(this.a1);
    const x2 = x1 + this.l2 * Math.sin(this.a2);
    const y2 = y1 + this.l2 * Math.cos(this.a2);
    return { x1, y1, x2, y2 };
  }

  updateTrail() {
    const { x2, y2 } = this.pos();
    this.trail.push({ x: x2, y: y2 });
    if (this.trail.length > 300) this.trail.shift();
  }

  draw() {
    const { x1, y1, x2, y2 } = this.pos();
    const c = this.color;

    // Trail — gradient opacity
    if (this.trail.length > 2) {
      for (let i = 1; i < this.trail.length; i++) {
        const t = i / this.trail.length;
        ctx.beginPath();
        ctx.moveTo(this.trail[i-1].x, this.trail[i-1].y);
        ctx.lineTo(this.trail[i].x, this.trail[i].y);
        ctx.strokeStyle = rgb(c, t * 0.4);
        ctx.lineWidth = t * 2;
        ctx.stroke();
      }
    }

    // Strings
    ctx.beginPath();
    ctx.moveTo(this.ox, this.oy);
    ctx.lineTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Pivot
    ctx.beginPath();
    ctx.arc(this.ox, this.oy, 3, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.35)';
    ctx.fill();

    // Bob 1
    const g1 = ctx.createRadialGradient(x1-2, y1-2, 1, x1, y1, 8);
    g1.addColorStop(0, rgb(c, 0.85)); g1.addColorStop(1, rgb(c, 0.45));
    ctx.beginPath(); ctx.arc(x1, y1, 8, 0, Math.PI*2); ctx.fillStyle = g1; ctx.fill();

    // Bob 2
    const g2 = ctx.createRadialGradient(x2-3, y2-3, 1, x2, y2, 12);
    g2.addColorStop(0, rgb(c, 1)); g2.addColorStop(1, rgb(c, 0.55));
    ctx.beginPath(); ctx.arc(x2, y2, 12, 0, Math.PI*2); ctx.fillStyle = g2; ctx.fill();

    // Glow on bob2
    ctx.beginPath(); ctx.arc(x2, y2, 24, 0, Math.PI*2);
    ctx.fillStyle = rgb(c, 0.06); ctx.fill();
  }
}

// ═══════════════════════════════════════════
//  Collision — proper elastic, no sticking
// ═══════════════════════════════════════════
const BOB_R = 16;

function collide() {
  if (!collisionOn) return;
  if (mode === 'newton') { collideNewton(); return; }

  for (let i = 0; i < pendulums.length; i++) {
    for (let j = i + 1; j < pendulums.length; j++) {
      const a = pendulums[i], b = pendulums[j];
      const ax = a.bobX, ay = a.bobY, bx = b.bobX, by = b.bobY;
      const dx = bx - ax, dy = by - ay;
      const dist = Math.hypot(dx, dy);
      const minD = BOB_R * 2;
      if (dist >= minD || dist < 1) continue;

      // Approach velocity check
      const wA = (a.angle - a.prevAngle), wB = (b.angle - b.prevAngle);
      const vAx = a.len * wA * Math.cos(a.angle);
      const vAy = -a.len * wA * Math.sin(a.angle);
      const vBx = b.len * wB * Math.cos(b.angle);
      const vBy = -b.len * wB * Math.sin(b.angle);
      const nx = dx / dist, ny = dy / dist;
      const relV = (vAx - vBx) * nx + (vAy - vBy) * ny;
      if (relV <= 0.05) continue; // separating → skip

      // Elastic collision: swap angular velocities with restitution
      const tmpW = wA;
      const restitution = 0.9;
      // For equal mass: simple swap scaled by restitution
      const newWA = wB * restitution;
      const newWB = tmpW * restitution;
      a.prevAngle = a.angle - newWA;
      b.prevAngle = b.angle - newWB;

      // Separate bobs to prevent overlap
      const overlap = minD - dist;
      const pushAngle = overlap / Math.min(a.len, b.len);
      if (a.angle < b.angle) {
        a.angle -= pushAngle * 0.6;
        b.angle += pushAngle * 0.6;
      } else {
        a.angle += pushAngle * 0.6;
        b.angle -= pushAngle * 0.6;
      }
      a.prevAngle = a.angle - newWA;
      b.prevAngle = b.angle - newWB;

      flash((ax+bx)/2, (ay+by)/2, Math.min(relV * 0.15, 1));
    }
  }
}

// Newton's cradle — impulse chain
function collideNewton() {
  const n = pendulums.length;
  if (n < 2) return;

  for (let i = 0; i < n - 1; i++) {
    const a = pendulums[i], b = pendulums[i+1];
    const ax = a.bobX, ay = a.bobY, bx = b.bobX, by = b.bobY;
    const dist = Math.hypot(bx - ax, by - ay);
    const contact = BOB_R * 2 + 0.5;
    if (dist > contact) continue;

    const wA = a.angle - a.prevAngle;
    const wB = b.angle - b.prevAngle;

    // Check if approaching
    const vRel = wA - wB; // positive = approaching (for adjacent bobs)
    if (a.angle <= b.angle && vRel <= 0.001) continue;
    if (a.angle > b.angle && vRel >= -0.001) continue;

    // Perfect elastic collision (equal mass): swap velocities
    a.prevAngle = a.angle - wB * 0.999;
    b.prevAngle = b.angle - wA * 0.999;

    // Separate
    const sep = Math.max(0, contact + 1 - dist) / a.len * 0.6;
    a.angle -= sep;
    b.angle += sep;
    a.prevAngle = a.angle - wB * 0.999;
    b.prevAngle = b.angle - wA * 0.999;

    flash((ax+bx)/2, (ay+by)/2, Math.min(Math.abs(vRel) * 5, 1));
  }
}

// Collision flash
function flash(x, y, intensity) {
  const r = 12 + 10 * intensity;
  const g = ctx.createRadialGradient(x, y, 0, x, y, r);
  g.addColorStop(0, `rgba(255,255,255,${0.12 * intensity})`);
  g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI*2);
  ctx.fillStyle = g; ctx.fill();
  playHit(intensity);
}

// ═══════════════════════════════════════════
//  Mode Initializers
// ═══════════════════════════════════════════
function initSingle() {
  damping = 0.999;
  pendulums = [];
  doublePendulums = [];
  const n = 5;
  const spacing = Math.min(80, (W - 120) / n);
  const sx = W / 2 - (n - 1) * spacing / 2;
  for (let i = 0; i < n; i++) {
    pendulums.push(new Pendulum(
      sx + i * spacing,
      (200 + i * 20) * lengthScale,
      Math.PI / 6 + i * 0.04,
      PALETTE[i % PALETTE.length]
    ));
  }
}

function initNewton() {
  damping = 0.9998;
  pendulums = [];
  doublePendulums = [];
  const n = 5;
  const d = BOB_R * 2;
  const sx = W / 2 - (n - 1) * d / 2;
  const ropeLen = 280 * lengthScale;

  for (let i = 0; i < n; i++) {
    const angle = (i === 0) ? -Math.PI / 5 : 0;
    pendulums.push(new Pendulum(sx + i * d, ropeLen, angle, [185, 195, 210]));
  }
  collisionOn = true;
  document.getElementById('collisionBtn').classList.add('active');
}

function initDouble() {
  damping = 0.9998;
  pendulums = [];
  doublePendulums = [];
  const n = 3;
  const spacing = Math.min(200, (W - 100) / n);
  const sx = W / 2 - (n - 1) * spacing / 2;
  for (let i = 0; i < n; i++) {
    doublePendulums.push(new DoublePendulum(
      sx + i * spacing,
      110 * lengthScale, 90 * lengthScale,
      Math.PI * 0.6 + (Math.random() - 0.5) * 0.1,
      Math.PI * 0.6 + (Math.random() - 0.5) * 0.1,
      PALETTE[i]
    ));
  }
}

function initWave() {
  damping = 0.9999;
  pendulums = [];
  doublePendulums = [];
  const n = 20;
  const spacing = W / (n + 1);
  for (let i = 0; i < n; i++) {
    const t = i / (n - 1);
    const c = [
      Math.round(20 + 220 * t),
      Math.round(200 - 80 * t),
      Math.round(230 - 60 * t)
    ];
    pendulums.push(new Pendulum(
      spacing * (i + 1),
      (180 + i * 6) * lengthScale,
      Math.PI / 4,
      c
    ));
  }
}

function reset() {
  ctx.clearRect(0, 0, W, H);
  switch (mode) {
    case 'single': initSingle(); break;
    case 'newton': initNewton(); break;
    case 'double': initDouble(); break;
    case 'wave':   initWave();   break;
  }
  document.getElementById('collisionBtn').classList.toggle('active', collisionOn);
  updateHUD();
}

function updateHUD() {
  document.getElementById('modeDisplay').textContent = mode;
  const total = pendulums.length + doublePendulums.length;
  document.getElementById('countDisplay').textContent = total;
  let E = 0;
  pendulums.forEach(p => E += p.energy());
  document.getElementById('energyDisplay').textContent = Math.round(E * 10) + '%';
}

// ═══════════════════════════════════════════
//  Main Loop
// ═══════════════════════════════════════════
let lastTime = 0;
let hintTimer = 0;

function loop(ts) {
  requestAnimationFrame(loop);

  // Hide hint after 5 seconds
  if (hintTimer < 300) {
    hintTimer++;
    if (hintTimer === 300) {
      const el = document.getElementById('hint');
      if (el) el.style.opacity = '0';
    }
  }

  // Motion blur
  ctx.fillStyle = mode === 'double' ? 'rgba(10,10,15,0.12)' : 'rgba(10,10,15,0.3)';
  ctx.fillRect(0, 0, W, H);

  // Physics substeps
  const dt = 1 / SUBSTEPS;
  for (let s = 0; s < SUBSTEPS; s++) {
    pendulums.forEach(p => p.step(dt));
    doublePendulums.forEach(p => p.step(dt));
    collide();
  }

  // Update trails
  pendulums.forEach(p => p.updateTrail());
  doublePendulums.forEach(p => p.updateTrail());

  // Draw Newton bar
  if (mode === 'newton' && pendulums.length > 0) {
    const first = pendulums[0], last = pendulums[pendulums.length - 1];
    ctx.beginPath();
    ctx.moveTo(first.ox - 40, first.oy);
    ctx.lineTo(last.ox + 40, last.oy);
    ctx.strokeStyle = 'rgba(255,255,255,0.15)';
    ctx.lineWidth = 3;
    ctx.stroke();
  }

  // Draw
  pendulums.forEach(p => p.draw());
  doublePendulums.forEach(p => p.draw());

  updateHUD();
}

// ═══════════════════════════════════════════
//  Controls
// ═══════════════════════════════════════════
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    collisionOn = mode !== 'double';
    reset();
  });
});

document.getElementById('collisionBtn').addEventListener('click', function() {
  collisionOn = !collisionOn;
  this.classList.toggle('active', collisionOn);
});

document.getElementById('resetBtn').addEventListener('click', reset);

document.getElementById('soundBtn').addEventListener('click', function() {
  ensureAudio();
  soundOn = !soundOn;
  this.classList.toggle('active', soundOn);
  this.textContent = soundOn ? 'SOUND' : 'MUTED';
  if (soundOn) playHit(0.3);
});

document.getElementById('gravitySlider').addEventListener('input', function() { gravity = +this.value; });
document.getElementById('dampingSlider').addEventListener('input', function() { damping = +this.value; });
document.getElementById('lengthSlider').addEventListener('input', function() { lengthScale = +this.value; reset(); });

// ═══════════════════════════════════════════
//  Pointer interaction
// ═══════════════════════════════════════════
function getXY(e) {
  if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

function findBob(x, y) {
  for (let i = pendulums.length - 1; i >= 0; i--) {
    const p = pendulums[i];
    if (Math.hypot(x - p.bobX, y - p.bobY) < BOB_R + 15) return p;
  }
  return null;
}

function onDown(e) {
  const { x, y } = getXY(e);
  const p = findBob(x, y);
  if (p) {
    dragging = p;
    p.dragging = true;
    p.prevAngle = p.angle;
    e.preventDefault();
  }
}

function onMove(e) {
  if (!dragging) return;
  const { x, y } = getXY(e);
  const dx = x - dragging.ox, dy = y - dragging.oy;
  const newAngle = Math.atan2(dx, dy);
  dragging.prevAngle = dragging.angle;
  dragging.angle = newAngle;
  e.preventDefault();
}

function onUp() {
  if (dragging) {
    dragging.dragging = false;
    dragging = null;
  }
}

canvas.addEventListener('mousedown', onDown);
canvas.addEventListener('mousemove', onMove);
canvas.addEventListener('mouseup', onUp);
canvas.addEventListener('mouseleave', onUp);
canvas.addEventListener('touchstart', onDown, { passive: false });
canvas.addEventListener('touchmove', onMove, { passive: false });
canvas.addEventListener('touchend', onUp);

canvas.addEventListener('dblclick', (e) => {
  if (mode === 'single' || mode === 'wave') {
    pendulums.push(new Pendulum(
      e.clientX,
      (180 + Math.random() * 100) * lengthScale,
      0,
      PALETTE[Math.floor(Math.random() * PALETTE.length)]
    ));
  }
});

canvas.addEventListener('contextmenu', (e) => {
  e.preventDefault();
  const p = findBob(e.clientX, e.clientY);
  if (p) {
    const idx = pendulums.indexOf(p);
    if (idx > -1) pendulums.splice(idx, 1);
  }
});

// ─── Start ───
reset();
requestAnimationFrame(loop);

})();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
