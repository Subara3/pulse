<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('maze');

$pageName = 'maze';
$pageTitle = 'Maze - PULSE';
$pageDesc = '毎回ランダム生成される迷路。ゴールを目指せ。';
$pageCanonical = 'https://pulse.subara3.com/maze.php';
$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}

.header {
  position: fixed;
  top: 2rem;
  left: 2rem;
}

.logo {
  font-family: "Orbitron", sans-serif;
  font-size: 1.5rem;
  color: #00f5d4;
  text-decoration: none;
}

.hint {
  color: #666;
  font-size: 0.7rem;
  margin-top: 0.5rem;
  letter-spacing: 0.1em;
}

.stats {
  display: flex;
  gap: 3rem;
  margin-bottom: 2rem;
}

.stat { text-align: center; }

.stat-value {
  font-family: "Orbitron", sans-serif;
  font-size: 2rem;
  color: #00f5d4;
}

.stat-label {
  font-size: 0.7rem;
  color: #666;
  letter-spacing: 0.2em;
}

.maze-container {
  border: 2px solid #1a1a24;
  border-radius: 8px;
  overflow: hidden;
}

.controls {
  margin-top: 2rem;
  display: flex;
  gap: 1rem;
  align-items: center;
}

.btn {
  font-family: "Orbitron", sans-serif;
  background: #1a1a24;
  border: 1px solid #333;
  color: #888;
  padding: 0.8rem 1.5rem;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 0.8rem;
  border-radius: 4px;
}

.btn:hover {
  border-color: #00f5d4;
  color: #00f5d4;
}

.btn-primary {
  background: linear-gradient(135deg, #00f5d4, #7b2cbf);
  border: none;
  color: #0a0a0f;
  font-weight: 600;
}

.btn-primary:hover {
  transform: scale(1.05);
  box-shadow: 0 0 20px rgba(0, 245, 212, 0.3);
}

.size-btns {
  display: flex;
  gap: 0.5rem;
}

.size-btn.active {
  border-color: #00f5d4;
  color: #00f5d4;
}

.message {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: rgba(0, 245, 212, 0.95);
  color: #0a0a0f;
  padding: 2rem 3rem;
  border-radius: 12px;
  font-size: 1.5rem;
  display: none;
  z-index: 100;
  text-align: center;
}

.message.show { display: block; }

.back-link {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  color: #666;
  text-decoration: none;
  font-size: 0.75rem;
}

.back-link:hover { color: #00f5d4; }

.instructions {
  color: #666;
  font-size: 0.75rem;
  margin-top: 1rem;
  text-align: center;
}

@media (max-width: 768px) {
  body { padding: 1rem; }
  .header { top: 0.5rem; left: 0.5rem; }
  .logo { font-size: 1rem; }
  .hint { font-size: 0.55rem; }
  .stats { gap: 1.5rem; margin-bottom: 1rem; }
  .stat-value { font-size: 1.2rem; }
  .stat-label { font-size: 0.55rem; }
  .controls { gap: 0.3rem; flex-wrap: wrap; }
  .control-btn { padding: 0.4rem 0.6rem; font-size: 0.55rem; }
  .btn-primary { padding: 0.5rem 1rem; font-size: 0.7rem; }
  .size-btn { padding: 0.3rem 0.5rem; font-size: 0.5rem; }
  .message { padding: 1.5rem 2rem; font-size: 1rem; }
  .instructions { font-size: 0.6rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/sfx.php';

?>

<div class="header">
  <a href="index.php" class="logo">PULSE</a>
  <p class="hint">MAZE GENERATOR</p>
</div>

<div class="stats">
  <div class="stat">
    <div class="stat-value" id="moves">0</div>
    <div class="stat-label">MOVES</div>
  </div>
  <div class="stat">
    <div class="stat-value" id="timer">0:00</div>
    <div class="stat-label">TIME</div>
  </div>
</div>

<div class="maze-container">
  <canvas id="maze"></canvas>
</div>

<div class="controls">
  <div class="size-btns">
    <button class="btn size-btn" onclick="setSize(11)">S</button>
    <button class="btn size-btn active" onclick="setSize(21)">M</button>
    <button class="btn size-btn" onclick="setSize(31)">L</button>
  </div>
  <button class="btn btn-primary" onclick="newMaze()">NEW MAZE</button>
</div>

<p class="instructions">Arrow keys or WASD to move</p>

<div class="message" id="message">
  🎉 CLEAR!<br>
  <span id="clearStats"></span>
</div>

<a href="index.php" class="back-link"><i class="fa-solid fa-arrow-left"></i></a>

<script>
const canvas = document.getElementById('maze');
const ctx = canvas.getContext('2d');

let size = 21;
let cellSize = 20;
let maze = [];
let playerX = 1;
let playerY = 1;
let goalX, goalY;
let moves = 0;
let seconds = 0;
let timerInterval = null;
let isRunning = false;

const WALL = 1;
const PATH = 0;

function generateMaze(width, height) {
  const m = [];
  for (let y = 0; y < height; y++) {
    m[y] = [];
    for (let x = 0; x < width; x++) {
      m[y][x] = WALL;
    }
  }
  
  function carve(x, y) {
    const directions = [[0, -2], [2, 0], [0, 2], [-2, 0]];
    directions.sort(() => Math.random() - 0.5);
    
    for (const [dx, dy] of directions) {
      const nx = x + dx;
      const ny = y + dy;
      
      if (nx > 0 && nx < width - 1 && ny > 0 && ny < height - 1 && m[ny][nx] === WALL) {
        m[y + dy/2][x + dx/2] = PATH;
        m[ny][nx] = PATH;
        carve(nx, ny);
      }
    }
  }
  
  m[1][1] = PATH;
  carve(1, 1);
  
  return m;
}

function draw() {
  canvas.width = size * cellSize;
  canvas.height = size * cellSize;
  
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (maze[y][x] === WALL) {
        ctx.fillStyle = '#1a1a24';
      } else {
        ctx.fillStyle = '#0a0a0f';
      }
      ctx.fillRect(x * cellSize, y * cellSize, cellSize, cellSize);
    }
  }
  
  ctx.fillStyle = '#7b2cbf';
  ctx.beginPath();
  ctx.arc(goalX * cellSize + cellSize/2, goalY * cellSize + cellSize/2, cellSize/3, 0, Math.PI * 2);
  ctx.fill();
  
  ctx.fillStyle = '#00f5d4';
  ctx.beginPath();
  ctx.arc(playerX * cellSize + cellSize/2, playerY * cellSize + cellSize/2, cellSize/3, 0, Math.PI * 2);
  ctx.fill();
}

function move(dx, dy) {
  const nx = playerX + dx;
  const ny = playerY + dy;
  
  if (maze[ny] && maze[ny][nx] === PATH) {
    SFX.play('move');
    playerX = nx;
    playerY = ny;
    moves++;
    document.getElementById('moves').textContent = moves;
    
    if (!isRunning) {
      isRunning = true;
      startTimer();
    }
    
    draw();
    
    if (playerX === goalX && playerY === goalY) {
      clearMaze();
    }
  }
}

function startTimer() {
  timerInterval = setInterval(() => {
    seconds++;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    document.getElementById('timer').textContent = `${m}:${s.toString().padStart(2, '0')}`;
  }, 1000);
}

function clearMaze() {
  clearInterval(timerInterval);
  SFX.play('complete');
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  document.getElementById('clearStats').textContent = `${moves} moves / ${m}:${s.toString().padStart(2, '0')}`;
  document.getElementById('message').classList.add('show');
}

function newMaze() {
  document.getElementById('message').classList.remove('show');
  clearInterval(timerInterval);
  
  maze = generateMaze(size, size);
  playerX = 1;
  playerY = 1;
  goalX = size - 2;
  goalY = size - 2;
  maze[goalY][goalX] = PATH;
  
  moves = 0;
  seconds = 0;
  isRunning = false;
  document.getElementById('moves').textContent = '0';
  document.getElementById('timer').textContent = '0:00';
  
  draw();
}

function setSize(s) {
  size = s;
  cellSize = s <= 11 ? 30 : s <= 21 ? 20 : 15;
  document.querySelectorAll('.size-btn').forEach(btn => {
    btn.classList.remove('active');
  });
  event.target.classList.add('active');
  newMaze();
}

document.addEventListener('keydown', (e) => {
  switch(e.key) {
    case 'ArrowUp': case 'w': case 'W': move(0, -1); break;
    case 'ArrowDown': case 's': case 'S': move(0, 1); break;
    case 'ArrowLeft': case 'a': case 'A': move(-1, 0); break;
    case 'ArrowRight': case 'd': case 'D': move(1, 0); break;
  }
});

let touchStartX, touchStartY;
canvas.addEventListener('touchstart', (e) => {
  touchStartX = e.touches[0].clientX;
  touchStartY = e.touches[0].clientY;
});

canvas.addEventListener('touchend', (e) => {
  const dx = e.changedTouches[0].clientX - touchStartX;
  const dy = e.changedTouches[0].clientY - touchStartY;
  
  if (Math.abs(dx) > Math.abs(dy)) {
    move(dx > 0 ? 1 : -1, 0);
  } else {
    move(0, dy > 0 ? 1 : -1);
  }
});

newMaze();
</script>

<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
