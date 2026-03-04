<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('cyclicca');

$pageName = 'cyclicca';
$pageTitle = 'CYCLIC CA - PULSE';
$pageDesc = '循環セルオートマトン。状態が追いかけ合い、発光する渦と波面が場を満たす。';
$pageCanonical = 'https://pulse.subara3.com/cyclicca.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 10; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; letter-spacing: 0.05em; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.36); font-size: 0.62rem; line-height: 1.75; text-align: right; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1rem; bottom: 1rem; color: rgba(255,255,255,0.32); font-size: 0.56rem; line-height: 1.7; max-width: min(72vw, 540px); }
@media (max-width: 768px) {
  .hud { top: 0.75rem; right: 0.75rem; font-size: 0.54rem; }
  .info { left: 0.75rem; bottom: 0.75rem; font-size: 0.5rem; max-width: 78vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  STATES: <span class="v" id="hudStates">12</span><br>
  THRESH: <span class="v" id="hudThresh">2</span><br>
  SPEED: <span class="v" id="hudSpeed">1.0x</span>
</div>

<div class="ui info">
  TAP: SEED BURST<br>
  DRAG: PAINT STATES<br>
  RIGHT-CLICK: CLEAR FIELD<br>
  CYCLIC AUTOMATON CHASES NEXT STATE AND FORMS SPIRALS
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>STATES</label>
    <input type="range" id="statesSlider" min="6" max="24" value="12">
  </div>
  <div class="slider-wrap">
    <label>THRESHOLD</label>
    <input type="range" id="threshSlider" min="1" max="4" value="2">
  </div>
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="4" value="2">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="randomBtn">RANDOMIZE</button>
    <button class="control-btn" id="pulseBtn">PULSE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'clear']
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/drawer-controls.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudStates = document.getElementById('hudStates');
  var hudThresh = document.getElementById('hudThresh');
  var hudSpeed = document.getElementById('hudSpeed');

  var statesSlider = document.getElementById('statesSlider');
  var threshSlider = document.getElementById('threshSlider');
  var speedSlider = document.getElementById('speedSlider');
  var randomBtn = document.getElementById('randomBtn');
  var pulseBtn = document.getElementById('pulseBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0, DPR = 1;
  var cols = 0, rows = 0, cell = 5;
  var grid = null;
  var next = null;
  var img = null;
  var off = null;
  var offCtx = null;
  var time = 0;
  var stepAccumulator = 0;
  var dragging = false;
  var pointerId = null;

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2;
    var r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; }
    else if (h < 120) { r = x; g = c; }
    else if (h < 180) { g = c; b = x; }
    else if (h < 240) { g = x; b = c; }
    else if (h < 300) { r = x; b = c; }
    else { r = c; b = x; }
    return [
      Math.round((r + m) * 255),
      Math.round((g + m) * 255),
      Math.round((b + m) * 255)
    ];
  }

  function paletteAt(t) {
    var h = 175 + 100 * t;
    if (h > 295) h = 295;
    return hslToRgb(h, 74, 56);
  }

  function idx(x, y) {
    if (x < 0) x += cols;
    if (x >= cols) x -= cols;
    if (y < 0) y += rows;
    if (y >= rows) y -= rows;
    return y * cols + x;
  }

  function resize() {
    DPR = Math.min(2, window.devicePixelRatio || 1);
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

    cell = W < 768 ? 7 : 6;
    cols = Math.max(40, Math.floor(W / cell));
    rows = Math.max(60, Math.floor(H / cell));
    grid = new Uint16Array(cols * rows);
    next = new Uint16Array(cols * rows);
    img = ctx.createImageData(cols, rows);
    off = document.createElement('canvas');
    off.width = cols;
    off.height = rows;
    offCtx = off.getContext('2d');
    seedInitial();
  }

  function seedInitial() {
    var states = parseInt(statesSlider.value, 10);
    for (var i = 0; i < grid.length; i++) {
      var n = Math.random();
      if (n < 0.14) grid[i] = (Math.random() * states) | 0;
      else grid[i] = 0;
    }
    for (var b = 0; b < 12; b++) {
      seedBurst(Math.random() * W, Math.random() * H, true);
    }
  }

  function seedBurst(x, y, quiet) {
    var states = parseInt(statesSlider.value, 10);
    var cx = Math.floor(x / cell);
    var cy = Math.floor(y / cell);
    var radius = W < 768 ? 6 : 7;
    for (var dy = -radius; dy <= radius; dy++) {
      for (var dx = -radius; dx <= radius; dx++) {
        var d2 = dx * dx + dy * dy;
        if (d2 > radius * radius) continue;
        var p = idx(cx + dx, cy + dy);
        if (Math.random() < 0.82 - d2 / (radius * radius * 1.25)) {
          grid[p] = (Math.random() * states) | 0;
        }
      }
    }
    if (!quiet && typeof SFX !== 'undefined') SFX.play('spawn', 45);
  }

  function paint(x, y) {
    var states = parseInt(statesSlider.value, 10);
    var cx = Math.floor(x / cell);
    var cy = Math.floor(y / cell);
    var brush = W < 768 ? 3 : 4;
    var base = ((time * 0.002) | 0) % states;
    for (var dy = -brush; dy <= brush; dy++) {
      for (var dx = -brush; dx <= brush; dx++) {
        if (dx * dx + dy * dy > brush * brush) continue;
        var p = idx(cx + dx, cy + dy);
        grid[p] = (base + ((Math.random() * 3) | 0)) % states;
      }
    }
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  }

  function step() {
    var states = parseInt(statesSlider.value, 10);
    var th = parseInt(threshSlider.value, 10);

    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        var p = y * cols + x;
        var cur = grid[p];
        var target = (cur + 1) % states;
        var count = 0;

        for (var oy = -1; oy <= 1; oy++) {
          for (var ox = -1; ox <= 1; ox++) {
            if (ox === 0 && oy === 0) continue;
            if (grid[idx(x + ox, y + oy)] === target) count++;
          }
        }

        if (count >= th) next[p] = target;
        else next[p] = cur;
      }
    }

    var t = grid;
    grid = next;
    next = t;
  }

  function render() {
    var data = img.data;
    var states = parseInt(statesSlider.value, 10);

    for (var i = 0; i < grid.length; i++) {
      var s = grid[i] / Math.max(1, states - 1);
      var rgb = paletteAt(s);
      var v = 0.78 + 0.22 * Math.sin(time * 0.001 + s * 7.2);
      data[i * 4 + 0] = clamp((rgb[0] * v) | 0, 0, 255);
      data[i * 4 + 1] = clamp((rgb[1] * v) | 0, 0, 255);
      data[i * 4 + 2] = clamp((rgb[2] * v) | 0, 0, 255);
      data[i * 4 + 3] = 255;
    }

    offCtx.putImageData(img, 0, 0);

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(off, 0, 0, cols, rows, 0, 0, W, H);

    var grad = ctx.createRadialGradient(W * 0.5, H * 0.5, 0, W * 0.5, H * 0.5, Math.max(W, H) * 0.75);
    grad.addColorStop(0, 'rgba(0,245,212,0.05)');
    grad.addColorStop(0.6, 'rgba(123,44,191,0.08)');
    grad.addColorStop(1, 'rgba(10,10,15,0.35)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, W, H);
  }

  function updateHud() {
    hudStates.textContent = statesSlider.value;
    hudThresh.textContent = threshSlider.value;
    var speedMap = [0.7, 1.0, 1.6, 2.3];
    hudSpeed.textContent = speedMap[parseInt(speedSlider.value, 10) - 1].toFixed(1) + 'x';
  }

  function frame(now) {
    if (!frame.prev) frame.prev = now;
    var dt = Math.min(50, now - frame.prev);
    frame.prev = now;
    time += dt;

    var speedMap = [0.6, 1.0, 1.7, 2.4];
    var mul = speedMap[parseInt(speedSlider.value, 10) - 1];
    stepAccumulator += dt * mul;
    while (stepAccumulator > 34) {
      step();
      stepAccumulator -= 34;
    }

    render();
    updateHud();
    requestAnimationFrame(frame);
  }

  function down(e) {
    dragging = true;
    pointerId = e.pointerId;
    canvas.setPointerCapture(e.pointerId);
    seedBurst(e.clientX, e.clientY, false);
  }

  function move(e) {
    if (!dragging || e.pointerId !== pointerId) return;
    paint(e.clientX, e.clientY);
  }

  function up(e) {
    if (e.pointerId !== pointerId) return;
    dragging = false;
    pointerId = null;
  }

  canvas.addEventListener('click', function(e) {
    seedBurst(e.clientX, e.clientY, false);
  });
  canvas.addEventListener('pointerdown', down);
  canvas.addEventListener('pointermove', move);
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);
  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    for (var i = 0; i < grid.length; i++) grid[i] = 0;
    if (typeof SFX !== 'undefined') SFX.play('pop', 80);
  });

  statesSlider.addEventListener('input', function() {
    seedInitial();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  threshSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 90);
  });
  speedSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 90);
  });

  randomBtn.addEventListener('click', function() {
    seedInitial();
    if (typeof SFX !== 'undefined') SFX.play('ripple', 70);
  });

  pulseBtn.addEventListener('click', function() {
    for (var i = 0; i < 6; i++) {
      seedBurst(Math.random() * W, Math.random() * H, true);
    }
    if (typeof SFX !== 'undefined') SFX.play('grow');
  });

  resetBtn.addEventListener('click', function() {
    statesSlider.value = 12;
    threshSlider.value = 2;
    speedSlider.value = 2;
    seedInitial();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
