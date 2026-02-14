<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('pi');

$pageName = 'pi';
$pageTitle = 'π - PULSE';
$pageDesc = '円周率πの数字をアートに。無限の数列を眺める。';
$pageCanonical = 'https://pulse.subara3.com/pi.php';

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
.pi-display { top: 1rem; left: 50%; transform: translateX(-50%); font-family: "JetBrains Mono", monospace; font-size: 1.5rem; color: #00f5d4; letter-spacing: 0.1em; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>
<div class="ui pi-display">π = <span id="piValue">3.</span></div>
<div class="ui title">π VISUALIZATION</div>
<div class="ui stats">
  <div>digits: <span id="digitCount">0</span></div>
  <div>mode: <span id="modeDisplay">walk</span></div>
</div>
<div class="ui hint">
  πの数字が<br>
  軌跡を描く
</div>
<div class="ui controls">
  <button class="control-btn active" data-mode="walk">WALK</button>
  <button class="control-btn" data-mode="spiral">SPIRAL</button>
  <button class="control-btn" data-mode="tree">TREE</button>
  <button class="control-btn" data-mode="color">COLOR</button>
  <button class="control-btn" id="resetBtn">RESET</button>
  <button class="control-btn" id="speedBtn">SPEED</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// πの最初の1000桁
const PI_DIGITS = "14159265358979323846264338327950288419716939937510582097494459230781640628620899862803482534211706798214808651328230664709384460955058223172535940812848111745028410270193852110555964462294895493038196442881097566593344612847564823378678316527120190914564856692346034861045432664821339360726024914127372458700660631558817488152092096282925409171536436789259036001133053054882046652138414695194151160943305727036575959195309218611738193261179310511854807446237996274956735188575272489122793818301194912983367336244065664308602139494639522473719070217986094370277053921717629317675238467481846766940513200056812714526356082778577134275778960917363717872146844090122495343014654958537105079227968925892354201995611212902196086403441815981362977477130996051870721134999999837297804995105973173281609631859502445945534690830264252230825334468503526193118817101000313783875288658753320838142061717766914730359825349042875546873115956286388235378759375195778185778053217122680661300192787661119590921642019893809525720106548586327886593615338182796823030195";

let width, height;
let currentDigitIndex = 0;
let mode = 'walk';
let speed = 1;
let animationId;

// Walk mode
let walkX, walkY, walkAngle;
const walkHistory = [];

// Spiral mode
let spiralAngle = 0;
let spiralRadius = 0;

// Tree mode
let treeSegments = [];

// Color mode
let colorGrid = [];
const GRID_SIZE = 50;

const COLORS = [
  '#004d40', '#00695c', '#00897b', '#009688', '#00bfa5',
  '#00f5d4', '#26c6da', '#5c6bc0', '#7b2cbf', '#9c27b0'
];

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  reset();
}

function reset() {
  currentDigitIndex = 0;
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, width, height);
  
  walkX = width / 2;
  walkY = height / 2;
  walkAngle = 0;
  walkHistory.length = 0;
  
  spiralAngle = 0;
  spiralRadius = 0;
  
  treeSegments = [{ x: width / 2, y: height - 50, angle: -Math.PI / 2, len: 100, depth: 0 }];
  
  colorGrid = [];
  for (let i = 0; i < GRID_SIZE; i++) {
    colorGrid[i] = [];
    for (let j = 0; j < GRID_SIZE; j++) {
      colorGrid[i][j] = null;
    }
  }
  
  document.getElementById('piValue').textContent = '3.';
  updateStats();
}

function getDigit() {
  if (currentDigitIndex >= PI_DIGITS.length) {
    currentDigitIndex = 0;
  }
  return parseInt(PI_DIGITS[currentDigitIndex++]);
}

function drawWalk() {
  for (let i = 0; i < speed; i++) {
    const digit = getDigit();
    
    // 数字に応じて角度を変える
    walkAngle += (digit - 4.5) * 0.3;
    
    const stepSize = 5;
    const newX = walkX + Math.cos(walkAngle) * stepSize;
    const newY = walkY + Math.sin(walkAngle) * stepSize;
    
    ctx.beginPath();
    ctx.moveTo(walkX, walkY);
    ctx.lineTo(newX, newY);
    ctx.strokeStyle = COLORS[digit];
    ctx.lineWidth = 1.5;
    ctx.stroke();
    
    walkX = newX;
    walkY = newY;
    
    // 画面外に出たら中央に戻す
    if (walkX < 0 || walkX > width || walkY < 0 || walkY > height) {
      walkX = width / 2;
      walkY = height / 2;
    }
    
    updatePiDisplay(digit);
  }
}

function drawSpiral() {
  for (let i = 0; i < speed; i++) {
    const digit = getDigit();
    
    spiralAngle += 0.1 + digit * 0.02;
    spiralRadius += 0.3;
    
    const x = width / 2 + Math.cos(spiralAngle) * spiralRadius;
    const y = height / 2 + Math.sin(spiralAngle) * spiralRadius;
    
    ctx.beginPath();
    ctx.arc(x, y, 2 + digit * 0.3, 0, Math.PI * 2);
    ctx.fillStyle = COLORS[digit];
    ctx.fill();
    
    // リセット
    if (spiralRadius > Math.min(width, height) / 2) {
      spiralRadius = 0;
      spiralAngle = 0;
      ctx.fillStyle = 'rgba(10, 10, 15, 0.1)';
      ctx.fillRect(0, 0, width, height);
    }
    
    updatePiDisplay(digit);
  }
}

function drawTree() {
  ctx.fillStyle = 'rgba(10, 10, 15, 0.02)';
  ctx.fillRect(0, 0, width, height);
  
  for (let i = 0; i < speed; i++) {
    if (treeSegments.length === 0) {
      treeSegments = [{ x: width / 2, y: height - 50, angle: -Math.PI / 2, len: 100, depth: 0 }];
    }
    
    const digit = getDigit();
    const seg = treeSegments.shift();
    
    const endX = seg.x + Math.cos(seg.angle) * seg.len;
    const endY = seg.y + Math.sin(seg.angle) * seg.len;
    
    ctx.beginPath();
    ctx.moveTo(seg.x, seg.y);
    ctx.lineTo(endX, endY);
    ctx.strokeStyle = COLORS[digit];
    ctx.lineWidth = Math.max(1, 5 - seg.depth);
    ctx.stroke();
    
    if (seg.depth < 8 && seg.len > 5) {
      const branchAngle = (digit - 4.5) * 0.15;
      const newLen = seg.len * (0.6 + digit * 0.03);
      
      treeSegments.push({
        x: endX, y: endY,
        angle: seg.angle - 0.3 - branchAngle,
        len: newLen,
        depth: seg.depth + 1
      });
      treeSegments.push({
        x: endX, y: endY,
        angle: seg.angle + 0.3 + branchAngle,
        len: newLen,
        depth: seg.depth + 1
      });
    }
    
    updatePiDisplay(digit);
  }
}

function drawColor() {
  const cellW = width / GRID_SIZE;
  const cellH = height / GRID_SIZE;
  
  for (let i = 0; i < speed * 5; i++) {
    const digit = getDigit();
    
    const gx = Math.floor(Math.random() * GRID_SIZE);
    const gy = Math.floor(Math.random() * GRID_SIZE);
    
    ctx.fillStyle = COLORS[digit];
    ctx.globalAlpha = 0.8;
    ctx.fillRect(gx * cellW, gy * cellH, cellW - 1, cellH - 1);
    ctx.globalAlpha = 1;
    
    // 数字を描画
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = `${Math.min(cellW, cellH) * 0.5}px JetBrains Mono`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(digit, gx * cellW + cellW / 2, gy * cellH + cellH / 2);
    
    updatePiDisplay(digit);
  }
}

function updatePiDisplay(digit) {
  const display = document.getElementById('piValue');
  if (display.textContent.length < 20) {
    display.textContent += digit;
  }
}

function updateStats() {
  document.getElementById('digitCount').textContent = currentDigitIndex;
  document.getElementById('modeDisplay').textContent = mode;
}

function animate() {
  switch (mode) {
    case 'walk': drawWalk(); break;
    case 'spiral': drawSpiral(); break;
    case 'tree': drawTree(); break;
    case 'color': drawColor(); break;
  }
  
  updateStats();
  animationId = requestAnimationFrame(animate);
}

// コントロール
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    reset();
  });
});

document.getElementById('resetBtn').addEventListener('click', reset);

document.getElementById('speedBtn').addEventListener('click', function() {
  speed = speed >= 10 ? 1 : speed + 1;
  this.textContent = `SPEED x${speed}`;
});

window.addEventListener('resize', () => {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
  reset();
});

init();
animate();
</script>

</body>
</html>
