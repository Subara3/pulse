<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('rule');
$pageName = 'rule';
$pageTitle = 'RULE - PULSE';
$pageDesc = 'ウルフラムの1Dセルオートマトン。たった8ビットのルールが、驚くほど複雑なパターンを生む';
$pageCanonical = 'https://pulse.subara3.com/rule.php';
$extraStyle = '
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
#hud {
  position: fixed; top: 1rem; right: 1rem;
  font-size: 0.85rem; text-align: right;
  color: rgba(255,255,255,0.7);
  pointer-events: none; z-index: 100;
  font-family: "SF Mono", Consolas, monospace;
}
#hud .big { font-size: 2rem; color: #00f5d4; }
#controls {
  position: fixed; bottom: 1rem; right: 1rem;
  display: flex; flex-direction: column; gap: 0.5rem;
  z-index: 100;
}
.control-row {
  display: flex; align-items: center; gap: 0.5rem;
  font-size: 0.75rem; color: rgba(255,255,255,0.6);
}
.control-row label { min-width: 3rem; text-align: right; }
.control-row input[type="range"] {
  width: 120px; accent-color: #00f5d4;
}
.control-row span { min-width: 2rem; text-align: left; }
.btn {
  background: rgba(0, 245, 212, 0.1);
  border: 1px solid rgba(0, 245, 212, 0.3);
  color: #00f5d4; padding: 0.4rem 0.8rem;
  cursor: pointer; font-size: 0.75rem;
  border-radius: 4px; transition: all 0.2s;
}
.btn:hover { background: rgba(0, 245, 212, 0.2); }
.btn.active { background: rgba(0, 245, 212, 0.3); border-color: #00f5d4; }
#presets {
  display: flex; flex-wrap: wrap; gap: 0.3rem;
  justify-content: flex-end;
}
#presets .btn { padding: 0.3rem 0.5rem; font-size: 0.65rem; }
#info {
  position: fixed; bottom: 1rem; left: 1rem;
  font-size: 0.7rem; color: rgba(255,255,255,0.5);
  z-index: 100; max-width: 200px;
}
#rule-bits {
  display: flex; gap: 2px; justify-content: flex-end;
  margin-top: 0.3rem;
}
.bit {
  width: 20px; height: 20px;
  background: rgba(255,255,255,0.1);
  border: 1px solid rgba(255,255,255,0.2);
  cursor: pointer; display: flex;
  align-items: center; justify-content: center;
  font-size: 0.6rem; color: rgba(255,255,255,0.5);
  transition: all 0.2s;
}
.bit.on {
  background: rgba(0, 245, 212, 0.5);
  border-color: #00f5d4;
  color: #fff;
}
';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>RULE</div>
  <div class="big" id="hud-rule">30</div>
  <div>GEN: <span id="hud-gen">0</span></div>
</div>
<div id="controls">
  <div class="control-row">
    <label>Rule</label>
    <input type="range" id="rule" min="0" max="255" value="30">
    <span id="rule-val">30</span>
  </div>
  <div id="rule-bits"></div>
  <div class="control-row">
    <label>Size</label>
    <input type="range" id="cell-size" min="1" max="8" value="3">
    <span id="size-val">3</span>
  </div>
  <div id="presets">
    <button class="btn" data-rule="30">30</button>
    <button class="btn" data-rule="90">90</button>
    <button class="btn" data-rule="110">110</button>
    <button class="btn" data-rule="184">184</button>
    <button class="btn" data-rule="45">45</button>
    <button class="btn" data-rule="73">73</button>
  </div>
  <div class="control-row" style="justify-content: flex-end; gap: 0.5rem; margin-top: 0.3rem;">
    <button class="btn" id="reset-btn">RESET</button>
    <button class="btn" id="random-btn">RANDOM</button>
    <button class="btn active" id="auto-btn">AUTO</button>
  </div>
</div>
<div id="info">
  Click to toggle cells in the first row.<br>
  Each rule defines 8 patterns → 256 possibilities.
</div>
<script>
(function() {
  const C = document.getElementById('canvas');
  const X = C.getContext('2d');
  let W, H;
  
  // State
  let rule = 30;
  let cellSize = 3;
  let cols, rows;
  let grid = [];
  let currentRow = 0;
  let autoMode = true;
  let speed = 16; // ms per generation
  let lastUpdate = 0;
  
  // Colors
  const BG = '#0a0a0f';
  const CELL_ON = '#00f5d4';
  const CELL_OFF = 'rgba(123, 44, 191, 0.1)';
  
  function resize() {
    const dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    initGrid();
  }
  
  function initGrid() {
    cols = Math.ceil(W / cellSize);
    rows = Math.ceil(H / cellSize);
    grid = [];
    for (let y = 0; y < rows; y++) {
      grid[y] = new Array(cols).fill(0);
    }
    // Start with single cell in center
    grid[0][Math.floor(cols / 2)] = 1;
    currentRow = 0;
  }
  
  function randomInit() {
    grid = [];
    for (let y = 0; y < rows; y++) {
      grid[y] = new Array(cols).fill(0);
    }
    // Random first row
    for (let x = 0; x < cols; x++) {
      grid[0][x] = Math.random() < 0.3 ? 1 : 0;
    }
    currentRow = 0;
    SFX.play('click');
  }
  
  function applyRule(left, center, right) {
    const index = (left << 2) | (center << 1) | right;
    return (rule >> index) & 1;
  }
  
  function nextGeneration() {
    if (currentRow >= rows - 1) {
      // Scroll up
      for (let y = 0; y < rows - 1; y++) {
        grid[y] = grid[y + 1].slice();
      }
      currentRow = rows - 2;
    }
    
    const prev = grid[currentRow];
    const next = grid[currentRow + 1];
    
    for (let x = 0; x < cols; x++) {
      const left = prev[(x - 1 + cols) % cols];
      const center = prev[x];
      const right = prev[(x + 1) % cols];
      next[x] = applyRule(left, center, right);
    }
    
    currentRow++;
  }
  
  function draw() {
    X.fillStyle = BG;
    X.fillRect(0, 0, W, H);
    
    for (let y = 0; y <= currentRow && y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        if (grid[y][x]) {
          // Gradient based on generation
          const t = y / rows;
          const hue = 175 + t * 60; // teal to blue-purple
          X.fillStyle = `hsl(${hue}, 80%, 55%)`;
        } else {
          X.fillStyle = CELL_OFF;
        }
        X.fillRect(x * cellSize, y * cellSize, cellSize - 0.5, cellSize - 0.5);
      }
    }
    
    document.getElementById('hud-gen').textContent = currentRow;
  }
  
  function updateRuleBits() {
    const container = document.getElementById('rule-bits');
    container.innerHTML = '';
    for (let i = 7; i >= 0; i--) {
      const bit = document.createElement('div');
      bit.className = 'bit' + ((rule >> i) & 1 ? ' on' : '');
      bit.textContent = i;
      bit.dataset.bit = i;
      bit.addEventListener('click', function() {
        rule ^= (1 << i);
        document.getElementById('rule').value = rule;
        document.getElementById('rule-val').textContent = rule;
        document.getElementById('hud-rule').textContent = rule;
        updateRuleBits();
        initGrid();
        SFX.play('toggle');
      });
      container.appendChild(bit);
    }
  }
  
  // Animation
  function animate(time) {
    if (autoMode && time - lastUpdate > speed) {
      nextGeneration();
      lastUpdate = time;
    }
    draw();
    requestAnimationFrame(animate);
  }
  
  // Controls
  document.getElementById('rule').addEventListener('input', function() {
    rule = parseInt(this.value);
    document.getElementById('rule-val').textContent = rule;
    document.getElementById('hud-rule').textContent = rule;
    updateRuleBits();
    initGrid();
    SFX.play('tick', 30);
  });
  
  document.getElementById('cell-size').addEventListener('input', function() {
    cellSize = parseInt(this.value);
    document.getElementById('size-val').textContent = cellSize;
    initGrid();
    SFX.play('tick', 30);
  });
  
  document.getElementById('reset-btn').addEventListener('click', function() {
    initGrid();
    SFX.play('click');
  });
  
  document.getElementById('random-btn').addEventListener('click', randomInit);
  
  document.getElementById('auto-btn').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  
  // Presets
  document.querySelectorAll('#presets .btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      rule = parseInt(this.dataset.rule);
      document.getElementById('rule').value = rule;
      document.getElementById('rule-val').textContent = rule;
      document.getElementById('hud-rule').textContent = rule;
      updateRuleBits();
      initGrid();
      SFX.play('toggle');
    });
  });
  
  // Click to toggle cells in first row
  C.addEventListener('click', function(e) {
    const rect = C.getBoundingClientRect();
    const x = Math.floor((e.clientX - rect.left) / cellSize);
    const y = Math.floor((e.clientY - rect.top) / cellSize);
    
    if (y === 0 && x >= 0 && x < cols) {
      grid[0][x] = 1 - grid[0][x];
      // Recalculate from row 1
      currentRow = 0;
      for (let row = 1; row < rows; row++) {
        grid[row].fill(0);
      }
      SFX.play('tick');
    }
  });
  
  // Touch support
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    const touch = e.touches[0];
    const rect = C.getBoundingClientRect();
    const x = Math.floor((touch.clientX - rect.left) / cellSize);
    const y = Math.floor((touch.clientY - rect.top) / cellSize);
    
    if (y === 0 && x >= 0 && x < cols) {
      grid[0][x] = 1 - grid[0][x];
      currentRow = 0;
      for (let row = 1; row < rows; row++) {
        grid[row].fill(0);
      }
      SFX.play('tick');
    }
  }, { passive: false });
  
  // Init
  window.addEventListener('resize', resize);
  resize();
  updateRuleBits();
  requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
