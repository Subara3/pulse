<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cml');

$pageName = 'cml';
$pageTitle = 'CML - PULSE';
$pageDesc = '結合写像格子。ロジスティック写像の格子が拡散結合し、時空間カオスとパターン選択が創発する。';
$pageCanonical = 'https://pulse.subara3.com/cml.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>ITER <span id="hud-iter">0</span></div>
  <div>AVG <span id="hud-avg">0.00</span></div>
  <div>σ <span id="hud-sigma">0.00</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">r</label>
    <input type="range" id="sl-r" min="280" max="400" value="380" step="1">
    <span class="slider-value" id="sv-r">3.80</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">ε</label>
    <input type="range" id="sl-eps" min="0" max="100" value="10" step="1">
    <span class="slider-value" id="sv-eps">0.10</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="20" value="4" step="1">
    <span class="slider-value" id="sv-speed">4</span>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-r="380" data-eps="10">FROZEN</button>
    <button class="preset-btn" data-r="380" data-eps="40">PATTERN</button>
    <button class="preset-btn" data-r="400" data-eps="30">INTERMITTENT</button>
    <button class="preset-btn" data-r="360" data-eps="70">SYNC</button>
    <button class="preset-btn" data-r="400" data-eps="8">DEFECT</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-play">AUTO</button>
    <button class="ctrl-btn" id="btn-step">STEP</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, gw, gh, cellSize;
  var grid, next;
  var r = 3.80, eps = 0.10, speed = 4;
  var iter = 0, autoPlay = true;
  var pointerDown = false, erasing = false;

  var hudIter = document.getElementById('hud-iter');
  var hudAvg = document.getElementById('hud-avg');
  var hudSigma = document.getElementById('hud-sigma');

  var lut = new Uint8Array(256 * 3);
  buildLUT();

  function buildLUT() {
    for (var i = 0; i < 256; i++) {
      var t = i / 255;
      var h, s, l;
      if (t < 0.5) {
        var u = t * 2;
        h = 180 + u * 15;
        s = 60 + u * 20;
        l = 5 + u * 40;
      } else {
        var u = (t - 0.5) * 2;
        var e = u < 0.5 ? 0.5 * Math.pow(2 * u, 3.2) : 1 - 0.5 * Math.pow(2 * (1 - u), 3.2);
        h = 195 + e * 80;
        s = 80 - u * 10;
        l = 45 - u * 10;
      }
      var c = hslToRgb(h, s, l);
      lut[i * 3] = c[0];
      lut[i * 3 + 1] = c[1];
      lut[i * 3 + 2] = c[2];
    }
  }

  function hslToRgb(h, s, l) {
    h /= 360; s /= 100; l /= 100;
    var r2, g, b;
    if (s === 0) { r2 = g = b = l; }
    else {
      var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      var p = 2 * l - q;
      r2 = hue2rgb(p, q, h + 1/3);
      g = hue2rgb(p, q, h);
      b = hue2rgb(p, q, h - 1/3);
    }
    return [Math.round(r2 * 255), Math.round(g * 255), Math.round(b * 255)];
  }

  function hue2rgb(p, q, t) {
    if (t < 0) t += 1;
    if (t > 1) t -= 1;
    if (t < 1/6) return p + (q - p) * 6 * t;
    if (t < 1/2) return q;
    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
    return p;
  }

  function resize() {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    X.imageSmoothingEnabled = false;
    initGrid();
  }

  function initGrid() {
    cellSize = W < 600 ? 2 : 3;
    gw = Math.floor(W / cellSize);
    gh = Math.floor(H / cellSize);
    grid = new Float64Array(gw * gh);
    next = new Float64Array(gw * gh);
    randomize();
    iter = 0;
  }

  function randomize() {
    for (var i = 0; i < grid.length; i++) {
      grid[i] = Math.random();
    }
  }

  function logistic(x) {
    return r * x * (1 - x);
  }

  function step() {
    var e4 = eps / 4;
    var e1 = 1 - eps;
    for (var y = 0; y < gh; y++) {
      for (var x = 0; x < gw; x++) {
        var idx = y * gw + x;
        var fx = logistic(grid[idx]);
        var left  = logistic(grid[y * gw + ((x - 1 + gw) % gw)]);
        var right = logistic(grid[y * gw + ((x + 1) % gw)]);
        var up    = logistic(grid[((y - 1 + gh) % gh) * gw + x]);
        var down  = logistic(grid[((y + 1) % gh) * gw + x]);
        var val = e1 * fx + e4 * (left + right + up + down);
        if (val < 0) val = 0;
        if (val > 1) val = 1;
        next[idx] = val;
      }
    }
    var tmp = grid;
    grid = next;
    next = tmp;
    iter++;
  }

  function render() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);
    var imgData = X.createImageData(gw, gh);
    var d = imgData.data;
    var sum = 0, sum2 = 0;
    for (var i = 0; i < grid.length; i++) {
      var v = grid[i];
      sum += v;
      sum2 += v * v;
      var ci = Math.floor(v * 255);
      if (ci > 255) ci = 255;
      if (ci < 0) ci = 0;
      var off = i * 4;
      d[off]     = lut[ci * 3];
      d[off + 1] = lut[ci * 3 + 1];
      d[off + 2] = lut[ci * 3 + 2];
      d[off + 3] = 255;
    }
    var offCanvas = document.createElement('canvas');
    offCanvas.width = gw;
    offCanvas.height = gh;
    var offCtx = offCanvas.getContext('2d');
    offCtx.putImageData(imgData, 0, 0);
    X.imageSmoothingEnabled = false;
    X.drawImage(offCanvas, 0, 0, W, H);

    var n = grid.length;
    var avg = sum / n;
    var sigma = Math.sqrt(sum2 / n - avg * avg);
    hudIter.textContent = iter;
    hudAvg.textContent = avg.toFixed(3);
    hudSigma.textContent = sigma.toFixed(3);
  }

  function perturbAt(px, py, value) {
    var gx = Math.floor(px / cellSize);
    var gy = Math.floor(py / cellSize);
    var rad = W < 600 ? 4 : 6;
    for (var dy = -rad; dy <= rad; dy++) {
      for (var dx = -rad; dx <= rad; dx++) {
        if (dx * dx + dy * dy > rad * rad) continue;
        var cx = (gx + dx + gw) % gw;
        var cy = (gy + dy + gh) % gh;
        grid[cy * gw + cx] = value;
      }
    }
  }

  function getPos(e) {
    if (e.touches && e.touches.length > 0) {
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }
    return { x: e.clientX, y: e.clientY };
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    erasing = e.button === 2;
    pointerDown = true;
    var p = getPos(e);
    perturbAt(p.x, p.y, erasing ? 0 : 1);
    if (typeof SFX !== 'undefined') SFX.play(erasing ? 'pop' : 'spawn');
  });
  C.addEventListener('mousemove', function(e) {
    if (!pointerDown) return;
    var p = getPos(e);
    perturbAt(p.x, p.y, erasing ? 0 : 1);
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  });
  C.addEventListener('mouseup', function() { pointerDown = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    pointerDown = true;
    erasing = false;
    var p = getPos(e);
    perturbAt(p.x, p.y, 1);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!pointerDown) return;
    var p = getPos(e);
    perturbAt(p.x, p.y, 1);
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  }, { passive: false });
  C.addEventListener('touchend', function() { pointerDown = false; });

  var slR = document.getElementById('sl-r');
  var svR = document.getElementById('sv-r');
  slR.addEventListener('input', function() {
    r = parseInt(this.value) / 100;
    svR.textContent = r.toFixed(2);
  });

  var slEps = document.getElementById('sl-eps');
  var svEps = document.getElementById('sv-eps');
  slEps.addEventListener('input', function() {
    eps = parseInt(this.value) / 100;
    svEps.textContent = eps.toFixed(2);
  });

  var slSpeed = document.getElementById('sl-speed');
  var svSpeed = document.getElementById('sv-speed');
  slSpeed.addEventListener('input', function() {
    speed = parseInt(this.value);
    svSpeed.textContent = speed;
  });

  document.querySelectorAll('.preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var rv = parseInt(this.dataset.r);
      var ev = parseInt(this.dataset.eps);
      slR.value = rv; r = rv / 100; svR.textContent = r.toFixed(2);
      slEps.value = ev; eps = ev / 100; svEps.textContent = eps.toFixed(2);
      randomize();
      iter = 0;
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btn-play').addEventListener('click', function() {
    autoPlay = !autoPlay;
    this.classList.toggle('active', autoPlay);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-step').addEventListener('click', function() {
    step();
    render();
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    randomize();
    iter = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();

  var offscreen = null;
  function createOffscreen() {
    offscreen = document.createElement('canvas');
    offscreen.width = gw;
    offscreen.height = gh;
  }

  function renderFast() {
    if (!offscreen || offscreen.width !== gw || offscreen.height !== gh) {
      createOffscreen();
    }
    var offCtx = offscreen.getContext('2d');
    var imgData = offCtx.createImageData(gw, gh);
    var d = imgData.data;
    var sum = 0, sum2 = 0;
    for (var i = 0; i < grid.length; i++) {
      var v = grid[i];
      sum += v;
      sum2 += v * v;
      var ci = (v * 255) | 0;
      if (ci > 255) ci = 255;
      if (ci < 0) ci = 0;
      var off = i * 4;
      var li = ci * 3;
      d[off]     = lut[li];
      d[off + 1] = lut[li + 1];
      d[off + 2] = lut[li + 2];
      d[off + 3] = 255;
    }
    offCtx.putImageData(imgData, 0, 0);
    X.imageSmoothingEnabled = false;
    X.drawImage(offscreen, 0, 0, W, H);

    var n = grid.length;
    var avg = sum / n;
    var sigma = Math.sqrt(Math.max(0, sum2 / n - avg * avg));
    hudIter.textContent = iter;
    hudAvg.textContent = avg.toFixed(3);
    hudSigma.textContent = sigma.toFixed(3);
  }

  function loop() {
    if (autoPlay) {
      for (var i = 0; i < speed; i++) step();
    }
    renderFast();
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
