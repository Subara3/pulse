<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('sandpile');

$pageName = 'sandpile';
$pageTitle = 'SANDPILE - PULSE';
$pageDesc = 'Abelian Sandpile Model. 砂粒が崩壊し、フラクタルを描く。';
$pageCanonical = 'https://pulse.subara3.com/sandpile.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body {
  background: #0a0a0f;
  min-height: 100vh;
  overflow: hidden;
}

#canvas {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  cursor: crosshair;
}

.ui {
  position: fixed;
  z-index: 100;
  font-family: "Orbitron", sans-serif;
}

.title {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.control-btn {
  padding: 0.5rem 1rem;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.5);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
}

.control-btn:hover {
  background: rgba(0, 245, 212, 0.1);
  border-color: rgba(0, 245, 212, 0.5);
  color: #00f5d4;
}

.control-btn.active {
  background: rgba(0, 245, 212, 0.2);
  border-color: #00f5d4;
  color: #00f5d4;
}

.grain-count {
  top: 1.5rem;
  right: 1.5rem;
  color: rgba(255,255,255,0.3);
  font-size: 0.7rem;
  text-align: right;
  letter-spacing: 0.1em;
}

@media (max-width: 768px) {
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .grain-count { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
  .controls { bottom: 0.5rem; gap: 0.3rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui title">SANDPILE</div>
<div class="ui grain-count" id="grainCount">0 grains</div>
<div class="ui controls">
  <button class="control-btn" id="autoBtn">AUTO</button>
  <button class="control-btn" id="clearBtn">CLEAR</button>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const grainCountEl = document.getElementById('grainCount');
  const autoBtn = document.getElementById('autoBtn');
  const clearBtn = document.getElementById('clearBtn');

  const isMobile = window.innerWidth <= 768;
  const CELL_SIZE = isMobile ? 6 : 4;
  const COLORS = ['#0a0a0f', '#1a1a3e', '#7b2cbf', '#00f5d4'];

  let width, height, cols, rows;
  let grid;
  let autoMode = false;
  let isPointerDown = false;
  let totalGrains = 0;

  function init() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    cols = Math.floor(width / CELL_SIZE);
    rows = Math.floor(height / CELL_SIZE);
    grid = new Int32Array(cols * rows);
    totalGrains = 0;
    updateGrainCount();
    drawFull();
  }

  function getIndex(x, y) {
    if (x < 0 || x >= cols || y < 0 || y >= rows) return -1;
    return y * cols + x;
  }

  function addSand(cx, cy, amount) {
    const idx = getIndex(cx, cy);
    if (idx < 0) return;
    grid[idx] += amount;
    totalGrains += amount;
  }

  function topple() {
    let changed = true;
    while (changed) {
      changed = false;
      for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
          const idx = y * cols + x;
          if (grid[idx] >= 4) {
            const spill = Math.floor(grid[idx] / 4);
            grid[idx] -= spill * 4;

            // 上下左右に分配
            const neighbors = [
              getIndex(x, y - 1),
              getIndex(x, y + 1),
              getIndex(x - 1, y),
              getIndex(x + 1, y)
            ];

            for (const ni of neighbors) {
              if (ni >= 0) {
                grid[ni] += spill;
              } else {
                // 境界外 → 砂が消滅
                totalGrains -= spill;
              }
            }

            changed = true;
          }
        }
      }
    }
  }

  function updateGrainCount() {
    grainCountEl.textContent = totalGrains.toLocaleString() + ' grains';
  }

  function drawFull() {
    const imageData = ctx.createImageData(width, height);
    const data = imageData.data;

    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const val = Math.min(grid[y * cols + x], 3);
        const color = COLORS[val];
        const r = parseInt(color.slice(1, 3), 16);
        const g = parseInt(color.slice(3, 5), 16);
        const b = parseInt(color.slice(5, 7), 16);

        for (let dy = 0; dy < CELL_SIZE && (y * CELL_SIZE + dy) < height; dy++) {
          for (let dx = 0; dx < CELL_SIZE && (x * CELL_SIZE + dx) < width; dx++) {
            const px = (y * CELL_SIZE + dy) * width + (x * CELL_SIZE + dx);
            const pi = px * 4;
            data[pi] = r;
            data[pi + 1] = g;
            data[pi + 2] = b;
            data[pi + 3] = 255;
          }
        }
      }
    }

    ctx.putImageData(imageData, 0, 0);
  }

  // 色のRGB値をキャッシュ
  const COLOR_RGB = COLORS.map(c => ({
    r: parseInt(c.slice(1, 3), 16),
    g: parseInt(c.slice(3, 5), 16),
    b: parseInt(c.slice(5, 7), 16)
  }));

  function drawCell(x, y) {
    const val = Math.min(grid[y * cols + x], 3);
    const rgb = COLOR_RGB[val];
    ctx.fillStyle = COLORS[val];
    ctx.fillRect(x * CELL_SIZE, y * CELL_SIZE, CELL_SIZE, CELL_SIZE);
  }

  function getPointerPos(e) {
    if (e.touches) {
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }
    return { x: e.clientX, y: e.clientY };
  }

  function handlePointer(e) {
    const pos = getPointerPos(e);
    const cx = Math.floor(pos.x / CELL_SIZE);
    const cy = Math.floor(pos.y / CELL_SIZE);
    addSand(cx, cy, 1);
    topple();
    drawFull();
    updateGrainCount();
    SFX.play('tick', 50);
  }

  canvas.addEventListener('mousedown', (e) => {
    isPointerDown = true;
    handlePointer(e);
  });

  canvas.addEventListener('mousemove', (e) => {
    if (isPointerDown) handlePointer(e);
  });

  canvas.addEventListener('mouseup', () => isPointerDown = false);
  canvas.addEventListener('mouseleave', () => isPointerDown = false);

  canvas.addEventListener('touchstart', (e) => {
    isPointerDown = true;
    handlePointer(e);
    e.preventDefault();
  }, { passive: false });

  canvas.addEventListener('touchmove', (e) => {
    if (isPointerDown) handlePointer(e);
    e.preventDefault();
  }, { passive: false });

  canvas.addEventListener('touchend', () => isPointerDown = false);
  canvas.addEventListener('touchcancel', () => isPointerDown = false);

  // AUTO モード
  let autoInterval = null;

  autoBtn.addEventListener('click', () => {
    autoMode = !autoMode;
    autoBtn.classList.toggle('active', autoMode);
    SFX.play('toggle');

    if (autoMode) {
      // autoIntervalは使わない。ループ内で処理する。
    } else {
      // 停止
    }
  });

  clearBtn.addEventListener('click', () => {
    autoMode = false;
    autoBtn.classList.remove('active');
    init();
    SFX.play('click');
  });

  window.addEventListener('resize', () => {
    autoMode = false;
    autoBtn.classList.remove('active');
    init();
  });

  // メインループ
  function loop() {
    if (autoMode) {
      const cx = Math.floor(cols / 2);
      const cy = Math.floor(rows / 2);
      addSand(cx, cy, 1);
      topple();
      drawFull();
      updateGrainCount();
    }
    requestAnimationFrame(loop);
  }

  init();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
