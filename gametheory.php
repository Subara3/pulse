<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gametheory');

$pageName = 'gametheory';
$pageTitle = 'GAMETHEORY - PULSE';
$pageDesc = '空間囚人のジレンマ。格子上の協力者と裏切り者が戦略をコピーし、複雑なパターンが創発する。';
$pageCanonical = 'https://pulse.subara3.com/gametheory.php';
$extraStyle = '
body { background: #0a0a0f; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
.hud {position:fixed;top:3.5rem;right:1rem;font-family: "JetBrains Mono", monospace;font-size: 0.7rem;color: rgba(255,255,255,0.4);text-align: left;line-height: 1.8;z-index: 10;pointer-events: none;}
.hud .vc { color: #00f5d4; }
.hud .vd { color: #9b4fd4; }
.ctrl { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 10; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end; }
.ctrl button { background: rgba(0,245,212,0.08); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; font-family: "JetBrains Mono", monospace; font-size: 0.65rem; padding: 0.35rem 0.8rem; cursor: pointer; letter-spacing: 0.1em; transition: background 0.2s; }
.ctrl button:hover { background: rgba(0,245,212,0.18); }
.ctrl label { font-family: "JetBrains Mono", monospace; font-size: 0.65rem; color: rgba(255,255,255,0.4); display: flex; align-items: center; gap: 0.6rem; }
.ctrl input[type=range] { width: 100px; accent-color: #00f5d4; }
.title { position: fixed; bottom: 1.5rem; left: 1.5rem; font-family: "Orbitron", sans-serif; font-size: 0.7rem; color: rgba(255,255,255,0.12); letter-spacing: 0.2em; z-index: 10; pointer-events: none; }
@media (max-width: 768px) {
  .hud {font-size: 0.6rem;}
  .ctrl input[type=range] { width: 80px; }
  .title { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  <div>GEN <span class="vc" id="hud-gen">0</span></div>
  <div>C <span class="vc" id="hud-c">--</span>%</div>
  <div>D <span class="vd" id="hud-d">--</span>%</div>
</div>

<div class="ctrl">
  <button id="btn-reset">RESET</button>
  <button id="btn-defect">ALL DEFECT</button>
  <label>b <span id="lbl-b">1.65</span>
    <input type="range" id="sl-b" min="1.0" max="2.5" step="0.05" value="1.65">
  </label>
</div>

<div class="title">GAMETHEORY</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'cooperate'],
  ['type' => 'right', 'label' => 'defect'],
  ['type' => 'drag', 'label' => 'paint'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  const CELL = 5;
  // payoffs: R=reward(C-C), S=sucker(C-D), P=punishment(D-D), T=temptation(D-C)
  const R = 1, S = 0, P = 0;
  let T = 1.65; // temptation = b (slider)

  let cols, rows;
  let grid, nextGrid, scoreGrid;
  let gen = 0;
  let animId;
  let lastStep = 0;
  const STEP_INTERVAL = 80; // ms per generation

  // ImageData buffer
  let imgData;

  // teal rgb for cooperator: 0,180,160 (dim teal)
  // bright teal for newly-C: 0,245,212
  // purple for defector: 100,35,160
  // bright purple for newly-D: 160,60,220
  const C_R = 0,   C_G = 185, C_B = 165;
  const D_R = 110, D_G = 30,  D_B = 170;

  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    cols = Math.floor(canvas.width / CELL);
    rows = Math.floor(canvas.height / CELL);
    imgData = ctx.createImageData(canvas.width, canvas.height);
    // fill alpha
    for (let i = 3; i < imgData.data.length; i += 4) imgData.data[i] = 255;
    initGrid();
  }

  function initGrid() {
    const n = cols * rows;
    grid = new Uint8Array(n);
    nextGrid = new Uint8Array(n);
    scoreGrid = new Float32Array(n);
    for (let i = 0; i < n; i++) {
      grid[i] = Math.random() < 0.5 ? 1 : 2; // 1=C, 2=D
    }
    gen = 0;
  }

  function payoff(a, b_strat) {
    if (a === 1 && b_strat === 1) return R;
    if (a === 1 && b_strat === 2) return S;
    if (a === 2 && b_strat === 1) return T;
    return P; // D-D
  }

  function step() {
    // Compute scores (self + 8 neighbors)
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const idx = y * cols + x;
        const me = grid[idx];
        let sc = payoff(me, me);
        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            if (dx === 0 && dy === 0) continue;
            const nx = (x + dx + cols) % cols;
            const ny = (y + dy + rows) % rows;
            sc += payoff(me, grid[ny * cols + nx]);
          }
        }
        scoreGrid[idx] = sc;
      }
    }

    // Update: adopt strategy of best-scoring neighbor (incl. self)
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const idx = y * cols + x;
        let best = scoreGrid[idx];
        let bestStrat = grid[idx];
        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            if (dx === 0 && dy === 0) continue;
            const nx = (x + dx + cols) % cols;
            const ny = (y + dy + rows) % rows;
            const ni = ny * cols + nx;
            if (scoreGrid[ni] > best) {
              best = scoreGrid[ni];
              bestStrat = grid[ni];
            }
          }
        }
        nextGrid[idx] = bestStrat;
      }
    }

    // Swap buffers
    const tmp = grid; grid = nextGrid; nextGrid = tmp;
    gen++;
  }

  function render() {
    const d = imgData.data;
    const cw = canvas.width;
    let cCount = 0, total = cols * rows;

    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const state = grid[y * cols + x];
        const r = state === 1 ? C_R : D_R;
        const g = state === 1 ? C_G : D_G;
        const b = state === 1 ? C_B : D_B;
        if (state === 1) cCount++;

        // fill CELL x CELL block (leave 1px gap)
        const px0 = x * CELL, py0 = y * CELL;
        for (let py = 0; py < CELL - 1; py++) {
          const row = (py0 + py) * cw;
          for (let px = 0; px < CELL - 1; px++) {
            const base = (row + px0 + px) * 4;
            d[base]   = r;
            d[base+1] = g;
            d[base+2] = b;
          }
        }
        // gap pixel (dark)
        for (let py = 0; py < CELL; py++) {
          const base = ((py0 + py) * cw + px0 + CELL - 1) * 4;
          d[base] = 10; d[base+1] = 10; d[base+2] = 15;
        }
        for (let px = 0; px < CELL; px++) {
          const base = ((py0 + CELL - 1) * cw + px0 + px) * 4;
          d[base] = 10; d[base+1] = 10; d[base+2] = 15;
        }
      }
    }

    ctx.putImageData(imgData, 0, 0);

    const cPct = Math.round(cCount / total * 100);
    document.getElementById('hud-gen').textContent = gen;
    document.getElementById('hud-c').textContent = cPct;
    document.getElementById('hud-d').textContent = 100 - cPct;
  }

  function loop(ts) {
    animId = requestAnimationFrame(loop);
    if (ts - lastStep >= STEP_INTERVAL) {
      step();
      render();
      lastStep = ts;
      if (gen % 10 === 0) SFX.play('tick', 200);
    }
  }

  // Pointer interaction
  let isDown = false, rightDown = false;

  function cellAt(clientX, clientY) {
    const rect = canvas.getBoundingClientRect();
    const x = Math.floor((clientX - rect.left) / CELL);
    const y = Math.floor((clientY - rect.top) / CELL);
    if (x >= 0 && x < cols && y >= 0 && y < rows) return y * cols + x;
    return -1;
  }

  function paint(clientX, clientY, strat) {
    const idx = cellAt(clientX, clientY);
    if (idx < 0) return;
    // Paint a small brush (3x3)
    const cx = Math.floor(clientX / CELL);
    const cy = Math.floor(clientY / CELL);
    for (let dy = -1; dy <= 1; dy++) {
      for (let dx = -1; dx <= 1; dx++) {
        const nx = cx + dx, ny = cy + dy;
        if (nx >= 0 && nx < cols && ny >= 0 && ny < rows) {
          grid[ny * cols + nx] = strat;
        }
      }
    }
  }

  canvas.addEventListener('mousedown', e => {
    e.preventDefault();
    isDown = true;
    rightDown = e.button === 2;
    paint(e.clientX, e.clientY, rightDown ? 2 : 1);
    SFX.play(rightDown ? 'tick' : 'spawn');
  });
  canvas.addEventListener('mousemove', e => {
    if (!isDown) return;
    paint(e.clientX, e.clientY, rightDown ? 2 : 1);
    SFX.play('move', 40);
  });
  canvas.addEventListener('mouseup', () => { isDown = false; });
  canvas.addEventListener('contextmenu', e => e.preventDefault());

  // Touch
  canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.touches[0];
    paint(t.clientX, t.clientY, 1);
    SFX.play('spawn');
  }, { passive: false });
  canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const t = e.touches[0];
    paint(t.clientX, t.clientY, 1);
    SFX.play('move', 40);
  }, { passive: false });

  // Controls
  document.getElementById('btn-reset').addEventListener('click', () => {
    initGrid();
    SFX.play('click');
  });
  document.getElementById('btn-defect').addEventListener('click', () => {
    // single cooperator in center, rest defectors
    grid.fill(2);
    const cx = Math.floor(cols / 2), cy = Math.floor(rows / 2);
    for (let dy = -2; dy <= 2; dy++) {
      for (let dx = -2; dx <= 2; dx++) {
        const nx = cx + dx, ny = cy + dy;
        if (nx >= 0 && nx < cols && ny >= 0 && ny < rows) {
          grid[ny * cols + nx] = 1;
        }
      }
    }
    gen = 0;
    SFX.play('toggle');
  });
  document.getElementById('sl-b').addEventListener('input', function() {
    T = parseFloat(this.value);
    document.getElementById('lbl-b').textContent = T.toFixed(2);
    SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
