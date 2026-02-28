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
let draggingPart = null; // 'bob1' or 'bob2' for double pendulum

let GRAVITY = 0.5;
let DAMPING = 0.999;
let BOB_SIZE = 20;
let LENGTH_SCALE = 1.0;
let mode = 'single';
let collisionEnabled = true;
let newtonCooldown = []; // Newton衝突の多重検出防止（フレーム単位）

// サウンド関連
let audioCtx = null;
let soundEnabled = true;

function initAudio() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (audioCtx.state === 'suspended') {
    audioCtx.resume();
  }
}

// 衝突音を鳴らす
function playCollisionSound(intensity = 1) {
  if (!soundEnabled || !audioCtx || audioCtx.state === 'suspended') return;

  const now = audioCtx.currentTime;

  // メインのクリック音
  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  osc.connect(gain);
  gain.connect(audioCtx.destination);

  osc.frequency.setValueAtTime(800 * intensity, now);
  osc.frequency.exponentialRampToValueAtTime(200, now + 0.1);
  osc.type = 'sine';

  gain.gain.setValueAtTime(0.3 * intensity, now);
  gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);

  osc.start(now);
  osc.stop(now + 0.15);

  // 金属的なハーモニクス
  const osc2 = audioCtx.createOscillator();
  const gain2 = audioCtx.createGain();
  osc2.connect(gain2);
  gain2.connect(audioCtx.destination);

  osc2.frequency.setValueAtTime(2400, now);
  osc2.frequency.exponentialRampToValueAtTime(1200, now + 0.05);
  osc2.type = 'triangle';

  gain2.gain.setValueAtTime(0.1 * intensity, now);
  gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.08);

  osc2.start(now);
  osc2.stop(now + 0.08);
}

// 初回のユーザー操作で音を有効化
['click', 'keydown', 'touchstart'].forEach(event => {
  document.addEventListener(event, function initOnce() {
    initAudio();
    document.removeEventListener(event, initOnce);
  }, { once: true });
});

const COLORS = [
  'rgba(0, 245, 212, 1)',
  'rgba(123, 44, 191, 1)',
  'rgba(255, 100, 100, 1)',
  'rgba(100, 200, 255, 1)',
  'rgba(255, 200, 100, 1)',
  'rgba(100, 255, 150, 1)',
  'rgba(255, 150, 200, 1)'
];

// 単振り子クラス
class Pendulum {
  constructor(x, length, angle, color, mass = 1) {
    this.originX = x;
    this.originY = 80;
    this.length = length;
    this.angle = angle;
    this.angleVelocity = 0;
    this.color = color;
    this.mass = mass;
    this.trail = [];
    this.isDragging = false;
  }

  getBobPosition() {
    return {
      x: this.originX + this.length * Math.sin(this.angle),
      y: this.originY + this.length * Math.cos(this.angle)
    };
  }

  getEnergy() {
    const h = this.length * (1 - Math.cos(this.angle));
    const v = this.length * Math.abs(this.angleVelocity);
    return this.mass * GRAVITY * h + 0.5 * this.mass * v * v;
  }

  update() {
    if (!this.isDragging) {
      const angleAcceleration = (-GRAVITY / this.length) * Math.sin(this.angle);
      this.angleVelocity += angleAcceleration;
      this.angleVelocity *= DAMPING;
      this.angle += this.angleVelocity;
    }

    const bob = this.getBobPosition();
    this.trail.push({ x: bob.x, y: bob.y, alpha: 1 });
    if (this.trail.length > 30) this.trail.shift();

    return bob;
  }

  draw(ctx) {
    const bob = this.update();

    // 軌跡
    this.trail.forEach((point, i) => {
      point.alpha -= 0.03;
      if (point.alpha > 0) {
        ctx.beginPath();
        ctx.arc(point.x, point.y, 2, 0, Math.PI * 2);
        ctx.fillStyle = this.color.replace('1)', `${point.alpha * 0.3})`);
        ctx.fill();
      }
    });

    // 紐
    ctx.beginPath();
    ctx.moveTo(this.originX, this.originY);
    ctx.lineTo(bob.x, bob.y);
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // 支点
    ctx.beginPath();
    ctx.arc(this.originX, this.originY, 6, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255, 255, 255, 0.5)';
    ctx.fill();

    // ボブ
    const size = BOB_SIZE * Math.sqrt(this.mass);
    ctx.beginPath();
    ctx.arc(bob.x, bob.y, size, 0, Math.PI * 2);
    const gradient = ctx.createRadialGradient(bob.x - size/3, bob.y - size/3, 0, bob.x, bob.y, size);
    gradient.addColorStop(0, this.color.replace('1)', '0.9)'));
    gradient.addColorStop(1, this.color);
    ctx.fillStyle = gradient;
    ctx.fill();

    // グロー
    ctx.beginPath();
    ctx.arc(bob.x, bob.y, size + 10, 0, Math.PI * 2);
    ctx.fillStyle = this.color.replace('1)', this.isDragging ? '0.3)' : '0.1)');
    ctx.fill();
  }
}

// 二重振り子クラス
class DoublePendulum {
  constructor(x, l1, l2, a1, a2, color) {
    this.originX = x;
    this.originY = 100;
    this.l1 = l1;
    this.l2 = l2;
    this.a1 = a1;
    this.a2 = a2;
    this.a1_v = 0;
    this.a2_v = 0;
    this.m1 = 1;
    this.m2 = 1;
    this.color = color;
    this.trail = [];
  }

  update() {
    const g = GRAVITY * 20;
    const { l1, l2, a1, a2, m1, m2 } = this;
    
    // 二重振り子の運動方程式
    const num1 = -g * (2 * m1 + m2) * Math.sin(a1);
    const num2 = -m2 * g * Math.sin(a1 - 2 * a2);
    const num3 = -2 * Math.sin(a1 - a2) * m2;
    const num4 = this.a2_v * this.a2_v * l2 + this.a1_v * this.a1_v * l1 * Math.cos(a1 - a2);
    const den = l1 * (2 * m1 + m2 - m2 * Math.cos(2 * a1 - 2 * a2));
    
    const a1_a = (num1 + num2 + num3 * num4) / den;
    
    const num5 = 2 * Math.sin(a1 - a2);
    const num6 = this.a1_v * this.a1_v * l1 * (m1 + m2);
    const num7 = g * (m1 + m2) * Math.cos(a1);
    const num8 = this.a2_v * this.a2_v * l2 * m2 * Math.cos(a1 - a2);
    const den2 = l2 * (2 * m1 + m2 - m2 * Math.cos(2 * a1 - 2 * a2));
    
    const a2_a = (num5 * (num6 + num7 + num8)) / den2;
    
    this.a1_v += a1_a;
    this.a2_v += a2_a;
    this.a1_v *= DAMPING;
    this.a2_v *= DAMPING;
    this.a1 += this.a1_v;
    this.a2 += this.a2_v;
    
    const x1 = this.originX + l1 * Math.sin(this.a1);
    const y1 = this.originY + l1 * Math.cos(this.a1);
    const x2 = x1 + l2 * Math.sin(this.a2);
    const y2 = y1 + l2 * Math.cos(this.a2);
    
    this.trail.push({ x: x2, y: y2, alpha: 1 });
    if (this.trail.length > 100) this.trail.shift();
    
    return { x1, y1, x2, y2 };
  }

  draw(ctx) {
    const { x1, y1, x2, y2 } = this.update();
    
    // 軌跡
    ctx.beginPath();
    this.trail.forEach((point, i) => {
      point.alpha -= 0.01;
      if (i === 0) ctx.moveTo(point.x, point.y);
      else ctx.lineTo(point.x, point.y);
    });
    ctx.strokeStyle = this.color.replace('1)', '0.3)');
    ctx.lineWidth = 1;
    ctx.stroke();
    
    // 紐1
    ctx.beginPath();
    ctx.moveTo(this.originX, this.originY);
    ctx.lineTo(x1, y1);
    ctx.strokeStyle = 'rgba(255,255,255,0.4)';
    ctx.lineWidth = 2;
    ctx.stroke();
    
    // 紐2
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
    
    // 支点
    ctx.beginPath();
    ctx.arc(this.originX, this.originY, 6, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.fill();
    
    // ボブ1
    ctx.beginPath();
    ctx.arc(x1, y1, 12, 0, Math.PI * 2);
    ctx.fillStyle = this.color.replace('1)', '0.8)');
    ctx.fill();
    
    // ボブ2
    ctx.beginPath();
    ctx.arc(x2, y2, 15, 0, Math.PI * 2);
    ctx.fillStyle = this.color;
    ctx.fill();
    
    // グロー
    ctx.beginPath();
    ctx.arc(x2, y2, 25, 0, Math.PI * 2);
    ctx.fillStyle = this.color.replace('1)', '0.15)');
    ctx.fill();
  }
}

// 衝突検出と処理
function handleCollisions() {
  if (!collisionEnabled) return;

  // Newton's Cradle モード用の特殊処理
  if (mode === 'newton') {
    handleNewtonCollisions();
    return;
  }

  // 通常の衝突処理
  for (let i = 0; i < pendulums.length; i++) {
    for (let j = i + 1; j < pendulums.length; j++) {
      const p1 = pendulums[i];
      const p2 = pendulums[j];

      const bob1 = p1.getBobPosition();
      const bob2 = p2.getBobPosition();

      const dx = bob2.x - bob1.x;
      const dy = bob2.y - bob1.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const minDist = BOB_SIZE * (Math.sqrt(p1.mass) + Math.sqrt(p2.mass));

      if (dist < minDist) {
        const v1 = p1.angleVelocity;
        const v2 = p2.angleVelocity;
        const m1 = p1.mass;
        const m2 = p2.mass;

        p1.angleVelocity = ((m1 - m2) * v1 + 2 * m2 * v2) / (m1 + m2) * 0.95;
        p2.angleVelocity = ((m2 - m1) * v2 + 2 * m1 * v1) / (m1 + m2) * 0.95;

        drawCollisionEffect((bob1.x + bob2.x) / 2, (bob1.y + bob2.y) / 2);
      }
    }
  }
}

// Newton's Cradle 専用の衝突処理
function handleNewtonCollisions() {
  const n = pendulums.length;
  if (n < 2) return;

  const radius = BOB_SIZE;
  const touching = new Array(n - 1).fill(false);

  const bobPos = pendulums.map(p => p.getBobPosition());
  const vx = pendulums.map((p, i) => p.length * p.angleVelocity * Math.cos(p.angle));

  // 近接判定 + クールダウン減衰
  for (let i = 0; i < n - 1; i++) {
    const dx = bobPos[i + 1].x - bobPos[i].x;
    const dy = bobPos[i + 1].y - bobPos[i].y;
    const dist = Math.sqrt(dx * dx + dy * dy);
    touching[i] = dist <= radius * 2 + 2;
    if (newtonCooldown[i] > 0) newtonCooldown[i]--;
  }

  // 隣接球同士の弾性衝突（等質量近似: 角速度を交換）
  // 連鎖を拾うため複数パス
  for (let pass = 0; pass < 2; pass++) {
    for (let i = 0; i < n - 1; i++) {
      if (!touching[i] || newtonCooldown[i] > 0) continue;

      const p1 = pendulums[i];
      const p2 = pendulums[i + 1];
      const b1 = bobPos[i];
      const b2 = bobPos[i + 1];
      const relX = b2.x - b1.x;
      const relVx = vx[i + 1] - vx[i];

      // 互いに近づいているときのみ衝突
      if (relX * relVx >= 0) continue;

      const w1 = p1.angleVelocity;
      const w2 = p2.angleVelocity;
      p1.angleVelocity = w2 * 0.998;
      p2.angleVelocity = w1 * 0.998;

      // 食い込み軽減
      const minSep = 0.012;
      if (p1.angle > p2.angle) {
        p1.angle += minSep;
        p2.angle -= minSep;
      } else {
        p1.angle -= minSep;
        p2.angle += minSep;
      }

      newtonCooldown[i] = 4;
      drawCollisionEffect((b1.x + b2.x) / 2, (b1.y + b2.y) / 2, 0.8);
    }
  }
}

function drawCollisionEffect(x, y, intensity = 1) {
  ctx.beginPath();
  ctx.arc(x, y, 30 * intensity, 0, Math.PI * 2);
  ctx.fillStyle = `rgba(255, 255, 255, ${0.3 * intensity})`;
  ctx.fill();

  // 衝突音を鳴らす
  playCollisionSound(intensity);
}

// 初期化
function initSingle() {
  DAMPING = 0.999;
  pendulums = [];
  doublePendulums = [];
  for (let i = 0; i < 5; i++) {
    pendulums.push(new Pendulum(
      width / 2 - 160 + i * 80,
      250 * LENGTH_SCALE,
      Math.PI / 6,
      COLORS[i % COLORS.length]
    ));
  }
}

function initNewton() {
  DAMPING = 0.9998;
  pendulums = [];
  doublePendulums = [];
  const count = 5;
  const ballDiameter = BOB_SIZE * 2;
  const spacing = ballDiameter; // ボールが静止時に接触する間隔
  const startX = width / 2 - (count - 1) * spacing / 2;
  const ropeLength = 280 * LENGTH_SCALE;

  for (let i = 0; i < count; i++) {
    const p = new Pendulum(
      startX + i * spacing,
      ropeLength,
      i === 0 ? -Math.PI / 4 : 0, // 最初の1つを左に引っ張る（負の角度）
      'rgba(200, 200, 210, 1)' // シルバー色
    );
    p.mass = 1;
    pendulums.push(p);
  }

  // 衝突フラグを初期化
  newtonCooldown = new Array(count - 1).fill(0);

  collisionEnabled = true;
  document.getElementById('collisionBtn').classList.add('active');
}

function initDouble() {
  DAMPING = 0.999;
  pendulums = [];
  doublePendulums = [];
  for (let i = 0; i < 3; i++) {
    doublePendulums.push(new DoublePendulum(
      width / 2 - 150 + i * 150,
      120 * LENGTH_SCALE,
      120 * LENGTH_SCALE,
      Math.PI / 2 + (Math.random() - 0.5) * 0.1,
      Math.PI / 2 + (Math.random() - 0.5) * 0.1,
      COLORS[i]
    ));
  }
}

function initWave() {
  DAMPING = 0.999;
  pendulums = [];
  doublePendulums = [];
  const count = 15;
  const spacing = width / (count + 1);
  
  for (let i = 0; i < count; i++) {
    const p = new Pendulum(
      spacing * (i + 1),
      (200 + i * 8) * LENGTH_SCALE, // 少しずつ長さを変える→共振
      Math.PI / 4,
      `rgba(0, ${155 + i * 6}, ${212 - i * 6}, 1)`
    );
    pendulums.push(p);
  }
}

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  
  switch (mode) {
    case 'single': initSingle(); break;
    case 'newton': initNewton(); break;
    case 'double': initDouble(); break;
    case 'wave': initWave(); break;
  }
  document.getElementById('collisionBtn').classList.toggle('active', collisionEnabled);
  updateStats();
}

function updateStats() {
  document.getElementById('modeDisplay').textContent = mode;
  document.getElementById('countDisplay').textContent = pendulums.length + doublePendulums.length;
  
  let totalEnergy = 0;
  pendulums.forEach(p => totalEnergy += p.getEnergy());
  document.getElementById('energyDisplay').textContent = Math.round(totalEnergy * 10) + '%';
}

function draw() {
  ctx.fillStyle = 'rgba(10, 10, 15, 0.4)';
  ctx.fillRect(0, 0, width, height);

  // 支点のバー（Newton モード）
  if (mode === 'newton' && pendulums.length > 0) {
    ctx.beginPath();
    ctx.moveTo(pendulums[0].originX - 30, pendulums[0].originY);
    ctx.lineTo(pendulums[pendulums.length - 1].originX + 30, pendulums[pendulums.length - 1].originY);
    ctx.strokeStyle = 'rgba(255,255,255,0.3)';
    ctx.lineWidth = 4;
    ctx.stroke();
  }

  handleCollisions();
  pendulums.forEach(p => p.draw(ctx));
  doublePendulums.forEach(p => p.draw(ctx));
  
  updateStats();
  requestAnimationFrame(draw);
}

// イベント
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    // DOUBLE以外は衝突ONをデフォルト
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
  if (soundEnabled) playCollisionSound(0.5); // テスト音
});

document.getElementById('gravitySlider').addEventListener('input', function() {
  GRAVITY = parseFloat(this.value);
});

document.getElementById('dampingSlider').addEventListener('input', function() {
  DAMPING = parseFloat(this.value);
});

document.getElementById('sizeSlider').addEventListener('input', function() {
  BOB_SIZE = parseInt(this.value);
});

document.getElementById('lengthSlider').addEventListener('input', function() {
  LENGTH_SCALE = parseFloat(this.value);
  init();
});

window.addEventListener('resize', init);

// ポインタ操作
function getPointerPos(e) {
  if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

function findPendulumAtPos(x, y) {
  for (let i = pendulums.length - 1; i >= 0; i--) {
    const p = pendulums[i];
    const bob = p.getBobPosition();
    const size = BOB_SIZE * Math.sqrt(p.mass);
    if (Math.hypot(x - bob.x, y - bob.y) < size + 15) return p;
  }
  return null;
}

function onPointerDown(e) {
  const pos = getPointerPos(e);
  const p = findPendulumAtPos(pos.x, pos.y);
  if (p) {
    draggingPendulum = p;
    p.isDragging = true;
    p.angleVelocity = 0;
    e.preventDefault();
  }
}

function onPointerMove(e) {
  if (!draggingPendulum) return;
  const pos = getPointerPos(e);
  const p = draggingPendulum;
  
  const dx = pos.x - p.originX;
  const dy = pos.y - p.originY;
  const newAngle = Math.atan2(dx, dy);
  
  p.angleVelocity = (newAngle - p.angle) * 0.3;
  p.angle = newAngle;
  
  e.preventDefault();
}

function onPointerUp() {
  if (draggingPendulum) {
    draggingPendulum.isDragging = false;
    draggingPendulum = null;
  }
}

canvas.addEventListener('mousedown', onPointerDown);
canvas.addEventListener('mousemove', onPointerMove);
canvas.addEventListener('mouseup', onPointerUp);
canvas.addEventListener('mouseleave', onPointerUp);
canvas.addEventListener('touchstart', onPointerDown, { passive: false });
canvas.addEventListener('touchmove', onPointerMove, { passive: false });
canvas.addEventListener('touchend', onPointerUp);

// ダブルクリックで追加
canvas.addEventListener('dblclick', (e) => {
  if (mode === 'single' || mode === 'wave') {
    pendulums.push(new Pendulum(
      e.clientX,
      200 + Math.random() * 100,
      0,
      COLORS[Math.floor(Math.random() * COLORS.length)],
      0.5 + Math.random() * 1.5
    ));
  }
});

// 右クリックで削除
canvas.addEventListener('contextmenu', (e) => {
  e.preventDefault();
  const pos = { x: e.clientX, y: e.clientY };
  const p = findPendulumAtPos(pos.x, pos.y);
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
