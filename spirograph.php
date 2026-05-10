<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('spirograph');

$pageName = 'spirograph';
$pageTitle = 'SPIROGRAPH - PULSE';
$pageDesc = 'スピログラフ。歯車が回転し、ペンが数学の曲線を描く。';
$pageCanonical = 'https://pulse.subara3.com/spirograph.php';

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
}

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

.hud {
  top: 1.5rem;
  right: 1.5rem;
  text-align: right;
  color: rgba(255,255,255,0.3);
  font-size: 0.7rem;
  letter-spacing: 0.1em;
}

.hud .type-label {
  color: #00f5d4;
  margin-bottom: 0.3rem;
}

.hud .params {
  font-size: 0.6rem;
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  align-items: flex-end;
}

.slider-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.slider-label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  width: 2rem;
  text-align: right;
}

.slider-value {
  color: #00f5d4;
  font-size: 0.6rem;
  width: 2.5rem;
  text-align: left;
}

input[type="range"] {
  -webkit-appearance: none;
  width: 100px;
  height: 4px;
  background: rgba(255,255,255,0.1);
  border-radius: 2px;
  outline: none;
}

input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 12px;
  height: 12px;
  background: #00f5d4;
  border-radius: 50%;
  cursor: pointer;
  transition: transform 0.2s;
}

input[type="range"]::-webkit-slider-thumb:hover {
  transform: scale(1.2);
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

@media (max-width: 768px) {
  .info { font-size: 0.55rem; bottom: 1rem; left: 1rem; }
  .hud { top: 0.5rem; right: 0.5rem; font-size: 0.55rem; }
  .hud .params { font-size: 0.5rem; }
  .controls { bottom: 0.8rem; right: 0.8rem; gap: 0.5rem; }
  .control-btn { padding: 0.35rem 0.6rem; font-size: 0.5rem; }
  input[type="range"] { width: 70px; }
  .slider-label, .slider-value { font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">SPIROGRAPH</div>

<div class="ui hud">
  <div class="type-label" id="typeLabel">HYPOTROCHOID</div>
  <div class="params" id="paramsDisplay">R:96 r:36 d:30</div>
  <div id="progressDisplay">0%</div>
</div>

<div class="ui controls">
  <div class="slider-group">
    <span class="slider-label">R</span>
    <input type="range" id="sliderR" min="40" max="150" step="2" value="96">
    <span class="slider-value" id="valueR">96</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">r</span>
    <input type="range" id="sliderr" min="10" max="80" step="2" value="36">
    <span class="slider-value" id="valuer">36</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">d</span>
    <input type="range" id="sliderd" min="5" max="80" step="1" value="30">
    <span class="slider-value" id="valued">30</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="hypoBtn">HYPO</button>
    <button class="control-btn" id="epiBtn">EPI</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="gearsBtn">GEARS</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>

<?php require_once __DIR__ . '/includes/colors.php'; ?>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');

  const sliderR = document.getElementById('sliderR');
  const sliderr = document.getElementById('sliderr');
  const sliderd = document.getElementById('sliderd');
  const valueR = document.getElementById('valueR');
  const valuer = document.getElementById('valuer');
  const valued = document.getElementById('valued');
  const typeLabel = document.getElementById('typeLabel');
  const paramsDisplay = document.getElementById('paramsDisplay');
  const progressDisplay = document.getElementById('progressDisplay');
  const hypoBtn = document.getElementById('hypoBtn');
  const epiBtn = document.getElementById('epiBtn');
  const gearsBtn = document.getElementById('gearsBtn');
  const resetBtn = document.getElementById('resetBtn');

  let width, height;
  let R = 96, r = 36, d = 30;
  let isHypo = true;
  let showGears = false;
  let t = 0;
  let trail = [];
  let drawing = true;
  let curveComplete = false;

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    resetCurve();
  }

  function gcd(a, b) {
    a = Math.abs(Math.round(a));
    b = Math.abs(Math.round(b));
    while (b) { const t = b; b = a % b; a = t; }
    return a;
  }

  function lcm(a, b) {
    return Math.abs(a * b) / gcd(a, b);
  }

  function getFullPeriod() {
    const g = gcd(R, r);
    return (2 * Math.PI * r) / g;
  }

  function spirograph(t) {
    let x, y;
    if (isHypo) {
      // Hypotrochoid: inner circle rolls inside
      const diff = R - r;
      x = diff * Math.cos(t) + d * Math.cos(diff / r * t);
      y = diff * Math.sin(t) - d * Math.sin(diff / r * t);
    } else {
      // Epitrochoid: inner circle rolls outside
      const sum = R + r;
      x = sum * Math.cos(t) - d * Math.cos(sum / r * t);
      y = sum * Math.sin(t) - d * Math.sin(sum / r * t);
    }
    return { x, y };
  }

  function resetCurve() {
    t = 0;
    trail = [];
    drawing = true;
    curveComplete = false;
  }

  function updateDisplay() {
    R = parseInt(sliderR.value);
    r = parseInt(sliderr.value);
    d = parseInt(sliderd.value);

    valueR.textContent = R;
    valuer.textContent = r;
    valued.textContent = d;
    typeLabel.textContent = isHypo ? 'HYPOTROCHOID' : 'EPITROCHOID';
    paramsDisplay.textContent = `R:${R} r:${r} d:${d}`;
  }

  function drawGears(cx, cy, scale) {
    // Fixed circle
    ctx.beginPath();
    ctx.arc(cx, cy, R * scale, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(123, 44, 191, 0.3)';
    ctx.lineWidth = 1;
    ctx.stroke();

    // Moving circle position
    let mcx, mcy, penX, penY;
    if (isHypo) {
      const diff = R - r;
      mcx = cx + diff * scale * Math.cos(t);
      mcy = cy + diff * scale * Math.sin(t);
      penX = cx + (diff * Math.cos(t) + d * Math.cos(diff / r * t)) * scale;
      penY = cy + (diff * Math.sin(t) - d * Math.sin(diff / r * t)) * scale;
    } else {
      const sum = R + r;
      mcx = cx + sum * scale * Math.cos(t);
      mcy = cy + sum * scale * Math.sin(t);
      penX = cx + (sum * Math.cos(t) - d * Math.cos(sum / r * t)) * scale;
      penY = cy + (sum * Math.sin(t) - d * Math.sin(sum / r * t)) * scale;
    }

    // Moving circle
    ctx.beginPath();
    ctx.arc(mcx, mcy, r * scale, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0, 245, 212, 0.4)';
    ctx.lineWidth = 1;
    ctx.stroke();

    // Pen arm
    ctx.beginPath();
    ctx.moveTo(mcx, mcy);
    ctx.lineTo(penX, penY);
    ctx.strokeStyle = 'rgba(0, 245, 212, 0.6)';
    ctx.lineWidth = 1;
    ctx.stroke();

    // Pen point
    ctx.beginPath();
    ctx.arc(penX, penY, 4, 0, Math.PI * 2);
    ctx.fillStyle = '#00f5d4';
    ctx.fill();
  }

  function draw() {
    // Subtle fade for trail effect
    ctx.fillStyle = 'rgba(10, 10, 15, 0.02)';
    ctx.fillRect(0, 0, width, height);

    const cx = width / 2;
    const cy = height / 2;
    const maxSize = isHypo ? (R - r + d) : (R + r + d);
    const scale = Math.min(width, height) * 0.35 / maxSize;

    const period = getFullPeriod();
    const progress = Math.min(t / period, 1);
    progressDisplay.textContent = Math.round(progress * 100) + '%';

    if (drawing && !curveComplete) {
      // Add points slowly
      const speed = 0.015; // Slow, meditative speed
      const pos = spirograph(t);
      const px = cx + pos.x * scale;
      const py = cy + pos.y * scale;

      trail.push({ x: px, y: py, t: t });

      // Sound on drawing (throttled)
      if (trail.length % 20 === 0) {
        SFX.play('tick', 100);
      }

      t += speed;

      if (t >= period) {
        curveComplete = true;
        drawing = false;
        SFX.play('complete');
      }
    }

    // Draw the curve
    if (trail.length > 1) {
      ctx.beginPath();
      ctx.moveTo(trail[0].x, trail[0].y);

      for (let i = 1; i < trail.length; i++) {
        const prog = i / trail.length;
        // Gradient from teal to purple
        const color = P.mix(P.accent, P.sub, prog);
        ctx.strokeStyle = P.rgba(color, 0.9);
        ctx.lineWidth = 1.5;
        ctx.lineTo(trail[i].x, trail[i].y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(trail[i].x, trail[i].y);
      }
    }

    // Current pen position glow
    if (drawing && trail.length > 0) {
      const last = trail[trail.length - 1];
      ctx.beginPath();
      ctx.arc(last.x, last.y, 8, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0, 245, 212, 0.4)';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(last.x, last.y, 4, 0, Math.PI * 2);
      ctx.fillStyle = '#00f5d4';
      ctx.fill();
    }

    // Draw gears if enabled
    if (showGears) {
      drawGears(cx, cy, scale);
    }

    requestAnimationFrame(draw);
  }

  // Events
  sliderR.addEventListener('input', () => {
    updateDisplay();
    resetCurve();
    SFX.play('tick', 30);
  });
  sliderr.addEventListener('input', () => {
    updateDisplay();
    resetCurve();
    SFX.play('tick', 30);
  });
  sliderd.addEventListener('input', () => {
    updateDisplay();
    resetCurve();
    SFX.play('tick', 30);
  });

  hypoBtn.addEventListener('click', () => {
    if (!isHypo) {
      isHypo = true;
      hypoBtn.classList.add('active');
      epiBtn.classList.remove('active');
      updateDisplay();
      resetCurve();
      SFX.play('toggle');
    }
  });

  epiBtn.addEventListener('click', () => {
    if (isHypo) {
      isHypo = false;
      epiBtn.classList.add('active');
      hypoBtn.classList.remove('active');
      updateDisplay();
      resetCurve();
      SFX.play('toggle');
    }
  });

  gearsBtn.addEventListener('click', () => {
    showGears = !showGears;
    gearsBtn.classList.toggle('active', showGears);
    SFX.play('toggle');
  });

  resetBtn.addEventListener('click', () => {
    resetCurve();
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
    SFX.play('click');
  });

  // Touch/click to reset or randomize
  canvas.addEventListener('click', (e) => {
    // Avoid controls area
    if (e.clientX > width - 200 && e.clientY > height - 250) return;
    if (e.clientX > width - 200 && e.clientY < 100) return;

    // Randomize parameters
    sliderR.value = 40 + Math.floor(Math.random() * 56) * 2;
    sliderr.value = 10 + Math.floor(Math.random() * 36) * 2;
    sliderd.value = 5 + Math.floor(Math.random() * 76);
    updateDisplay();
    resetCurve();
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
    SFX.play('spawn');
  });

  window.addEventListener('resize', resize);

  // Initialize
  resize();
  updateDisplay();
  draw();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
