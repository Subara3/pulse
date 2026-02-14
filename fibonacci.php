<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('fibonacci');

$pageName = 'fibonacci';
$pageTitle = 'FIBONACCI - PULSE';
$pageDesc = 'フィボナッチ数列。自然界に潜む美しいパターン。';
$pageCanonical = 'https://pulse.subara3.com/fibonacci.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.sequence { top: 1rem; left: 50%; transform: translateX(-50%); font-family: "JetBrains Mono", monospace; font-size: 1rem; color: rgba(0, 245, 212, 0.7); text-align: center; }
.stats { top: 4rem; right: 1.5rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.75rem; color: rgba(255,255,255,0.5); background: rgba(0,0,0,0.5); padding: 1rem; border-radius: 8px; }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; max-width: 450px; }
.control-btn { padding: 0.5rem 1rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.65rem; line-height: 1.8; max-width: 100px; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">FIBONACCI</div>
<div class="ui sequence" id="sequence">1, 1, 2, 3, 5, 8, 13, 21, 34, 55...</div>
<div class="ui stats">
  <div>F(<span id="nValue">10</span>) = <span id="fibValue">55</span></div>
  <div>φ ≈ <span id="phiApprox">1.618</span></div>
</div>
<div class="ui hint">
  Fₙ = Fₙ₋₁ + Fₙ₋₂<br>
  1, 1, 2, 3, 5, 8, 13…
</div>
<div class="ui controls">
  <button class="control-btn active" data-mode="spiral">SPIRAL</button>
  <button class="control-btn" data-mode="sunflower">SUNFLOWER</button>
  <button class="control-btn" data-mode="tree">TREE</button>
  <button class="control-btn" data-mode="wave">WAVE</button>
  <button class="control-btn" data-mode="bars">BARS</button>
  <button class="control-btn" id="animateBtn">▶ GROW</button>
  <button class="control-btn" id="colorBtn">COLOR</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
const PHI = (1 + Math.sqrt(5)) / 2;
let mode = 'spiral';
let animating = false;
let progress = 0;
let colorMode = false;
let time = 0;

function generateFibonacci(n) {
  const fib = [1, 1];
  for (let i = 2; i < n; i++) {
    fib[i] = fib[i - 1] + fib[i - 2];
  }
  return fib;
}

const FIB = generateFibonacci(30);

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  
  time += 0.01;
  
  switch (mode) {
    case 'spiral': drawSpiral(); break;
    case 'sunflower': drawSunflower(); break;
    case 'tree': drawTree(); break;
    case 'wave': drawWave(); break;
    case 'bars': drawBars(); break;
  }
  
  updateStats();
  requestAnimationFrame(draw);
}

function drawSpiral() {
  const centerX = width / 2;
  const centerY = height / 2;
  const scale = Math.min(width, height) / (FIB[11] * 1.5);
  
  const limit = animating ? Math.floor(progress * 12) + 1 : 12;
  
  let x = 0, y = 0;
  
  ctx.save();
  ctx.translate(centerX - FIB[10] * scale / 2, centerY - FIB[9] * scale / 2);
  
  for (let i = 0; i < limit && i < 12; i++) {
    const size = FIB[i] * scale;
    
    // 四角形
    const hue = colorMode ? (i * 25 + time * 30) % 60 + 165 : 174;
    ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${0.4 - i * 0.02})`;
    ctx.lineWidth = 1;

    let rectX = x, rectY = y, rectW = size, rectH = size;
    switch (i % 4) {
      case 0: break;
      case 1: rectX = x - size; break;
      case 2: rectX = x - size; rectY = y - size; break;
      case 3: rectY = y - size; break;
    }
    ctx.strokeRect(rectX, rectY, rectW, rectH);

    // 弧（螺旋）
    ctx.beginPath();
    let arcX, arcY, startAngle, endAngle;

    switch (i % 4) {
      case 0:
        arcX = x + size; arcY = y + size;
        startAngle = Math.PI; endAngle = Math.PI * 1.5;
        break;
      case 1:
        arcX = x - size; arcY = y + size;
        startAngle = Math.PI * 1.5; endAngle = Math.PI * 2;
        break;
      case 2:
        arcX = x - size; arcY = y - size;
        startAngle = 0; endAngle = Math.PI * 0.5;
        break;
      case 3:
        arcX = x + size; arcY = y - size;
        startAngle = Math.PI * 0.5; endAngle = Math.PI;
        break;
    }

    ctx.arc(arcX, arcY, size, startAngle, endAngle);
    ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${0.9 - i * 0.05})`;
    ctx.lineWidth = 3 - i * 0.2;
    ctx.stroke();
    
    // 数字を表示
    ctx.fillStyle = `hsla(${hue}, 70%, 60%, 0.6)`;
    ctx.font = `${Math.max(10, size * 0.3)}px JetBrains Mono`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(FIB[i], rectX + size / 2, rectY + size / 2);
    
    // 次の位置
    switch (i % 4) {
      case 0: x += size; break;
      case 1: y += size; break;
      case 2: x -= size; break;
      case 3: y -= size; break;
    }
  }
  
  ctx.restore();
}

function drawSunflower() {
  const centerX = width / 2;
  const centerY = height / 2;
  const goldenAngle = Math.PI * (3 - Math.sqrt(5)); // 137.5度
  
  const seeds = animating ? Math.floor(progress * 500) : 500;
  
  for (let i = 0; i < seeds; i++) {
    const angle = i * goldenAngle;
    const radius = Math.sqrt(i) * 10;
    
    const x = centerX + Math.cos(angle) * radius;
    const y = centerY + Math.sin(angle) * radius;
    
    const hue = colorMode ? (i * 0.5 + time * 20) % 60 + 165 : 174 + (i % 20);
    const size = 4 + Math.sin(i * 0.1 + time * 2) * 1;
    
    ctx.beginPath();
    ctx.arc(x, y, size, 0, Math.PI * 2);
    ctx.fillStyle = `hsl(${hue}, 70%, 50%)`;
    ctx.fill();
  }
  
  // 中心
  ctx.beginPath();
  ctx.arc(centerX, centerY, 25, 0, Math.PI * 2);
  ctx.fillStyle = '#0a2a2a';
  ctx.fill();
}

function drawTree() {
  ctx.strokeStyle = '#00f5d4';
  ctx.lineWidth = 1;
  
  const limit = animating ? Math.floor(progress * 12) + 3 : 12;
  
  function branch(x, y, len, angle, depth) {
    if (depth > limit || len < 2) return;
    
    const endX = x + Math.cos(angle) * len;
    const endY = y + Math.sin(angle) * len;
    
    const hue = colorMode ? (depth * 25 + time * 30) % 60 + 165 : 174;
    ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${1 - depth * 0.08})`;
    ctx.lineWidth = Math.max(1, 8 - depth);
    
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(endX, endY);
    ctx.stroke();
    
    // フィボナッチ分岐角度
    const branchAngle = Math.PI / (FIB[depth % 10] + 2);
    const newLen = len * 0.75;
    
    branch(endX, endY, newLen, angle - branchAngle, depth + 1);
    branch(endX, endY, newLen, angle + branchAngle, depth + 1);
  }
  
  branch(width / 2, height - 50, 120, -Math.PI / 2, 0);
}

function drawWave() {
  const amplitude = height * 0.3;
  const limit = animating ? Math.floor(progress * 15) + 1 : 15;
  
  for (let f = 0; f < limit; f++) {
    ctx.beginPath();
    
    const fibFreq = FIB[f % FIB.length];
    const hue = colorMode ? (f * 25 + time * 30) % 60 + 165 : 174;
    
    for (let x = 0; x < width; x += 2) {
      const y = height / 2 + 
        Math.sin(x * 0.01 * fibFreq + time * 2) * (amplitude / (f + 1)) +
        Math.sin(x * 0.005 + time) * 20;
      
      if (x === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    }
    
    ctx.strokeStyle = `hsla(${hue}, 70%, 60%, ${0.5 - f * 0.03})`;
    ctx.lineWidth = 2;
    ctx.stroke();
  }
}

function drawBars() {
  const barWidth = width / 20;
  const maxHeight = height * 0.7;
  const limit = animating ? Math.floor(progress * 15) + 1 : 15;
  
  for (let i = 0; i < limit && i < 15; i++) {
    const barHeight = (FIB[i] / FIB[14]) * maxHeight;
    const x = width / 2 - (limit * barWidth) / 2 + i * barWidth;
    const y = height - 50 - barHeight;
    
    const hue = colorMode ? (i * 25 + time * 30) % 60 + 165 : 174;
    
    // バー
    ctx.fillStyle = `hsla(${hue}, 70%, 50%, 0.8)`;
    ctx.fillRect(x + 2, y, barWidth - 4, barHeight);
    
    // 枠
    ctx.strokeStyle = `hsla(${hue}, 70%, 70%, 0.5)`;
    ctx.lineWidth = 1;
    ctx.strokeRect(x + 2, y, barWidth - 4, barHeight);
    
    // 数字
    ctx.fillStyle = '#fff';
    ctx.font = '12px JetBrains Mono';
    ctx.textAlign = 'center';
    ctx.fillText(FIB[i], x + barWidth / 2, y - 10);
  }
}

function updateStats() {
  const n = animating ? Math.floor(progress * 15) + 5 : 15;
  document.getElementById('nValue').textContent = n;
  document.getElementById('fibValue').textContent = FIB[n - 1];
  document.getElementById('phiApprox').textContent = (FIB[n] / FIB[n - 1]).toFixed(6);
  
  const seq = FIB.slice(0, Math.min(10, n)).join(', ');
  document.getElementById('sequence').textContent = seq + (n > 10 ? '...' : '');
}

function animate() {
  if (!animating) return;
  
  progress += 0.003;
  if (progress > 1) progress = 0;
  
  requestAnimationFrame(animate);
}

// コントロール
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    progress = 0;
  });
});

document.getElementById('animateBtn').addEventListener('click', function() {
  animating = !animating;
  this.textContent = animating ? '⏸ PAUSE' : '▶ GROW';
  this.classList.toggle('active', animating);
  if (animating) {
    progress = 0;
    animate();
  }
});

document.getElementById('colorBtn').addEventListener('click', function() {
  colorMode = !colorMode;
  this.classList.toggle('active', colorMode);
});

window.addEventListener('resize', init);

init();
draw();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
