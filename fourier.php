<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('fourier');

$pageName = 'fourier';
$pageTitle = 'FOURIER - PULSE';
$pageDesc = 'フーリエ変換の可視化。回転する円の連鎖が図形を描く。';
$pageCanonical = 'https://pulse.subara3.com/fourier.php';

$extraHead = '';

$extraStyle = '
body {
  background: #0a0a0f;
  overflow: hidden;
}

.container {
  position: relative;
  width: 100vw;
  height: 100vh;
}

canvas {
  display: block;
  width: 100%;
  height: 100%;
}

/* HUD */
.hud {
  position: fixed;
  top: 1rem;
  left: 1rem;
  z-index: 100;
  pointer-events: none;
}

.hud-panel {
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  backdrop-filter: blur(10px);
}

.hud-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  color: var(--accent);
  letter-spacing: 0.2em;
  margin-bottom: 0.8rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hud-title::before {
  content: "";
  width: 8px;
  height: 8px;
  background: var(--accent);
  border-radius: 50%;
  animation: hud-pulse 1.5s infinite;
}

@keyframes hud-pulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 10px var(--accent); }
  50% { opacity: 0.5; box-shadow: 0 0 5px var(--accent); }
}

.hud-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 0.3rem 0;
  border-bottom: 1px solid rgba(0, 245, 212, 0.1);
  gap: 1rem;
}

.hud-row:last-child {
  border-bottom: none;
}

.hud-label {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.7rem;
  color: rgba(255, 255, 255, 0.5);
}

.hud-value {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.9rem;
  color: var(--accent);
  text-align: right;
}

/* 図形選択 */
.shape-select {
  position: fixed;
  top: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  justify-content: center;
  pointer-events: auto;
}

.shape-btn {
  padding: 0.5rem 1rem;
  border-radius: 8px;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: rgba(255, 255, 255, 0.6);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  transition: all 0.2s;
  backdrop-filter: blur(10px);
}

.shape-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.shape-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: var(--accent);
  color: var(--accent);
  box-shadow: 0 0 15px rgba(0, 245, 212, 0.3);
}

/* コントロール */
.controls {
  position: fixed;
  bottom: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  gap: 0.8rem;
  flex-wrap: wrap;
  justify-content: center;
  pointer-events: auto;
}

.ctrl-btn {
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 0.6rem 1.2rem;
  color: var(--accent);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  backdrop-filter: blur(10px);
}

.ctrl-btn:hover {
  background: rgba(0, 245, 212, 0.1);
  border-color: var(--accent);
  box-shadow: 0 0 20px rgba(0, 245, 212, 0.2);
}

.ctrl-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: var(--accent);
}

/* スライダー */
.slider-panel {
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 1rem;
  backdrop-filter: blur(10px);
}

.slider-row {
  margin-bottom: 0.8rem;
}

.slider-row:last-child {
  margin-bottom: 0;
}

.slider-label {
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  color: rgba(255, 255, 255, 0.5);
  margin-bottom: 0.4rem;
  display: flex;
  justify-content: space-between;
}

.slider-label span {
  color: var(--accent);
}

input[type="range"] {
  width: 120px;
  height: 4px;
  -webkit-appearance: none;
  background: rgba(0, 245, 212, 0.2);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 14px;
  height: 14px;
  background: var(--accent);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 0 10px var(--accent);
}

/* 説明 */
.info-panel {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  border-radius: 8px;
  padding: 1rem;
  max-width: 200px;
  backdrop-filter: blur(10px);
}

.info-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  color: var(--accent);
  margin-bottom: 0.5rem;
}

.info-text {
  font-size: 0.7rem;
  color: rgba(255, 255, 255, 0.5);
  line-height: 1.6;
}

@media (max-width: 768px) {
  .info-panel { display: none; }
  .slider-panel { bottom: 4rem; right: 0.5rem; padding: 0.8rem; }
  .shape-select { top: auto; bottom: 7rem; padding: 0 0.5rem; }
  .shape-btn { padding: 0.4rem 0.7rem; font-size: 0.6rem; }
  .controls { bottom: 0.5rem; }
  .ctrl-btn { padding: 0.5rem 0.8rem; font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<div class="container">
  <canvas id="canvas"></canvas>
</div>

<!-- HUD -->
<div class="hud">
  <div class="hud-panel">
    <div class="hud-title">FOURIER EPICYCLES</div>
    <div class="hud-row">
      <span class="hud-label">CIRCLES</span>
      <span class="hud-value" id="circleCount">0</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">PROGRESS</span>
      <span class="hud-value" id="progress">0%</span>
    </div>
  </div>
</div>

<!-- 図形選択 -->
<div class="shape-select" id="shapeSelect">
  <button class="shape-btn active" data-shape="square">SQUARE</button>
  <button class="shape-btn" data-shape="star">STAR</button>
  <button class="shape-btn" data-shape="heart">HEART</button>
  <button class="shape-btn" data-shape="pi">PI</button>
  <button class="shape-btn" data-shape="treble">TREBLE</button>
  <button class="shape-btn" data-shape="infinity">INFINITY</button>
</div>

<!-- 説明 -->
<div class="info-panel">
  <div class="info-title"><i class="fa-solid fa-circle-info"></i> ABOUT</div>
  <div class="info-text">
    Fourier transform decomposes any shape into rotating circles. Each circle's radius and speed come from frequency analysis.
  </div>
</div>

<!-- コントロール -->
<div class="controls">
  <button class="ctrl-btn" id="pauseBtn"><i class="fa-solid fa-pause"></i> PAUSE</button>
  <button class="ctrl-btn" id="resetBtn"><i class="fa-solid fa-rotate"></i> RESET</button>
  <button class="ctrl-btn" id="circlesBtn"><i class="fa-solid fa-eye"></i> CIRCLES</button>
</div>

<!-- スライダー -->
<div class="slider-panel">
  <div class="slider-row">
    <div class="slider-label">SPEED <span id="speedValue">1x</span></div>
    <input type="range" id="speedSlider" min="1" max="10" value="3">
  </div>
  <div class="slider-row">
    <div class="slider-label">DETAIL <span id="detailValue">50</span></div>
    <input type="range" id="detailSlider" min="10" max="150" value="50">
  </div>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// 設定
let time = 0;
let paused = false;
let speed = 3;
let numCircles = 50;
let showCircles = true;
let path = [];
let fourierX = [];
let fourierY = [];
let currentShape = 'square';

// DOM
const circleCountEl = document.getElementById('circleCount');
const progressEl = document.getElementById('progress');
const speedValueEl = document.getElementById('speedValue');
const detailValueEl = document.getElementById('detailValue');

// 図形データ生成
const shapes = {
  square: () => {
    const points = [];
    const size = 150;
    // 正方形の4辺
    for (let i = 0; i <= 50; i++) points.push({ x: -size + i * (size * 2 / 50), y: -size });
    for (let i = 0; i <= 50; i++) points.push({ x: size, y: -size + i * (size * 2 / 50) });
    for (let i = 0; i <= 50; i++) points.push({ x: size - i * (size * 2 / 50), y: size });
    for (let i = 0; i <= 50; i++) points.push({ x: -size, y: size - i * (size * 2 / 50) });
    return points;
  },

  star: () => {
    const points = [];
    const outerR = 150, innerR = 60;
    const spikes = 5;
    for (let i = 0; i < spikes * 2; i++) {
      const r = i % 2 === 0 ? outerR : innerR;
      const angle = (i * Math.PI / spikes) - Math.PI / 2;
      points.push({ x: Math.cos(angle) * r, y: Math.sin(angle) * r });
    }
    // 補間
    const result = [];
    for (let i = 0; i < points.length; i++) {
      const next = points[(i + 1) % points.length];
      for (let t = 0; t < 20; t++) {
        result.push({
          x: points[i].x + (next.x - points[i].x) * (t / 20),
          y: points[i].y + (next.y - points[i].y) * (t / 20)
        });
      }
    }
    return result;
  },

  heart: () => {
    const points = [];
    for (let t = 0; t < Math.PI * 2; t += 0.05) {
      const x = 16 * Math.pow(Math.sin(t), 3);
      const y = -(13 * Math.cos(t) - 5 * Math.cos(2*t) - 2 * Math.cos(3*t) - Math.cos(4*t));
      points.push({ x: x * 9, y: y * 9 });
    }
    return points;
  },

  pi: () => {
    const points = [];
    // πの形を手動で定義
    const scale = 2;
    const piPath = [
      // 上のバー
      {x: -60, y: -60}, {x: 60, y: -60},
      // 右の縦棒
      {x: 50, y: -60}, {x: 50, y: 60},
      // 下へ移動
      {x: 50, y: 60}, {x: 40, y: 60},
      // 右棒戻り
      {x: 40, y: -50}, {x: -10, y: -50},
      // 左の縦棒
      {x: -10, y: -50}, {x: -10, y: 40},
      // 左下カーブ
      {x: -10, y: 50}, {x: -20, y: 60}, {x: -35, y: 60},
      {x: -45, y: 55}, {x: -50, y: 40},
      // 戻り
      {x: -50, y: 40}, {x: -40, y: 40},
      {x: -35, y: 50}, {x: -25, y: 50},
      {x: -20, y: 45}, {x: -20, y: -50},
      // 上に戻る
      {x: -60, y: -50}, {x: -60, y: -60}
    ];
    // 補間
    for (let i = 0; i < piPath.length - 1; i++) {
      const p1 = piPath[i], p2 = piPath[i + 1];
      for (let t = 0; t < 10; t++) {
        points.push({
          x: (p1.x + (p2.x - p1.x) * (t / 10)) * scale,
          y: (p1.y + (p2.y - p1.y) * (t / 10)) * scale
        });
      }
    }
    return points;
  },

  treble: () => {
    const points = [];
    // ト音記号（簡易版）
    for (let t = 0; t < Math.PI * 4; t += 0.08) {
      const r = 30 + t * 15;
      const x = Math.cos(t * 1.5) * r * 0.5;
      const y = Math.sin(t) * r * 0.8 - t * 10;
      points.push({ x, y: y + 100 });
    }
    return points;
  },

  infinity: () => {
    const points = [];
    const a = 120;
    for (let t = 0; t < Math.PI * 2; t += 0.05) {
      const x = a * Math.cos(t) / (1 + Math.sin(t) * Math.sin(t));
      const y = a * Math.sin(t) * Math.cos(t) / (1 + Math.sin(t) * Math.sin(t));
      points.push({ x: x * 1.5, y: y * 1.5 });
    }
    return points;
  }
};

// 離散フーリエ変換
function dft(x) {
  const X = [];
  const N = x.length;
  for (let k = 0; k < N; k++) {
    let re = 0, im = 0;
    for (let n = 0; n < N; n++) {
      const phi = (2 * Math.PI * k * n) / N;
      re += x[n] * Math.cos(phi);
      im -= x[n] * Math.sin(phi);
    }
    re /= N;
    im /= N;
    X.push({
      re, im,
      freq: k,
      amp: Math.sqrt(re * re + im * im),
      phase: Math.atan2(im, re)
    });
  }
  return X;
}

// 図形を設定
function setShape(name) {
  currentShape = name;
  const points = shapes[name]();

  const x = points.map(p => p.x);
  const y = points.map(p => p.y);

  fourierX = dft(x).sort((a, b) => b.amp - a.amp).slice(0, numCircles);
  fourierY = dft(y).sort((a, b) => b.amp - a.amp).slice(0, numCircles);

  time = 0;
  path = [];

  circleCountEl.textContent = Math.min(fourierX.length, numCircles);

  // ボタン更新
  document.querySelectorAll('.shape-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.shape === name);
  });
}

// エピサイクル描画
function epicycles(x, y, rotation, fourier) {
  for (let i = 0; i < Math.min(fourier.length, numCircles); i++) {
    const prevX = x;
    const prevY = y;

    const f = fourier[i];
    const radius = f.amp;
    const angle = f.freq * time + f.phase + rotation;

    x += radius * Math.cos(angle);
    y += radius * Math.sin(angle);

    if (showCircles) {
      // 円
      ctx.beginPath();
      ctx.arc(prevX, prevY, radius, 0, Math.PI * 2);
      ctx.strokeStyle = `rgba(0, 245, 212, ${0.15 - i * 0.002})`;
      ctx.lineWidth = 1;
      ctx.stroke();

      // 半径線
      ctx.beginPath();
      ctx.moveTo(prevX, prevY);
      ctx.lineTo(x, y);
      ctx.strokeStyle = `rgba(0, 245, 212, ${0.4 - i * 0.005})`;
      ctx.lineWidth = 1;
      ctx.stroke();
    }
  }

  return { x, y };
}

// リサイズ
function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

// 描画
function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // グリッド
  ctx.strokeStyle = 'rgba(0, 245, 212, 0.03)';
  ctx.lineWidth = 1;
  for (let x = 0; x < canvas.width; x += 50) {
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, canvas.height);
    ctx.stroke();
  }
  for (let y = 0; y < canvas.height; y += 50) {
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(canvas.width, y);
    ctx.stroke();
  }

  const centerX = canvas.width / 2;
  const centerY = canvas.height / 2;

  // X軸のエピサイクル（上部）
  const vx = epicycles(centerX, centerY - 180, 0, fourierX);

  // Y軸のエピサイクル（左部）
  const vy = epicycles(centerX - 250, centerY + 50, Math.PI / 2, fourierY);

  // 描画点
  const drawX = vx.x;
  const drawY = vy.y;

  // ガイドライン
  if (showCircles) {
    ctx.beginPath();
    ctx.moveTo(vx.x, vx.y);
    ctx.lineTo(drawX, drawY);
    ctx.moveTo(vy.x, vy.y);
    ctx.lineTo(drawX, drawY);
    ctx.strokeStyle = 'rgba(255, 215, 0, 0.3)';
    ctx.lineWidth = 1;
    ctx.setLineDash([5, 5]);
    ctx.stroke();
    ctx.setLineDash([]);
  }

  // 現在の点
  ctx.beginPath();
  ctx.arc(drawX, drawY, 4, 0, Math.PI * 2);
  ctx.fillStyle = '#00f5d4';
  ctx.shadowColor = '#00f5d4';
  ctx.shadowBlur = 15;
  ctx.fill();
  ctx.shadowBlur = 0;

  // パスに追加
  path.unshift({ x: drawX, y: drawY });

  // パス描画
  ctx.beginPath();
  for (let i = 0; i < path.length; i++) {
    const p = path[i];
    if (i === 0) {
      ctx.moveTo(p.x, p.y);
    } else {
      ctx.lineTo(p.x, p.y);
    }
  }
  ctx.strokeStyle = '#00f5d4';
  ctx.lineWidth = 2;
  ctx.shadowColor = '#00f5d4';
  ctx.shadowBlur = 10;
  ctx.stroke();
  ctx.shadowBlur = 0;

  // 進捗更新
  const progressPercent = ((time / (Math.PI * 2)) * 100).toFixed(0);
  progressEl.textContent = progressPercent + '%';
}

// 更新
function update() {
  if (!paused) {
    const dt = speed * 0.001;
    time += dt;

    // 一周したらリセット
    if (time >= Math.PI * 2) {
      time = 0;
      path = [];
    }
  }
}

// メインループ
function loop() {
  update();
  draw();
  requestAnimationFrame(loop);
}

// イベント
window.addEventListener('resize', resize);

document.getElementById('pauseBtn').addEventListener('click', function() {
  paused = !paused;
  this.innerHTML = paused
    ? '<i class="fa-solid fa-play"></i> PLAY'
    : '<i class="fa-solid fa-pause"></i> PAUSE';
  this.classList.toggle('active', paused);
});

document.getElementById('resetBtn').addEventListener('click', () => {
  time = 0;
  path = [];
});

document.getElementById('circlesBtn').addEventListener('click', function() {
  showCircles = !showCircles;
  this.classList.toggle('active', !showCircles);
  this.innerHTML = showCircles
    ? '<i class="fa-solid fa-eye"></i> CIRCLES'
    : '<i class="fa-solid fa-eye-slash"></i> CIRCLES';
});

document.getElementById('speedSlider').addEventListener('input', function() {
  speed = parseInt(this.value);
  speedValueEl.textContent = speed + 'x';
});

document.getElementById('detailSlider').addEventListener('input', function() {
  numCircles = parseInt(this.value);
  detailValueEl.textContent = numCircles;
  setShape(currentShape);
});

document.querySelectorAll('.shape-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    setShape(btn.dataset.shape);
  });
});

// 初期化
resize();
setShape('square');
loop();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
