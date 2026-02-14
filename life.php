<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('life');

$pageName = 'life';
$pageTitle = 'LIFE - PULSE';
$pageDesc = 'コンウェイのライフゲーム。セルが生きて死んで進化する。';
$pageCanonical = 'https://pulse.subara3.com/life.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; cursor: crosshair; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; }
.back-link { top: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.3); text-decoration: none; font-size: 0.8rem; letter-spacing: 0.1em; transition: color 0.3s; }
.back-link:hover { color: rgba(255,255,255,0.7); }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.stats { top: 3.5rem; right: 1.5rem; text-align: right; font-family: "JetBrains Mono", monospace; font-size: 0.8rem; color: rgba(255,255,255,0.5); }
.stats div { margin-bottom: 0.3rem; }
.stats span { color: #00f5d4; }
.controls { bottom: 1.5rem; right: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; max-width: 400px; }
.control-btn { padding: 0.5rem 1rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.5); font-family: "Orbitron", sans-serif; font-size: 0.7rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; }
.control-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.5); color: #00f5d4; }
.control-btn.active { background: rgba(0, 245, 212, 0.2); border-color: #00f5d4; color: #00f5d4; }
.hint { top: 50%; left: 1.5rem; transform: translateY(-50%); color: rgba(255,255,255,0.3); font-size: 0.7rem; line-height: 1.8; }
.speed-control { display: flex; align-items: center; gap: 0.5rem; }
.speed-slider { width: 80px; height: 4px; -webkit-appearance: none; background: rgba(255,255,255,0.2); border-radius: 2px; outline: none; }
.speed-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; background: #00f5d4; border-radius: 50%; cursor: pointer; }
.pattern-menu { position: fixed; top: 50%; right: 1.5rem; transform: translateY(-50%); display: flex; flex-direction: column; gap: 0.3rem; }
.pattern-btn { padding: 0.4rem 0.8rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.4); font-family: "JetBrains Mono", monospace; font-size: 0.65rem; cursor: pointer; border-radius: 4px; transition: all 0.3s; text-align: left; }
.pattern-btn:hover { background: rgba(0, 245, 212, 0.1); border-color: rgba(0, 245, 212, 0.3); color: #00f5d4; }

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .stats { top: 0.5rem; right: 0.5rem; font-size: 0.5rem; }
  .pattern-menu { top: auto; bottom: 4rem; right: 0.5rem; transform: none; flex-direction: row; flex-wrap: wrap; max-width: 200px; }
  .pattern-btn { padding: 0.3rem 0.5rem; font-size: 0.5rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>
<div class="ui title">LIFE</div>
<div class="ui stats">
  <div>generation: <span id="generation">0</span></div>
  <div>population: <span id="population">0</span></div>
  <div>density: <span id="density">0</span>%</div>
</div>
<div class="ui hint">
  click to toggle cells<br>
  drag to draw
</div>
<div class="ui controls">
  <button class="control-btn active" id="playBtn">▶ PLAY</button>
  <button class="control-btn" id="stepBtn">STEP</button>
  <button class="control-btn" id="clearBtn">CLEAR</button>
  <button class="control-btn" id="randomBtn">RANDOM</button>
  <div class="speed-control">
    <span style="color: rgba(255,255,255,0.4); font-size: 0.7rem;">SPEED</span>
    <input type="range" class="speed-slider" id="speedSlider" min="1" max="10" value="5">
  </div>
</div>
<div class="ui pattern-menu">
  <button class="pattern-btn" data-pattern="glider">▸ Glider</button>
  <button class="pattern-btn" data-pattern="lwss">▸ Spaceship</button>
  <button class="pattern-btn" data-pattern="pulsar">▸ Pulsar</button>
  <button class="pattern-btn" data-pattern="gosper">▸ Gosper Gun</button>
  <button class="pattern-btn" data-pattern="rpentomino">▸ R-pentomino</button>
  <button class="pattern-btn" data-pattern="acorn">▸ Acorn</button>
</div>

<script>
const canvas = document.getElementById('canvas');
const ctx = canvas.getContext('2d');

// 設定
const isMobile = window.innerWidth <= 768;
const CELL_SIZE = isMobile ? 16 : 12;
let cols, rows;
let grid = [];
let isPlaying = true;
let generation = 0;
let speed = 5;
let lastUpdate = 0;
let selectedPattern = null;

// 色
const COLORS = {
  cell: 'rgba(0, 245, 212, 0.7)',
  cellGlow: 'rgba(0, 245, 212, 0.3)',
  cellAlt: 'rgba(123, 44, 191, 0.6)',
  cellAltGlow: 'rgba(123, 44, 191, 0.2)',
  grid: 'rgba(255, 255, 255, 0.03)'
};

// パターン定義
const PATTERNS = {
  glider: [[0,0], [1,0], [2,0], [2,1], [1,2]],
  lwss: [[0,1], [0,3], [1,0], [2,0], [3,0], [4,0], [4,1], [4,2], [3,3]],
  pulsar: [
    [2,0], [3,0], [4,0], [8,0], [9,0], [10,0],
    [0,2], [5,2], [7,2], [12,2],
    [0,3], [5,3], [7,3], [12,3],
    [0,4], [5,4], [7,4], [12,4],
    [2,5], [3,5], [4,5], [8,5], [9,5], [10,5],
    [2,7], [3,7], [4,7], [8,7], [9,7], [10,7],
    [0,8], [5,8], [7,8], [12,8],
    [0,9], [5,9], [7,9], [12,9],
    [0,10], [5,10], [7,10], [12,10],
    [2,12], [3,12], [4,12], [8,12], [9,12], [10,12]
  ],
  gosper: [
    [0,4], [0,5], [1,4], [1,5],
    [10,3], [10,4], [10,5], [11,2], [11,6], [12,1], [12,7], [13,1], [13,7],
    [14,4], [15,2], [15,6], [16,3], [16,4], [16,5], [17,4],
    [20,1], [20,2], [20,3], [21,1], [21,2], [21,3], [22,0], [22,4],
    [24,0], [24,-1], [24,4], [24,5],
    [34,2], [34,3], [35,2], [35,3]
  ],
  rpentomino: [[1,0], [2,0], [0,1], [1,1], [1,2]],
  acorn: [[1,0], [3,1], [0,2], [1,2], [4,2], [5,2], [6,2]]
};

function init() {
  resize();
  randomize();
  loop();
}

function resize() {
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  cols = Math.ceil(canvas.width / CELL_SIZE);
  rows = Math.ceil(canvas.height / CELL_SIZE);
  
  // グリッドを再構築
  const oldGrid = grid;
  grid = [];
  for (let i = 0; i < cols; i++) {
    grid[i] = [];
    for (let j = 0; j < rows; j++) {
      grid[i][j] = (oldGrid[i] && oldGrid[i][j]) ? oldGrid[i][j] : 0;
    }
  }
}

function randomize() {
  generation = 0;
  for (let i = 0; i < cols; i++) {
    for (let j = 0; j < rows; j++) {
      grid[i][j] = Math.random() < 0.15 ? 1 : 0;
    }
  }
}

function clear() {
  generation = 0;
  for (let i = 0; i < cols; i++) {
    for (let j = 0; j < rows; j++) {
      grid[i][j] = 0;
    }
  }
}

function countNeighbors(x, y) {
  let count = 0;
  for (let i = -1; i <= 1; i++) {
    for (let j = -1; j <= 1; j++) {
      if (i === 0 && j === 0) continue;
      const ni = (x + i + cols) % cols;
      const nj = (y + j + rows) % rows;
      count += grid[ni][nj];
    }
  }
  return count;
}

function step() {
  const next = [];
  for (let i = 0; i < cols; i++) {
    next[i] = [];
    for (let j = 0; j < rows; j++) {
      const neighbors = countNeighbors(i, j);
      if (grid[i][j] === 1) {
        next[i][j] = (neighbors === 2 || neighbors === 3) ? 1 : 0;
      } else {
        next[i][j] = neighbors === 3 ? 1 : 0;
      }
    }
  }
  grid = next;
  generation++;
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  
  // グリッド線（薄く）
  ctx.strokeStyle = COLORS.grid;
  ctx.lineWidth = 1;
  for (let i = 0; i <= cols; i++) {
    ctx.beginPath();
    ctx.moveTo(i * CELL_SIZE, 0);
    ctx.lineTo(i * CELL_SIZE, canvas.height);
    ctx.stroke();
  }
  for (let j = 0; j <= rows; j++) {
    ctx.beginPath();
    ctx.moveTo(0, j * CELL_SIZE);
    ctx.lineTo(canvas.width, j * CELL_SIZE);
    ctx.stroke();
  }
  
  // セル
  let population = 0;
  for (let i = 0; i < cols; i++) {
    for (let j = 0; j < rows; j++) {
      if (grid[i][j] === 1) {
        population++;
        const x = i * CELL_SIZE;
        const y = j * CELL_SIZE;
        
        // 色のグラデーション
        const ratio = (i + j) / (cols + rows);
        const useAlt = ratio > 0.5;
        
        ctx.shadowBlur = 8;
        ctx.shadowColor = useAlt ? COLORS.cellAltGlow : COLORS.cellGlow;
        ctx.fillStyle = useAlt ? COLORS.cellAlt : COLORS.cell;
        
        ctx.fillRect(x + 1, y + 1, CELL_SIZE - 2, CELL_SIZE - 2);
      }
    }
  }
  ctx.shadowBlur = 0;
  
  // 統計更新
  const totalCells = cols * rows;
  document.getElementById('generation').textContent = generation;
  document.getElementById('population').textContent = population;
  document.getElementById('density').textContent = ((population / totalCells) * 100).toFixed(1);
}

function loop(timestamp) {
  const interval = 300 - (speed * 25); // 速度に応じた間隔
  
  if (isPlaying && timestamp - lastUpdate >= interval) {
    step();
    lastUpdate = timestamp;
  }
  
  draw();
  requestAnimationFrame(loop);
}

function placePattern(pattern, cx, cy) {
  const cells = PATTERNS[pattern];
  if (!cells) return;
  
  // パターンの中心を計算
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
  cells.forEach(([x, y]) => {
    minX = Math.min(minX, x);
    minY = Math.min(minY, y);
    maxX = Math.max(maxX, x);
    maxY = Math.max(maxY, y);
  });
  const offsetX = Math.floor((maxX - minX) / 2);
  const offsetY = Math.floor((maxY - minY) / 2);
  
  cells.forEach(([x, y]) => {
    const nx = (cx + x - offsetX + cols) % cols;
    const ny = (cy + y - offsetY + rows) % rows;
    if (grid[nx] && grid[nx][ny] !== undefined) {
      grid[nx][ny] = 1;
    }
  });
}

// マウスイベント
let isDrawing = false;
let lastCell = null;

canvas.addEventListener('mousedown', (e) => {
  isDrawing = true;
  const x = Math.floor(e.clientX / CELL_SIZE);
  const y = Math.floor(e.clientY / CELL_SIZE);
  
  if (selectedPattern) {
    placePattern(selectedPattern, x, y);
    selectedPattern = null;
    document.querySelectorAll('.pattern-btn').forEach(b => b.classList.remove('active'));
  } else {
    if (grid[x] && grid[x][y] !== undefined) {
      grid[x][y] = grid[x][y] ? 0 : 1;
      lastCell = { x, y };
      SFX.play('tick', 30);
    }
  }
});

canvas.addEventListener('mousemove', (e) => {
  if (!isDrawing || selectedPattern) return;
  const x = Math.floor(e.clientX / CELL_SIZE);
  const y = Math.floor(e.clientY / CELL_SIZE);
  
  if (lastCell && (lastCell.x !== x || lastCell.y !== y)) {
    if (grid[x] && grid[x][y] !== undefined) {
      grid[x][y] = 1;
      lastCell = { x, y };
    }
  }
});

canvas.addEventListener('mouseup', () => {
  isDrawing = false;
  lastCell = null;
});

canvas.addEventListener('mouseleave', () => {
  isDrawing = false;
  lastCell = null;
});

// コントロール
document.getElementById('playBtn').addEventListener('click', function() {
  isPlaying = !isPlaying;
  this.textContent = isPlaying ? '▶ PLAY' : '❚❚ PAUSE';
  this.classList.toggle('active', isPlaying);
  SFX.play('toggle');
});

document.getElementById('stepBtn').addEventListener('click', () => {
  if (!isPlaying) { step(); SFX.play('tick'); }
});

document.getElementById('clearBtn').addEventListener('click', () => { clear(); SFX.play('click'); });
document.getElementById('randomBtn').addEventListener('click', () => { randomize(); SFX.play('click'); });

document.getElementById('speedSlider').addEventListener('input', function() {
  speed = parseInt(this.value);
});

// パターン選択
document.querySelectorAll('.pattern-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.pattern-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    selectedPattern = this.dataset.pattern;
    SFX.play('toggle');
  });
});

// リサイズ
window.addEventListener('resize', resize);

// 初期化
init();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
