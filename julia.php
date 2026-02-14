<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('julia');

$pageName = 'julia';
$pageTitle = 'JULIA SET - PULSE';
$pageDesc = 'ジュリア集合。マンデルブロの仲間。パラメータで無限の形を生む。';
$pageCanonical = 'https://pulse.subara3.com/julia.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: crosshair; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1.5rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.75rem; color: rgba(255,255,255,0.5); background: rgba(0,0,0,0.5); padding: 1rem; border-radius: 8px; }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; max-width: 400px; }
.control-btn { padding: 0.5rem 1rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.7rem; line-height: 1.8; }
.loading { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); color: rgba(255,255,255,0.3); font-family: "Orbitron", sans-serif; font-size: 0.9rem; display: none; }
.loading.show { display: block; }
.presets { position: fixed; top: 50%; right: 1.5rem; transform: translateY(-50%); display: flex; flex-direction: column; gap: 0.3rem; }
.preset-btn { padding: 0.4rem 0.8rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.4); font-family: "JetBrains Mono", monospace; font-size: 0.65rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; text-align: left; }
.preset-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.3); color: #00f5d4; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">JULIA SET</div>
<div class="ui stats">
  <div>c = <span id="cReal">-0.7</span> + <span id="cImag">0.27</span>i</div>
  <div>zoom: <span id="zoomLevel">1</span>x</div>
  <div>iterations: <span id="iterations">100</span></div>
</div>
<div class="ui hint">
  Move → change c<br>
  Scroll → zoom<br>
  Click → lock
</div>
<div class="loading" id="loading">RENDERING...</div>
<div class="ui controls">
  <button class="control-btn" id="animateBtn">▶ ANIMATE</button>
  <button class="control-btn" id="resetBtn">RESET</button>
  <button class="control-btn active" data-theme="pulse">PULSE</button>
  <button class="control-btn" data-theme="ember">EMBER</button>
  <button class="control-btn" data-theme="deep">DEEP</button>
  <button class="control-btn" data-theme="psyche">PSYCHE</button>
</div>
<div class="ui presets">
  <button class="preset-btn" data-c="-0.7,0.27015">▸ Classic</button>
  <button class="preset-btn" data-c="-0.8,0.156">▸ Spiral</button>
  <button class="preset-btn" data-c="-0.4,0.6">▸ Dendrite</button>
  <button class="preset-btn" data-c="0.285,0.01">▸ Galaxy</button>
  <button class="preset-btn" data-c="-0.835,-0.2321">▸ Dragon</button>
  <button class="preset-btn" data-c="-0.70176,-0.3842">▸ Starfish</button>
  <button class="preset-btn" data-c="0.355,0.355">▸ Rabbit</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
const loading = document.getElementById('loading');

let width, height;
let cReal = -0.7;
let cImag = 0.27015;
let zoom = 1;
let centerX = 0;
let centerY = 0;
let maxIterations = 100;
let currentTheme = 'pulse';
let locked = false;
let animating = false;
let animAngle = 0;

const THEMES = {
  pulse: (t) => {
    // Teal primary (#00f5d4) - site accent
    const r = Math.floor(Math.sin(t * 3) * 30);
    const g = Math.floor(Math.sin(t * 3 + 1) * 60 + 195);
    const b = Math.floor(Math.sin(t * 3 + 2) * 40 + 172);
    return [Math.max(0, r), Math.min(255, g), Math.min(255, b)];
  },
  ember: (t) => {
    // Magenta-purple range (hue 290-330)
    const r = Math.floor(180 * Math.min(1, t * 2.5) + 75 * t);
    const g = Math.floor(50 * Math.pow(t, 2));
    const b = Math.floor(191 * Math.min(1, t * 2));
    return [Math.min(255, r), g, Math.min(255, b)];
  },
  deep: (t) => {
    // Teal-to-blue (hue 185-220)
    const r = Math.floor(20 * t);
    const g = Math.floor(120 + 125 * t);
    const b = Math.floor(170 + 85 * t);
    return [r, Math.min(255, g), Math.min(255, b)];
  },
  psyche: (t) => {
    // Constrained teal-purple cycle (hue 160-300)
    const hue = 160 + (Math.sin(t * 10) * 0.5 + 0.5) * 140;
    const s = 0.8;
    const l = 0.55 + Math.sin(t * 10 + 2) * 0.15;
    // HSL to RGB conversion
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((hue / 60) % 2) - 1));
    const m = l - c / 2;
    let r1, g1, b1;
    if (hue < 180) { r1 = 0; g1 = c; b1 = x; }
    else if (hue < 240) { r1 = 0; g1 = x; b1 = c; }
    else if (hue < 300) { r1 = x; g1 = 0; b1 = c; }
    else { r1 = c; g1 = 0; b1 = x; }
    return [Math.floor((r1 + m) * 255), Math.floor((g1 + m) * 255), Math.floor((b1 + m) * 255)];
  }
};

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  render();
}

function render() {
  loading.classList.add('show');
  
  setTimeout(() => {
    const imageData = ctx.createImageData(width, height);
    const data = imageData.data;
    
    const aspectRatio = width / height;
    const rangeX = 4 / zoom;
    const rangeY = rangeX / aspectRatio;
    
    const minX = centerX - rangeX / 2;
    const maxX = centerX + rangeX / 2;
    const minY = centerY - rangeY / 2;
    const maxY = centerY + rangeY / 2;
    
    maxIterations = Math.floor(100 + Math.log(zoom) * 30);
    
    const colorFunc = THEMES[currentTheme];
    
    for (let py = 0; py < height; py++) {
      for (let px = 0; px < width; px++) {
        let x = minX + (px / width) * (maxX - minX);
        let y = minY + (py / height) * (maxY - minY);
        
        let iteration = 0;
        
        while (x * x + y * y <= 4 && iteration < maxIterations) {
          const xTemp = x * x - y * y + cReal;
          y = 2 * x * y + cImag;
          x = xTemp;
          iteration++;
        }
        
        const idx = (py * width + px) * 4;
        
        if (iteration === maxIterations) {
          data[idx] = 0;
          data[idx + 1] = 0;
          data[idx + 2] = 0;
        } else {
          const t = iteration / maxIterations;
          const [r, g, b] = colorFunc(t);
          data[idx] = r;
          data[idx + 1] = g;
          data[idx + 2] = b;
        }
        data[idx + 3] = 255;
      }
    }
    
    ctx.putImageData(imageData, 0, 0);
    updateStats();
    loading.classList.remove('show');
  }, 10);
}

function updateStats() {
  document.getElementById('cReal').textContent = cReal.toFixed(4);
  document.getElementById('cImag').textContent = cImag.toFixed(4);
  document.getElementById('zoomLevel').textContent = zoom.toFixed(0);
  document.getElementById('iterations').textContent = maxIterations;
}

function reset() {
  cReal = -0.7;
  cImag = 0.27015;
  zoom = 1;
  centerX = 0;
  centerY = 0;
  locked = false;
  render();
}

canvas.addEventListener('mousemove', (e) => {
  if (locked || animating) return;
  
  // マウス位置からcの値を計算
  cReal = (e.clientX / width - 0.5) * 2;
  cImag = (e.clientY / height - 0.5) * 2;
  
  render();
});

canvas.addEventListener('click', () => {
  if (animating) return;
  locked = !locked;
});

canvas.addEventListener('wheel', (e) => {
  e.preventDefault();
  const factor = e.deltaY < 0 ? 1.5 : 0.67;
  zoom *= factor;
  if (zoom < 0.5) zoom = 0.5;
  if (zoom > 1e6) zoom = 1e6;
  render();
});

// プリセット
document.querySelectorAll('[data-c]').forEach(btn => {
  btn.addEventListener('click', function() {
    const [r, i] = this.dataset.c.split(',').map(Number);
    cReal = r;
    cImag = i;
    locked = true;
    render();
  });
});

// テーマ
document.querySelectorAll('[data-theme]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-theme]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentTheme = this.dataset.theme;
    render();
  });
});

// アニメーション
document.getElementById('animateBtn').addEventListener('click', function() {
  animating = !animating;
  this.textContent = animating ? '⏸ PAUSE' : '▶ ANIMATE';
  this.classList.toggle('active', animating);
  if (animating) animate();
});

function animate() {
  if (!animating) return;
  
  animAngle += 0.02;
  cReal = 0.7885 * Math.cos(animAngle);
  cImag = 0.7885 * Math.sin(animAngle);
  
  render();
  setTimeout(() => requestAnimationFrame(animate), 50);
}

document.getElementById('resetBtn').addEventListener('click', reset);

window.addEventListener('resize', init);

init();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
