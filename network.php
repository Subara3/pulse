<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

// アクセス記録
recordPageView('network');

$pageName = 'network';
$pageTitle = 'NETWORK - PULSE';
$pageDesc = 'Erdős–Rényi ランダムグラフ。接続確率を上げると、突然すべてがひとつになる。';
$pageCanonical = 'https://pulse.subara3.com/network.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.title { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.15); font-size: 0.7rem; letter-spacing: 0.2em; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.hud .critical { color: #7b2cbf; }
.phase-indicator { top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; opacity: 0; transition: opacity 1s; pointer-events: none; }
.phase-indicator.visible { opacity: 1; }
.phase-indicator .phase-text { font-size: 1.2rem; font-weight: 700; letter-spacing: 0.3em; color: #00f5d4; text-shadow: 0 0 30px rgba(0, 245, 212, 0.5); }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.5rem; right: 0.5rem; }
  .title { bottom: 1rem; left: 1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui title">NETWORK</div>

<div class="ui hud" id="hud">
  NODES: <span id="hudNodes">0</span><br>
  EDGES: <span id="hudEdges">0</span><br>
  LARGEST: <span id="hudLargest" class="highlight">0</span><br>
  p: <span id="hudP">0.000</span>
  <span id="hudCritical" class="critical"></span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label for="probSlider" id="probLabel">p=0.000</label>
    <input type="range" id="probSlider" min="0" max="1000" value="0">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<div class="ui phase-indicator" id="phaseIndicator">
  <div class="phase-text">CONNECTED</div>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  // UI elements
  const hudNodes = document.getElementById('hudNodes');
  const hudEdges = document.getElementById('hudEdges');
  const hudLargest = document.getElementById('hudLargest');
  const hudP = document.getElementById('hudP');
  const hudCritical = document.getElementById('hudCritical');
  const probSlider = document.getElementById('probSlider');
  const probLabel = document.getElementById('probLabel');
  const autoBtn = document.getElementById('autoBtn');
  const resetBtn = document.getElementById('resetBtn');
  const phaseIndicator = document.getElementById('phaseIndicator');

  // Constants
  const isMobile = window.innerWidth <= 768;
  const N = isMobile ? 40 : 80;
  const ACCENT = [0, 245, 212];
  const PURPLE = [123, 44, 191];
  const DIM = [60, 60, 80];
  const EDGE_DIM = [40, 40, 55];

  let width, height;
  let nodes = [];
  let edges = [];
  let components = [];
  let largestId = -1;
  let largestSize = 0;
  let prob = 0;
  let autoMode = false;
  let phaseTriggered = false;
  let prevLargestRatio = 0;

  // --- Union-Find ---
  let parent, rank;

  function ufInit(n) {
    parent = new Int32Array(n);
    rank = new Int32Array(n);
    for (let i = 0; i < n; i++) parent[i] = i;
  }

  function ufFind(x) {
    while (parent[x] !== x) {
      parent[x] = parent[parent[x]];
      x = parent[x];
    }
    return x;
  }

  function ufUnion(a, b) {
    a = ufFind(a);
    b = ufFind(b);
    if (a === b) return;
    if (rank[a] < rank[b]) { const t = a; a = b; b = t; }
    parent[b] = a;
    if (rank[a] === rank[b]) rank[a]++;
  }

  // --- Init ---
  function initNodes() {
    nodes = [];
    const cx = width / 2;
    const cy = height / 2;
    const spread = Math.min(width, height) * 0.35;

    for (let i = 0; i < N; i++) {
      const angle = (i / N) * Math.PI * 2 + Math.random() * 0.5;
      const r = spread * (0.3 + Math.random() * 0.7);
      nodes.push({
        x: cx + Math.cos(angle) * r,
        y: cy + Math.sin(angle) * r,
        vx: 0,
        vy: 0,
        radius: 3 + Math.random() * 2
      });
    }
  }

  function buildGraph(p) {
    edges = [];
    ufInit(N);

    // Deterministic seeded approach: for each pair, use a fixed random threshold
    for (let i = 0; i < N; i++) {
      for (let j = i + 1; j < N; j++) {
        if (edgeSeeds[i * N + j] < p) {
          edges.push([i, j]);
          ufUnion(i, j);
        }
      }
    }

    // Find components
    const sizes = new Map();
    for (let i = 0; i < N; i++) {
      const root = ufFind(i);
      sizes.set(root, (sizes.get(root) || 0) + 1);
    }

    largestId = -1;
    largestSize = 0;
    for (const [root, size] of sizes) {
      if (size > largestSize) {
        largestSize = size;
        largestId = root;
      }
    }

    // Phase transition detection
    const ratio = largestSize / N;
    if (!phaseTriggered && ratio > 0.5 && prevLargestRatio <= 0.5) {
      phaseTriggered = true;
      phaseIndicator.classList.add('visible');
      setTimeout(() => phaseIndicator.classList.remove('visible'), 3000);
    }
    prevLargestRatio = ratio;
  }

  // Pre-generate edge seeds (so slider movement is smooth, not random each time)
  let edgeSeeds;

  function generateSeeds() {
    edgeSeeds = new Float32Array(N * N);
    for (let i = 0; i < N; i++) {
      for (let j = i + 1; j < N; j++) {
        edgeSeeds[i * N + j] = Math.random();
      }
    }
  }

  // --- Force-directed layout ---
  function simulate() {
    const repulsion = 800;
    const attraction = 0.005;
    const damping = 0.85;
    const restLength = 80;
    const centerPull = 0.001;
    const cx = width / 2;
    const cy = height / 2;

    // Repulsion between all nodes
    for (let i = 0; i < N; i++) {
      for (let j = i + 1; j < N; j++) {
        let dx = nodes[j].x - nodes[i].x;
        let dy = nodes[j].y - nodes[i].y;
        let dist = Math.sqrt(dx * dx + dy * dy) || 1;
        let force = repulsion / (dist * dist);
        let fx = (dx / dist) * force;
        let fy = (dy / dist) * force;
        nodes[i].vx -= fx;
        nodes[i].vy -= fy;
        nodes[j].vx += fx;
        nodes[j].vy += fy;
      }
    }

    // Attraction along edges
    for (const [a, b] of edges) {
      let dx = nodes[b].x - nodes[a].x;
      let dy = nodes[b].y - nodes[a].y;
      let dist = Math.sqrt(dx * dx + dy * dy) || 1;
      let force = (dist - restLength) * attraction;
      let fx = (dx / dist) * force;
      let fy = (dy / dist) * force;
      nodes[a].vx += fx;
      nodes[a].vy += fy;
      nodes[b].vx -= fx;
      nodes[b].vy -= fy;
    }

    // Center gravity
    for (let i = 0; i < N; i++) {
      nodes[i].vx += (cx - nodes[i].x) * centerPull;
      nodes[i].vy += (cy - nodes[i].y) * centerPull;
    }

    // Update positions
    for (let i = 0; i < N; i++) {
      nodes[i].vx *= damping;
      nodes[i].vy *= damping;
      nodes[i].x += nodes[i].vx;
      nodes[i].y += nodes[i].vy;

      // Keep in bounds
      const margin = 30;
      if (nodes[i].x < margin) { nodes[i].x = margin; nodes[i].vx *= -0.5; }
      if (nodes[i].x > width - margin) { nodes[i].x = width - margin; nodes[i].vx *= -0.5; }
      if (nodes[i].y < margin) { nodes[i].y = margin; nodes[i].vy *= -0.5; }
      if (nodes[i].y > height - margin) { nodes[i].y = height - margin; nodes[i].vy *= -0.5; }
    }
  }

  // --- Rendering ---
  function isInLargest(i) {
    return ufFind(i) === largestId && largestSize > 1;
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    const critical = 1 / N;
    const ratio = largestSize / N;

    // Draw edges
    for (const [a, b] of edges) {
      const inLargest = isInLargest(a) && isInLargest(b);
      if (inLargest) {
        const alpha = 0.15 + ratio * 0.4;
        ctx.strokeStyle = `rgba(${ACCENT[0]},${ACCENT[1]},${ACCENT[2]},${alpha})`;
        ctx.lineWidth = 1.2;
      } else {
        ctx.strokeStyle = `rgba(${EDGE_DIM[0]},${EDGE_DIM[1]},${EDGE_DIM[2]},0.3)`;
        ctx.lineWidth = 0.6;
      }
      ctx.beginPath();
      ctx.moveTo(nodes[a].x, nodes[a].y);
      ctx.lineTo(nodes[b].x, nodes[b].y);
      ctx.stroke();
    }

    // Draw nodes
    for (let i = 0; i < N; i++) {
      const inL = isInLargest(i);
      const r = nodes[i].radius;

      if (inL) {
        // Glow
        const glow = ctx.createRadialGradient(
          nodes[i].x, nodes[i].y, 0,
          nodes[i].x, nodes[i].y, r * 4
        );
        glow.addColorStop(0, `rgba(${ACCENT[0]},${ACCENT[1]},${ACCENT[2]},0.15)`);
        glow.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = glow;
        ctx.beginPath();
        ctx.arc(nodes[i].x, nodes[i].y, r * 4, 0, Math.PI * 2);
        ctx.fill();

        // Node
        ctx.fillStyle = `rgb(${ACCENT[0]},${ACCENT[1]},${ACCENT[2]})`;
      } else {
        ctx.fillStyle = `rgb(${DIM[0]},${DIM[1]},${DIM[2]})`;
      }

      ctx.beginPath();
      ctx.arc(nodes[i].x, nodes[i].y, r, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function updateHud() {
    const critical = 1 / N;
    hudNodes.textContent = N;
    hudEdges.textContent = edges.length;
    hudLargest.textContent = largestSize + ' / ' + N + ' (' + Math.round(largestSize / N * 100) + '%)';
    hudP.textContent = prob.toFixed(3);

    if (prob >= critical * 0.8 && prob <= critical * 1.5) {
      hudCritical.textContent = ' CRITICAL';
    } else {
      hudCritical.textContent = '';
    }
  }

  // --- Main loop ---
  function loop() {
    if (autoMode) {
      prob += 0.0003;
      if (prob > 0.15) prob = 0.15; // Cap at meaningful range
      probSlider.value = Math.round(prob * 1000);
      probLabel.textContent = 'p=' + prob.toFixed(3);
      buildGraph(prob);
    }

    simulate();
    draw();
    updateHud();
    requestAnimationFrame(loop);
  }

  // --- Events ---
  probSlider.addEventListener('input', function() {
    prob = this.value / 1000;
    probLabel.textContent = 'p=' + prob.toFixed(3);
    buildGraph(prob);
  });

  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
  });

  resetBtn.addEventListener('click', function() {
    autoMode = false;
    autoBtn.classList.remove('active');
    prob = 0;
    probSlider.value = 0;
    probLabel.textContent = 'p=0.000';
    phaseTriggered = false;
    prevLargestRatio = 0;
    generateSeeds();
    initNodes();
    buildGraph(0);
  });

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
  }

  window.addEventListener('resize', function() {
    resize();
    // Keep existing nodes but re-center
    const cx = width / 2;
    const cy = height / 2;
    for (let i = 0; i < N; i++) {
      nodes[i].vx += (cx - nodes[i].x) * 0.01;
      nodes[i].vy += (cy - nodes[i].y) * 0.01;
    }
  });

  // --- Start ---
  resize();
  generateSeeds();
  initNodes();
  buildGraph(0);
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
