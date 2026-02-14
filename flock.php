<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('flock');

$pageName = 'flock';
$pageTitle = 'FLOCK - PULSE';
$pageDesc = '鳥の群れシミュレーション。3つの単純なルールから生まれる複雑な動き。';
$pageCanonical = 'https://pulse.subara3.com/flock.php';

$extraHead = '';

$extraStyle = '
body {
  background: linear-gradient(to bottom, #0a0a0f 0%, #0a1a1a 50%, #0a0f15 100%);
  min-height: 100vh;
  overflow: hidden;
}

#canvas {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.ui {
  position: fixed;
  z-index: 100;
  font-family: "Orbitron", sans-serif;
  pointer-events: none;
}

.title {
  top: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.3em;
}

.stats {
  top: 1.5rem;
  right: 1.5rem;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.7rem;
  color: rgba(255,255,255,0.3);
  text-align: right;
  line-height: 1.8;
}

.hint {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.25);
  font-size: 0.65rem;
  line-height: 1.8;
  max-width: 200px;
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  justify-content: flex-end;
  max-width: 400px;
  pointer-events: auto;
}

.control-btn {
  padding: 0.5rem 1rem;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.4);
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
  letter-spacing: 0.1em;
}

.control-btn:hover {
  background: rgba(0, 245, 212, 0.08);
  border-color: rgba(0, 245, 212, 0.4);
  color: rgba(0, 245, 212, 0.8);
}

.control-btn.active {
  background: rgba(0, 245, 212, 0.1);
  border-color: rgba(0, 245, 212, 0.5);
  color: #00f5d4;
}

.rules {
  position: fixed;
  bottom: 5rem;
  right: 1.5rem;
  background: rgba(0,0,0,0.4);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  padding: 1rem;
  font-size: 0.65rem;
  color: rgba(255,255,255,0.4);
  max-width: 220px;
  pointer-events: auto;
  backdrop-filter: blur(10px);
}

.rules-title {
  font-family: "Orbitron", sans-serif;
  color: rgba(0,245,212,0.7);
  margin-bottom: 0.8rem;
  font-size: 0.6rem;
  letter-spacing: 0.15em;
}

.rule-item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.6rem;
}

.rule-item:last-child { margin-bottom: 0; }

.rule-icon {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.5rem;
  flex-shrink: 0;
}

.rule-icon.sep { background: rgba(123,44,191,0.3); color: #7b2cbf; }
.rule-icon.ali { background: rgba(0,245,212,0.3); color: #00f5d4; }
.rule-icon.coh { background: rgba(0,191,165,0.3); color: #00bfa5; }

.slider-group {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(255,255,255,0.1);
}

.slider-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.slider-label {
  font-size: 0.55rem;
  color: rgba(255,255,255,0.4);
}

.slider-value {
  font-family: "JetBrains Mono", monospace;
  font-size: 0.55rem;
  color: #00f5d4;
  min-width: 30px;
  text-align: right;
}

input[type="range"] {
  width: 80px;
  height: 4px;
  -webkit-appearance: none;
  background: rgba(255,255,255,0.1);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  background: #00f5d4;
  border-radius: 50%;
  cursor: pointer;
}

@media (max-width: 600px) {
  .rules { display: none; }
  .hint { display: none; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui title">FLOCK</div>

<div class="ui stats">
  <div>boids: <span id="boidCount">0</span></div>
  <div>mode: <span id="modeDisplay">attract</span></div>
</div>

<div class="ui hint">
  Click to attract<br>
  Right-click to repel<br>
  Drag to sustain
</div>

<div class="rules">
  <div class="rules-title">BOIDS RULES</div>
  <div class="rule-item">
    <div class="rule-icon sep">←→</div>
    <div>Separation</div>
  </div>
  <div class="rule-item">
    <div class="rule-icon ali">→→</div>
    <div>Alignment</div>
  </div>
  <div class="rule-item">
    <div class="rule-icon coh">⊙</div>
    <div>Cohesion</div>
  </div>

  <div class="slider-group">
    <div class="slider-row">
      <span class="slider-label">Count</span>
      <input type="range" id="countSlider" min="50" max="500" value="200">
      <span class="slider-value" id="countValue">200</span>
    </div>
    <div class="slider-row">
      <span class="slider-label">Speed</span>
      <input type="range" id="speedSlider" min="1" max="10" value="4">
      <span class="slider-value" id="speedValue">4</span>
    </div>
  </div>
</div>

<div class="ui controls">
  <button class="control-btn active" data-mode="attract">ATTRACT</button>
  <button class="control-btn" data-mode="repel">REPEL</button>
  <button class="control-btn" data-mode="none">OBSERVE</button>
  <button class="control-btn" id="resetBtn">RESET</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

let width, height;
let boids = [];
let mouseX = -1000, mouseY = -1000;
let mouseDown = false;
let mode = 'attract';
let targetCount = 200;
let maxSpeed = 4;

// Boidクラス
class Boid {
  constructor(x, y) {
    this.x = x || Math.random() * width;
    this.y = y || Math.random() * height;
    this.vx = (Math.random() - 0.5) * 2;
    this.vy = (Math.random() - 0.5) * 2;
    this.hue = 175 + Math.random() * 40; // ティール〜シアン
    this.size = 3 + Math.random() * 2;
  }

  // 近隣のBoidを取得
  getNeighbors(radius) {
    return boids.filter(b => {
      if (b === this) return false;
      const dx = b.x - this.x;
      const dy = b.y - this.y;
      return dx * dx + dy * dy < radius * radius;
    });
  }

  update() {
    const neighbors = this.getNeighbors(100);

    // 3つのルール
    const sep = this.separation(neighbors);
    const ali = this.alignment(neighbors);
    const coh = this.cohesion(neighbors);

    // 力を適用
    this.vx += sep.x * 0.05 + ali.x * 0.03 + coh.x * 0.02;
    this.vy += sep.y * 0.05 + ali.y * 0.03 + coh.y * 0.02;

    // マウスインタラクション
    if (mouseDown && mode !== 'none') {
      const dx = mouseX - this.x;
      const dy = mouseY - this.y;
      const dist = Math.sqrt(dx * dx + dy * dy);

      if (dist < 200 && dist > 0) {
        const force = (200 - dist) / 200 * 0.5;
        if (mode === 'attract') {
          this.vx += (dx / dist) * force;
          this.vy += (dy / dist) * force;
        } else if (mode === 'repel') {
          this.vx -= (dx / dist) * force * 2;
          this.vy -= (dy / dist) * force * 2;
        }
      }
    }

    // 速度制限
    const speed = Math.sqrt(this.vx * this.vx + this.vy * this.vy);
    if (speed > maxSpeed) {
      this.vx = (this.vx / speed) * maxSpeed;
      this.vy = (this.vy / speed) * maxSpeed;
    }

    // 位置更新
    this.x += this.vx;
    this.y += this.vy;

    // 画面端の処理（ワープ）
    if (this.x < 0) this.x = width;
    if (this.x > width) this.x = 0;
    if (this.y < 0) this.y = height;
    if (this.y > height) this.y = 0;
  }

  // 分離：近すぎる仲間から離れる
  separation(neighbors) {
    let fx = 0, fy = 0;
    const closeNeighbors = neighbors.filter(b => {
      const dx = b.x - this.x;
      const dy = b.y - this.y;
      return dx * dx + dy * dy < 30 * 30;
    });

    for (const b of closeNeighbors) {
      const dx = this.x - b.x;
      const dy = this.y - b.y;
      const dist = Math.sqrt(dx * dx + dy * dy) || 1;
      fx += dx / dist;
      fy += dy / dist;
    }
    return { x: fx, y: fy };
  }

  // 整列：近隣と同じ方向に向かう
  alignment(neighbors) {
    if (neighbors.length === 0) return { x: 0, y: 0 };

    let avgVx = 0, avgVy = 0;
    for (const b of neighbors) {
      avgVx += b.vx;
      avgVy += b.vy;
    }
    avgVx /= neighbors.length;
    avgVy /= neighbors.length;

    return { x: avgVx - this.vx, y: avgVy - this.vy };
  }

  // 結合：群れの中心に向かう
  cohesion(neighbors) {
    if (neighbors.length === 0) return { x: 0, y: 0 };

    let cx = 0, cy = 0;
    for (const b of neighbors) {
      cx += b.x;
      cy += b.y;
    }
    cx /= neighbors.length;
    cy /= neighbors.length;

    return { x: (cx - this.x) * 0.01, y: (cy - this.y) * 0.01 };
  }

  draw() {
    // 進行方向を計算
    const angle = Math.atan2(this.vy, this.vx);
    const speed = Math.sqrt(this.vx * this.vx + this.vy * this.vy);

    ctx.save();
    ctx.translate(this.x, this.y);
    ctx.rotate(angle);

    // 三角形（鳥の形）
    ctx.beginPath();
    ctx.moveTo(this.size * 2, 0);
    ctx.lineTo(-this.size, this.size);
    ctx.lineTo(-this.size * 0.5, 0);
    ctx.lineTo(-this.size, -this.size);
    ctx.closePath();

    // 速度に応じて明るさを変える
    const brightness = 50 + speed * 10;
    ctx.fillStyle = `hsla(${this.hue}, 70%, ${brightness}%, 0.8)`;
    ctx.fill();

    // 軌跡（尾）
    ctx.beginPath();
    ctx.moveTo(-this.size * 0.5, 0);
    ctx.lineTo(-this.size * 2 - speed * 2, 0);
    ctx.strokeStyle = `hsla(${this.hue}, 70%, ${brightness}%, 0.3)`;
    ctx.lineWidth = this.size * 0.5;
    ctx.stroke();

    ctx.restore();
  }
}

function init() {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;

  boids = [];
  for (let i = 0; i < targetCount; i++) {
    boids.push(new Boid());
  }
}

function adjustBoidCount() {
  while (boids.length < targetCount) {
    boids.push(new Boid());
  }
  while (boids.length > targetCount) {
    boids.pop();
  }
}

function draw() {
  // フェード効果
  ctx.fillStyle = 'rgba(10, 10, 15, 0.15)';
  ctx.fillRect(0, 0, width, height);

  // Boid更新・描画
  for (const boid of boids) {
    boid.update();
    boid.draw();
  }

  // マウスインジケーター
  if (mouseDown && mode !== 'none') {
    ctx.beginPath();
    ctx.arc(mouseX, mouseY, 200, 0, Math.PI * 2);
    ctx.strokeStyle = mode === 'attract'
      ? 'rgba(0, 245, 212, 0.2)'
      : 'rgba(123, 44, 191, 0.2)';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(mouseX, mouseY, 10, 0, Math.PI * 2);
    ctx.fillStyle = mode === 'attract'
      ? 'rgba(0, 245, 212, 0.5)'
      : 'rgba(123, 44, 191, 0.5)';
    ctx.fill();
  }

  // 統計更新
  document.getElementById('boidCount').textContent = boids.length;

  requestAnimationFrame(draw);
}

// イベント
canvas.addEventListener('mousedown', (e) => {
  if (e.button === 2) {
    mode = 'repel';
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    document.querySelector('[data-mode="repel"]').classList.add('active');
  }
  mouseDown = true;
  mouseX = e.clientX;
  mouseY = e.clientY;
});

canvas.addEventListener('mousemove', (e) => {
  mouseX = e.clientX;
  mouseY = e.clientY;
});

canvas.addEventListener('mouseup', () => {
  mouseDown = false;
});

canvas.addEventListener('mouseleave', () => {
  mouseDown = false;
});

canvas.addEventListener('contextmenu', (e) => e.preventDefault());

// タッチ対応
canvas.addEventListener('touchstart', (e) => {
  e.preventDefault();
  mouseDown = true;
  mouseX = e.touches[0].clientX;
  mouseY = e.touches[0].clientY;
}, { passive: false });

canvas.addEventListener('touchmove', (e) => {
  e.preventDefault();
  mouseX = e.touches[0].clientX;
  mouseY = e.touches[0].clientY;
}, { passive: false });

canvas.addEventListener('touchend', () => {
  mouseDown = false;
});

// コントロール
document.querySelectorAll('[data-mode]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-mode]').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    mode = this.dataset.mode;
    document.getElementById('modeDisplay').textContent = mode;
  });
});

document.getElementById('resetBtn').addEventListener('click', init);

// スライダー
document.getElementById('countSlider').addEventListener('input', function() {
  targetCount = parseInt(this.value);
  document.getElementById('countValue').textContent = targetCount;
  adjustBoidCount();
});

document.getElementById('speedSlider').addEventListener('input', function() {
  maxSpeed = parseInt(this.value);
  document.getElementById('speedValue').textContent = maxSpeed;
});

window.addEventListener('resize', () => {
  width = canvas.width = window.innerWidth;
  height = canvas.height = window.innerHeight;
});

init();
draw();
</script>

<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
