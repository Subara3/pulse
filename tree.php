<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('tree');

$pageName = 'tree';
$pageTitle = 'TREE - PULSE';
$pageDesc = 'フラクタルの木。再帰的に枝分かれする生命の形。';
$pageCanonical = 'https://pulse.subara3.com/tree.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: linear-gradient(to bottom, #0a0a0f 0%, #0a0a14 50%, #0a0a18 100%); min-height: 100vh; overflow: hidden; margin: 0; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 1000; font-family: "Orbitron", sans-serif; }
.info { bottom: 1rem; left: 50%; transform: translateX(-50%); color: rgba(255,255,255,0.3); font-size: 0.6rem; text-align: center; }
.hide-ui .info, .hide-ui .content-controls, .hide-ui .floating-nav { opacity: 0 !important; pointer-events: none; }

@media (max-width: 768px) {
  .info { font-size: 0.5rem; bottom: 0.5rem; }
}
';

// コントロールボタン設定
$controls = [
  ['id' => 'seasonBtn', 'icon' => 'fa-leaf', 'label' => 'SPRING'],
  ['id' => 'windBtn', 'icon' => 'fa-wind', 'label' => 'WIND'],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<div class="ui info">H: hide UI / Click: new tree</div>

<canvas id="canvas"></canvas>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let time = 0;
let windStrength = 0.02;
let season = 0; // 0:春, 1:夏, 2:秋, 3:冬
const seasons = ['SPRING', 'SUMMER', 'AUTUMN', 'WINTER'];
const seasonColors = [
  { leaf: '#00f5d4', trunk: '#8B4513' }, // 春：ティール
  { leaf: '#00b894', trunk: '#654321' }, // 夏：ディープティール
  { leaf: '#7b2cbf', trunk: '#8B4513' }, // 秋：パープル
  { leaf: '#b8c6db', trunk: '#A0A0A0' }, // 冬：クールシルバーブルー
];

function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
}

function drawBranch(x, y, length, angle, depth, maxDepth) {
  if (depth > maxDepth) return;
  
  // 風による揺れ（ゆっくり）
  const windOffset = Math.sin(time * 0.005 + y * 0.01) * windStrength * depth;
  const currentAngle = angle + windOffset;
  
  // 枝の終点
  const endX = x + Math.cos(currentAngle) * length;
  const endY = y + Math.sin(currentAngle) * length;
  
  // 枝を描画
  const thickness = Math.max(1, (maxDepth - depth + 1) * 2);
  const colors = seasonColors[season];
  
  ctx.beginPath();
  ctx.moveTo(x, y);
  ctx.lineTo(endX, endY);
  ctx.strokeStyle = colors.trunk;
  ctx.lineWidth = thickness;
  ctx.lineCap = 'round';
  ctx.stroke();
  
  // 葉っぱ（末端に近い枝）
  if (depth >= maxDepth - 2) {
    const leafSize = 3 + Math.random() * 4;
    ctx.beginPath();
    ctx.arc(endX, endY, leafSize, 0, Math.PI * 2);
    ctx.fillStyle = colors.leaf + (season === 3 ? '80' : 'CC');
    ctx.fill();
  }
  
  // 再帰的に枝を生成
  if (depth < maxDepth) {
    const newLength = length * (0.65 + Math.random() * 0.1);
    const branchAngle = 0.4 + Math.random() * 0.2;
    
    // 左の枝
    drawBranch(endX, endY, newLength, currentAngle - branchAngle, depth + 1, maxDepth);
    // 右の枝
    drawBranch(endX, endY, newLength, currentAngle + branchAngle, depth + 1, maxDepth);
    
    // 確率で追加の枝
    if (Math.random() > 0.7) {
      drawBranch(endX, endY, newLength * 0.8, currentAngle, depth + 1, maxDepth);
    }
  }
}

function drawTree() {
  // 背景グラデーション（季節で変化）
  const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
  if (season === 3) { // 冬
    gradient.addColorStop(0, '#0a0a12');
    gradient.addColorStop(1, '#0f1018');
  } else if (season === 2) { // 秋
    gradient.addColorStop(0, '#0a0a0f');
    gradient.addColorStop(1, '#120a18');
  } else { // 春・夏
    gradient.addColorStop(0, '#0a0a0f');
    gradient.addColorStop(1, '#0a0a18');
  }
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  
  // 地面
  ctx.fillStyle = season === 3 ? '#1a1a24' : '#0a1a14';
  ctx.fillRect(0, canvas.height - 50, canvas.width, 50);
  
  // 木を描画
  const startX = canvas.width / 2;
  const startY = canvas.height - 50;
  const trunkLength = canvas.height * 0.2;
  
  drawBranch(startX, startY, trunkLength, -Math.PI / 2, 0, 10);
}

// 1秒に1回描画（ゆっくりとした変化）
let lastDraw = 0;
function animate(timestamp) {
  if (timestamp - lastDraw >= 1000) { // 1秒ごと
    time++;
    drawTree();
    lastDraw = timestamp;
  }
  requestAnimationFrame(animate);
}

// 初回描画
drawTree();

// イベント
window.addEventListener('resize', resize);

canvas.addEventListener('click', () => {
  // クリックで新しい木（乱数リセット効果）
  drawTree();
});

document.getElementById('seasonBtn').addEventListener('click', () => {
  season = (season + 1) % 4;
  const label = document.querySelector('#seasonBtn .ctrl-label');
  if (label) label.textContent = seasons[season];
});

document.getElementById('windBtn').addEventListener('click', () => {
  windStrength = windStrength === 0.02 ? 0.08 : windStrength === 0.08 ? 0 : 0.02;
  const label = document.querySelector('#windBtn .ctrl-label');
  const text = windStrength === 0 ? 'NO WIND' : windStrength === 0.08 ? 'STRONG' : 'WIND';
  if (label) label.textContent = text;
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'h' || e.key === 'H') {
    document.body.classList.toggle('hide-ui');
  }
});

resize();
animate();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
