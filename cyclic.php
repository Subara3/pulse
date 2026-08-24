<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cyclic');

$pageName = 'cyclic';
$pageTitle = 'CYCLIC - PULSE';
$pageDesc = '巡回セルオートマトン。N個の状態が環状に捕食し合い、ランダムから自発的にスパイラル波が生まれる。';
$pageCanonical = 'https://pulse.subara3.com/cyclic.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  STATES <span class="v" id="hud-n">14</span><br>
  THRESH <span class="v" id="hud-t">1</span><br>
  STEP <span class="v" id="hud-step">0</span>
</div>

<div id="info">
  <span class="v">Cyclic CA</span><br>
  N-state &middot; Moore &middot; spiral waves
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'disturb'],
  ['type' => 'drag', 'label' => 'paint'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">STATES</span>
      <input type="range" id="sl-n" min="4" max="20" value="14">
      <span class="slider-value" id="sv-n">14</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">THRESH</span>
      <input type="range" id="sl-t" min="1" max="5" value="1">
      <span class="slider-value" id="sv-t">1</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="30" value="10">
      <span class="slider-value" id="sv-speed">10</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SCALE</span>
      <input type="range" id="sl-scale" min="2" max="8" value="3">
      <span class="slider-value" id="sv-scale">3</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
    <button class="control-btn active" id="btn-run">RUN</button>
    <button class="control-btn" id="btn-step">STEP</button>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-n="6" data-t="1">N6 T1 — turbulence</button>
    <button class="preset-btn active" data-n="14" data-t="1">N14 T1 — spirals</button>
    <button class="preset-btn" data-n="14" data-t="3">N14 T3 — crystal</button>
    <button class="preset-btn" data-n="20" data-t="2">N20 T2 — fine</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function() {
'use strict';

var c = document.getElementById('canvas');
var ctx = c.getContext('2d');
var W, H, dpr;
var cellSize = 3;
var cols, rows;
var grid, next;
var N = 14;
var T = 1;
var speed = 10;
var running = true;
var stepCount = 0;
var imgData;

var hudN = document.getElementById('hud-n');
var hudT = document.getElementById('hud-t');
var hudStep = document.getElementById('hud-step');
var slN = document.getElementById('sl-n');
var slT = document.getElementById('sl-t');
var slSpeed = document.getElementById('sl-speed');
var slScale = document.getElementById('sl-scale');
var svN = document.getElementById('sv-n');
var svT = document.getElementById('sv-t');
var svSpeed = document.getElementById('sv-speed');
var svScale = document.getElementById('sv-scale');
var btnReset = document.getElementById('btn-reset');
var btnRun = document.getElementById('btn-run');
var btnStep = document.getElementById('btn-step');

var palette = [];

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function buildPalette() {
  palette = [];
  for (var i = 0; i < N; i++) {
    var t = i / N;
    var h = brandHue(t);
    var s = 72 + 10 * Math.sin(t * Math.PI);
    var l = 42 + 18 * Math.sin(t * Math.PI * 2);
    var rgb = P.hsl(h, s, l);
    palette.push(rgb);
  }
}

function resize() {
  dpr = Math.min(2, window.devicePixelRatio || 1);
  W = Math.floor(window.innerWidth * dpr);
  H = Math.floor(window.innerHeight * dpr);
  c.width = W;
  c.height = H;
  c.style.width = window.innerWidth + 'px';
  c.style.height = window.innerHeight + 'px';
  cols = Math.floor(W / cellSize);
  rows = Math.floor(H / cellSize);
  initGrid();
}

function initGrid() {
  grid = new Uint8Array(cols * rows);
  next = new Uint8Array(cols * rows);
  for (var i = 0; i < grid.length; i++) {
    grid[i] = Math.floor(Math.random() * N);
  }
  stepCount = 0;
  hudStep.textContent = '0';
  imgData = ctx.createImageData(cols, rows);
}

function step() {
  for (var y = 0; y < rows; y++) {
    for (var x = 0; x < cols; x++) {
      var idx = y * cols + x;
      var s = grid[idx];
      var succ = (s + 1) % N;
      var count = 0;
      for (var dy = -1; dy <= 1; dy++) {
        for (var dx = -1; dx <= 1; dx++) {
          if (dx === 0 && dy === 0) continue;
          var nx = (x + dx + cols) % cols;
          var ny = (y + dy + rows) % rows;
          if (grid[ny * cols + nx] === succ) count++;
        }
      }
      next[idx] = count >= T ? succ : s;
    }
  }
  var tmp = grid;
  grid = next;
  next = tmp;
  stepCount++;
}

function draw() {
  var data = imgData.data;
  for (var i = 0, len = cols * rows; i < len; i++) {
    var rgb = palette[grid[i]];
    var j = i << 2;
    data[j]     = rgb[0];
    data[j + 1] = rgb[1];
    data[j + 2] = rgb[2];
    data[j + 3] = 255;
  }
  ctx.putImageData(imgData, 0, 0);
  if (cellSize > 1) {
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(c, 0, 0, cols, rows, 0, 0, W, H);
  }
  hudStep.textContent = stepCount;
}

function drawScaled() {
  var data = imgData.data;
  for (var i = 0, len = cols * rows; i < len; i++) {
    var rgb = palette[grid[i]];
    var j = i << 2;
    data[j]     = rgb[0];
    data[j + 1] = rgb[1];
    data[j + 2] = rgb[2];
    data[j + 3] = 255;
  }

  var offscreen = document.createElement('canvas');
  offscreen.width = cols;
  offscreen.height = rows;
  var offCtx = offscreen.getContext('2d');
  offCtx.putImageData(imgData, 0, 0);

  ctx.imageSmoothingEnabled = false;
  ctx.clearRect(0, 0, W, H);
  ctx.drawImage(offscreen, 0, 0, cols, rows, 0, 0, W, H);

  hudStep.textContent = stepCount;
}

var frameCount = 0;
var animId;

function loop() {
  animId = requestAnimationFrame(loop);
  if (running) {
    for (var i = 0; i < speed; i++) {
      step();
    }
  }
  drawScaled();
}

function disturb(px, py, radius) {
  var gx = Math.floor(px * dpr / cellSize);
  var gy = Math.floor(py * dpr / cellSize);
  var r = radius || 5;
  var state = Math.floor(Math.random() * N);
  for (var dy = -r; dy <= r; dy++) {
    for (var dx = -r; dx <= r; dx++) {
      if (dx * dx + dy * dy > r * r) continue;
      var nx = ((gx + dx) % cols + cols) % cols;
      var ny = ((gy + dy) % rows + rows) % rows;
      grid[ny * cols + nx] = Math.floor(Math.random() * N);
    }
  }
}

var isDown = false;

c.addEventListener('mousedown', function(e) {
  if (e.button === 2) return;
  isDown = true;
  disturb(e.clientX, e.clientY, 8);
  if (window.SFX) SFX.play('spawn');
});

c.addEventListener('mousemove', function(e) {
  if (!isDown) return;
  disturb(e.clientX, e.clientY, 5);
  if (window.SFX) SFX.play('move', 80);
});

c.addEventListener('mouseup', function() { isDown = false; });
c.addEventListener('mouseleave', function() { isDown = false; });

c.addEventListener('touchstart', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    disturb(e.touches[i].clientX, e.touches[i].clientY, 8);
  }
  if (window.SFX) SFX.play('spawn');
}, { passive: false });

c.addEventListener('touchmove', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    disturb(e.touches[i].clientX, e.touches[i].clientY, 5);
  }
  if (window.SFX) SFX.play('move', 80);
}, { passive: false });

c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

slN.addEventListener('input', function() {
  N = parseInt(this.value);
  svN.textContent = N;
  hudN.textContent = N;
  buildPalette();
  initGrid();
  if (window.SFX) SFX.play('toggle');
});

slT.addEventListener('input', function() {
  T = parseInt(this.value);
  svT.textContent = T;
  hudT.textContent = T;
  if (window.SFX) SFX.play('toggle');
});

slSpeed.addEventListener('input', function() {
  speed = parseInt(this.value);
  svSpeed.textContent = speed;
});

slScale.addEventListener('input', function() {
  cellSize = parseInt(this.value);
  svScale.textContent = cellSize;
  cols = Math.floor(W / cellSize);
  rows = Math.floor(H / cellSize);
  initGrid();
  if (window.SFX) SFX.play('toggle');
});

btnReset.addEventListener('click', function() {
  initGrid();
  if (window.SFX) SFX.play('click');
});

btnRun.addEventListener('click', function() {
  running = !running;
  this.textContent = running ? 'RUN' : 'PAUSE';
  this.classList.toggle('active', running);
  if (window.SFX) SFX.play('toggle');
});

btnStep.addEventListener('click', function() {
  if (!running) {
    step();
    drawScaled();
  }
  if (window.SFX) SFX.play('tick');
});

var presetBtns = document.querySelectorAll('.preset-btn');
presetBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    presetBtns.forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    N = parseInt(this.dataset.n);
    T = parseInt(this.dataset.t);
    slN.value = N;
    slT.value = T;
    svN.textContent = N;
    svT.textContent = T;
    hudN.textContent = N;
    hudT.textContent = T;
    buildPalette();
    initGrid();
    if (window.SFX) SFX.play('click');
  });
});

window.addEventListener('resize', function() {
  dpr = Math.min(2, window.devicePixelRatio || 1);
  W = Math.floor(window.innerWidth * dpr);
  H = Math.floor(window.innerHeight * dpr);
  c.width = W;
  c.height = H;
  c.style.width = window.innerWidth + 'px';
  c.style.height = window.innerHeight + 'px';
  cols = Math.floor(W / cellSize);
  rows = Math.floor(H / cellSize);
  initGrid();
});

buildPalette();
resize();
loop();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
