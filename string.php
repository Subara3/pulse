<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('string');

$pageName = 'string';
$pageTitle = 'STRING ART - PULSE';
$pageDesc = 'ストリングアート。円周上の点を糸で結ぶと、数式が曲線を描き出す。';
$pageCanonical = 'https://pulse.subara3.com/string.php';

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

.slider-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.slider-label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  min-width: 3rem;
  text-align: right;
}

.slider {
  -webkit-appearance: none;
  width: 100px;
  height: 3px;
  background: rgba(255,255,255,0.15);
  border-radius: 2px;
  outline: none;
}

.slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  background: #00f5d4;
  border-radius: 50%;
  cursor: pointer;
}

.slider::-moz-range-thumb {
  width: 12px;
  height: 12px;
  background: #00f5d4;
  border-radius: 50%;
  cursor: pointer;
  border: none;
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

.presets {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  max-width: 200px;
  justify-content: flex-end;
}

.preset-btn {
  padding: 0.3rem 0.6rem;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.4);
  font-family: "Orbitron", sans-serif;
  font-size: 0.55rem;
  cursor: pointer;
  border-radius: 3px;
  transition: all 0.3s;
}

.preset-btn:hover {
  background: rgba(123, 44, 191, 0.15);
  border-color: rgba(123, 44, 191, 0.5);
  color: #7b2cbf;
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
  .slider {
    width: 80px;
  }
  .info {
    bottom: 1rem;
    left: 1rem;
    font-size: 0.55rem;
  }
  .presets {
    max-width: 160px;
  }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  ADJUST SLIDERS TO EXPLORE<br>
  DRAG TO ROTATE
</div>

<div class="ui hud" id="hud">
  POINTS: <span id="hudPoints" class="highlight">200</span><br>
  MULTIPLIER: <span id="hudMult" class="highlight">2</span><br>
  LINES: <span id="hudLines" class="highlight">0</span><br>
  PATTERN: <span id="hudPattern">CARDIOID</span>
</div>

<div class="ui controls">
  <div class="presets" id="presets">
    <button class="preset-btn" data-points="200" data-mult="2">CARDIOID</button>
    <button class="preset-btn" data-points="200" data-mult="3">NEPHROID</button>
    <button class="preset-btn" data-points="360" data-mult="2.618">GOLDEN</button>
    <button class="preset-btn" data-points="400" data-mult="71">PRIME</button>
    <button class="preset-btn" data-points="500" data-mult="199">DENSE</button>
    <button class="preset-btn" data-points="300" data-mult="51">STAR</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">POINTS</span>
    <input type="range" class="slider" id="pointsSlider" min="10" max="600" value="200">
  </div>
  <div class="slider-row">
    <span class="slider-label">MULT</span>
    <input type="range" class="slider" id="multSlider" min="2" max="300" value="2" step="0.001">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="animBtn">ANIMATE</button>
    <button class="control-btn" id="colorBtn">COLOR</button>
  </div>
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const hudPoints = document.getElementById('hudPoints');
  const hudMult = document.getElementById('hudMult');
  const hudLines = document.getElementById('hudLines');
  const hudPattern = document.getElementById('hudPattern');
  const pointsSlider = document.getElementById('pointsSlider');
  const multSlider = document.getElementById('multSlider');
  const animBtn = document.getElementById('animBtn');
  const colorBtn = document.getElementById('colorBtn');
  const presets = document.getElementById('presets');

  const isMobile = window.innerWidth <= 768;
  let W, H, cx, cy, radius;
  let numPoints = isMobile ? 120 : 200;
  let multiplier = 2;
  let animating = false;
  let animSpeed = 0.003;
  let rotation = 0;
  let hideUI = false;
  let paletteIdx = 0;
  let dragging = false;
  let dragStart = 0;
  let rotationStart = 0;

  // Teal-purple palette variations
  const PALETTES = [
    { name: 'PULSE', baseHue: 172, range: 45, sat: 75, light: 55 },
    { name: 'VIOLET', baseHue: 270, range: 40, sat: 65, light: 50 },
    { name: 'OCEAN', baseHue: 195, range: 35, sat: 70, light: 45 },
    { name: 'AURORA', baseHue: 160, range: 80, sat: 60, light: 50 }
  ];

  function getPatternName(mult) {
    const m = Math.round(mult * 1000) / 1000;
    if (Math.abs(m - 2) < 0.01) return 'CARDIOID';
    if (Math.abs(m - 3) < 0.01) return 'NEPHROID';
    if (Math.abs(m - 4) < 0.01) return 'TREFOIL';
    if (Math.abs(m - 2.618) < 0.01) return 'GOLDEN';
    if (m === Math.floor(m) && m > 4) return 'ROSE-' + m;
    return 'CUSTOM';
  }

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    radius = Math.min(W, H) * 0.38;
  }

  function getPointOnCircle(index, total) {
    const angle = (index / total) * Math.PI * 2 - Math.PI / 2 + rotation;
    return {
      x: cx + Math.cos(angle) * radius,
      y: cy + Math.sin(angle) * radius
    };
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    const pal = PALETTES[paletteIdx];
    let lineCount = 0;

    // Draw connection lines
    ctx.lineWidth = 0.5;
    ctx.globalAlpha = 0.4;

    for (let i = 0; i < numPoints; i++) {
      const from = getPointOnCircle(i, numPoints);
      const toIndex = (i * multiplier) % numPoints;
      const to = getPointOnCircle(toIndex, numPoints);

      // Color based on position
      const hue = pal.baseHue + (i / numPoints) * pal.range;
      ctx.strokeStyle = 'hsl(' + hue + ',' + pal.sat + '%,' + pal.light + '%)';

      ctx.beginPath();
      ctx.moveTo(from.x, from.y);
      ctx.lineTo(to.x, to.y);
      ctx.stroke();
      lineCount++;
    }

    ctx.globalAlpha = 1;

    // Draw subtle outer ring
    ctx.strokeStyle = 'rgba(0, 245, 212, 0.08)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.stroke();

    // Draw points on circle
    ctx.fillStyle = 'rgba(0, 245, 212, 0.15)';
    for (let i = 0; i < numPoints; i++) {
      const p = getPointOnCircle(i, numPoints);
      ctx.beginPath();
      ctx.arc(p.x, p.y, 1.5, 0, Math.PI * 2);
      ctx.fill();
    }

    // Update HUD
    hudPoints.textContent = numPoints;
    hudMult.textContent = multiplier.toFixed(3).replace(/\.?0+$/, '');
    hudLines.textContent = lineCount;
    hudPattern.textContent = getPatternName(multiplier);
  }

  function animate() {
    if (animating) {
      multiplier += animSpeed;
      if (multiplier > 300) multiplier = 2;
      multSlider.value = multiplier;
    }
    render();
    requestAnimationFrame(animate);
  }

  // Event listeners
  pointsSlider.addEventListener('input', function() {
    numPoints = parseInt(this.value);
    SFX.play('tick', 30);
  });

  multSlider.addEventListener('input', function() {
    multiplier = parseFloat(this.value);
    SFX.play('tick', 30);
  });

  animBtn.addEventListener('click', function() {
    animating = !animating;
    this.classList.toggle('active', animating);
    SFX.play('toggle');
  });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    SFX.play('click');
  });

  presets.addEventListener('click', function(e) {
    if (e.target.classList.contains('preset-btn')) {
      numPoints = parseInt(e.target.dataset.points);
      multiplier = parseFloat(e.target.dataset.mult);
      pointsSlider.value = numPoints;
      multSlider.value = multiplier;
      SFX.play('toggle');
    }
  });

  // Drag to rotate
  canvas.addEventListener('mousedown', function(e) {
    dragging = true;
    dragStart = e.clientX;
    rotationStart = rotation;
  });

  canvas.addEventListener('mousemove', function(e) {
    if (dragging) {
      const delta = (e.clientX - dragStart) * 0.005;
      rotation = rotationStart + delta;
    }
  });

  canvas.addEventListener('mouseup', function() {
    dragging = false;
  });

  canvas.addEventListener('mouseleave', function() {
    dragging = false;
  });

  // Touch support
  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    dragging = true;
    dragStart = e.touches[0].clientX;
    rotationStart = rotation;
  }, {passive: false});

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragging) {
      const delta = (e.touches[0].clientX - dragStart) * 0.005;
      rotation = rotationStart + delta;
    }
  }, {passive: false});

  canvas.addEventListener('touchend', function() {
    dragging = false;
  });

  // Keyboard
  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      animating = !animating;
      animBtn.classList.toggle('active', animating);
    }
  });

  // UI hide style
  const style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // Start
  window.addEventListener('resize', resize);
  resize();
  animate();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
