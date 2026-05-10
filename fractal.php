<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('fractal');

$pageName = 'fractal';
$pageTitle = 'FRACTAL - PULSE';
$pageDesc = 'マンデルブロ集合。無限の複雑さを眺める。';
$pageCanonical = 'https://pulse.subara3.com/fractal.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: grab; }
#canvas:active { cursor: grabbing; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.75rem; color: rgba(255,255,255,0.5); }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.65rem; line-height: 1.8; }
.loading { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); color: rgba(255,255,255,0.3); font-family: "Orbitron", sans-serif; font-size: 0.9rem; display: none; }
.loading.show { display: block; }

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
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">FRACTAL</div>
<div class="ui stats">
  <div>real: <span id="realCoord">-0.5</span></div>
  <div>imag: <span id="imagCoord">0</span></div>
  <div>zoom: <span id="zoomLevel">1</span>x</div>
  <div>iterations: <span id="iterations">100</span></div>
</div>
<div class="ui hint">
  scroll to zoom<br>
  drag to pan<br>
  double-click to center
</div>
<div class="loading" id="loading">RENDERING...</div>
<div id="controls" class="drawer-controls">
  <div class="control-group">
    <button class="control-btn" id="autoBtn">▶ AUTO</button>
    <button class="control-btn" id="resetBtn">RESET</button>
    <button class="control-btn" id="zoomInBtn">ZOOM +</button>
    <button class="control-btn" id="zoomOutBtn">ZOOM -</button>
  </div>
  <div class="theme-group">
    <button class="control-btn active" data-theme="pulse">PULSE</button>
    <button class="control-btn" data-theme="ember">EMBER</button>
    <button class="control-btn" data-theme="deep">DEEP</button>
    <button class="control-btn" data-theme="mono">MONO</button>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-preset="seahorse">▸ Seahorse Valley</button>
    <button class="preset-btn" data-preset="spiral">▸ Spiral</button>
    <button class="preset-btn" data-preset="lightning">▸ Lightning</button>
    <button class="preset-btn" data-preset="elephant">▸ Elephant Valley</button>
    <button class="preset-btn" data-preset="doubleSpiral">▸ Double Spiral</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');
const loading = document.getElementById('loading');

// フラクタルパラメータ
let centerX = -0.5;
let centerY = 0;
let zoom = 1;
let maxIterations = 100;
let currentTheme = 'pulse';

// ドラッグ状態
let isDragging = false;
let dragStartX, dragStartY;
let dragStartCenterX, dragStartCenterY;

// 色テーマ
const THEMES = {
  pulse: (t) => {
    // Teal primary (#00f5d4) - site accent
    const r = Math.floor(Math.sin(t * 3) * 30);
    const g = Math.floor(Math.sin(t * 3 + 1) * 60 + 195);
    const b = Math.floor(Math.sin(t * 3 + 2) * 40 + 172);
    return [Math.max(0, r), Math.min(255, g), Math.min(255, b)];
  },
  ember: (t) => {
    // Magenta-pink range (hue 290-330), related to purple accent
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
  mono: (t) => {
    // Subtle blue tint (hue 210-220)
    const v = Math.floor(230 * t);
    const r = Math.floor(v * 0.85);
    const g = Math.floor(v * 0.9);
    const b = v;
    return [r, g, b];
  }
};

// プリセット位置
const PRESETS = {
  seahorse: { x: -0.745, y: 0.113, z: 500 },
  spiral: { x: -0.761574, y: -0.0847596, z: 2000 },
  lightning: { x: -1.315180982, y: 0.073481395, z: 50000 },
  elephant: { x: 0.275, y: 0.006, z: 200 },
  doubleSpiral: { x: -0.0452, y: -0.9868, z: 100 }
};

// 自動ズーム用のスポット（ループ用）
const AUTO_SPOTS = [
  { x: -0.745, y: 0.113, name: 'Seahorse Valley' },
  { x: -0.761574, y: -0.0847596, name: 'Spiral' },
  { x: -1.315180982, y: 0.073481395, name: 'Lightning' },
  { x: 0.275, y: 0.006, name: 'Elephant Valley' },
  { x: -0.0452, y: -0.9868, name: 'Double Spiral' },
  { x: -0.75, y: 0.1, name: 'Tendril' },
  { x: -1.25066, y: 0.02012, name: 'Mini Mandelbrot' },
];

let autoMode = false;
let autoSpotIndex = 0;
let fadeAlpha = 1;

function init() {
  resize();
  render();
}

function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

function render() {
  loading.classList.add('show');
  
  // 非同期レンダリング
  setTimeout(() => {
    const width = canvas.width;
    const height = canvas.height;
    const imageData = ctx.createImageData(width, height);
    const data = imageData.data;
    
    const aspectRatio = width / height;
    const rangeX = 3.5 / zoom;
    const rangeY = rangeX / aspectRatio;
    
    const minX = centerX - rangeX / 2;
    const maxX = centerX + rangeX / 2;
    const minY = centerY - rangeY / 2;
    const maxY = centerY + rangeY / 2;
    
    // イテレーション数をズームに応じて調整
    maxIterations = Math.floor(100 + Math.log(zoom) * 50);
    
    const colorFunc = THEMES[currentTheme];
    
    for (let py = 0; py < height; py++) {
      for (let px = 0; px < width; px++) {
        const x0 = minX + (px / width) * (maxX - minX);
        const y0 = minY + (py / height) * (maxY - minY);
        
        let x = 0, y = 0;
        let iteration = 0;
        
        while (x * x + y * y <= 4 && iteration < maxIterations) {
          const xTemp = x * x - y * y + x0;
          y = 2 * x * y + y0;
          x = xTemp;
          iteration++;
        }
        
        const idx = (py * width + px) * 4;
        
        if (iteration === maxIterations) {
          data[idx] = 0;
          data[idx + 1] = 0;
          data[idx + 2] = 0;
        } else {
          // スムージング
          const log_zn = Math.log(x * x + y * y) / 2;
          const nu = Math.log(log_zn / Math.log(2)) / Math.log(2);
          const smooth = iteration + 1 - nu;
          const t = smooth / maxIterations;
          
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
  document.getElementById('realCoord').textContent = centerX.toFixed(10);
  document.getElementById('imagCoord').textContent = centerY.toFixed(10);
  document.getElementById('zoomLevel').textContent = zoom.toFixed(0);
  document.getElementById('iterations').textContent = maxIterations;
}

function reset() {
  centerX = -0.5;
  centerY = 0;
  zoom = 1;
  render();
}

function zoomAt(x, y, factor) {
  const width = canvas.width;
  const height = canvas.height;
  const aspectRatio = width / height;
  const rangeX = 3.5 / zoom;
  const rangeY = rangeX / aspectRatio;
  
  // クリック位置を複素数座標に変換
  const clickX = centerX - rangeX / 2 + (x / width) * rangeX;
  const clickY = centerY - rangeY / 2 + (y / height) * rangeY;
  
  // ズーム後の新しい中心を計算
  zoom *= factor;
  
  // ズーム上限
  if (zoom > 1e14) zoom = 1e14;
  if (zoom < 0.5) zoom = 0.5;
  
  const newRangeX = 3.5 / zoom;
  const newRangeY = newRangeX / aspectRatio;
  
  // クリック位置を維持しながら中心を移動
  centerX = clickX - ((x / width) - 0.5) * newRangeX;
  centerY = clickY - ((y / height) - 0.5) * newRangeY;
  
  render();
}

// マウスイベント
canvas.addEventListener('wheel', (e) => {
  e.preventDefault();
  const factor = e.deltaY < 0 ? 1.5 : 0.67;
  zoomAt(e.clientX, e.clientY, factor);
});

canvas.addEventListener('mousedown', (e) => {
  isDragging = true;
  dragStartX = e.clientX;
  dragStartY = e.clientY;
  dragStartCenterX = centerX;
  dragStartCenterY = centerY;
});

canvas.addEventListener('mousemove', (e) => {
  if (!isDragging) return;
  
  const width = canvas.width;
  const height = canvas.height;
  const aspectRatio = width / height;
  const rangeX = 3.5 / zoom;
  const rangeY = rangeX / aspectRatio;
  
  const dx = (e.clientX - dragStartX) / width * rangeX;
  const dy = (e.clientY - dragStartY) / height * rangeY;
  
  centerX = dragStartCenterX - dx;
  centerY = dragStartCenterY - dy;
  
  render();
});

canvas.addEventListener('mouseup', () => {
  isDragging = false;
});

canvas.addEventListener('mouseleave', () => {
  isDragging = false;
});

canvas.addEventListener('dblclick', (e) => {
  const width = canvas.width;
  const height = canvas.height;
  const aspectRatio = width / height;
  const rangeX = 3.5 / zoom;
  const rangeY = rangeX / aspectRatio;
  
  centerX = centerX - rangeX / 2 + (e.clientX / width) * rangeX;
  centerY = centerY - rangeY / 2 + (e.clientY / height) * rangeY;
  
  render();
});

// コントロール
document.getElementById('resetBtn').addEventListener('click', reset);
document.getElementById('zoomInBtn').addEventListener('click', () => {
  zoom *= 2;
  render();
});
document.getElementById('zoomOutBtn').addEventListener('click', () => {
  zoom /= 2;
  if (zoom < 0.5) zoom = 0.5;
  render();
});

// テーマ切り替え
document.querySelectorAll('[data-theme]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-theme]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    currentTheme = this.dataset.theme;
    render();
  });
});

// プリセット
document.querySelectorAll('[data-preset]').forEach(btn => {
  btn.addEventListener('click', function() {
    const preset = PRESETS[this.dataset.preset];
    centerX = preset.x;
    centerY = preset.y;
    zoom = preset.z;
    render();
  });
});

// リサイズ
window.addEventListener('resize', () => {
  resize();
  render();
});

// 自動ズームモード
let autoInterval = null;
const autoBtn = document.getElementById('autoBtn');

function startAutoMode() {
  if (autoMode) return;
  autoMode = true;
  autoBtn.classList.add('active');
  autoBtn.textContent = '⏸ AUTO';
  
  // 最初のスポットに移動
  goToNextSpot();
  
  autoInterval = setInterval(() => {
    if (!autoMode) return;
    
    // ズーム限界に近づいたら次のスポットへ
    if (zoom > 1e12) {
      fadeAndTransition();
    } else {
      // ゆっくりズーム
      zoom *= 1.03;
      render();
    }
  }, 100);
}

function stopAutoMode() {
  autoMode = false;
  autoBtn.classList.remove('active');
  autoBtn.textContent = '▶ AUTO';
  if (autoInterval) {
    clearInterval(autoInterval);
    autoInterval = null;
  }
}

function goToNextSpot() {
  const spot = AUTO_SPOTS[autoSpotIndex];
  centerX = spot.x;
  centerY = spot.y;
  zoom = 1;
  autoSpotIndex = (autoSpotIndex + 1) % AUTO_SPOTS.length;
  render();
}

function fadeAndTransition() {
  // フェードアウト
  stopAutoMode();
  
  let alpha = 1;
  const fadeOut = setInterval(() => {
    alpha -= 0.05;
    canvas.style.opacity = alpha;
    if (alpha <= 0) {
      clearInterval(fadeOut);
      goToNextSpot();
      
      // フェードイン
      let alphaIn = 0;
      const fadeIn = setInterval(() => {
        alphaIn += 0.05;
        canvas.style.opacity = alphaIn;
        if (alphaIn >= 1) {
          clearInterval(fadeIn);
          canvas.style.opacity = 1;
          startAutoMode();
        }
      }, 30);
    }
  }, 30);
}

autoBtn.addEventListener('click', () => {
  if (autoMode) {
    stopAutoMode();
  } else {
    startAutoMode();
  }
});

// 手動操作時は自動モード停止
canvas.addEventListener('mousedown', () => {
  if (autoMode) stopAutoMode();
});
canvas.addEventListener('wheel', () => {
  if (autoMode) stopAutoMode();
});

// 初期化
init();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
