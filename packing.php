<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('packing');

$pageName = 'packing';
$pageTitle = 'CIRCLE PACKING - PULSE';
$pageDesc = '円充填。空間をゆっくり埋め尽くす、成長する円たち。';
$pageCanonical = 'https://pulse.subara3.com/packing.php';

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

@media (max-width: 600px) {
  .hud {
    font-size: 0.55rem;
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
    font-size: 0.5rem;
  }
  .slider-wrap input[type="range"] {
    width: 60px;
  }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  CLICK TO ADD CIRCLES<br>
  CIRCLES GROW UNTIL THEY TOUCH<br>
  WATCH THE SPACE FILL
</div>

<div class="ui hud" id="hud">
  CIRCLES: <span id="hudCircles" class="highlight">0</span><br>
  GROWING: <span id="hudGrowing" class="highlight">0</span><br>
  COVERAGE: <span id="hudCoverage" class="highlight">0%</span><br>
  MODE: <span id="hudMode">RANDOM</span>
</div>

<div class="ui controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="10" value="3">
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

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudCircles = document.getElementById('hudCircles');
  const hudGrowing = document.getElementById('hudGrowing');
  const hudCoverage = document.getElementById('hudCoverage');
  const hudMode = document.getElementById('hudMode');
  const speedSlider = document.getElementById('speedSlider');
  const modeBtn = document.getElementById('modeBtn');
  const colorBtn = document.getElementById('colorBtn');
  const pauseBtn = document.getElementById('pauseBtn');
  const resetBtn = document.getElementById('resetBtn');

  let W, H;
  let circles = [];
  let paused = false;
  let mode = 0;
  let paletteIdx = 0;
  let hideUI = false;
  let growthRate = 0.3;
  let lastSpawnTime = 0;
  let spawnInterval = 500; // 0.5秒に1回新しい円を生成
  let lastGrowTime = 0;
  let growInterval = 50; // 50msごとに成長

  const MODES = ['RANDOM', 'CENTER', 'EDGE'];
  const MIN_RADIUS = 3;
  const MAX_RADIUS = 200;
  const MAX_ATTEMPTS = 100;

  // Audio context for ambient drone
  let audioCtx = null;
  let droneOsc = null;
  let droneGain = null;
  let droneActive = false;

  function initAudio() {
    if (audioCtx) return;
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    
    // Ambient drone - very quiet background hum
    droneOsc = audioCtx.createOscillator();
    droneGain = audioCtx.createGain();
    const filter = audioCtx.createBiquadFilter();
    
    droneOsc.type = 'sine';
    droneOsc.frequency.value = 55; // Low A
    filter.type = 'lowpass';
    filter.frequency.value = 200;
    droneGain.gain.value = 0;
    
    droneOsc.connect(filter);
    filter.connect(droneGain);
    droneGain.connect(audioCtx.destination);
    droneOsc.start();
  }

  function startDrone() {
    if (!audioCtx || droneActive) return;
    droneActive = true;
    droneGain.gain.linearRampToValueAtTime(0.03, audioCtx.currentTime + 2);
  }

  function stopDrone() {
    if (!audioCtx || !droneActive) return;
    droneActive = false;
    droneGain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 1);
  }

  function updateDrone() {
    if (!audioCtx || !droneActive) return;
    // Modulate drone based on growing circles
    const growing = circles.filter(c => c.growing).length;
    const targetFreq = 55 + (growing * 2);
    droneOsc.frequency.linearRampToValueAtTime(
      Math.min(targetFreq, 110), 
      audioCtx.currentTime + 0.5
    );
  }

  // Color palettes - teal/purple spectrum
  const PALETTES = [
    {
      name: 'PULSE',
      getColor: function(r, maxR) {
        const t = r / maxR;
        return {
          h: 175 + t * 50,  // teal to blue-purple
          s: 70,
          l: 35 + t * 20
        };
      }
    },
    {
      name: 'AURORA',
      getColor: function(r, maxR) {
        const t = r / maxR;
        return {
          h: 180 + t * 80,  // cyan to violet
          s: 60 + t * 20,
          l: 30 + t * 25
        };
      }
    },
    {
      name: 'DEPTH',
      getColor: function(r, maxR) {
        const t = r / maxR;
        return {
          h: 270 - t * 30,  // purple to blue
          s: 50 + t * 30,
          l: 25 + t * 30
        };
      }
    },
    {
      name: 'VOID',
      getColor: function(r, maxR) {
        const t = r / maxR;
        return {
          h: 200,
          s: 10 + t * 40,
          l: 15 + t * 35
        };
      }
    }
  ];

  let palette = PALETTES[0];

  function canPlace(x, y, r) {
    // Check wall collision
    if (x - r < 0 || x + r > W || y - r < 0 || y + r > H) {
      return false;
    }
    // Check other circles
    for (const c of circles) {
      const dx = x - c.x;
      const dy = y - c.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < r + c.r + 1) {
        return false;
      }
    }
    return true;
  }

  function findValidPosition() {
    const modeName = MODES[mode];
    
    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
      let x, y;
      
      if (modeName === 'CENTER') {
        // Bias toward center
        const angle = Math.random() * Math.PI * 2;
        const dist = Math.random() * Math.min(W, H) * 0.4;
        x = W / 2 + Math.cos(angle) * dist;
        y = H / 2 + Math.sin(angle) * dist;
      } else if (modeName === 'EDGE') {
        // Bias toward edges
        const side = Math.floor(Math.random() * 4);
        const margin = MIN_RADIUS + 10;
        if (side === 0) { x = margin + Math.random() * 50; y = Math.random() * H; }
        else if (side === 1) { x = W - margin - Math.random() * 50; y = Math.random() * H; }
        else if (side === 2) { x = Math.random() * W; y = margin + Math.random() * 50; }
        else { x = Math.random() * W; y = H - margin - Math.random() * 50; }
      } else {
        // Random
        x = MIN_RADIUS + Math.random() * (W - MIN_RADIUS * 2);
        y = MIN_RADIUS + Math.random() * (H - MIN_RADIUS * 2);
      }
      
      if (canPlace(x, y, MIN_RADIUS)) {
        return { x, y };
      }
    }
    return null;
  }

  function addCircle(x, y) {
    if (!canPlace(x, y, MIN_RADIUS)) {
      // Try nearby positions
      for (let i = 0; i < 10; i++) {
        const nx = x + (Math.random() - 0.5) * 50;
        const ny = y + (Math.random() - 0.5) * 50;
        if (canPlace(nx, ny, MIN_RADIUS)) {
          x = nx;
          y = ny;
          break;
        }
      }
      if (!canPlace(x, y, MIN_RADIUS)) return false;
    }
    
    const col = palette.getColor(MIN_RADIUS, MAX_RADIUS);
    circles.push({
      x: x,
      y: y,
      r: MIN_RADIUS,
      growing: true,
      color: col,
      birthTime: Date.now()
    });
    
    SFX.play('spawn');
    startDrone();
    return true;
  }

  function growCircles() {
    const rate = parseFloat(speedSlider.value) * 0.3;
    let anyGrowing = false;
    
    for (const c of circles) {
      if (!c.growing) continue;
      
      const newR = c.r + rate;
      
      // Check if can grow
      if (newR >= MAX_RADIUS) {
        c.growing = false;
        SFX.play('collide', 100);
        continue;
      }
      
      // Wall collision
      if (c.x - newR < 0 || c.x + newR > W || c.y - newR < 0 || c.y + newR > H) {
        c.growing = false;
        SFX.play('collide', 100);
        continue;
      }
      
      // Other circle collision
      let blocked = false;
      for (const other of circles) {
        if (other === c) continue;
        const dx = c.x - other.x;
        const dy = c.y - other.y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < newR + other.r + 0.5) {
          blocked = true;
          break;
        }
      }
      
      if (blocked) {
        c.growing = false;
        SFX.play('pop', 80);
      } else {
        c.r = newR;
        c.color = palette.getColor(c.r, MAX_RADIUS);
        anyGrowing = true;
      }
    }
    
    if (!anyGrowing && droneActive) {
      stopDrone();
    }
    
    updateDrone();
  }

  function calculateCoverage() {
    let totalArea = 0;
    for (const c of circles) {
      totalArea += Math.PI * c.r * c.r;
    }
    const screenArea = W * H;
    return Math.min(100, (totalArea / screenArea) * 100);
  }

  function autoSpawn() {
    if (paused) return;
    
    const now = Date.now();
    if (now - lastSpawnTime < spawnInterval) return;
    
    // Check if any space remains
    const coverage = calculateCoverage();
    if (coverage > 85) return; // Stop spawning when mostly filled
    
    const pos = findValidPosition();
    if (pos) {
      addCircle(pos.x, pos.y);
      lastSpawnTime = now;
    }
  }

  function update() {
    if (paused) return;
    
    const now = Date.now();
    
    // Slow growth - every 100ms
    if (now - lastGrowTime >= growInterval) {
      growCircles();
      lastGrowTime = now;
    }
    
    // Auto spawn new circles
    autoSpawn();
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    
    // Draw circles
    for (const c of circles) {
      const col = c.color;
      
      // Fill
      ctx.beginPath();
      ctx.arc(c.x, c.y, c.r, 0, Math.PI * 2);
      ctx.fillStyle = 'hsla(' + col.h + ',' + col.s + '%,' + col.l + '%, 0.6)';
      ctx.fill();
      
      // Glow for growing circles
      if (c.growing) {
        ctx.beginPath();
        ctx.arc(c.x, c.y, c.r + 3, 0, Math.PI * 2);
        ctx.strokeStyle = 'hsla(' + col.h + ',' + col.s + '%,' + (col.l + 20) + '%, 0.4)';
        ctx.lineWidth = 2;
        ctx.stroke();
      }
      
      // Border
      ctx.beginPath();
      ctx.arc(c.x, c.y, c.r, 0, Math.PI * 2);
      ctx.strokeStyle = 'hsla(' + col.h + ',' + col.s + '%,' + (col.l + 30) + '%, 0.3)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }
    
    // Update HUD
    const growingCount = circles.filter(c => c.growing).length;
    hudCircles.textContent = circles.length;
    hudGrowing.textContent = growingCount;
    hudCoverage.textContent = calculateCoverage().toFixed(1) + '%';
    hudMode.textContent = MODES[mode];
  }

  let animFrame = null;
  function loop() {
    update();
    render();
    animFrame = requestAnimationFrame(loop);
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
    initAudio();
    const [mx, my] = getPos(e);
    addCircle(mx, my);
  });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    initAudio();
    const t = e.changedTouches[0];
    const [mx, my] = getPos(t);
    addCircle(mx, my);
  }, { passive: false });

  modeBtn.addEventListener('click', function() {
    mode = (mode + 1) % MODES.length;
    SFX.play('toggle');
  });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    palette = PALETTES[paletteIdx];
    // Update existing circles
    for (const c of circles) {
      c.color = palette.getColor(c.r, MAX_RADIUS);
    }
    SFX.play('click');
  });

  pauseBtn.addEventListener('click', function() {
    initAudio();
    paused = !paused;
    this.classList.toggle('active', paused);
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    if (paused) {
      stopDrone();
    }
    SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    circles = [];
    lastSpawnTime = 0;
    stopDrone();
    SFX.play('click');
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      initAudio();
      paused = !paused;
      pauseBtn.classList.toggle('active', paused);
      pauseBtn.textContent = paused ? 'PLAY' : 'PAUSE';
      if (paused) {
        stopDrone();
      }
    }
  });

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    // Keep existing circles that still fit
    circles = circles.filter(c => 
      c.x - c.r >= 0 && c.x + c.r <= W && 
      c.y - c.r >= 0 && c.y + c.r <= H
    );
  }

  window.addEventListener('resize', resize);

  // UI hide style
  const style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // Initial circles to show something immediately
  function initialSpawn() {
    // Place 5 circles at random positions directly
    const positions = [
      { x: W * 0.2, y: H * 0.3 },
      { x: W * 0.7, y: H * 0.2 },
      { x: W * 0.5, y: H * 0.5 },
      { x: W * 0.3, y: H * 0.7 },
      { x: W * 0.8, y: H * 0.6 }
    ];
    
    for (const pos of positions) {
      const col = palette.getColor(MIN_RADIUS, MAX_RADIUS);
      circles.push({
        x: pos.x,
        y: pos.y,
        r: MIN_RADIUS + Math.random() * 20,
        growing: true,
        color: col,
        birthTime: Date.now()
      });
    }
    
  }

  // Start
  console.log('[PACKING] Starting, canvas:', canvas, 'W:', W, 'H:', H);
  resize();
  console.log('[PACKING] After resize, W:', W, 'H:', H);
  initialSpawn();
  console.log('[PACKING] After initialSpawn, circles:', circles.length, circles);
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
