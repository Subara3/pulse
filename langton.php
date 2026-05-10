<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('langton');

$pageName = 'langton';
$pageTitle = "LANGTON'S ANT - PULSE";
$pageDesc = "ラングトンのアリ。たった2つのルールが生む秩序と混沌。";
$pageCanonical = 'https://pulse.subara3.com/langton.php';

$extraHead = '';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; margin: 0; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }

.hud {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.85);
  border: 1px solid rgba(0, 245, 212, 0.25);
  border-radius: 8px;
  padding: 1rem 1.2rem;
  backdrop-filter: blur(10px);
  font-family: "JetBrains Mono", monospace;
}

.hud-title {
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  color: var(--accent);
  letter-spacing: 0.2em;
  margin-bottom: 0.6rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hud-title::before {
  content: "";
  width: 6px;
  height: 6px;
  background: var(--accent);
  border-radius: 50%;
  box-shadow: 0 0 8px var(--accent);
  animation: hud-blink 1.5s infinite;
}

@keyframes hud-blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.hud-row {
  display: flex;
  justify-content: space-between;
  gap: 1.5rem;
  padding: 0.25rem 0;
  border-bottom: 1px solid rgba(0, 245, 212, 0.08);
}

.hud-row:last-child { border-bottom: none; }

.hud-label {
  font-size: 0.65rem;
  color: rgba(255, 255, 255, 0.45);
}

.hud-value {
  font-size: 0.85rem;
  color: var(--accent);
  min-width: 50px;
  text-align: right;
}

.hud-value.highway {
  color: #ffd700;
  text-shadow: 0 0 8px rgba(255, 215, 0, 0.5);
}

.controls {
  position: fixed;
  bottom: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  display: flex;
  gap: 0.6rem;
  align-items: center;
}

.ctrl-btn {
  background: rgba(0, 10, 15, 0.85);
  border: 1px solid rgba(0, 245, 212, 0.25);
  border-radius: 8px;
  padding: 0.5rem 1rem;
  color: var(--accent);
  font-family: "Orbitron", sans-serif;
  font-size: 0.65rem;
  cursor: pointer;
  transition: all 0.2s;
  backdrop-filter: blur(10px);
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.ctrl-btn:hover {
  background: rgba(0, 245, 212, 0.1);
  border-color: var(--accent);
  box-shadow: 0 0 15px rgba(0, 245, 212, 0.15);
}

.ctrl-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: var(--accent);
}

.speed-control {
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  z-index: 100;
  background: rgba(0, 10, 15, 0.85);
  border: 1px solid rgba(0, 245, 212, 0.25);
  border-radius: 8px;
  padding: 0.6rem 1rem;
  backdrop-filter: blur(10px);
}

.speed-label {
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: rgba(255, 255, 255, 0.45);
  margin-bottom: 0.4rem;
  display: flex;
  justify-content: space-between;
}

.speed-label span { color: var(--accent); }

input[type="range"] {
  width: 90px;
  height: 3px;
  -webkit-appearance: none;
  background: rgba(0, 245, 212, 0.2);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  background: var(--accent);
  border-radius: 50%;
  cursor: pointer;
  box-shadow: 0 0 8px var(--accent);
}

/* ハイウェイ検出通知 */
.highway-toast {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.8);
  z-index: 200;
  font-family: "Orbitron", sans-serif;
  font-size: 1.5rem;
  color: #ffd700;
  text-shadow: 0 0 30px rgba(255, 215, 0, 0.6);
  letter-spacing: 0.15em;
  opacity: 0;
  pointer-events: none;
  transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}

.highway-toast.show {
  opacity: 1;
  transform: translate(-50%, -50%) scale(1);
}

.highway-toast.fade {
  opacity: 0;
  transform: translate(-50%, -50%) scale(1.1);
}

/* ヒント */
.hint {
  position: fixed;
  bottom: 4rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100;
  font-family: "Orbitron", sans-serif;
  font-size: 0.6rem;
  color: rgba(255, 255, 255, 0.25);
  letter-spacing: 0.1em;
  pointer-events: none;
  transition: opacity 2s;
}

@media (max-width: 768px) {
  .hud { padding: 0.7rem 0.9rem; top: 0.5rem; right: 0.5rem; }
  .hud-title { font-size: 0.6rem; }
  .hud-label { font-size: 0.6rem; }
  .hud-value { font-size: 0.75rem; }
  .controls { bottom: 0.5rem; gap: 0.4rem; }
  .ctrl-btn { padding: 0.4rem 0.7rem; font-size: 0.6rem; }
  .speed-control { bottom: 0.5rem; right: 0.5rem; padding: 0.5rem 0.8rem; }
  .hint { bottom: 3.5rem; font-size: 0.55rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<!-- HUD -->
<div class="hud">
  <div class="hud-title">LANGTON'S ANT</div>
  <div class="hud-row">
    <span class="hud-label">STEPS</span>
    <span class="hud-value" id="steps">0</span>
  </div>
  <div class="hud-row">
    <span class="hud-label">ANTS</span>
    <span class="hud-value" id="antCount">1</span>
  </div>
  <div class="hud-row">
    <span class="hud-label">STATE</span>
    <span class="hud-value" id="state">CHAOS</span>
  </div>
</div>

<!-- コントロール -->
<div class="controls">
  <button class="ctrl-btn" id="resetBtn"><i class="fa-solid fa-rotate"></i></button>
  <button class="ctrl-btn" id="pauseBtn"><i class="fa-solid fa-pause"></i></button>
  <button class="ctrl-btn" id="addAntBtn"><i class="fa-solid fa-plus"></i></button>
</div>

<!-- 速度 -->
<div class="speed-control">
  <div class="speed-label">SPEED <span id="speedValue">1x</span></div>
  <input type="range" id="speedSlider" min="0" max="6" value="0">
</div>

<!-- ハイウェイ通知 -->
<div class="highway-toast" id="highwayToast">HIGHWAY</div>

<!-- ヒント -->
<div class="hint" id="hint">CLICK TO ADD ANT</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  // 速度テーブル（指数的）
  const SPEED_TABLE = [1, 5, 20, 50, 200, 1000, 5000];

  // 状態
  let cellSize = 4;
  let cols, rows;
  let grid; // Uint8Array
  let ants = [];
  let totalSteps = 0;
  let paused = false;
  let speed = 1;
  let highwayDetected = false;
  let highwayCheckInterval = 1000;
  let prevPositions = [];

  // 色
  const BG_COLOR = '#0a0a0f';
  const CELL_ON = '#00f5d4';
  const CELL_OFF = BG_COLOR;
  const ANT_COLORS = [
    '#ff3c3c', '#ff9f43', '#ffd700', '#00f5d4',
    '#54a0ff', '#c56cf0', '#ff6b81', '#2ed573'
  ];

  // 方向: 0=上, 1=右, 2=下, 3=左
  const DX = [0, 1, 0, -1];
  const DY = [-1, 0, 1, 0];

  class Ant {
    constructor(x, y, colorIndex) {
      this.x = x;
      this.y = y;
      this.dir = 0; // 上向き
      this.colorIndex = colorIndex;
      this.color = ANT_COLORS[colorIndex % ANT_COLORS.length];
      this.steps = 0;
    }

    step() {
      const idx = this.y * cols + this.x;
      if (grid[idx] === 0) {
        // 白 → 右に曲がる、黒にする
        this.dir = (this.dir + 1) % 4;
        grid[idx] = 1;
      } else {
        // 黒 → 左に曲がる、白にする
        this.dir = (this.dir + 3) % 4;
        grid[idx] = 0;
      }
      // 前進
      this.x = (this.x + DX[this.dir] + cols) % cols;
      this.y = (this.y + DY[this.dir] + rows) % rows;
      this.steps++;
    }
  }

  // DOM
  const stepsEl = document.getElementById('steps');
  const antCountEl = document.getElementById('antCount');
  const stateEl = document.getElementById('state');
  const speedValueEl = document.getElementById('speedValue');
  const highwayToast = document.getElementById('highwayToast');
  const hintEl = document.getElementById('hint');

  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    cols = Math.floor(canvas.width / cellSize);
    rows = Math.floor(canvas.height / cellSize);
    reset();
  }

  function reset() {
    grid = new Uint8Array(cols * rows);
    ants = [];
    totalSteps = 0;
    highwayDetected = false;
    prevPositions = [];
    stateEl.textContent = 'CHAOS';
    stateEl.classList.remove('highway');

    // 中央にアリ1匹
    addAnt(Math.floor(cols / 2), Math.floor(rows / 2));
    updateHUD();
  }

  function addAnt(cx, cy) {
    ants.push(new Ant(cx, cy, ants.length));
    antCountEl.textContent = ants.length;
  }

  function updateHUD() {
    stepsEl.textContent = totalSteps.toLocaleString();
    antCountEl.textContent = ants.length;
  }

  // ハイウェイ検出（アリ1匹のとき、周期的パターンを検出）
  function checkHighway() {
    if (ants.length !== 1 || highwayDetected) return;
    const ant = ants[0];
    const pos = { x: ant.x, y: ant.y, dir: ant.dir };
    prevPositions.push(pos);

    // 直近104ステップの移動パターンを見る（ハイウェイの周期は104）
    if (prevPositions.length >= 208) {
      const len = prevPositions.length;
      const period = 104;
      let match = true;
      for (let i = 0; i < period; i++) {
        const a = prevPositions[len - 1 - i];
        const b = prevPositions[len - 1 - i - period];
        const dx = a.x - b.x;
        const dy = a.y - b.y;
        // ハイウェイでは各周期で同じ方向に2マスずれる
        if (i === 0) {
          // 最初の比較で基準を記録
          prevPositions._dx = dx;
          prevPositions._dy = dy;
        } else {
          if (dx !== prevPositions._dx || dy !== prevPositions._dy) {
            match = false;
            break;
          }
        }
        if (a.dir !== b.dir) { match = false; break; }
      }
      if (match) {
        highwayDetected = true;
        stateEl.textContent = 'HIGHWAY';
        stateEl.classList.add('highway');
        // トースト
        highwayToast.classList.add('show');
        setTimeout(() => highwayToast.classList.add('fade'), 2000);
        setTimeout(() => {
          highwayToast.classList.remove('show', 'fade');
        }, 3000);
      }
      // メモリ節約
      if (prevPositions.length > 300) {
        prevPositions = prevPositions.slice(-210);
      }
    }
  }

  function draw() {
    // 高速時はバッファ描画
    ctx.fillStyle = BG_COLOR;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // グリッド上の黒セルを描画
    ctx.fillStyle = CELL_ON;
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        if (grid[y * cols + x] === 1) {
          ctx.fillRect(x * cellSize, y * cellSize, cellSize, cellSize);
        }
      }
    }

    // アリを描画
    for (const ant of ants) {
      const ax = ant.x * cellSize;
      const ay = ant.y * cellSize;
      const s = Math.max(cellSize, 3);

      // グロー
      ctx.fillStyle = ant.color + '40';
      ctx.fillRect(ax - 1, ay - 1, s + 2, s + 2);

      // 本体
      ctx.fillStyle = ant.color;
      ctx.fillRect(ax, ay, s, s);
    }
  }

  function update() {
    for (let s = 0; s < speed; s++) {
      for (const ant of ants) {
        ant.step();
      }
      totalSteps++;

      // ハイウェイ検出（1000ステップごと、かつ9000ステップ以降）
      if (totalSteps > 9000 && totalSteps % highwayCheckInterval === 0) {
        checkHighway();
      }
    }
    updateHUD();
  }

  function loop() {
    if (!paused) {
      update();
    }
    draw();
    requestAnimationFrame(loop);
  }

  // イベント
  window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    // リサイズ時はリセットしない（描画のみ更新）
  });

  document.getElementById('resetBtn').addEventListener('click', () => {
    resize();
  });

  document.getElementById('pauseBtn').addEventListener('click', function() {
    paused = !paused;
    this.innerHTML = paused
      ? '<i class="fa-solid fa-play"></i>'
      : '<i class="fa-solid fa-pause"></i>';
    this.classList.toggle('active', paused);
  });

  document.getElementById('addAntBtn').addEventListener('click', () => {
    // ランダム位置にアリ追加
    const rx = Math.floor(Math.random() * cols);
    const ry = Math.floor(Math.random() * rows);
    addAnt(rx, ry);
    SFX.play('spawn');
  });

  document.getElementById('speedSlider').addEventListener('input', function() {
    const idx = parseInt(this.value);
    speed = SPEED_TABLE[idx];
    speedValueEl.textContent = speed >= 1000 ? (speed / 1000) + 'k' : speed + 'x';
  });

  // キャンバスクリックでアリ追加
  canvas.addEventListener('click', (e) => {
    const cx = Math.floor(e.clientX / cellSize);
    const cy = Math.floor(e.clientY / cellSize);
    addAnt(cx, cy);
    SFX.play('spawn');
    // ヒントを消す
    if (hintEl) hintEl.style.opacity = '0';
  });

  // ヒントを数秒後に消す
  setTimeout(() => {
    if (hintEl) hintEl.style.opacity = '0';
  }, 5000);

  // 初期化
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
