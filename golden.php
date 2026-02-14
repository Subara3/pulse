<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('golden');

$pageName = 'golden';
$pageTitle = 'GOLDEN SPIRAL - PULSE';
$pageDesc = '黄金螺旋。自然界に潜む美しい比率。';
$pageCanonical = 'https://pulse.subara3.com/golden.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 1rem; right: 1.5rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.8rem; color: rgba(255,255,255,0.5); }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; }
.control-btn { padding: 0.5rem 1rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.7rem; line-height: 1.8; }
.golden-display { top: 1rem; left: 50%; transform: translateX(-50%); font-family: "JetBrains Mono", monospace; font-size: 1.2rem; color: #00f5d4; letter-spacing: 0.05em; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="ui golden-display">φ = <span id="phiValue">1.6180339887...</span></div>
<div class="ui title">GOLDEN SPIRAL</div>
<div class="ui stats">
  <div>φ = <span>(1+√5)/2</span></div>
  <div>fibonacci: <span id="fibDisplay">1, 1, 2, 3...</span></div>
</div>
<div class="ui hint">
  黄金比 φ<br>
  自然界の美
</div>
<div class="ui controls">
  <button class="control-btn active" data-mode="spiral">SPIRAL</button>
  <button class="control-btn" data-mode="rectangles">RECTANGLES</button>
  <button class="control-btn" data-mode="sunflower">SUNFLOWER</button>
  <button class="control-btn" data-mode="shell">SHELL</button>
  <button class="control-btn" id="animateBtn">▶ ANIMATE</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

const PHI = (1 + Math.sqrt(5)) / 2; // 黄金比
let width, height;
let mode = 'spiral';
let animating = false;
let animationProgress = 0;

// フィボナッチ数列
function fibonacci(n) {
  const fib = [1, 1];
  for (let i = 2; i < n; i++) {
    fib.push(fib[i - 1] + fib[i - 2]);
  }
  return fib;
}

const FIB = fibonacci(20);

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  draw();
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  
  switch (mode) {
    case 'spiral': drawSpiral(); break;
    case 'rectangles': drawRectangles(); break;
    case 'sunflower': drawSunflower(); break;
    case 'shell': drawShell(); break;
  }
}

function drawSpiral() {
  const centerX = width / 2;
  const centerY = height / 2;
  const maxRadius = Math.min(width, height) * 0.4;
  
  ctx.strokeStyle = '#00f5d4';
  ctx.lineWidth = 2;
  ctx.beginPath();
  
  const points = animating ? Math.floor(animationProgress * 1000) : 1000;
  
  for (let i = 0; i < points; i++) {
    const angle = i * 0.05;
    const radius = Math.pow(PHI, angle / (Math.PI / 2)) * 2;
    
    if (radius > maxRadius) break;
    
    const x = centerX + Math.cos(angle) * radius;
    const y = centerY + Math.sin(angle) * radius;
    
    if (i === 0) {
      ctx.moveTo(x, y);
    } else {
      ctx.lineTo(x, y);
    }
  }
  
  ctx.stroke();
  
  // 中心の点
  ctx.beginPath();
  ctx.arc(centerX, centerY, 5, 0, Math.PI * 2);
  ctx.fillStyle = '#00f5d4';
  ctx.fill();
}

function drawRectangles() {
  const scale = Math.min(width, height) / (FIB[12] * 1.2);
  let x = width / 2 - FIB[12] * scale / 2;
  let y = height / 2 - FIB[11] * scale / 2;
  
  const limit = animating ? Math.floor(animationProgress * 12) + 1 : 12;
  
  const colors = [
    'rgba(0, 245, 212, 0.8)',
    'rgba(255, 180, 0, 0.7)',
    'rgba(255, 150, 0, 0.6)',
    'rgba(200, 150, 50, 0.5)'
  ];
  
  for (let i = 0; i < limit && i < 12; i++) {
    const size = FIB[11 - i] * scale;
    
    ctx.strokeStyle = colors[i % colors.length];
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y, size, size);
    
    // 四分円を描く
    ctx.beginPath();
    const dir = i % 4;
    let arcX, arcY, startAngle, endAngle;
    
    switch (dir) {
      case 0:
        arcX = x + size; arcY = y + size;
        startAngle = Math.PI; endAngle = Math.PI * 1.5;
        break;
      case 1:
        arcX = x; arcY = y + size;
        startAngle = Math.PI * 1.5; endAngle = Math.PI * 2;
        break;
      case 2:
        arcX = x; arcY = y;
        startAngle = 0; endAngle = Math.PI * 0.5;
        break;
      case 3:
        arcX = x + size; arcY = y;
        startAngle = Math.PI * 0.5; endAngle = Math.PI;
        break;
    }
    
    ctx.arc(arcX, arcY, size, startAngle, endAngle);
    ctx.strokeStyle = '#00f5d4';
    ctx.stroke();
    
    // 次の位置を計算
    const nextSize = FIB[10 - i] * scale;
    switch (dir) {
      case 0: x += size - nextSize; break;
      case 1: y += size - nextSize; break;
      case 2: x += size; break;
      case 3: y += size; break;
    }
  }
}

function drawSunflower() {
  const centerX = width / 2;
  const centerY = height / 2;
  const goldenAngle = Math.PI * 2 / (PHI * PHI); // 137.5度
  
  const seeds = animating ? Math.floor(animationProgress * 500) : 500;
  
  for (let i = 0; i < seeds; i++) {
    const angle = i * goldenAngle;
    const radius = Math.sqrt(i) * 8;
    
    const x = centerX + Math.cos(angle) * radius;
    const y = centerY + Math.sin(angle) * radius;
    
    const hue = (i * 0.5) % 40 + 165; // teal〜cyan
    ctx.beginPath();
    ctx.arc(x, y, 3 + (i % 5) * 0.5, 0, Math.PI * 2);
    ctx.fillStyle = `hsl(${hue}, 80%, 50%)`;
    ctx.fill();
  }
  
  // 中心
  ctx.beginPath();
  ctx.arc(centerX, centerY, 20, 0, Math.PI * 2);
  ctx.fillStyle = '#8B4513';
  ctx.fill();
}

function drawShell() {
  const centerX = width / 2;
  const centerY = height / 2;
  
  const segments = animating ? Math.floor(animationProgress * 50) + 5 : 50;
  
  ctx.strokeStyle = '#00f5d4';
  ctx.lineWidth = 2;
  
  for (let ring = 0; ring < 8; ring++) {
    ctx.beginPath();
    
    for (let i = 0; i < segments; i++) {
      const t = i / segments;
      const angle = t * Math.PI * 4 + ring * 0.3;
      const r = Math.pow(PHI, angle / Math.PI) * 3 + ring * 15;
      
      const x = centerX + Math.cos(angle) * r;
      const y = centerY + Math.sin(angle) * r;
      
      if (i === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    }
    
    ctx.globalAlpha = 0.3 + ring * 0.1;
    ctx.stroke();
  }
  
  ctx.globalAlpha = 1;
}

function animate() {
  if (!animating) return;
  
  animationProgress += 0.005;
  if (animationProgress > 1) {
    animationProgress = 0;
  }
  
  draw();
  requestAnimationFrame(animate);
}

// コントロール
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    animationProgress = 0;
    draw();
  });
});

document.getElementById('animateBtn').addEventListener('click', function() {
  animating = !animating;
  this.textContent = animating ? '⏸ PAUSE' : '▶ ANIMATE';
  this.classList.toggle('active', animating);
  if (animating) {
    animationProgress = 0;
    animate();
  }
});

window.addEventListener('resize', init);

init();

// フィボナッチ表示更新
document.getElementById('fibDisplay').textContent = FIB.slice(0, 8).join(', ') + '...';
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
