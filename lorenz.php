<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('lorenz');

$pageName = 'lorenz';
$pageTitle = 'LORENZ - PULSE';
$pageDesc = 'ローレンツアトラクタ。カオス理論が描く蝶の軌道。';
$pageCanonical = 'https://pulse.subara3.com/lorenz.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; margin: 0; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 1000; font-family: "Orbitron", sans-serif; }
.back-link { top: 1rem; left: 1rem; color: rgba(255,255,255,0.3); text-decoration: none; font-size: 0.7rem; letter-spacing: 0.1em; transition: all 0.3s; }
.back-link:hover { color: #00f5d4; }
.info { bottom: 1rem; left: 1rem; color: rgba(255,255,255,0.3); font-size: 0.6rem; line-height: 1.8; }
.formula { color: rgba(0,245,212,0.5); font-family: serif; font-style: italic; }
.hide-ui .back-link, .hide-ui .info, .hide-ui .content-controls, .hide-ui .floating-nav { opacity: 0 !important; pointer-events: none; }
';

// コントロールボタン設定
$controls = [
  ['id' => 'resetBtn', 'icon' => 'fa-rotate-right', 'label' => 'RESET'],
  ['id' => 'colorBtn', 'icon' => 'fa-palette', 'label' => 'COLOR'],
  ['id' => 'trailBtn', 'icon' => 'fa-route', 'label' => 'TRAIL'],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<div class="ui info">
  Press H to hide UI<br>
  <span class="formula">dx/dt = σ(y - x)</span><br>
  <span class="formula">dy/dt = x(ρ - z) - y</span><br>
  <span class="formula">dz/dt = xy - βz</span>
</div>

<canvas id="canvas"></canvas>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// ローレンツ方程式のパラメータ
const sigma = 10;
const rho = 28;
const beta = 8 / 3;
const dt = 0.005;

// 軌道の点
let points = [];
const maxPoints = 5000;
let trailLength = 2000;

// 現在の位置
let x, y, z;

// 回転角度
let rotateX = 0;
let rotateZ = 0;

// 色
let colorMode = 0;
const colors = [
  { start: '#00f5d4', end: '#7b2cbf' },
  { start: '#ff6b6b', end: '#feca57' },
  { start: '#48dbfb', end: '#ff9ff3' },
  { start: '#1dd1a1', end: '#ee5a24' },
];

function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

function init() {
  // 初期位置（わずかにランダム）
  x = 0.1 + Math.random() * 0.1;
  y = 0;
  z = 0;
  points = [];
}

function step() {
  // ローレンツ方程式
  const dx = sigma * (y - x) * dt;
  const dy = (x * (rho - z) - y) * dt;
  const dz = (x * y - beta * z) * dt;
  
  x += dx;
  y += dy;
  z += dz;
  
  points.push({ x, y, z });
  
  if (points.length > maxPoints) {
    points.shift();
  }
}

function project(px, py, pz) {
  // Y軸回転
  const cosZ = Math.cos(rotateZ);
  const sinZ = Math.sin(rotateZ);
  const x1 = px * cosZ - py * sinZ;
  const y1 = px * sinZ + py * cosZ;
  
  // X軸回転
  const cosX = Math.cos(rotateX);
  const sinX = Math.sin(rotateX);
  const y2 = y1 * cosX - pz * sinX;
  const z2 = y1 * sinX + pz * cosX;
  
  // スケーリングと中心移動
  const scale = 10;
  const cx = canvas.width / 2;
  const cy = canvas.height / 2;
  
  return {
    x: cx + x1 * scale,
    y: cy - z2 * scale + y2 * 2
  };
}

function draw() {
  // 残像効果
  ctx.fillStyle = 'rgba(10, 10, 15, 0.1)';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  
  if (points.length < 2) return;
  
  const scheme = colors[colorMode];
  const visible = Math.min(points.length, trailLength);
  const startIdx = points.length - visible;
  
  for (let i = startIdx + 1; i < points.length; i++) {
    const p1 = project(points[i - 1].x, points[i - 1].y, points[i - 1].z);
    const p2 = project(points[i].x, points[i].y, points[i].z);
    
    const progress = (i - startIdx) / visible;
    
    ctx.beginPath();
    ctx.moveTo(p1.x, p1.y);
    ctx.lineTo(p2.x, p2.y);
    
    // グラデーション
    const alpha = 0.3 + progress * 0.7;
    ctx.strokeStyle = lerpColor(scheme.start, scheme.end, progress, alpha);
    ctx.lineWidth = 1 + progress * 1.5;
    ctx.stroke();
  }
  
  // 現在位置のドット
  const current = project(x, y, z);
  ctx.beginPath();
  ctx.arc(current.x, current.y, 4, 0, Math.PI * 2);
  ctx.fillStyle = '#fff';
  ctx.fill();
}

function lerpColor(c1, c2, t, alpha) {
  const r1 = parseInt(c1.slice(1, 3), 16);
  const g1 = parseInt(c1.slice(3, 5), 16);
  const b1 = parseInt(c1.slice(5, 7), 16);
  const r2 = parseInt(c2.slice(1, 3), 16);
  const g2 = parseInt(c2.slice(3, 5), 16);
  const b2 = parseInt(c2.slice(5, 7), 16);
  
  const r = Math.round(r1 + (r2 - r1) * t);
  const g = Math.round(g1 + (g2 - g1) * t);
  const b = Math.round(b1 + (b2 - b1) * t);
  
  return `rgba(${r},${g},${b},${alpha})`;
}

function animate() {
  // ゆっくり回転
  rotateZ += 0.002;
  rotateX = Math.sin(Date.now() * 0.0003) * 0.3;
  
  // 複数ステップ進める
  for (let i = 0; i < 5; i++) {
    step();
  }
  
  draw();
  requestAnimationFrame(animate);
}

// イベント
window.addEventListener('resize', resize);

document.getElementById('resetBtn').addEventListener('click', () => {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  init();
});

document.getElementById('colorBtn').addEventListener('click', () => {
  colorMode = (colorMode + 1) % colors.length;
});

document.getElementById('trailBtn').addEventListener('click', () => {
  trailLength = trailLength === 2000 ? 500 : trailLength === 500 ? 5000 : 2000;
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'h' || e.key === 'H') {
    document.body.classList.toggle('hide-ui');
  }
});

resize();
init();
animate();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
