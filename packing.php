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
  .hud { font-size: 0.55rem; top: 1rem; right: 1rem; }
  .controls { bottom: 1rem; right: 1rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.5rem; }
  .slider-wrap input[type="range"] { width: 60px; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">
  CLICK TO ADD CIRCLES<br>
  CIRCLES GROW UNTIL TOUCH<br>
  WATCH THE SPACE FILL
</div>

<div class="ui hud">
  CIRCLES: <span id="hudCircles" class="highlight">0</span><br>
  GROWING: <span id="hudGrowing" class="highlight">0</span><br>
  COVERAGE: <span id="hudCoverage" class="highlight">0%</span>
</div>

<div class="ui controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="10" value="5">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="colorBtn">COLOR</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var hudCircles = document.getElementById('hudCircles');
  var hudGrowing = document.getElementById('hudGrowing');
  var hudCoverage = document.getElementById('hudCoverage');
  var speedSlider = document.getElementById('speedSlider');
  var colorBtn = document.getElementById('colorBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W, H;
  var circles = [];
  var paletteIdx = 0;
  var MIN_RADIUS = 5;
  var MAX_RADIUS = 150;
  var lastSpawn = 0;
  var spawnInterval = 400;

  var PALETTES = [
    function(r) { var t = r / MAX_RADIUS; return 'hsla(' + (175 + t * 50) + ',70%,' + (35 + t * 20) + '%,0.7)'; },
    function(r) { var t = r / MAX_RADIUS; return 'hsla(' + (180 + t * 80) + ',65%,' + (30 + t * 25) + '%,0.7)'; },
    function(r) { var t = r / MAX_RADIUS; return 'hsla(' + (270 - t * 30) + ',55%,' + (25 + t * 30) + '%,0.7)'; }
  ];

  function getColor(r) {
    return PALETTES[paletteIdx](r);
  }

  function canPlace(x, y, r) {
    if (x - r < 0 || x + r > W || y - r < 0 || y + r > H) return false;
    for (var i = 0; i < circles.length; i++) {
      var c = circles[i];
      var dx = x - c.x, dy = y - c.y;
      if (Math.sqrt(dx*dx + dy*dy) < r + c.r + 2) return false;
    }
    return true;
  }

  function addCircle(x, y) {
    if (!canPlace(x, y, MIN_RADIUS)) return false;
    circles.push({ x: x, y: y, r: MIN_RADIUS, growing: true });
    return true;
  }

  function spawnRandom() {
    for (var i = 0; i < 50; i++) {
      var x = MIN_RADIUS + Math.random() * (W - MIN_RADIUS * 2);
      var y = MIN_RADIUS + Math.random() * (H - MIN_RADIUS * 2);
      if (addCircle(x, y)) {
        if (typeof SFX !== 'undefined') SFX.play('spawn', 100);
        return;
      }
    }
  }

  function growCircles() {
    var rate = parseFloat(speedSlider.value) * 0.3;
    for (var i = 0; i < circles.length; i++) {
      var c = circles[i];
      if (!c.growing) continue;
      var newR = c.r + rate;
      if (newR >= MAX_RADIUS) { c.growing = false; if (typeof SFX !== 'undefined') SFX.play('pop', 50); continue; }
      if (c.x - newR < 0 || c.x + newR > W || c.y - newR < 0 || c.y + newR > H) {
        c.growing = false; typeof SFX !== 'undefined' && SFX.play('pop', 50); continue;
      }
      var blocked = false;
      for (var j = 0; j < circles.length; j++) {
        if (i === j) continue;
        var o = circles[j];
        var dx = c.x - o.x, dy = c.y - o.y;
        if (Math.sqrt(dx*dx + dy*dy) < newR + o.r + 1) { blocked = true; break; }
      }
      if (blocked) { c.growing = false; typeof SFX !== 'undefined' && SFX.play('pop', 50); }
      else { c.r = newR; }
    }
  }

  function getCoverage() {
    var total = 0;
    for (var i = 0; i < circles.length; i++) {
      total += Math.PI * circles[i].r * circles[i].r;
    }
    return Math.min(100, total / (W * H) * 100);
  }

  function update() {
    growCircles();
    var now = Date.now();
    if (now - lastSpawn > spawnInterval && getCoverage() < 80) {
      spawnRandom();
      lastSpawn = now;
    }
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    for (var i = 0; i < circles.length; i++) {
      var c = circles[i];
      ctx.beginPath();
      ctx.arc(c.x, c.y, c.r, 0, Math.PI * 2);
      ctx.fillStyle = getColor(c.r);
      ctx.fill();
      if (c.growing) {
        ctx.strokeStyle = 'rgba(0,245,212,0.4)';
        ctx.lineWidth = 2;
        ctx.stroke();
      }
    }
    var growing = 0;
    for (var i = 0; i < circles.length; i++) if (circles[i].growing) growing++;
    hudCircles.textContent = circles.length;
    hudGrowing.textContent = growing;
    hudCoverage.textContent = getCoverage().toFixed(1) + '%';
  }

  function loop() {
    update();
    render();
    requestAnimationFrame(loop);
  }

  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    return [(e.clientX - r.left) * (W / r.width), (e.clientY - r.top) * (H / r.height)];
  }

  canvas.addEventListener('click', function(e) {
    var p = getPos(e);
    if (addCircle(p[0], p[1])) typeof SFX !== 'undefined' && SFX.play('spawn');
  });

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.changedTouches[0];
    var p = getPos(t);
    if (addCircle(p[0], p[1])) typeof SFX !== 'undefined' && SFX.play('spawn');
  }, { passive: false });

  colorBtn.addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    typeof SFX !== 'undefined' && SFX.play('click');
  });

  resetBtn.addEventListener('click', function() {
    circles = [];
    initCircles();
    typeof SFX !== 'undefined' && SFX.play('click');
  });

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  function initCircles() {
    // Start with 5 circles (no SFX here - not loaded yet)
    for (var i = 0; i < 5; i++) {
      var x = W * 0.2 + Math.random() * W * 0.6;
      var y = H * 0.2 + Math.random() * H * 0.6;
      if (canPlace(x, y, MIN_RADIUS)) {
        circles.push({ x: x, y: y, r: MIN_RADIUS, growing: true });
      }
    }
  }

  window.addEventListener('resize', function() {
    resize();
    circles = [];
    initCircles();
  });

  // Start
  resize();
  initCircles();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
