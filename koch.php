<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('koch');

$pageName = 'koch';
$pageTitle = 'KOCH SNOWFLAKE - PULSE';
$pageDesc = 'コッホ雪片。再帰的に成長するフラクタル図形。';
$pageCanonical = 'https://pulse.subara3.com/koch.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1.5rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.8rem; color: rgba(255,255,255,0.5); background: rgba(0,0,0,0.5); padding: 1rem; border-radius: 8px; }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; }
.control-btn { padding: 0.5rem 1rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.7rem; line-height: 1.8; }
.depth-display { top: 1rem; left: 50%; transform: translateX(-50%); font-family: "JetBrains Mono", monospace; font-size: 1.5rem; color: #00f5d4; }

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .hint { display: none; }
  .depth-display { font-size: 1rem; top: 0.5rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>
<div class="ui depth-display">Depth: <span id="depthValue">0</span></div>
<div class="ui title">KOCH SNOWFLAKE</div>
<div class="ui stats">
  <div>segments: <span id="segments">3</span></div>
  <div>length: <span id="perimeter">∞</span></div>
  <div>area: <span id="area">finite</span></div>
</div>
<div class="ui hint">
  ∞ perimeter<br>
  finite area<br>
  a paradox
</div>
<div class="ui controls">
  <button class="control-btn" id="depthDown">DEPTH -</button>
  <button class="control-btn" id="depthUp">DEPTH +</button>
  <button class="control-btn active" data-shape="snowflake">SNOWFLAKE</button>
  <button class="control-btn" data-shape="curve">CURVE</button>
  <button class="control-btn" data-shape="anti">ANTI</button>
  <button class="control-btn" id="animateBtn">▶ ANIMATE</button>
  <button class="control-btn" id="colorBtn">COLOR</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let depth = 0;
let shape = 'snowflake';
let animating = false;
let colorMode = false;
let hue = 175;

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  draw();
}

function kochPoints(x1, y1, x2, y2, depth, direction = 1) {
  if (depth === 0) {
    return [[x1, y1], [x2, y2]];
  }
  
  const dx = x2 - x1;
  const dy = y2 - y1;
  
  // 3等分点
  const ax = x1 + dx / 3;
  const ay = y1 + dy / 3;
  const bx = x1 + dx * 2 / 3;
  const by = y1 + dy * 2 / 3;
  
  // 頂点（正三角形の頂点）
  const angle = Math.atan2(dy, dx) + direction * Math.PI / 3;
  const len = Math.sqrt(dx * dx + dy * dy) / 3;
  const px = ax + Math.cos(angle) * len;
  const py = ay + Math.sin(angle) * len;
  
  // 再帰
  const points = [];
  points.push(...kochPoints(x1, y1, ax, ay, depth - 1, direction));
  points.push(...kochPoints(ax, ay, px, py, depth - 1, direction));
  points.push(...kochPoints(px, py, bx, by, depth - 1, direction));
  points.push(...kochPoints(bx, by, x2, y2, depth - 1, direction));
  
  return points;
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  
  const size = Math.min(width, height) * 0.7;
  const centerX = width / 2;
  const centerY = height / 2;
  
  let points = [];
  const direction = shape === 'anti' ? -1 : 1;
  
  if (shape === 'curve') {
    // 単一のコッホ曲線
    const x1 = centerX - size / 2;
    const y1 = centerY;
    const x2 = centerX + size / 2;
    const y2 = centerY;
    
    points = kochPoints(x1, y1, x2, y2, depth, direction);
  } else {
    // 雪片（正三角形の各辺）
    const h = size * Math.sqrt(3) / 2;
    const vertices = [
      [centerX, centerY - h * 2 / 3],
      [centerX - size / 2, centerY + h / 3],
      [centerX + size / 2, centerY + h / 3]
    ];
    
    for (let i = 0; i < 3; i++) {
      const [x1, y1] = vertices[i];
      const [x2, y2] = vertices[(i + 1) % 3];
      points.push(...kochPoints(x1, y1, x2, y2, depth, direction));
    }
  }
  
  // 描画
  if (points.length > 0) {
    ctx.beginPath();
    ctx.moveTo(points[0][0], points[0][1]);
    
    for (let i = 1; i < points.length; i++) {
      if (colorMode) {
        ctx.strokeStyle = `hsl(${(hue + i * 0.5) % 360}, 70%, 60%)`;
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(points[i - 1][0], points[i - 1][1]);
      }
      ctx.lineTo(points[i][0], points[i][1]);
    }
    
    if (!colorMode) {
      ctx.strokeStyle = '#00f5d4';
    }
    ctx.lineWidth = Math.max(0.5, 2 - depth * 0.3);
    ctx.stroke();
    
    if (shape !== 'curve') {
      ctx.closePath();
      ctx.fillStyle = 'rgba(0, 245, 212, 0.05)';
      ctx.fill();
    }
  }
  
  updateStats();
}

function updateStats() {
  document.getElementById('depthValue').textContent = depth;
  document.getElementById('segments').textContent = shape === 'curve' 
    ? Math.pow(4, depth) 
    : 3 * Math.pow(4, depth);
  
  // 周長は (4/3)^n に比例して増加
  const ratio = Math.pow(4 / 3, depth).toFixed(2);
  document.getElementById('perimeter').textContent = `×${ratio}`;
  document.getElementById('area').textContent = 'finite';
}

function animate() {
  if (!animating) return;
  
  depth = (depth + 1) % 7;
  draw();
  
  setTimeout(() => requestAnimationFrame(animate), 1000);
}

// コントロール
document.getElementById('depthUp').addEventListener('click', () => {
  if (depth < 7) {
    depth++;
    draw();
  }
});

document.getElementById('depthDown').addEventListener('click', () => {
  if (depth > 0) {
    depth--;
    draw();
  }
});

document.querySelectorAll('[data-shape]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-shape]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    shape = this.dataset.shape;
    draw();
  });
});

document.getElementById('animateBtn').addEventListener('click', function() {
  animating = !animating;
  this.textContent = animating ? '⏸ PAUSE' : '▶ ANIMATE';
  this.classList.toggle('active', animating);
  if (animating) {
    depth = 0;
    animate();
  }
});

document.getElementById('colorBtn').addEventListener('click', function() {
  colorMode = !colorMode;
  this.classList.toggle('active', colorMode);
  draw();
});

window.addEventListener('resize', init);

// キーボード操作
window.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowUp' && depth < 7) {
    depth++;
    draw();
  } else if (e.key === 'ArrowDown' && depth > 0) {
    depth--;
    draw();
  }
});

init();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
