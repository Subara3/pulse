<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('percolation');

$pageName = 'percolation';
$pageTitle = 'PERCOLATION - PULSE';
$pageDesc = 'パーコレーション。確率的にセルを埋め、連結クラスタを可視化。臨界確率p≈0.593で相転移が起こる。';
$pageCanonical = 'https://pulse.subara3.com/percolation.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.hud .percolates { color: #00f5d4; }
.hud .no-percolates { color: #7b2cbf; }
.info { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.2); font-size: 0.6rem; letter-spacing: 0.05em; line-height: 1.8; }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.5rem; right: 0.5rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.55rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">
  p ≈ 0.593: CRITICAL THRESHOLD<br>
  DRAG SLIDER TO CHANGE PROBABILITY
</div>

<div class="ui hud" id="hud">
  PROBABILITY: <span id="hudProb" class="highlight">0.50</span><br>
  FILLED: <span id="hudFilled">0</span> / <span id="hudTotal">0</span><br>
  CLUSTERS: <span id="hudClusters">0</span><br>
  PERCOLATES: <span id="hudPerc" class="no-percolates">NO</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label>p</label>
    <input type="range" id="probSlider" min="0" max="100" value="50">
    <label id="probLabel">0.50</label>
  </div>
  <div class="slider-row">
    <label>SPD</label>
    <input type="range" id="speedSlider" min="1" max="20" value="1">
    <label id="speedLabel">1</label>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="autoBtn">AUTO</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  const hudProb = document.getElementById('hudProb');
  const hudFilled = document.getElementById('hudFilled');
  const hudTotal = document.getElementById('hudTotal');
  const hudClusters = document.getElementById('hudClusters');
  const hudPerc = document.getElementById('hudPerc');
  const probSlider = document.getElementById('probSlider');
  const probLabel = document.getElementById('probLabel');
  const speedSlider = document.getElementById('speedSlider');
  const speedLabel = document.getElementById('speedLabel');
  const autoBtn = document.getElementById('autoBtn');
  const resetBtn = document.getElementById('resetBtn');

  const isMobile = window.innerWidth <= 768;
  const CELL_SIZE = isMobile ? 6 : 4;
  const CRITICAL_P = 0.593;

  let W, H, cols, rows;
  let grid, clusters, clusterColors;
  let probability = 0.5;
  let autoMode = true;
  let autoDir = 1;
  let autoSpeed = 0.00001;
  let percolates = false;
  let prevPercolates = false;
  let prevCriticalSide = 0; // -1: below, 1: above
  let hideUI = false;
  let needsCompute = true;
  let frameCount = 0;
  let computeInterval = 60; // フレームごとに再計算（60 ≈ 1秒）

  // --- Color generation (teal-purple palette) ---
  function hsl2rgb(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  const PHI = 0.618033988749895;

  function generateColor(index, isPercolating) {
    // Percolating cluster gets bright accent teal
    if (isPercolating) {
      return [0, 245, 212];
    }
    // Other clusters: varied hues in teal-purple range
    const t = (index * PHI) % 1;
    let h;
    if (t < 0.6) {
      // Teal region: hue 165-205
      h = 165 + ((index * 37) % 40);
    } else {
      // Purple region: hue 255-295
      h = 255 + ((index * 29) % 40);
    }
    const s = 35 + (index * 17) % 30;
    const l = 25 + (index * 13) % 25;
    return hsl2rgb(h, s, l);
  }

  // --- Grid generation ---
  function generateGrid() {
    grid = new Uint8Array(cols * rows);
    let filled = 0;
    for (let i = 0; i < grid.length; i++) {
      if (Math.random() < probability) {
        grid[i] = 1;
        filled++;
      }
    }
    return filled;
  }

  // --- Union-Find for connected components ---
  function findClusters() {
    const parent = new Int32Array(cols * rows);
    const rank = new Uint8Array(cols * rows);
    for (let i = 0; i < parent.length; i++) parent[i] = i;

    function find(x) {
      if (parent[x] !== x) parent[x] = find(parent[x]);
      return parent[x];
    }

    function union(a, b) {
      const ra = find(a), rb = find(b);
      if (ra === rb) return;
      if (rank[ra] < rank[rb]) parent[ra] = rb;
      else if (rank[ra] > rank[rb]) parent[rb] = ra;
      else { parent[rb] = ra; rank[ra]++; }
    }

    // Connect filled adjacent cells
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const i = y * cols + x;
        if (!grid[i]) continue;
        // Right neighbor
        if (x < cols - 1 && grid[i + 1]) union(i, i + 1);
        // Down neighbor
        if (y < rows - 1 && grid[i + cols]) union(i, i + cols);
      }
    }

    // Assign cluster IDs
    clusters = new Int32Array(cols * rows);
    const rootToId = new Map();
    let nextId = 1;
    const topRoots = new Set();
    const bottomRoots = new Set();

    for (let i = 0; i < grid.length; i++) {
      if (!grid[i]) {
        clusters[i] = 0;
        continue;
      }
      const root = find(i);
      if (!rootToId.has(root)) {
        rootToId.set(root, nextId++);
      }
      clusters[i] = rootToId.get(root);

      // Track top and bottom row roots
      const y = Math.floor(i / cols);
      if (y === 0) topRoots.add(root);
      if (y === rows - 1) bottomRoots.add(root);
    }

    // Check for percolation (top-bottom connection)
    let percolatingCluster = -1;
    for (const tr of topRoots) {
      if (bottomRoots.has(tr)) {
        percolatingCluster = rootToId.get(tr);
        break;
      }
    }

    percolates = percolatingCluster > 0;

    // Generate colors
    clusterColors = [null]; // Index 0 = empty
    for (let id = 1; id < nextId; id++) {
      clusterColors.push(generateColor(id, id === percolatingCluster));
    }

    return nextId - 1;
  }

  // --- Rendering ---
  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    const offsetX = Math.floor((W - cols * CELL_SIZE) / 2);
    const offsetY = Math.floor((H - rows * CELL_SIZE) / 2);

    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const i = y * cols + x;
        const cid = clusters[i];
        if (cid === 0) continue;

        const col = clusterColors[cid];
        ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
        ctx.fillRect(
          offsetX + x * CELL_SIZE,
          offsetY + y * CELL_SIZE,
          CELL_SIZE - 1,
          CELL_SIZE - 1
        );
      }
    }

    // Draw grid boundary
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 1;
    ctx.strokeRect(offsetX - 0.5, offsetY - 0.5, cols * CELL_SIZE + 1, rows * CELL_SIZE + 1);
  }

  // --- Update HUD ---
  function updateHud(filled, clusterCount) {
    hudProb.textContent = probability.toFixed(2);
    probLabel.textContent = probability.toFixed(2);
    hudFilled.textContent = filled;
    hudTotal.textContent = cols * rows;
    hudClusters.textContent = clusterCount;
    
    if (percolates) {
      hudPerc.textContent = 'YES';
      hudPerc.className = 'percolates';
    } else {
      hudPerc.textContent = 'NO';
      hudPerc.className = 'no-percolates';
    }
  }

  // --- Compute and render ---
  function compute() {
    const filled = generateGrid();
    const clusterCount = findClusters();

    // 状態変化時のサウンド
    if (percolates !== prevPercolates) {
      SFX.play(percolates ? 'spawn' : 'toggle', 45);
      prevPercolates = percolates;
    }

    const side = probability >= CRITICAL_P ? 1 : -1;
    if (prevCriticalSide !== 0 && side !== prevCriticalSide) {
      SFX.play('tick', 40);
    }
    prevCriticalSide = side;

    render();
    updateHud(filled, clusterCount);
    needsCompute = false;
  }

  // --- Auto mode ---
  function updateAuto() {
    if (!autoMode) return;
    
    frameCount++;
    
    // 一定間隔でのみ確率を更新してグリッドを再生成
    if (frameCount >= computeInterval) {
      frameCount = 0;
      
      probability += autoDir * autoSpeed;
      if (probability >= 1) {
        probability = 1;
        autoDir = -1;
      } else if (probability <= 0) {
        probability = 0;
        autoDir = 1;
      }
      
      probSlider.value = Math.round(probability * 100);
      needsCompute = true;
    }
  }

  // --- Main loop ---
  function loop() {
    updateAuto();
    if (needsCompute) compute();
    requestAnimationFrame(loop);
  }

  // --- Events ---
  probSlider.addEventListener('input', function() {
    probability = this.value / 100;
    needsCompute = true;
    SFX.play('tick', 30);
  });

  speedSlider.addEventListener('input', function() {
    // 速度スライダー: 1（遅い）〜100（速い）
    // 値が大きいほど間隔が短い（速い）
    computeInterval = Math.max(1, Math.round(600 / this.value));
    autoSpeed = 0.005 + (this.value / 100) * 0.02;
    speedLabel.textContent = this.value;
    SFX.play('tick', 30);
  });

  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    needsCompute = true;
    SFX.play('click');
  });

  // Keyboard
  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      needsCompute = true;
      SFX.play('click');
    }
    if (e.key === 'a' || e.key === 'A') {
      autoMode = !autoMode;
      autoBtn.classList.toggle('active', autoMode);
      SFX.play('toggle');
    }
  });

  // Touch: tap anywhere to regenerate
  canvas.addEventListener('click', function() {
    needsCompute = true;
    SFX.play('spawn');
  });

  // Resize
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    
    cols = Math.floor((W * 0.9) / CELL_SIZE);
    rows = Math.floor((H * 0.85) / CELL_SIZE);
    
    needsCompute = true;
  }

  window.addEventListener('resize', resize);

  // UI hide style
  const style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // --- Start ---
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
