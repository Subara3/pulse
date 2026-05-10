<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('reaction');

$pageName = 'reaction';
$pageTitle = 'REACTION - PULSE';
$pageDesc = '反応拡散系。チューリングパターンが生み出す有機的な模様。クリックで種を蒔こう。';
$pageCanonical = 'https://pulse.subara3.com/reaction.php';

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
  image-rendering: pixelated;
  image-rendering: crisp-edges;
}

/* HUD */
.hud {position:fixed;top:3.5rem;right:1rem;z-index: 100;pointer-events: none;}

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

/* プリセット選択 */
.preset-select {
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

.preset-btn {
  padding: 0.5rem 1rem;
  border-radius: 8px;
  background: rgba(0, 10, 15, 0.9);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: rgba(255, 255, 255, 0.6);
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  cursor: pointer;
  transition: all 0.2s;
  backdrop-filter: blur(10px);
  letter-spacing: 0.05em;
}

.preset-btn:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.preset-btn.active {
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
  max-width: 220px;
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

.info-turing {
  margin-top: 0.6rem;
  padding-top: 0.6rem;
  border-top: 1px solid rgba(0, 245, 212, 0.15);
  font-family: "JetBrains Mono", monospace;
  font-size: 0.6rem;
  color: rgba(0, 245, 212, 0.4);
  letter-spacing: 0.1em;
}

/* ブラシカーソル */
.brush-cursor {
  position: fixed;
  border: 1px solid rgba(0, 245, 212, 0.6);
  border-radius: 50%;
  pointer-events: none;
  z-index: 50;
  transform: translate(-50%, -50%);
  transition: width 0.15s, height 0.15s;
}

@media (max-width: 768px) {
  .info-panel { display: none; }
  .slider-panel { bottom: 4rem; right: 0.5rem; padding: 0.8rem; }
  .preset-select { top: auto; bottom: 7rem; padding: 0 0.5rem; }
  .preset-btn { padding: 0.4rem 0.7rem; font-size: 0.55rem; }
  .controls { bottom: 0.5rem; }
  
  .brush-cursor { display: none; }
  input[type="range"] { width: 90px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
  ['type' => 'drag', 'label' => 'drag'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
?>

<div class="container">
  <canvas id="canvas"></canvas>
</div>

<!-- ブラシカーソル -->
<div class="brush-cursor" id="brushCursor"></div>

<!-- HUD -->
<div class="hud">
  <div class="hud-panel">
    <div class="hud-title">REACTION DIFFUSION</div>
    <div class="hud-row">
      <span class="hud-label">FEED</span>
      <span class="hud-value" id="feedDisplay">0.0367</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">KILL</span>
      <span class="hud-value" id="killDisplay">0.0649</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">STEP</span>
      <span class="hud-value" id="stepDisplay">0</span>
    </div>
    <div class="hud-row">
      <span class="hud-label">FPS</span>
      <span class="hud-value" id="fpsDisplay">0</span>
    </div>
  </div>
</div>

<!-- プリセット選択 -->
<div class="preset-select" id="presetSelect">
  <button class="preset-btn active" data-preset="mitosis">MITOSIS</button>
  <button class="preset-btn" data-preset="coral">CORAL</button>
  <button class="preset-btn" data-preset="maze">MAZE</button>
  <button class="preset-btn" data-preset="stripe">STRIPE</button>
  <button class="preset-btn" data-preset="ripple">RIPPLE</button>
  <button class="preset-btn" data-preset="worms">WORMS</button>
</div>

<!-- 説明 -->
<div class="info-panel">
  <div class="info-title"><i class="fa-solid fa-circle-info"></i> ABOUT</div>
  <div class="info-text">
    2つの化学物質が拡散と反応を繰り返し、生物のような有機的パターンを生む。クリック/ドラッグで種を蒔ける。
  </div>
  <div class="info-turing">— ALAN TURING, 1952</div>
</div>

<!-- コントロール -->
<div class="controls">
  <button class="control-btn" id="pauseBtn"><i class="fa-solid fa-pause"></i> PAUSE</button>
  <button class="control-btn" id="resetBtn"><i class="fa-solid fa-rotate"></i> RESET</button>
  <button class="control-btn" id="clearBtn"><i class="fa-solid fa-eraser"></i> CLEAR</button>
</div>

<!-- スライダー -->
<div class="slider-panel">
  <div class="slider-row">
    <div class="slider-label">FEED <span id="feedValue">0.0367</span></div>
    <input type="range" id="feedSlider" min="1" max="80" value="37" step="1">
  </div>
  <div class="slider-row">
    <div class="slider-label">KILL <span id="killValue">0.0649</span></div>
    <input type="range" id="killSlider" min="40" max="70" value="65" step="1">
  </div>
  <div class="slider-row">
    <div class="slider-label">SPEED <span id="speedValue">8</span></div>
    <input type="range" id="speedSlider" min="1" max="20" value="8">
  </div>
  <div class="slider-row">
    <div class="slider-label">BRUSH <span id="brushValue">5</span></div>
    <input type="range" id="brushSlider" min="1" max="15" value="5">
  </div>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
const brushCursor = document.getElementById('brushCursor');

// ── シミュレーション設定 ──
const isMobile = window.innerWidth <= 768;
const SIM_SCALE = isMobile ? 6 : 4;
let simW, simH;
let U, V, nextU, nextV;
let imageData, pixels;

let feed = 0.0367;
let kill = 0.0649;
const Du = 1.0;
const Dv = 0.5;
let stepsPerFrame = 8;
let brushSize = 5;

let paused = false;
let stepCount = 0;
let painting = false;
let lastMouseSim = null;

// FPS計測
let frameCount = 0;
let lastFpsTime = performance.now();
let currentFps = 0;

// ── プリセット ──
const presets = {
  mitosis: { f: 0.0367, k: 0.0649 },
  coral:   { f: 0.0545, k: 0.0620 },
  maze:    { f: 0.0290, k: 0.0570 },
  stripe:  { f: 0.0620, k: 0.0609 },
  ripple:  { f: 0.0140, k: 0.0540 },
  worms:   { f: 0.0780, k: 0.0610 }
};
let currentPreset = 'mitosis';

// ── カラーLUT（256エントリ）──
const colorLUT = new Uint8Array(256 * 3);

function buildColorLUT() {
  const stops = [
    { t: 0.00, r: 10,  g: 10,  b: 15  },
    { t: 0.10, r: 18,  g: 8,   b: 48  },
    { t: 0.25, r: 100, g: 30,  b: 160 },
    { t: 0.40, r: 40,  g: 90,  b: 150 },
    { t: 0.55, r: 0,   g: 200, b: 180 },
    { t: 0.70, r: 0,   g: 245, b: 212 },
    { t: 0.85, r: 140, g: 255, b: 238 },
    { t: 1.00, r: 240, g: 255, b: 252 }
  ];

  for (let i = 0; i < 256; i++) {
    const t = i / 255;
    let s0 = stops[0], s1 = stops[stops.length - 1];
    for (let j = 0; j < stops.length - 1; j++) {
      if (t >= stops[j].t && t <= stops[j + 1].t) {
        s0 = stops[j];
        s1 = stops[j + 1];
        break;
      }
    }
    const lt = (s1.t - s0.t) > 0 ? (t - s0.t) / (s1.t - s0.t) : 0;
    const st = lt * lt * (3 - 2 * lt); // smoothstep
    colorLUT[i * 3 + 0] = Math.round(s0.r + (s1.r - s0.r) * st);
    colorLUT[i * 3 + 1] = Math.round(s0.g + (s1.g - s0.g) * st);
    colorLUT[i * 3 + 2] = Math.round(s0.b + (s1.b - s0.b) * st);
  }
}

// ── シミュレーション初期化 ──
function initSim() {
  const size = simW * simH;
  U = new Float32Array(size).fill(1.0);
  V = new Float32Array(size).fill(0.0);
  nextU = new Float32Array(size);
  nextV = new Float32Array(size);
  imageData = ctx.createImageData(simW, simH);
  pixels = imageData.data;
  // α=255で初期化
  for (let i = 3; i < pixels.length; i += 4) pixels[i] = 255;

  stepCount = 0;
  addRandomSeeds(10);
}

function addRandomSeeds(count) {
  const margin = 15;
  for (let s = 0; s < count; s++) {
    const cx = margin + Math.floor(Math.random() * (simW - margin * 2));
    const cy = margin + Math.floor(Math.random() * (simH - margin * 2));
    const r = 2 + Math.floor(Math.random() * 4);
    seedAt(cx, cy, r);
  }
}

function seedAt(cx, cy, radius) {
  for (let dy = -radius; dy <= radius; dy++) {
    for (let dx = -radius; dx <= radius; dx++) {
      if (dx * dx + dy * dy > radius * radius) continue;
      const x = cx + dx;
      const y = cy + dy;
      if (x < 0 || x >= simW || y < 0 || y >= simH) continue;
      const idx = y * simW + x;
      U[idx] = 0.0;
      V[idx] = 1.0;
    }
  }
}

// ── Bresenham線分上にシードを打つ ──
function seedLine(x0, y0, x1, y1, r) {
  const dx = Math.abs(x1 - x0);
  const dy = Math.abs(y1 - y0);
  const sx = x0 < x1 ? 1 : -1;
  const sy = y0 < y1 ? 1 : -1;
  let err = dx - dy;

  while (true) {
    seedAt(x0, y0, r);
    if (x0 === x1 && y0 === y1) break;
    const e2 = 2 * err;
    if (e2 > -dy) { err -= dy; x0 += sx; }
    if (e2 < dx)  { err += dx; y0 += sy; }
  }
}

// ── シミュレーション1ステップ ──
function simulate() {
  const w = simW;
  const h = simH;

  for (let y = 0; y < h; y++) {
    const yUp   = y > 0     ? y - 1 : h - 1;
    const yDown = y < h - 1 ? y + 1 : 0;
    const rowC = y * w;
    const rowU = yUp * w;
    const rowD = yDown * w;

    for (let x = 0; x < w; x++) {
      const xL = x > 0     ? x - 1 : w - 1;
      const xR = x < w - 1 ? x + 1 : 0;

      const idx = rowC + x;
      const u = U[idx];
      const v = V[idx];

      // 3x3 weighted Laplacian
      const lapU =
        0.05 * U[rowU + xL] + 0.2 * U[rowU + x] + 0.05 * U[rowU + xR] +
        0.2  * U[rowC + xL] - 1.0 * u            + 0.2  * U[rowC + xR] +
        0.05 * U[rowD + xL] + 0.2 * U[rowD + x] + 0.05 * U[rowD + xR];

      const lapV =
        0.05 * V[rowU + xL] + 0.2 * V[rowU + x] + 0.05 * V[rowU + xR] +
        0.2  * V[rowC + xL] - 1.0 * v            + 0.2  * V[rowC + xR] +
        0.05 * V[rowD + xL] + 0.2 * V[rowD + x] + 0.05 * V[rowD + xR];

      const uvv = u * v * v;
      let nu = u + Du * lapU - uvv + feed * (1.0 - u);
      let nv = v + Dv * lapV + uvv - (feed + kill) * v;

      // clamp
      if (nu < 0) nu = 0; else if (nu > 1) nu = 1;
      if (nv < 0) nv = 0; else if (nv > 1) nv = 1;

      nextU[idx] = nu;
      nextV[idx] = nv;
    }
  }

  // swap
  const tmpU = U; U = nextU; nextU = tmpU;
  const tmpV = V; V = nextV; nextV = tmpV;
  stepCount++;
}

// ── 描画 ──
function render() {
  const len = simW * simH;
  for (let i = 0; i < len; i++) {
    const ci = Math.min(255, Math.max(0, (V[i] * 255) | 0)) * 3;
    const pi = i * 4;
    pixels[pi]     = colorLUT[ci];
    pixels[pi + 1] = colorLUT[ci + 1];
    pixels[pi + 2] = colorLUT[ci + 2];
  }
  ctx.putImageData(imageData, 0, 0);
}

// ── リサイズ ──
function resize() {
  simW = Math.ceil(window.innerWidth / SIM_SCALE);
  simH = Math.ceil(window.innerHeight / SIM_SCALE);
  canvas.width = simW;
  canvas.height = simH;
  initSim();
}

// ── プリセット適用 ──
function applyPreset(name) {
  const p = presets[name];
  if (!p) return;
  currentPreset = name;
  feed = p.f;
  kill = p.k;

  // スライダー同期
  document.getElementById('feedSlider').value = Math.round(p.f * 10000) / 10;
  document.getElementById('killSlider').value = Math.round(p.k * 10000) / 10;
  updateSliderDisplays();

  // ボタン更新
  document.querySelectorAll('.preset-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.preset === name);
  });

  // リセット
  initSim();
}

function updateSliderDisplays() {
  document.getElementById('feedValue').textContent = feed.toFixed(4);
  document.getElementById('killValue').textContent = kill.toFixed(4);
  document.getElementById('feedDisplay').textContent = feed.toFixed(4);
  document.getElementById('killDisplay').textContent = kill.toFixed(4);
}

// ── マウス/タッチ → シミュレーション座標変換 ──
function toSimCoords(clientX, clientY) {
  const rect = canvas.getBoundingClientRect();
  return {
    x: Math.floor((clientX - rect.left) / rect.width * simW),
    y: Math.floor((clientY - rect.top) / rect.height * simH)
  };
}

// ── メインループ ──
function loop() {
  if (!paused) {
    for (let i = 0; i < stepsPerFrame; i++) {
      simulate();
    }
  }
  render();

  // HUD更新
  document.getElementById('stepDisplay').textContent = stepCount;

  // FPS
  frameCount++;
  const now = performance.now();
  if (now - lastFpsTime >= 1000) {
    currentFps = frameCount;
    frameCount = 0;
    lastFpsTime = now;
    document.getElementById('fpsDisplay').textContent = currentFps;
  }

  requestAnimationFrame(loop);
}

// ── イベント：マウス ──
canvas.addEventListener('mousedown', function(e) {
  painting = true;
  const s = toSimCoords(e.clientX, e.clientY);
  seedAt(s.x, s.y, brushSize);
  lastMouseSim = s;
});

canvas.addEventListener('mousemove', function(e) {
  // ブラシカーソル更新
  const bpx = brushSize * SIM_SCALE * 2;
  brushCursor.style.left = e.clientX + 'px';
  brushCursor.style.top = e.clientY + 'px';
  brushCursor.style.width = bpx + 'px';
  brushCursor.style.height = bpx + 'px';

  if (!painting) return;
  const s = toSimCoords(e.clientX, e.clientY);
  if (lastMouseSim) {
    seedLine(lastMouseSim.x, lastMouseSim.y, s.x, s.y, brushSize);
  } else {
    seedAt(s.x, s.y, brushSize);
  }
  lastMouseSim = s;
});

canvas.addEventListener('mouseup', function() {
  painting = false;
  lastMouseSim = null;
});

canvas.addEventListener('mouseleave', function() {
  painting = false;
  lastMouseSim = null;
});

// ── イベント：タッチ ──
canvas.addEventListener('touchstart', function(e) {
  e.preventDefault();
  painting = true;
  const t = e.touches[0];
  const s = toSimCoords(t.clientX, t.clientY);
  seedAt(s.x, s.y, brushSize);
  lastMouseSim = s;
}, { passive: false });

canvas.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!painting) return;
  const t = e.touches[0];
  const s = toSimCoords(t.clientX, t.clientY);
  if (lastMouseSim) {
    seedLine(lastMouseSim.x, lastMouseSim.y, s.x, s.y, brushSize);
  } else {
    seedAt(s.x, s.y, brushSize);
  }
  lastMouseSim = s;
}, { passive: false });

canvas.addEventListener('touchend', function() {
  painting = false;
  lastMouseSim = null;
});

// ── イベント：ウィンドウ ──
window.addEventListener('resize', resize);

// ── イベント：コントロールボタン ──
document.getElementById('pauseBtn').addEventListener('click', function() {
  paused = !paused;
  this.innerHTML = paused
    ? '<i class="fa-solid fa-play"></i> PLAY'
    : '<i class="fa-solid fa-pause"></i> PAUSE';
  this.classList.toggle('active', paused);
});

document.getElementById('resetBtn').addEventListener('click', function() {
  initSim();
});

document.getElementById('clearBtn').addEventListener('click', function() {
  const size = simW * simH;
  U.fill(1.0);
  V.fill(0.0);
  stepCount = 0;
});

// ── イベント：プリセットボタン ──
document.querySelectorAll('.preset-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    applyPreset(this.dataset.preset);
  });
});

// ── イベント：スライダー ──
document.getElementById('feedSlider').addEventListener('input', function() {
  feed = parseInt(this.value) / 10000;
  updateSliderDisplays();
  // プリセットハイライト解除
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
});

document.getElementById('killSlider').addEventListener('input', function() {
  kill = parseInt(this.value) / 10000;
  updateSliderDisplays();
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
});

document.getElementById('speedSlider').addEventListener('input', function() {
  stepsPerFrame = parseInt(this.value);
  document.getElementById('speedValue').textContent = stepsPerFrame;
});

document.getElementById('brushSlider').addEventListener('input', function() {
  brushSize = parseInt(this.value);
  document.getElementById('brushValue').textContent = brushSize;
});

// ── 初期化 ──
buildColorLUT();
resize();
loop();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
