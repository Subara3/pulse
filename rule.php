<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rule');

$pageName = 'rule';
$pageTitle = 'RULE - PULSE';
$pageDesc = '1次元セルオートマトン。単純なルールから複雑な模様が生まれる。';
$pageCanonical = 'https://pulse.subara3.com/rule.php';
$extraStyle = '
#hud { position: fixed; top: 16px; right: 16px; text-align: right; font-size: 13px; color: rgba(255,255,255,0.7); pointer-events: none; z-index: 100; font-family: "JetBrains Mono", monospace; }
#hud .rule-num { font-size: 20px; color: #00f5d4; }
#controls button { background: rgba(0,245,212,0.1); border: 1px solid rgba(0,245,212,0.3); color: #00f5d4; padding: 8px 14px; cursor: pointer; font-size: 12px; border-radius: 4px; font-family: inherit; transition: all 0.2s; }
#controls button:hover { background: rgba(0,245,212,0.2); }
#controls button.active { background: rgba(0,245,212,0.3); }
#controls .slider-group { display: flex; flex-direction: column; gap: 4px; }
#controls .slider-group label { font-size: 11px; color: rgba(255,255,255,0.6); }
#controls input[type="range"] { width: 140px; accent-color: #00f5d4; }
#info { position: fixed; bottom: 16px; left: 16px; font-size: 11px; color: rgba(255,255,255,0.5); z-index: 100; max-width: 280px; line-height: 1.5; }
#preset-btns { display: flex; gap: 6px; flex-wrap: wrap; max-width: 150px; }
#preset-btns button { padding: 5px 8px; font-size: 11px; }

/* モバイルではinfoを上に移動（ドロワーと重ならないように） */
@media (max-width: 768px) {
  #info { bottom: auto; top: 80px; left: 16px; right: 16px; max-width: none; }
  #hud { top: 16px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="hud">
  RULE <span class="rule-num">30</span><br>
  <span id="row-count">0</span> rows
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-group">
    <label>Rule (0-255)</label>
    <input type="range" id="rule-slider" min="0" max="255" value="30">
  </div>
  <div class="slider-group">
    <label>Speed</label>
    <input type="range" id="speed-slider" min="1" max="20" value="5">
  </div>
  <div id="preset-btns">
    <button data-rule="30">30</button>
    <button data-rule="90">90</button>
    <button data-rule="110">110</button>
    <button data-rule="184">184</button>
    <button data-rule="45">45</button>
    <button data-rule="73">73</button>
  </div>
  <button id="init-btn">INIT: CENTER</button>
  <button id="reset-btn">RESET</button>
</div>

<div id="info">
  1D Cellular Automaton<br>
  Rule: どの近傍パターンが生になるかを8ビットで定義<br>
  クリック/タップで一時停止
</div>

<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  
  let W, H, cellSize, cols, rows;
  let grid = [];
  let currentRow = 0;
  let rule = 30;
  let speed = 5;
  let paused = false;
  let initMode = 'center'; // 'center' or 'random'
  let frameCount = 0;
  
  // Colors
  const bgColor = '#0a0a0f';
  const cellColor = [0, 245, 212];
  const cellColorAlt = [123, 44, 191];
  
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cellSize = Math.max(2, Math.floor(Math.min(W, H) / 200));
    cols = Math.floor(W / cellSize);
    rows = Math.floor(H / cellSize);
    reset();
  }
  
  function reset() {
    grid = [];
    currentRow = 0;
    
    // Initialize first row
    const firstRow = new Array(cols).fill(0);
    if (initMode === 'center') {
      firstRow[Math.floor(cols / 2)] = 1;
    } else {
      for (let i = 0; i < cols; i++) {
        firstRow[i] = Math.random() < 0.5 ? 1 : 0;
      }
    }
    grid.push(firstRow);
    
    ctx.fillStyle = bgColor;
    ctx.fillRect(0, 0, W, H);
    drawRow(0);
    
    if (typeof SFX !== 'undefined') SFX.play('click');
  }
  
  function getRule(left, center, right) {
    const index = (left << 2) | (center << 1) | right;
    return (rule >> index) & 1;
  }
  
  function nextGeneration() {
    if (currentRow >= rows - 1) {
      // Scroll up
      grid.shift();
      ctx.drawImage(canvas, 0, cellSize, W, H - cellSize, 0, 0, W, H - cellSize);
      ctx.fillStyle = bgColor;
      ctx.fillRect(0, H - cellSize, W, cellSize);
    } else {
      currentRow++;
    }
    
    const prevRow = grid[grid.length - 1];
    const newRow = new Array(cols).fill(0);
    
    for (let i = 0; i < cols; i++) {
      const left = prevRow[(i - 1 + cols) % cols];
      const center = prevRow[i];
      const right = prevRow[(i + 1) % cols];
      newRow[i] = getRule(left, center, right);
    }
    
    grid.push(newRow);
    if (grid.length > rows) grid.shift();
    
    drawRow(Math.min(currentRow, rows - 1));
    
    // Sound on every row (throttled)
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
    
    updateHUD();
  }
  
  function drawRow(rowIndex) {
    const row = grid[rowIndex] || grid[grid.length - 1];
    const y = rowIndex * cellSize;
    
    for (let x = 0; x < cols; x++) {
      if (row[x] === 1) {
        // Gradient based on position
        const t = x / cols;
        const r = Math.round(cellColor[0] * (1 - t) + cellColorAlt[0] * t);
        const g = Math.round(cellColor[1] * (1 - t) + cellColorAlt[1] * t);
        const b = Math.round(cellColor[2] * (1 - t) + cellColorAlt[2] * t);
        ctx.fillStyle = `rgb(${r},${g},${b})`;
        ctx.fillRect(x * cellSize, y, cellSize, cellSize);
      }
    }
  }
  
  function updateHUD() {
    document.querySelector('.rule-num').textContent = rule;
    document.getElementById('row-count').textContent = grid.length + currentRow;
  }
  
  function animate() {
    requestAnimationFrame(animate);
    
    if (paused) return;
    
    frameCount++;
    const interval = Math.max(1, 21 - speed);
    
    if (frameCount % interval === 0) {
      nextGeneration();
    }
  }
  
  // Events
  window.addEventListener('resize', resize);
  
  canvas.addEventListener('click', () => {
    paused = !paused;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  
  canvas.addEventListener('touchstart', (e) => {
    e.preventDefault();
    paused = !paused;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  
  document.getElementById('rule-slider').addEventListener('input', (e) => {
    rule = parseInt(e.target.value);
    updateHUD();
  });
  
  document.getElementById('rule-slider').addEventListener('change', () => {
    reset();
  });
  
  document.getElementById('speed-slider').addEventListener('input', (e) => {
    speed = parseInt(e.target.value);
  });
  
  document.getElementById('init-btn').addEventListener('click', () => {
    initMode = initMode === 'center' ? 'random' : 'center';
    document.getElementById('init-btn').textContent = 'INIT: ' + initMode.toUpperCase();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
    reset();
  });
  
  document.getElementById('reset-btn').addEventListener('click', () => {
    reset();
  });
  
  document.querySelectorAll('#preset-btns button').forEach(btn => {
    btn.addEventListener('click', () => {
      rule = parseInt(btn.dataset.rule);
      document.getElementById('rule-slider').value = rule;
      updateHUD();
      reset();
    });
  });
  
  // Init
  resize();
  animate();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
