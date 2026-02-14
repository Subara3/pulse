<?php
require_once __DIR__ . '/includes/config.php';

// アクセス記録
recordPageView('voronoi');

$pageName = 'voronoi';
$pageTitle = 'VORONOI - PULSE';
$pageDesc = 'ボロノイ図。最も近い点が空間を分割する。クリックで点を追加。';
$pageCanonical = 'https://pulse.subara3.com/voronoi.php';

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
}

.ui {
  position: fixed;
  z-index: 100;
  font-family: "Orbitron", sans-serif;
  pointer-events: none;
}

.ui * {
  pointer-events: auto;
}

.title {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

.hud {
  top: 1.5rem;
  right: 1.5rem;
  color: rgba(255,255,255,0.35);
  font-size: 0.65rem;
  text-align: right;
  letter-spacing: 0.05em;
  line-height: 1.8;
}

.hud .highlight {
  color: #00f5d4;
  font-weight: 700;
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  align-items: flex-end;
}

.btn-row {
  display: flex;
  gap: 0.5rem;
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

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.2);
  font-size: 0.6rem;
  letter-spacing: 0.05em;
  line-height: 1.8;
}

@media (max-width: 600px) {
  .hud {
    font-size: 0.6rem;
    top: 1rem;
    right: 1rem;
  }
  .controls {
    bottom: 1rem;
    right: 1rem;
  }
  .info {
    bottom: 1rem;
    left: 1rem;
    font-size: 0.55rem;
  }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  CLICK TO ADD / DRAG TO MOVE<br>
  DOUBLE-CLICK TO REMOVE
</div>

<div class="ui hud" id="hud">
  SEEDS: <span id="hudSeeds" class="highlight">0</span><br>
  MODE: <span id="hudMode">CELL</span>
</div>

<div class="ui controls">
  <div class="btn-row">
    <button class="control-btn" id="modeBtn">MODE</button>
    <button class="control-btn" id="autoBtn">AUTO</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="colorBtn">COLOR</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudSeeds = document.getElementById('hudSeeds');
  const hudMode = document.getElementById('hudMode');
  const modeBtn = document.getElementById('modeBtn');
  const autoBtn = document.getElementById('autoBtn');
  const colorBtn = document.getElementById('colorBtn');
  const resetBtn = document.getElementById('resetBtn');
  const infoPanel = document.getElementById('infoPanel');

  const MODES = ['CELL', 'DISTANCE', 'DUAL'];
  const ACCENT = [0, 245, 212];
  const PURPLE = [123, 44, 191];
  const RES = 3;
  const INIT_COUNT = 20;
  const MAX_SEEDS = 80;
  const DRAG_RADIUS = 20;

  let W, H, gridW, gridH;
  let grid, imgData;
  let seeds = [];
  let mode = 0;
  let paletteIdx = 0;
  let autoMode = false;
  let hideUI = false;
  let paused = false;
  let dragging = -1;
  let needsCompute = true;
  let delaunayEdges = [];

  // --- Palettes ---
  function hsl2rgb(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  function makePalette(count, sat, light) {
    const pal = [];
    for (let i = 0; i < count; i++) {
      pal.push(hsl2rgb((i * 360 / count + paletteIdx * 90) % 360, sat, light));
    }
    return pal;
  }

  const PALETTES = [
    () => makePalette(MAX_SEEDS, 65, 22),
    () => makePalette(MAX_SEEDS, 45, 32),
    () => makePalette(MAX_SEEDS, 80, 15),
    () => { // mono
      const p = [];
      for (let i = 0; i < MAX_SEEDS; i++) {
        const v = 12 + (i * 7) % 20;
        p.push([v, v, v + 8]);
      }
      return p;
    }
  ];

  let palette = PALETTES[0]();

  // --- Seed management ---
  function createSeed(x, y) {
    return {
      x, y,
      vx: (Math.random() - 0.5) * 0.8,
      vy: (Math.random() - 0.5) * 0.8
    };
  }

  function initSeeds() {
    seeds = [];
    const margin = 60;
    for (let i = 0; i < INIT_COUNT; i++) {
      seeds.push(createSeed(
        margin + Math.random() * (W - margin * 2),
        margin + Math.random() * (H - margin * 2)
      ));
    }
    needsCompute = true;
  }

  function findNearest(mx, my) {
    let best = -1, bestD = DRAG_RADIUS * DRAG_RADIUS;
    for (let i = 0; i < seeds.length; i++) {
      const dx = mx - seeds[i].x, dy = my - seeds[i].y;
      const d = dx * dx + dy * dy;
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  // --- Voronoi computation ---
  function computeVoronoi() {
    gridW = Math.ceil(W / RES);
    gridH = Math.ceil(H / RES);
    if (!grid || grid.length < gridW * gridH) {
      grid = new Int16Array(gridW * gridH);
    }

    const n = seeds.length;
    if (n === 0) return;

    for (let gy = 0; gy < gridH; gy++) {
      const py = gy * RES + RES * 0.5;
      for (let gx = 0; gx < gridW; gx++) {
        const px = gx * RES + RES * 0.5;
        let minD = Infinity, nearest = 0;
        for (let i = 0; i < n; i++) {
          const dx = px - seeds[i].x, dy = py - seeds[i].y;
          const d = dx * dx + dy * dy;
          if (d < minD) { minD = d; nearest = i; }
        }
        grid[gy * gridW + gx] = nearest;
      }
    }

    // Extract Delaunay edges from adjacency
    if (MODES[mode] === 'DUAL') {
      const edgeSet = new Set();
      for (let gy = 0; gy < gridH; gy++) {
        for (let gx = 0; gx < gridW; gx++) {
          const a = grid[gy * gridW + gx];
          if (gx < gridW - 1) {
            const b = grid[gy * gridW + gx + 1];
            if (a !== b) {
              const key = a < b ? a * MAX_SEEDS + b : b * MAX_SEEDS + a;
              edgeSet.add(key);
            }
          }
          if (gy < gridH - 1) {
            const c = grid[(gy + 1) * gridW + gx];
            if (a !== c) {
              const key = a < c ? a * MAX_SEEDS + c : c * MAX_SEEDS + a;
              edgeSet.add(key);
            }
          }
        }
      }
      delaunayEdges = [];
      for (const key of edgeSet) {
        delaunayEdges.push([Math.floor(key / MAX_SEEDS), key % MAX_SEEDS]);
      }
    }

    needsCompute = false;
  }

  // --- Rendering ---
  function render() {
    const n = seeds.length;
    if (n === 0) {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, W, H);
      return;
    }

    if (!imgData || imgData.width !== W) {
      imgData = ctx.createImageData(W, H);
    }
    const data = imgData.data;
    const modeName = MODES[mode];

    // Fill pixels from grid
    for (let gy = 0; gy < gridH; gy++) {
      for (let gx = 0; gx < gridW; gx++) {
        const si = grid[gy * gridW + gx];
        const col = palette[si % palette.length];

        // Edge detection
        let isEdge = false;
        if (gx > 0 && grid[gy * gridW + gx - 1] !== si) isEdge = true;
        else if (gx < gridW - 1 && grid[gy * gridW + gx + 1] !== si) isEdge = true;
        else if (gy > 0 && grid[(gy - 1) * gridW + gx] !== si) isEdge = true;
        else if (gy < gridH - 1 && grid[(gy + 1) * gridW + gx] !== si) isEdge = true;

        // Distance factor for DISTANCE mode
        let distFactor = 1;
        if (modeName === 'DISTANCE') {
          const px = gx * RES + RES * 0.5;
          const py = gy * RES + RES * 0.5;
          const dx = px - seeds[si].x, dy = py - seeds[si].y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          distFactor = Math.max(0.08, 1 - dist / (Math.min(W, H) * 0.35));
        }

        let r, g, b;
        if (isEdge) {
          r = ACCENT[0]; g = ACCENT[1]; b = ACCENT[2];
        } else {
          r = col[0] * distFactor;
          g = col[1] * distFactor;
          b = col[2] * distFactor;
        }

        // Fill RES x RES block
        const startX = gx * RES;
        const startY = gy * RES;
        const endX = Math.min(startX + RES, W);
        const endY = Math.min(startY + RES, H);
        for (let py = startY; py < endY; py++) {
          for (let px = startX; px < endX; px++) {
            const idx = (py * W + px) << 2;
            data[idx] = r;
            data[idx + 1] = g;
            data[idx + 2] = b;
            data[idx + 3] = 255;
          }
        }
      }
    }

    ctx.putImageData(imgData, 0, 0);

    // Delaunay overlay
    if (modeName === 'DUAL') {
      ctx.strokeStyle = 'rgba(' + PURPLE[0] + ',' + PURPLE[1] + ',' + PURPLE[2] + ',0.5)';
      ctx.lineWidth = 1;
      for (const [a, b] of delaunayEdges) {
        ctx.beginPath();
        ctx.moveTo(seeds[a].x, seeds[a].y);
        ctx.lineTo(seeds[b].x, seeds[b].y);
        ctx.stroke();
      }
    }

    // Draw seed points
    for (let i = 0; i < n; i++) {
      ctx.fillStyle = '#fff';
      ctx.beginPath();
      ctx.arc(seeds[i].x, seeds[i].y, 2.5, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = 'rgba(' + ACCENT[0] + ',' + ACCENT[1] + ',' + ACCENT[2] + ',0.15)';
      ctx.beginPath();
      ctx.arc(seeds[i].x, seeds[i].y, 8, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  // --- Auto movement ---
  function updateAuto() {
    if (!autoMode || paused) return;
    for (let i = 0; i < seeds.length; i++) {
      seeds[i].x += seeds[i].vx;
      seeds[i].y += seeds[i].vy;
      if (seeds[i].x < 0 || seeds[i].x > W) seeds[i].vx *= -1;
      if (seeds[i].y < 0 || seeds[i].y > H) seeds[i].vy *= -1;
      seeds[i].x = Math.max(0, Math.min(W, seeds[i].x));
      seeds[i].y = Math.max(0, Math.min(H, seeds[i].y));
    }
    needsCompute = true;
  }

  // --- HUD ---
  function updateHud() {
    hudSeeds.textContent = seeds.length;
    hudMode.textContent = MODES[mode];
  }

  // --- Main loop ---
  function loop() {
    updateAuto();
    if (needsCompute) computeVoronoi();
    render();
    updateHud();
    requestAnimationFrame(loop);
  }

  // --- Mouse events ---
  let clickTimer = null;
  let pendingClick = null;

  function getPos(e) {
    const r = canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) * (W / r.width),
      (e.clientY - r.top) * (H / r.height)
    ];
  }

  canvas.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    const [mx, my] = getPos(e);
    const hit = findNearest(mx, my);
    if (hit >= 0) {
      dragging = hit;
    }
  });

  canvas.addEventListener('mousemove', function(e) {
    if (dragging >= 0) {
      const [mx, my] = getPos(e);
      seeds[dragging].x = mx;
      seeds[dragging].y = my;
      needsCompute = true;
    }
  });

  canvas.addEventListener('mouseup', function(e) {
    if (dragging >= 0) {
      dragging = -1;
      return;
    }
  });

  canvas.addEventListener('click', function(e) {
    if (dragging >= 0) return;
    const [mx, my] = getPos(e);

    if (clickTimer) {
      clearTimeout(clickTimer);
      clickTimer = null;
      // Double click - remove
      const hit = findNearest(mx, my);
      if (hit >= 0) {
        seeds.splice(hit, 1);
        palette = PALETTES[paletteIdx % PALETTES.length]();
        needsCompute = true;
        SFX.play('pop');
      }
      return;
    }

    pendingClick = [mx, my];
    clickTimer = setTimeout(function() {
      clickTimer = null;
      // Single click - add
      if (seeds.length < MAX_SEEDS) {
        seeds.push(createSeed(pendingClick[0], pendingClick[1]));
        seeds[seeds.length - 1].vx = (Math.random() - 0.5) * 0.8;
        seeds[seeds.length - 1].vy = (Math.random() - 0.5) * 0.8;
        needsCompute = true;
        SFX.play('spawn');
      }
    }, 250);
  });

  // Touch support
  let touchId = null;
  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (touchId !== null) return;
    const t = e.changedTouches[0];
    touchId = t.identifier;
    const [mx, my] = getPos(t);
    const hit = findNearest(mx, my);
    if (hit >= 0) {
      dragging = hit;
    } else if (seeds.length < MAX_SEEDS) {
      seeds.push(createSeed(mx, my));
      needsCompute = true;
      SFX.play('spawn');
    }
  }, {passive: false});

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (const t of e.changedTouches) {
      if (t.identifier === touchId && dragging >= 0) {
        const [mx, my] = getPos(t);
        seeds[dragging].x = mx;
        seeds[dragging].y = my;
        needsCompute = true;
      }
    }
  }, {passive: false});

  canvas.addEventListener('touchend', function(e) {
    for (const t of e.changedTouches) {
      if (t.identifier === touchId) {
        touchId = null;
        dragging = -1;
      }
    }
  });

  // Keyboard
  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      paused = !paused;
    }
  });

  // Buttons
  modeBtn.addEventListener('click', function() {
    mode = (mode + 1) % MODES.length;
    needsCompute = true;
    SFX.play('toggle');
  });

  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    palette = PALETTES[paletteIdx]();
    needsCompute = true;
    SFX.play('click');
  });

  resetBtn.addEventListener('click', function() {
    initSeeds();
    palette = PALETTES[paletteIdx % PALETTES.length]();
    SFX.play('click');
  });

  // Resize
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    imgData = null;
    needsCompute = true;
  }

  window.addEventListener('resize', resize);

  // UI hide style
  const style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // --- Start ---
  resize();
  initSeeds();
  autoMode = true;
  autoBtn.classList.add('active');
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
