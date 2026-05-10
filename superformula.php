<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('superformula');

$pageName = 'superformula';
$pageTitle = 'SUPERFORMULA - PULSE';
$pageDesc = '1つの数式が、花・葉・貝・結晶を描く。パラメータを変えて自然の形を見つけよう。';
$pageCanonical = 'https://pulse.subara3.com/superformula.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">';

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
  pointer-events: none;
}

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.15);
  font-family: "Orbitron", sans-serif;
  font-size: 0.7rem;
  letter-spacing: 0.2em;
}

.hud {position:fixed;top:3.5rem;right:1rem;text-align: left;color: rgba(255,255,255,0.3);font-family: "JetBrains Mono", monospace;font-size: 0.7rem;letter-spacing: 0.05em;}

.hud .preset-name {
  color: #00f5d4;
  font-size: 0.85rem;
  margin-bottom: 0.3rem;
  font-weight: 700;
}

.hud .param-line {
  font-size: 0.6rem;
  margin-top: 0.15rem;
}

.hud .shape-count {
  font-size: 0.55rem;
  margin-top: 0.4rem;
  color: rgba(123, 44, 191, 0.6);
}

.controls {
  bottom: 1.5rem;
  right: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  align-items: flex-end;
  pointer-events: auto;
}

.slider-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.slider-label {
  color: rgba(255,255,255,0.4);
  font-family: "JetBrains Mono", monospace;
  font-size: 0.6rem;
  width: 1.6rem;
  text-align: right;
}

.slider-value {
  color: #00f5d4;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.6rem;
  width: 2.8rem;
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
  gap: 0.4rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.control-btn {
  padding: 0.4rem 0.7rem;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.5);
  font-family: "Orbitron", sans-serif;
  font-size: 0.55rem;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.3s;
  pointer-events: auto;
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
  .hud {top:3rem;right:0.6rem;font-size: 0.55rem;}
  .hud .preset-name { font-size: 0.7rem; }
  .hud .param-line { font-size: 0.5rem; }
  .controls { bottom: 0.8rem; right: 0.8rem; gap: 0.4rem; }
  .control-btn { padding: 0.3rem 0.5rem; font-size: 0.45rem; }
  input[type="range"] { width: 70px; }
  .slider-label, .slider-value { font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info">SUPERFORMULA</div>

<div class="ui hud">
  <div class="preset-name" id="presetName">CIRCLE</div>
  <div class="param-line" id="paramM">m: 0</div>
  <div class="param-line" id="paramN1">n1: 1</div>
  <div class="param-line" id="paramN2">n2: 1</div>
  <div class="param-line" id="paramN3">n3: 1</div>
  <div class="shape-count" id="shapeCount"></div>
</div>

<div class="ui controls">
  <div class="slider-group">
    <span class="slider-label">m</span>
    <input type="range" id="sliderM" min="1" max="20" step="0.1" value="1">
    <span class="slider-value" id="valueM">1.0</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">n1</span>
    <input type="range" id="sliderN1" min="0.1" max="40" step="0.1" value="1">
    <span class="slider-value" id="valueN1">1.0</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">n2</span>
    <input type="range" id="sliderN2" min="0.1" max="20" step="0.1" value="1">
    <span class="slider-value" id="valueN2">1.0</span>
  </div>
  <div class="slider-group">
    <span class="slider-label">n3</span>
    <input type="range" id="sliderN3" min="0.1" max="20" step="0.1" value="1">
    <span class="slider-value" id="valueN3">1.0</span>
  </div>
  <div class="btn-row" id="presetBtns"></div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO</button>
    <button class="control-btn" id="clearBtn">CLEAR</button>
  </div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'next'],
  ['type' => 'drag',  'label' => 'rotate'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<?php require_once __DIR__ . '/includes/colors.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');

  var width, height;

  // Presets: [name, m, n1, n2, n3, a, b]
  var presets = [
    { name: 'CIRCLE',    m: 4,   n1: 2,   n2: 2,   n3: 2,   a: 1, b: 1 },
    { name: 'FLOWER',    m: 7,   n1: 0.5, n2: 0.5, n3: 0.5, a: 1, b: 1 },
    { name: 'STARFISH',  m: 5,   n1: 0.3, n2: 0.3, n3: 0.3, a: 1, b: 1 },
    { name: 'LEAF',      m: 2,   n1: 1,   n2: 10,  n3: 10,  a: 1, b: 1 },
    { name: 'SHELL',     m: 3,   n1: 5,   n2: 18,  n3: 18,  a: 1, b: 1 },
    { name: 'CRYSTAL',   m: 4,   n1: 30,  n2: 15,  n3: 15,  a: 1, b: 1 },
    { name: 'CROSS',     m: 4,   n1: 10,  n2: 10,  n3: 10,  a: 1, b: 1 },
    { name: 'TRIANGLE',  m: 3,   n1: 5,   n2: 5,   n3: 5,   a: 1, b: 1 },
    { name: 'SQUARE',    m: 4,   n1: 100, n2: 100, n3: 100, a: 1, b: 1 },
    { name: 'ASTEROID',  m: 6,   n1: 0.3, n2: 0.3, n3: 0.3, a: 1, b: 1 },
    { name: 'CLOVER',    m: 8,   n1: 0.5, n2: 1.5, n3: 1.5, a: 1, b: 1 },
    { name: 'ORGANIC',   m: 5,   n1: 2,   n2: 7,   n3: 7,   a: 1, b: 1 },
  ];

  var presetIndex = 0;
  var autoMode = false;
  var autoTimer = 0;
  var autoInterval = 3000;

  // Current shape parameters (animated)
  var current = { m: 4, n1: 2, n2: 2, n3: 2, a: 1, b: 1 };
  var target  = { m: 4, n1: 2, n2: 2, n3: 2, a: 1, b: 1 };
  var morphing = false;
  var morphT = 0;
  var morphFrom = { m: 4, n1: 2, n2: 2, n3: 2, a: 1, b: 1 };

  // All spawned shapes
  var shapes = [];
  var maxShapes = 20;

  // Rotation from drag
  var globalRotation = 0;
  var dragging = false;
  var lastDragX = 0;

  // References
  var presetNameEl = document.getElementById('presetName');
  var paramMEl = document.getElementById('paramM');
  var paramN1El = document.getElementById('paramN1');
  var paramN2El = document.getElementById('paramN2');
  var paramN3El = document.getElementById('paramN3');
  var shapeCountEl = document.getElementById('shapeCount');
  var sliderM = document.getElementById('sliderM');
  var sliderN1 = document.getElementById('sliderN1');
  var sliderN2 = document.getElementById('sliderN2');
  var sliderN3 = document.getElementById('sliderN3');
  var valueM = document.getElementById('valueM');
  var valueN1 = document.getElementById('valueN1');
  var valueN2 = document.getElementById('valueN2');
  var valueN3 = document.getElementById('valueN3');
  var presetBtnsEl = document.getElementById('presetBtns');
  var autoBtn = document.getElementById('autoBtn');
  var clearBtn = document.getElementById('clearBtn');

  // Build preset buttons
  presets.forEach(function(p, i) {
    var btn = document.createElement('button');
    btn.className = 'control-btn' + (i === 0 ? ' active' : '');
    btn.textContent = p.name.slice(0, 4);
    btn.title = p.name;
    btn.setAttribute('data-idx', i);
    btn.addEventListener('click', function() {
      selectPreset(i);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    });
    presetBtnsEl.appendChild(btn);
  });

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
  }

  // Superformula r(theta)
  function superformula(theta, m, n1, n2, n3, a, b) {
    var t1 = Math.cos(m * theta / 4) / a;
    var t2 = Math.sin(m * theta / 4) / b;
    var r = Math.pow(Math.pow(Math.abs(t1), n2) + Math.pow(Math.abs(t2), n3), -1 / n1);
    if (!isFinite(r)) r = 0;
    return r;
  }

  // Compute shape points
  function computeShape(params, steps, scale) {
    var pts = [];
    for (var i = 0; i <= steps; i++) {
      var theta = (i / steps) * 2 * Math.PI;
      var r = superformula(theta, params.m, params.n1, params.n2, params.n3, params.a, params.b);
      pts.push({ x: r * Math.cos(theta) * scale, y: r * Math.sin(theta) * scale });
    }
    return pts;
  }

  // Easing
  function easeInOutCubic(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  }

  // Lerp single value
  function lerp(a, b, t) {
    return a + (b - a) * t;
  }

  function selectPreset(idx) {
    presetIndex = idx;
    var p = presets[idx];
    morphFrom.m = current.m; morphFrom.n1 = current.n1;
    morphFrom.n2 = current.n2; morphFrom.n3 = current.n3;
    morphFrom.a = current.a; morphFrom.b = current.b;
    target.m = p.m; target.n1 = p.n1; target.n2 = p.n2;
    target.n3 = p.n3; target.a = p.a; target.b = p.b;
    morphT = 0;
    morphing = true;
    presetNameEl.textContent = p.name;

    // Update slider values to target
    sliderM.value = p.m;
    sliderN1.value = p.n1;
    sliderN2.value = p.n2;
    sliderN3.value = p.n3;

    // Update button states
    var btns = presetBtnsEl.children;
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', i === idx);
    }
  }

  function spawnShape() {
    if (shapes.length >= maxShapes) {
      shapes.shift();
    }
    shapes.push({
      m: current.m, n1: current.n1, n2: current.n2, n3: current.n3,
      a: current.a, b: current.b,
      birth: Date.now(),
      life: 6000 + Math.random() * 4000,
      x: width / 2 + (Math.random() - 0.5) * width * 0.3,
      y: height / 2 + (Math.random() - 0.5) * height * 0.3,
      scale: 0.3 + Math.random() * 0.4,
      rotation: Math.random() * Math.PI * 2,
      hueShift: Math.random() * 40,
    });
    updateShapeCount();
  }

  function updateShapeCount() {
    shapeCountEl.textContent = shapes.length > 0 ? (shapes.length + ' shapes') : '';
  }

  function updateHUD() {
    paramMEl.textContent = 'm: ' + current.m.toFixed(1);
    paramN1El.textContent = 'n1: ' + current.n1.toFixed(1);
    paramN2El.textContent = 'n2: ' + current.n2.toFixed(1);
    paramN3El.textContent = 'n3: ' + current.n3.toFixed(1);
    valueM.textContent = current.m.toFixed(1);
    valueN1.textContent = current.n1.toFixed(1);
    valueN2.textContent = current.n2.toFixed(1);
    valueN3.textContent = current.n3.toFixed(1);
  }

  // Draw a single shape with glow
  function drawShape(params, cx, cy, scale, rotation, alpha, hueBase) {
    var steps = 360;
    var pts = computeShape(params, steps, scale);

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(rotation);

    // Glow layers
    for (var g = 2; g >= 0; g--) {
      var glowAlpha = alpha * (g === 0 ? 0.9 : g === 1 ? 0.3 : 0.1);
      var lw = g === 0 ? 2 : g === 1 ? 5 : 10;
      var hue = hueBase + 165;
      if (hue > 205) hue = 205;

      ctx.beginPath();
      for (var i = 0; i <= steps; i++) {
        if (i === 0) {
          ctx.moveTo(pts[i].x, pts[i].y);
        } else {
          ctx.lineTo(pts[i].x, pts[i].y);
        }
      }
      ctx.closePath();

      if (g === 0) {
        // Main stroke with gradient along the curve
        ctx.strokeStyle = 'hsla(' + hue + ', 90%, 65%, ' + glowAlpha + ')';
      } else {
        ctx.strokeStyle = 'hsla(' + hue + ', 80%, 50%, ' + glowAlpha + ')';
      }
      ctx.lineWidth = lw;
      ctx.lineJoin = 'round';
      ctx.stroke();
    }

    // Inner fill glow
    ctx.beginPath();
    for (var i = 0; i <= steps; i++) {
      if (i === 0) ctx.moveTo(pts[i].x, pts[i].y);
      else ctx.lineTo(pts[i].x, pts[i].y);
    }
    ctx.closePath();
    var grad = ctx.createRadialGradient(0, 0, 0, 0, 0, scale);
    grad.addColorStop(0, 'hsla(' + (hueBase + 175) + ', 80%, 60%, ' + (alpha * 0.08) + ')');
    grad.addColorStop(1, 'hsla(' + (hueBase + 270) + ', 60%, 40%, 0)');
    ctx.fillStyle = grad;
    ctx.fill();

    ctx.restore();
  }

  function draw() {
    // Clear
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    var now = Date.now();

    // Update morph
    if (morphing) {
      morphT += 0.02;
      if (morphT >= 1) {
        morphT = 1;
        morphing = false;
      }
      var ease = easeInOutCubic(morphT);
      current.m = lerp(morphFrom.m, target.m, ease);
      current.n1 = lerp(morphFrom.n1, target.n1, ease);
      current.n2 = lerp(morphFrom.n2, target.n2, ease);
      current.n3 = lerp(morphFrom.n3, target.n3, ease);
      current.a = lerp(morphFrom.a, target.a, ease);
      current.b = lerp(morphFrom.b, target.b, ease);
    }

    // Auto mode
    if (autoMode) {
      autoTimer += 16;
      if (autoTimer >= autoInterval) {
        autoTimer = 0;
        var nextIdx = (presetIndex + 1) % presets.length;
        selectPreset(nextIdx);
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }

    // Main shape scale
    var minDim = Math.min(width, height);
    var mainScale = minDim * 0.28;

    // Draw spawned shapes (background)
    var alive = [];
    for (var i = 0; i < shapes.length; i++) {
      var s = shapes[i];
      var age = now - s.birth;
      if (age > s.life) continue;
      var fadeIn = Math.min(age / 500, 1);
      var fadeOut = age > s.life - 1000 ? (s.life - age) / 1000 : 1;
      var alpha = fadeIn * fadeOut * 0.35;
      drawShape(
        { m: s.m, n1: s.n1, n2: s.n2, n3: s.n3, a: s.a, b: s.b },
        s.x, s.y, mainScale * s.scale, s.rotation + age * 0.0001,
        alpha, s.hueShift
      );
      alive.push(s);
    }
    if (shapes.length !== alive.length) updateShapeCount();
    shapes = alive;

    // Draw main shape (center)
    var pulse = 1 + Math.sin(now * 0.001) * 0.02;
    drawShape(
      current,
      width / 2, height / 2,
      mainScale * pulse,
      globalRotation + now * 0.0002,
      1, 0
    );

    // Draw secondary purple accent outline
    drawShapeAccent(
      current,
      width / 2, height / 2,
      mainScale * pulse * 1.03,
      globalRotation + now * 0.0002 + 0.02,
      0.15
    );

    updateHUD();

    requestAnimationFrame(draw);
  }

  // Purple accent outline
  function drawShapeAccent(params, cx, cy, scale, rotation, alpha) {
    var steps = 360;
    var pts = computeShape(params, steps, scale);

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(rotation);
    ctx.beginPath();
    for (var i = 0; i <= steps; i++) {
      if (i === 0) ctx.moveTo(pts[i].x, pts[i].y);
      else ctx.lineTo(pts[i].x, pts[i].y);
    }
    ctx.closePath();
    ctx.strokeStyle = 'hsla(275, 70%, 50%, ' + alpha + ')';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    ctx.restore();
  }

  // Events: sliders
  function onSliderInput() {
    var m = parseFloat(sliderM.value);
    var n1 = parseFloat(sliderN1.value);
    var n2 = parseFloat(sliderN2.value);
    var n3 = parseFloat(sliderN3.value);
    current.m = m; current.n1 = n1; current.n2 = n2; current.n3 = n3;
    target.m = m; target.n1 = n1; target.n2 = n2; target.n3 = n3;
    morphing = false;
    presetNameEl.textContent = 'CUSTOM';
    // Deactivate preset buttons
    var btns = presetBtnsEl.children;
    for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
    if (typeof SFX !== 'undefined') SFX.play('click', 50);
  }

  sliderM.addEventListener('input', onSliderInput);
  sliderN1.addEventListener('input', onSliderInput);
  sliderN2.addEventListener('input', onSliderInput);
  sliderN3.addEventListener('input', onSliderInput);

  // Auto button
  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    autoBtn.classList.toggle('active', autoMode);
    autoTimer = 0;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  // Clear button
  clearBtn.addEventListener('click', function() {
    shapes = [];
    updateShapeCount();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Click on canvas: spawn + cycle preset
  canvas.addEventListener('click', function(e) {
    // Avoid controls area (bottom-right) and hud (top-left)
    if (e.clientX > width - 220 && e.clientY > height - 300) return;
    if (e.clientX < 220 && e.clientY < 150) return;

    spawnShape();
    var nextIdx = (presetIndex + 1) % presets.length;
    selectPreset(nextIdx);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  // Right-click: clear all
  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    shapes = [];
    updateShapeCount();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Drag to rotate
  canvas.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    dragging = true;
    lastDragX = e.clientX;
  });

  window.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var dx = e.clientX - lastDragX;
    globalRotation += dx * 0.005;
    lastDragX = e.clientX;
  });

  window.addEventListener('mouseup', function() {
    dragging = false;
  });

  // Touch events
  var touchStartX = 0;
  var touchMoved = false;

  canvas.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      touchStartX = e.touches[0].clientX;
      touchMoved = false;
    }
  }, { passive: true });

  canvas.addEventListener('touchmove', function(e) {
    if (e.touches.length === 1) {
      var dx = e.touches[0].clientX - touchStartX;
      if (Math.abs(dx) > 5) touchMoved = true;
      globalRotation += dx * 0.005;
      touchStartX = e.touches[0].clientX;
    }
  }, { passive: true });

  canvas.addEventListener('touchend', function(e) {
    if (!touchMoved) {
      // Treat as tap = click
      var touch = e.changedTouches[0];
      if (touch.clientX > width - 220 && touch.clientY > height - 300) return;
      if (touch.clientX < 220 && touch.clientY < 150) return;
      spawnShape();
      var nextIdx = (presetIndex + 1) % presets.length;
      selectPreset(nextIdx);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  window.addEventListener('resize', resize);

  // Init
  resize();
  selectPreset(0);
  draw();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
