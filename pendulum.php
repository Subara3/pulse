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
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: grab; }
#canvas:active { cursor: grabbing; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1rem; font-family: "JetBrains Mono", monospace; font-size: 0.7rem; color: rgba(255,255,255,0.4); text-align: right; }
.hint { top: 5rem; left: 1.5rem; color: rgba(255,255,255,0.3); font-size: 0.65rem; line-height: 1.8; }

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .stats { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
  .hint { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';

?>

<canvas id="canvas"></canvas>
<div class="ui title">PENDULUM</div>
<div class="ui stats">
  <div>mode: <span id="modeDisplay">single</span></div>
  <div>pendulums: <span id="countDisplay">5</span></div>
  <div>energy: <span id="energyDisplay">100%</span></div>
</div>
<div class="ui hint">
  Drag to swing<br>
  Double-click to add<br>
  Right-click to remove
</div>
<div id="controls" class="drawer-controls">
  <div class="slider-group">
    <label>Gravity <input type="range" id="gravitySlider" min="0.1" max="2" step="0.1" value="0.5"></label>
    <label>Damping <input type="range" id="dampingSlider" min="0.9" max="1" step="0.001" value="0.999"></label>
    <label>Bob Size <input type="range" id="sizeSlider" min="10" max="40" step="2" value="20"></label>
    <label>Length <input type="range" id="lengthSlider" min="0.6" max="1.6" step="0.05" value="1.0"></label>
  </div>
  <div class="mode-group">
    <button class="control-btn active" data-mode="single">SINGLE</button>
    <button class="control-btn" data-mode="newton">NEWTON</button>
    <button class="control-btn" data-mode="double">DOUBLE</button>
    <button class="control-btn" data-mode="wave">WAVE</button>
  </div>
  <div class="action-group">
    <button class="control-btn active" id="collisionBtn">COLLISION</button>
    <button class="control-btn active" id="soundBtn">🔊 SOUND</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let pendulums = [];
let doublePendulums = [];
let draggingPendulum = null;

let GRAVITY = 0.5;
let DAMPING = 0.999;
let BOB_SIZE = 20;
let LENGTH_SCALE = 1.0;
let mode = 'single';
let collisionEnabled = true;

const SUBSTEPS = 4;

// ─── サウンド ───
let audioCtx = null;
let soundEnabled = true;
let lastSoundTime = 0;

function initAudio() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  if (audioCtx.state === 'suspended') audioCtx.resume();
}

function playCollisionSound(intensity = 1) {
  if (!soundEnabled || !audioCtx || audioCtx.state === 'suspended') return;
  const now = audioCtx.currentTime;
  if (now - lastSoundTime < 0.08) return;
  lastSoundTime = now;

  const vol = 0.04 * Math.min(intensity, 1);
  const freq = 300 + 200 * intensity;

  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  osc.connect(gain);
  gain.connect(audioCtx.destination);
  osc.type = 'sine';
  osc.frequency.setValueAtTime(freq, now);
  osc.frequency.exponentialRampToValueAtTime(120, now + 0.06);
  gain.gain.setValueAtTime(vol, now);
  gain.gain.exponentialRampToValueAtTime(0.001, now + 0.07);
  osc.start(now);
  osc.stop(now + 0.07);
}

['click', 'keydown', 'touchstart'].forEach(evt => {
  document.addEventListener(evt, function f() { initAudio(); document.removeEventListener(evt, f); }, { once: true });
});

// ─── 色 ───
const COLORS = [
  [0, 245, 212],
  [123, 44, 191],
  [255, 100, 100],
  [100, 200, 255],
  [255, 200, 100],
  [100, 255, 150],
  [255, 150, 200],
];

function rgba(c, a) { return `rgba(${c[0]},${c[1]},${c[2]},${a})`; }

// ─── 単振り子 ───
class Pendulum {
  constructor(x, length, angle, color, mass = 1) {
    this.originX = x;
    this.originY = 80;
    this.length = length;
    this.angle = angle;
    this.omega = 0; // angular velocity
    this.color = color;
    this.mass = mass;
    this.trail = [];
    this.isDragging = false;
    this.prevAngle = angle;
  }

  bobX() { return this.originX + this.length * Math.sin(this.angle); }
  bobY() { return this.originY + this.length * Math.cos(this.angle); }

  getEnergy() {
    const h = this.length * (1 - Math.cos(this.angle));
    const v = this.length * Math.abs(this.omega);
    return this.mass * GRAVITY * h + 0.5 * this.mass * v * v;
  }

  step(dt) {
    if (this.isDragging) return;
    // Symplectic Euler (energy-preserving)
    const alpha = (-GRAVITY / this.length) * Math.sin(this.angle);
    this.omega += alpha * dt;
    this.omega *= Math.pow(DAMPING, dt);
    this.angle += this.omega * dt;
  }

  updateTrail() {
    const bx = this.bobX(), by = this.bobY();
    this.trail.push({ x: bx, y: by, life: 1 });
    if (this.trail.length > 40) this.trail.shift();
  }

  draw(ctx) {
    const bx = this.bobX(), by = this.bobY();
    const r = BOB_SIZE * Math.sqrt(this.mass);
    const c = this.color;

    // 軌跡
    if (this.trail.length > 1) {
      ctx.beginPath();
      ctx.moveTo(this.trail[0].x, this.trail[0].y);
      for (let i = 1; i < this.trail.length; i++) {
        ctx.lineTo(this.trail[i].x, this.trail[i].y);
        this.trail[i].life -= 0.025;
      }
      ctx.strokeStyle = rgba(c, 0.15);
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }

    // 紐
    ctx.beginPath();
    ctx.moveTo(this.originX, this.originY);
    ctx.lineTo(bx, by);
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // 支点
    ctx.beginPath();
    ctx.arc(this.originX, this.originY, 4, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.fill();

    // グロー
    const glow = ctx.createRadialGradient(bx, by, r * 0.3, bx, by, r * 2.5);
    glow.addColorStop(0, rgba(c, this.isDragging ? 0.2 : 0.08));
    glow.addColorStop(1, rgba(c, 0));
    ctx.beginPath();
    ctx.arc(bx, by, r * 2.5, 0, Math.PI * 2);
    ctx.fillStyle = glow;
    ctx.fill();

    // ボブ本体 — 立体感のあるグラデーション
    const grad = ctx.createRadialGradient(bx - r * 0.3, by - r * 0.3, r * 0.1, bx, by, r);
    grad.addColorStop(0, rgba(c, 1));
    grad.addColorStop(0.6, rgba(c, 0.85));
    grad.addColorStop(1, rgba([c[0] * 0.4, c[1] * 0.4, c[2] * 0.4], 0.9));
    ctx.beginPath();
    ctx.arc(bx, by, r, 0, Math.PI * 2);
    ctx.fillStyle = grad;
    ctx.fill();

    // ハイライト
    ctx.beginPath();
    ctx.arc(bx - r * 0.25, by - r * 0.25, r * 0.3, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.25)';
    ctx.fill();
  }
}

// ─── 二重振り子 ───
class DoublePendulum {
  constructor(x, l1, l2, a1, a2, color) {
    this.originX = x;
    this.originY = 100;
    this.l1 = l1; this.l2 = l2;
    this.a1 = a1; this.a2 = a2;
    this.w1 = 0; this.w2 = 0;
    this.m1 = 1; this.m2 = 1;
    this.color = color;
    this.trail = [];
  }

  step(dt) {
    const { l1, l2, a1, a2, m1, m2 } = this;
    const g = GRAVITY * 20;
    const da = a1 - a2;
    const den1 = l1 * (2 * m1 + m2 - m2 * Math.cos(2 * da));
    const den2 = l2 * (2 * m1 + m2 - m2 * Math.cos(2 * da));

    const alpha1 = (
      -g * (2 * m1 + m2) * Math.sin(a1)
      - m2 * g * Math.sin(a1 - 2 * a2)
      - 2 * Math.sin(da) * m2 * (this.w2 * this.w2 * l2 + this.w1 * this.w1 * l1 * Math.cos(da))
    ) / den1;

    const alpha2 = (
      2 * Math.sin(da) * (
        this.w1 * this.w1 * l1 * (m1 + m2)
        + g * (m1 + m2) * Math.cos(a1)
        + this.w2 * this.w2 * l2 * m2 * Math.cos(da)
      )
    ) / den2;

    this.w1 += alpha1 * dt;
    this.w2 += alpha2 * dt;
    this.w1 *= Math.pow(DAMPING, dt);
    this.w2 *= Math.pow(DAMPING, dt);
    this.a1 += this.w1 * dt;
    this.a2 += this.w2 * dt;
  }

  getPositions() {
    const x1 = this.originX + this.l1 * Math.sin(this.a1);
    const y1 = this.originY + this.l1 * Math.cos(this.a1);
    const x2 = x1 + this.l2 * Math.sin(this.a2);
    const y2 = y1 + this.l2 * Math.cos(this.a2);
    return { x1, y1, x2, y2 };
  }

  updateTrail() {
    const { x2, y2 } = this.getPositions();
    this.trail.push({ x: x2, y: y2 });
    if (this.trail.length > 200) this.trail.shift();
  }

  draw(ctx) {
    const { x1, y1, x2, y2 } = this.getPositions();
    const c = this.color;

    // 軌跡 — 長く美しいトレイル
    if (this.trail.length > 2) {
      for (let i = 1; i < this.trail.length; i++) {
        const t = i / this.trail.length;
        ctx.beginPath();
        ctx.moveTo(this.trail[i - 1].x, this.trail[i - 1].y);
        ctx.lineTo(this.trail[i].x, this.trail[i].y);
        ctx.strokeStyle = rgba(c, t * 0.5);
        ctx.lineWidth = t * 2;
        ctx.stroke();
      }
    }

    // 紐
    ctx.beginPath();
    ctx.moveTo(this.originX, this.originY);
    ctx.lineTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.strokeStyle = 'rgba(255,255,255,0.3)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // 支点
    ctx.beginPath();
    ctx.arc(this.originX, this.originY, 4, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.fill();

    // ボブ1
    const g1 = ctx.createRadialGradient(x1 - 3, y1 - 3, 1, x1, y1, 10);
    g1.addColorStop(0, rgba(c, 0.9));
    g1.addColorStop(1, rgba(c, 0.5));
    ctx.beginPath();
    ctx.arc(x1, y1, 10, 0, Math.PI * 2);
    ctx.fillStyle = g1;
    ctx.fill();

    // ボブ2
    const g2 = ctx.createRadialGradient(x2 - 4, y2 - 4, 1, x2, y2, 14);
    g2.addColorStop(0, rgba(c, 1));
    g2.addColorStop(1, rgba(c, 0.6));
    ctx.beginPath();
    ctx.arc(x2, y2, 14, 0, Math.PI * 2);
    ctx.fillStyle = g2;
    ctx.fill();

    // ボブ2 グロー
    ctx.beginPath();
    ctx.arc(x2, y2, 28, 0, Math.PI * 2);
    ctx.fillStyle = rgba(c, 0.08);
    ctx.fill();
  }
}

// ─── 衝突処理 ───
function handleCollisions() {
  if (!collisionEnabled) return;
  if (mode === 'newton') { handleNewtonCollisions(); return; }

  for (let i = 0; i < pendulums.length; i++) {
    for (let j = i + 1; j < pendulums.length; j++) {
      const a = pendulums[i], b = pendulums[j];
      const ax = a.bobX(), ay = a.bobY();
      const bx = b.bobX(), by = b.bobY();
      const dx = bx - ax, dy = by - ay;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const rA = BOB_SIZE * Math.sqrt(a.mass);
      const rB = BOB_SIZE * Math.sqrt(b.mass);
      const minDist = rA + rB;

      if (dist >= minDist || dist < 0.1) continue;

      // 接近チェック: 離れていたら無視
      const vAx = a.length * a.omega * Math.cos(a.angle);
      const vBx = b.length * b.omega * Math.cos(b.angle);
      const approachSpeed = (vAx - vBx) * dx / dist;
      if (approachSpeed <= 0) continue;

      // 弾性衝突（角速度ベース、質量考慮）
      const wA = a.omega, wB = b.omega;
      const mA = a.mass, mB = b.mass;
      a.omega = ((mA - mB) * wA + 2 * mB * wB) / (mA + mB) * 0.92;
      b.omega = ((mB - mA) * wB + 2 * mA * wA) / (mA + mB) * 0.92;

      // 位置補正 — 重なりを解消
      const overlap = minDist - dist;
      const push = overlap / Math.min(a.length, b.length) * 1.2;
      const totalM = mA + mB;
      if (a.angle < b.angle) {
        a.angle -= push * mB / totalM;
        b.angle += push * mA / totalM;
      } else {
        a.angle += push * mB / totalM;
        b.angle -= push * mA / totalM;
      }

      showCollisionFlash((ax + bx) / 2, (ay + by) / 2, approachSpeed * 0.1);
    }
  }
}

// Newton's Cradle — 隣接ペアのみ、等質量弾性衝突
let newtonCooldown = [];

function handleNewtonCollisions() {
  const n = pendulums.length;
  if (n < 2) return;

  for (let i = 0; i < n - 1; i++) {
    if (newtonCooldown[i] > 0) { newtonCooldown[i]--; continue; }

    const a = pendulums[i], b = pendulums[i + 1];
    const ax = a.bobX(), ay = a.bobY();
    const bx = b.bobX(), by = b.bobY();
    const dx = bx - ax, dy = by - ay;
    const dist = Math.sqrt(dx * dx + dy * dy);
    const contact = BOB_SIZE * 2 + 1;

    if (dist > contact) continue;

    // 接近チェック
    const vAx = a.length * a.omega * Math.cos(a.angle);
    const vBx = b.length * b.omega * Math.cos(b.angle);
    const relV = vAx - vBx;
    if (relV * dx <= 0) continue; // 離れている or 横方向

    // 等質量弾性衝突: 角速度を交換
    const wA = a.omega, wB = b.omega;
    a.omega = wB * 0.999;
    b.omega = wA * 0.999;

    // 確実に分離
    const sep = Math.max(0, (contact + 2 - dist) / a.length) * 0.5;
    a.angle -= sep;
    b.angle += sep;

    newtonCooldown[i] = 15;
    showCollisionFlash((ax + bx) / 2, (ay + by) / 2, Math.abs(relV) * 0.08);
  }
}

// 衝突フラッシュ — 控えめ
function showCollisionFlash(x, y, intensity) {
  intensity = Math.min(intensity, 1);
  const r = 15 + 10 * intensity;
  const grad = ctx.createRadialGradient(x, y, 0, x, y, r);
  grad.addColorStop(0, `rgba(255,255,255,${0.15 * intensity})`);
  grad.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.beginPath();
  ctx.arc(x, y, r, 0, Math.PI * 2);
  ctx.fillStyle = grad;
  ctx.fill();
  playCollisionSound(intensity);
}

// ─── 初期化 ───
function initSingle() {
  DAMPING = 0.999;
  pendulums = [];
  doublePendulums = [];
  const count = 5;
  const spacing = Math.min(80, (width - 100) / count);
  const startX = width / 2 - (count - 1) * spacing / 2;
  for (let i = 0; i < count; i++) {
    pendulums.push(new Pendulum(
      startX + i * spacing,
      (200 + i * 20) * LENGTH_SCALE,
      Math.PI / 6 + i * 0.05,
      COLORS[i % COLORS.length]
    ));
  }
}

function initNewton() {
  DAMPING = 0.9995;
  pendulums = [];
  doublePendulums = [];
  const count = 5;
  const diameter = BOB_SIZE * 2;
  const startX = width / 2 - (count - 1) * diameter / 2;
  const ropeLen = 280 * LENGTH_SCALE;

  for (let i = 0; i < count; i++) {
    const p = new Pendulum(
      startX + i * diameter,
      ropeLen,
      i === 0 ? -Math.PI / 5 : 0,
      [190, 195, 210]
    );
    p.mass = 1;
    pendulums.push(p);
  }
  newtonCooldown = new Array(count - 1).fill(0);
  collisionEnabled = true;
  document.getElementById('collisionBtn').classList.add('active');
}

function initDouble() {
  DAMPING = 0.9995;
  pendulums = [];
  doublePendulums = [];
  const count = 3;
  const spacing = Math.min(200, (width - 100) / count);
  const startX = width / 2 - (count - 1) * spacing / 2;
  for (let i = 0; i < count; i++) {
    doublePendulums.push(new DoublePendulum(
      startX + i * spacing,
      120 * LENGTH_SCALE,
      100 * LENGTH_SCALE,
      Math.PI * 0.6 + (Math.random() - 0.5) * 0.15,
      Math.PI * 0.6 + (Math.random() - 0.5) * 0.15,
      COLORS[i]
    ));
  }
}

function initWave() {
  DAMPING = 0.9998;
  pendulums = [];
  doublePendulums = [];
  const count = 20;
  const spacing = width / (count + 1);
  for (let i = 0; i < count; i++) {
    const t = i / (count - 1);
    const c = [
      Math.round(20 + 220 * t),
      Math.round(200 - 80 * t),
      Math.round(230 - 60 * t),
    ];
    pendulums.push(new Pendulum(
      spacing * (i + 1),
      (180 + i * 6) * LENGTH_SCALE,
      Math.PI / 4,
      c
    ));
  }
}

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  switch (mode) {
    case 'single': initSingle(); break;
    case 'newton': initNewton(); break;
    case 'double': initDouble(); break;
    case 'wave':   initWave();   break;
  }
  document.getElementById('collisionBtn').classList.toggle('active', collisionEnabled);
  updateStats();
}

function updateStats() {
  document.getElementById('modeDisplay').textContent = mode;
  document.getElementById('countDisplay').textContent = pendulums.length + doublePendulums.length;
  let E = 0;
  pendulums.forEach(p => E += p.getEnergy());
  document.getElementById('energyDisplay').textContent = Math.round(E * 10) + '%';
}

// ─── 描画ループ ───
function draw() {
  // 残像（モーションブラー）
  ctx.fillStyle = mode === 'double' ? 'rgba(10,10,15,0.15)' : 'rgba(10,10,15,0.35)';
  ctx.fillRect(0, 0, width, height);

  // 物理サブステップ
  const dt = 1 / SUBSTEPS;
  for (let s = 0; s < SUBSTEPS; s++) {
    pendulums.forEach(p => p.step(dt));
    doublePendulums.forEach(p => p.step(dt));
    handleCollisions();
  }

  // 軌跡更新
  pendulums.forEach(p => p.updateTrail());
  doublePendulums.forEach(p => p.updateTrail());

  // Newton バー
  if (mode === 'newton' && pendulums.length > 0) {
    const first = pendulums[0], last = pendulums[pendulums.length - 1];
    ctx.beginPath();
    ctx.moveTo(first.originX - 40, first.originY);
    ctx.lineTo(last.originX + 40, last.originY);
    ctx.strokeStyle = 'rgba(255,255,255,0.2)';
    ctx.lineWidth = 3;
    ctx.stroke();
  }

  // 描画
  pendulums.forEach(p => p.draw(ctx));
  doublePendulums.forEach(p => p.draw(ctx));

  updateStats();
  requestAnimationFrame(draw);
}

// ─── イベント ───
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    collisionEnabled = (mode !== 'double');
    document.getElementById('collisionBtn').classList.toggle('active', collisionEnabled);
    init();
  });
});

document.getElementById('collisionBtn').addEventListener('click', function() {
  collisionEnabled = !collisionEnabled;
  this.classList.toggle('active', collisionEnabled);
});

document.getElementById('resetBtn').addEventListener('click', init);

document.getElementById('soundBtn').addEventListener('click', function() {
  initAudio();
  soundEnabled = !soundEnabled;
  this.classList.toggle('active', soundEnabled);
  this.textContent = soundEnabled ? '🔊 SOUND' : '🔇 SOUND';
  if (soundEnabled) playCollisionSound(0.5);
});

document.getElementById('gravitySlider').addEventListener('input', function() { GRAVITY = parseFloat(this.value); });
document.getElementById('dampingSlider').addEventListener('input', function() { DAMPING = parseFloat(this.value); });
document.getElementById('sizeSlider').addEventListener('input', function() { BOB_SIZE = parseInt(this.value); });
document.getElementById('lengthSlider').addEventListener('input', function() { LENGTH_SCALE = parseFloat(this.value); init(); });

window.addEventListener('resize', init);

// ─── ポインタ操作 ───
function getPos(e) {
  if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

function findBobAt(x, y) {
  for (let i = pendulums.length - 1; i >= 0; i--) {
    const p = pendulums[i];
    const r = BOB_SIZE * Math.sqrt(p.mass) + 15;
    if (Math.hypot(x - p.bobX(), y - p.bobY()) < r) return p;
  }
  return null;
}

function onDown(e) {
  const pos = getPos(e);
  const p = findBobAt(pos.x, pos.y);
  if (p) {
    draggingPendulum = p;
    p.isDragging = true;
    p.omega = 0;
    e.preventDefault();
  }
}

function onMove(e) {
  if (!draggingPendulum) return;
  const pos = getPos(e);
  const p = draggingPendulum;
  const dx = pos.x - p.originX;
  const dy = pos.y - p.originY;
  const newAngle = Math.atan2(dx, dy);
  p.omega = (newAngle - p.angle) * 0.3;
  p.angle = newAngle;
  e.preventDefault();
}

function onUp() {
  if (draggingPendulum) {
    draggingPendulum.isDragging = false;
    draggingPendulum = null;
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
      (180 + Math.random() * 120) * LENGTH_SCALE,
      0,
      COLORS[Math.floor(Math.random() * COLORS.length)],
      0.5 + Math.random() * 1.5
    ));
  }
});

canvas.addEventListener('contextmenu', (e) => {
  e.preventDefault();
  const p = findBobAt(e.clientX, e.clientY);
  if (p) {
    const idx = pendulums.indexOf(p);
    if (idx > -1) pendulums.splice(idx, 1);
  }
});

init();
draw();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
