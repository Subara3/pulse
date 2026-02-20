<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('dla');

$pageName = 'dla';
$pageTitle = 'DLA - PULSE';
$pageDesc = '拡散律速凝集。ランダムウォークする粒子が触れた瞬間、結晶は成長する。';
$pageCanonical = 'https://pulse.subara3.com/dla.php';

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

/* drawer-controls handles positioning */

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

.slider-wrap {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.slider-wrap label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  min-width: 50px;
}

.slider-wrap input[type="range"] {
  width: 80px;
  height: 4px;
  -webkit-appearance: none;
  background: rgba(255,255,255,0.1);
  border-radius: 2px;
  outline: none;
}

.slider-wrap input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #00f5d4;
  cursor: pointer;
}

@media (max-width: 768px) {
  .hud {
    font-size: 0.55rem;
    top: 1rem;
    right: 1rem;
  }
  .info {
    bottom: 1rem;
    left: 1rem;
    font-size: 0.5rem;
  }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  CLICK TO PLACE SEED<br>
  PARTICLES WALK RANDOMLY<br>
  CRYSTALS GROW ON CONTACT
</div>

<div class="ui hud" id="hud">
  PARTICLES: <span id="hudParticles" class="highlight">0</span><br>
  CRYSTAL: <span id="hudCrystal" class="highlight">0</span><br>
  MODE: <span id="hudMode">CLASSIC</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="50" value="20">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="modeBtn">MODE</button>
    <button class="control-btn" id="colorBtn">COLOR</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="pauseBtn">PAUSE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudParticles = document.getElementById('hudParticles');
  const hudCrystal = document.getElementById('hudCrystal');
  const hudMode = document.getElementById('hudMode');
  const speedSlider = document.getElementById('speedSlider');
  const modeBtn = document.getElementById('modeBtn');
  const colorBtn = document.getElementById('colorBtn');
  const pauseBtn = document.getElementById('pauseBtn');
  const resetBtn = document.getElementById('resetBtn');

  const isMobile = window.innerWidth <= 768;
  const PARTICLE_COUNT = isMobile ? 800 : 2000;
  const PARTICLE_RADIUS = 2;
  const CRYSTAL_RADIUS = 2;
  const SPAWN_MARGIN = 50;
  const MODES = ['CLASSIC', 'RADIAL', 'SNOW'];

  let W, H, cx, cy;
  let particles = [];
  let crystal = [];
  let crystalGrid = {};
  let paused = false;
  let mode = 1; // RADIAL（中心から放射状に成長）
  let paletteIdx = 0;
  let hideUI = false;
  let stepsPerFrame = 20;

  // Color palettes - teal/purple spectrum
  const PALETTES = [
    {
      name: 'PULSE',
      particle: [0, 245, 212],
      crystal: function(depth) {
        const t = Math.min(1, depth / 150);
        return [
          Math.floor(0 + t * 123),
          Math.floor(245 - t * 201),
          Math.floor(212 - t * 21)
        ];
      }
    },
    {
      name: 'AURORA',
      particle: [100, 200, 255],
      crystal: function(depth) {
        const t = Math.min(1, depth / 150);
        const h = 180 + t * 80;
        return hsl2rgb(h, 70, 40 + t * 20);
      }
    },
    {
      name: 'EMBER',
      particle: [200, 100, 255],
      crystal: function(depth) {
        const t = Math.min(1, depth / 150);
        const h = 280 - t * 20;
        return hsl2rgb(h, 60 + t * 20, 30 + t * 30);
      }
    },
    {
      name: 'VOID',
      particle: [150, 170, 200],
      crystal: function(depth) {
        const t = Math.min(1, depth / 150);
        const l = 20 + t * 40;
        return [l * 2.2, l * 2.3, l * 2.55];
      }
    }
  ];

  let palette = PALETTES[0];

  function hsl2rgb(h, s, l) {
    s /= 100; l /= 100;
    const k = n => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = n => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  function gridKey(x, y) {
    const gx = Math.floor(x / 10);
    const gy = Math.floor(y / 10);
    return gx + ',' + gy;
  }

  function addToCrystal(x, y) {
    const depth = crystal.length;
    crystal.push({ x, y, depth });
    const key = gridKey(x, y);
    if (!crystalGrid[key]) crystalGrid[key] = [];
    crystalGrid[key].push({ x, y, depth });
  }

  function checkCrystalCollision(x, y) {
    const key = gridKey(x, y);
    const radius = PARTICLE_RADIUS + CRYSTAL_RADIUS;
    const r2 = radius * radius;
    
    // Check surrounding cells
    for (let dx = -1; dx <= 1; dx++) {
      for (let dy = -1; dy <= 1; dy++) {
        const k = (Math.floor(x / 10) + dx) + ',' + (Math.floor(y / 10) + dy);
        const cell = crystalGrid[k];
        if (!cell) continue;
        for (const c of cell) {
          const ddx = x - c.x, ddy = y - c.y;
          if (ddx * ddx + ddy * ddy < r2) return true;
        }
      }
    }
    return false;
  }

  function spawnParticle() {
    let x, y;
    const modeName = MODES[mode];
    
    if (modeName === 'SNOW') {
      // Spawn from top
      x = Math.random() * W;
      y = -SPAWN_MARGIN;
    } else if (modeName === 'RADIAL') {
      // Spawn from circle edge
      const maxR = Math.min(cx, cy) - SPAWN_MARGIN;
      const angle = Math.random() * Math.PI * 2;
      x = cx + Math.cos(angle) * maxR;
      y = cy + Math.sin(angle) * maxR;
    } else {
      // Classic: spawn from edges
      const side = Math.floor(Math.random() * 4);
      if (side === 0) { x = Math.random() * W; y = -SPAWN_MARGIN; }
      else if (side === 1) { x = W + SPAWN_MARGIN; y = Math.random() * H; }
      else if (side === 2) { x = Math.random() * W; y = H + SPAWN_MARGIN; }
      else { x = -SPAWN_MARGIN; y = Math.random() * H; }
    }
    
    return { x, y };
  }

  function initSeeds() {
    crystal = [];
    crystalGrid = {};
    particles = [];
    
    const modeName = MODES[mode];
    
    if (modeName === 'SNOW') {
      // Bottom line as seed
      for (let x = 0; x < W; x += 30) {
        addToCrystal(x, H - 20);
      }
    } else if (modeName === 'RADIAL') {
      // Center seed
      addToCrystal(cx, cy);
    } else {
      // Classic: center seed
      addToCrystal(cx, cy);
    }
    
    // Spawn particles
    for (let i = 0; i < PARTICLE_COUNT; i++) {
      particles.push(spawnParticle());
    }
  }

  function stepParticle(p) {
    const modeName = MODES[mode];
    
    // Random walk
    p.x += (Math.random() - 0.5) * 6;
    p.y += (Math.random() - 0.5) * 6;
    
    // Mode-specific bias
    if (modeName === 'SNOW') {
      p.y += 0.8; // Gravity
    } else if (modeName === 'RADIAL') {
      // Slight inward bias
      const dx = cx - p.x, dy = cy - p.y;
      const d = Math.sqrt(dx * dx + dy * dy);
      if (d > 10) {
        p.x += dx / d * 0.3;
        p.y += dy / d * 0.3;
      }
    }
    
    // Check collision
    if (checkCrystalCollision(p.x, p.y)) {
      addToCrystal(p.x, p.y);
      SFX.play('tick', 30);
      return true; // Consumed
    }
    
    // Respawn if out of bounds
    if (p.x < -SPAWN_MARGIN * 2 || p.x > W + SPAWN_MARGIN * 2 ||
        p.y < -SPAWN_MARGIN * 2 || p.y > H + SPAWN_MARGIN * 2) {
      const np = spawnParticle();
      p.x = np.x;
      p.y = np.y;
    }
    
    return false;
  }

  function update() {
    if (paused) return;
    
    stepsPerFrame = parseInt(speedSlider.value);
    
    for (let s = 0; s < stepsPerFrame; s++) {
      for (let i = particles.length - 1; i >= 0; i--) {
        if (stepParticle(particles[i])) {
          // Respawn
          const np = spawnParticle();
          particles[i].x = np.x;
          particles[i].y = np.y;
        }
      }
    }
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    
    // Draw crystal with depth coloring
    for (const c of crystal) {
      const col = palette.crystal(c.depth);
      ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
      ctx.beginPath();
      ctx.arc(c.x, c.y, CRYSTAL_RADIUS, 0, Math.PI * 2);
      ctx.fill();
    }
    
    // Draw glow on recent additions
    const recentCount = Math.min(50, crystal.length);
    for (let i = crystal.length - recentCount; i < crystal.length; i++) {
      if (i < 0) continue;
      const c = crystal[i];
      const t = (i - (crystal.length - recentCount)) / recentCount;
      const col = palette.particle;
      ctx.fillStyle = 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',' + (t * 0.3) + ')';
      ctx.beginPath();
      ctx.arc(c.x, c.y, CRYSTAL_RADIUS + 4, 0, Math.PI * 2);
      ctx.fill();
    }
    
    // Draw particles
    const pcol = palette.particle;
    ctx.fillStyle = 'rgba(' + pcol[0] + ',' + pcol[1] + ',' + pcol[2] + ',0.4)';
    for (const p of particles) {
      ctx.fillRect(p.x - 1, p.y - 1, 2, 2);
    }
    
    // Update HUD
    hudParticles.textContent = particles.length;
    hudCrystal.textContent = crystal.length;
    hudMode.textContent = MODES[mode];
  }

  function loop() {
    update();
    render();
    requestAnimationFrame(loop);
  }

  // Events
  function getPos(e) {
    const r = canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) * (W / r.width),
      (e.clientY - r.top) * (H / r.height)
    ];
  }

  canvas.addEventListener('click', function(e) {
    const [mx, my] = getPos(e);
    addToCrystal(mx, my);
    SFX.play('spawn');
  });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    const t = e.changedTouches[0];
    const [mx, my] = getPos(t);
    addToCrystal(mx, my);
    SFX.play('spawn');
  }, { passive: false });

  modeBtn.addEventListener('click', function() {
    mode = (mode + 1) % MODES.length;
    initSeeds();
    SFX.play('toggle');
  });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    palette = PALETTES[paletteIdx];
    SFX.play('click');
  });

  pauseBtn.addEventListener('click', function() {
    paused = !paused;
    this.classList.toggle('active', paused);
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    initSeeds();
    SFX.play('click');
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      paused = !paused;
      pauseBtn.classList.toggle('active', paused);
      pauseBtn.textContent = paused ? 'PLAY' : 'PAUSE';
    }
  });

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    initSeeds();
  }

  window.addEventListener('resize', resize);

  // UI hide style
  const style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // Start
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
