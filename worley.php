<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('worley');

$pageName = 'worley';
$pageTitle = 'WORLEY - PULSE';
$pageDesc = 'セルラーノイズ。ランダムな母点からの距離が有機的なテクスチャを描き出す。';
$pageCanonical = 'https://pulse.subara3.com/worley.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  SEEDS: <span class="v" id="hud-seeds">0</span><br>
  FUNC: <span class="v" id="hud-func">F1</span><br>
  METRIC: <span class="v" id="hud-metric">EUCLID</span>
</div>

<div id="info">
  <span class="v">worley noise</span><br>
  cellular noise · F(x) = dist_n
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <span class="slider-label">FUNCTION</span>
    <div class="btn-row" id="func-btns">
      <button class="control-btn active" data-fn="0">F1</button>
      <button class="control-btn" data-fn="1">F2</button>
      <button class="control-btn" data-fn="2">F2-F1</button>
      <button class="control-btn" data-fn="3">F1×F2</button>
    </div>
  </div>
  <div>
    <span class="slider-label">METRIC</span>
    <div class="btn-row" id="metric-btns">
      <button class="control-btn active" data-mt="0">EUCLID</button>
      <button class="control-btn" data-mt="1">MANHATTAN</button>
      <button class="control-btn" data-mt="2">CHEBYSHEV</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="speed-slider" min="0" max="100" value="40">
      <span class="slider-value" id="speed-val">40</span>
    </div>
  </div>
  <div class="slider-row">
    <span class="slider-label">SEEDS</span>
    <span class="slider-value" id="seed-count">25</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');

  var hudSeeds = document.getElementById('hud-seeds');
  var hudFunc = document.getElementById('hud-func');
  var hudMetric = document.getElementById('hud-metric');
  var speedSlider = document.getElementById('speed-slider');
  var speedVal = document.getElementById('speed-val');
  var seedCountEl = document.getElementById('seed-count');
  var resetBtn = document.getElementById('reset-btn');
  var funcBtns = document.querySelectorAll('#func-btns .control-btn');
  var metricBtns = document.querySelectorAll('#metric-btns .control-btn');

  var isMobile = 'ontouchstart' in window || window.innerWidth <= 768;
  var RES = isMobile ? 5 : 4;
  var INIT_SEEDS = isMobile ? 18 : 25;
  var MAX_SEEDS = 80;
  var DRAG_RADIUS = 30;

  var W, H, rW, rH;
  var seeds = [];
  var funcMode = 0;
  var metricMode = 0;
  var FUNC_NAMES = ['F1', 'F2', 'F2-F1', 'F1×F2'];
  var METRIC_NAMES = ['EUCLID', 'MANHATTAN', 'CHEBYSHEV'];

  var dragging = -1;
  var dragStarted = false;

  var offCanvas = document.createElement('canvas');
  var offCtx = offCanvas.getContext('2d');

  // Spatial grid
  var GRID_SIZE = 0;
  var gridCols = 0, gridRows = 0;
  var grid = [];

  function buildGrid() {
    var maxDist = Math.max(W, H) * 0.6;
    GRID_SIZE = Math.max(80, maxDist / 6) | 0;
    gridCols = Math.ceil(W / GRID_SIZE);
    gridRows = Math.ceil(H / GRID_SIZE);
    grid = [];
    for (var i = 0; i < gridCols * gridRows; i++) grid[i] = [];
    for (var i = 0; i < seeds.length; i++) {
      var s = seeds[i];
      var gc = (s.x / GRID_SIZE) | 0;
      var gr = (s.y / GRID_SIZE) | 0;
      gc = Math.max(0, Math.min(gridCols - 1, gc));
      gr = Math.max(0, Math.min(gridRows - 1, gr));
      grid[gr * gridCols + gc].push(i);
    }
  }

  function findNearestTwo(px, py) {
    var gc = (px / GRID_SIZE) | 0;
    var gr = (py / GRID_SIZE) | 0;
    gc = Math.max(0, Math.min(gridCols - 1, gc));
    gr = Math.max(0, Math.min(gridRows - 1, gr));

    var d1 = Infinity, d2 = Infinity;
    var searchR = 2;

    for (var attempt = 0; attempt < 2; attempt++) {
      d1 = Infinity; d2 = Infinity;
      var r0 = Math.max(0, gr - searchR);
      var r1 = Math.min(gridRows - 1, gr + searchR);
      var c0 = Math.max(0, gc - searchR);
      var c1 = Math.min(gridCols - 1, gc + searchR);

      for (var row = r0; row <= r1; row++) {
        for (var col = c0; col <= c1; col++) {
          var cell = grid[row * gridCols + col];
          for (var k = 0; k < cell.length; k++) {
            var s = seeds[cell[k]];
            var d = dist(px, py, s.x, s.y);
            if (d < d1) { d2 = d1; d1 = d; }
            else if (d < d2) { d2 = d; }
          }
        }
      }

      if (d1 < Infinity && d2 < Infinity) break;
      searchR = Math.max(gridCols, gridRows);
    }

    return [d1, d2];
  }

  function dist(px, py, sx, sy) {
    var dx = px - sx, dy = py - sy;
    if (metricMode === 0) return Math.sqrt(dx * dx + dy * dy);
    if (metricMode === 1) return Math.abs(dx) + Math.abs(dy);
    return Math.max(Math.abs(dx), Math.abs(dy));
  }

  function createSeed(x, y) {
    var speed = 0.3 + Math.random() * 0.7;
    var angle = Math.random() * Math.PI * 2;
    return {
      x: x, y: y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed
    };
  }

  function initSeeds() {
    seeds = [];
    var margin = 40;
    for (var i = 0; i < INIT_SEEDS; i++) {
      seeds.push(createSeed(
        margin + Math.random() * (W - margin * 2),
        margin + Math.random() * (H - margin * 2)
      ));
    }
  }

  function findNearestSeed(mx, my) {
    var best = -1, bestD = DRAG_RADIUS * DRAG_RADIUS;
    for (var i = 0; i < seeds.length; i++) {
      var dx = mx - seeds[i].x, dy = my - seeds[i].y;
      var d = dx * dx + dy * dy;
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  // Color LUT for performance
  var colorLUT = null;
  var LUT_SIZE = 256;

  function buildColorLUT() {
    colorLUT = new Uint8Array(LUT_SIZE * 3);
    for (var i = 0; i < LUT_SIZE; i++) {
      var t = i / (LUT_SIZE - 1);
      var r, g, b;
      if (t < 0.15) {
        // Near seed: deep purple
        var f = t / 0.15;
        r = 20 + f * 15;
        g = 5 + f * 10;
        b = 40 + f * 30;
      } else if (t < 0.4) {
        // Transition purple -> teal
        var f = (t - 0.15) / 0.25;
        r = 35 * (1 - f) + 0 * f;
        g = 15 * (1 - f) + 180 * f;
        b = 70 * (1 - f) + 160 * f;
      } else if (t < 0.7) {
        // Teal peak
        var f = (t - 0.4) / 0.3;
        r = 0;
        g = 180 + f * 65;
        b = 160 + f * 52;
      } else if (t < 0.9) {
        // Teal -> dim
        var f = (t - 0.7) / 0.2;
        r = 0;
        g = 245 * (1 - f * 0.85);
        b = 212 * (1 - f * 0.8);
      } else {
        // Fade to dark
        var f = (t - 0.9) / 0.1;
        r = 0;
        g = 245 * 0.15 * (1 - f);
        b = 212 * 0.2 * (1 - f);
      }
      colorLUT[i * 3] = r | 0;
      colorLUT[i * 3 + 1] = g | 0;
      colorLUT[i * 3 + 2] = b | 0;
    }
  }

  function applyFunc(d1, d2, maxDist) {
    var v;
    if (funcMode === 0) {
      v = d1 / maxDist;
    } else if (funcMode === 1) {
      v = d2 / maxDist;
    } else if (funcMode === 2) {
      v = (d2 - d1) / maxDist;
    } else {
      v = (d1 * d2) / (maxDist * maxDist) * 2;
    }
    return Math.max(0, Math.min(1, v));
  }

  function renderNoise() {
    if (seeds.length < 2) return;

    rW = Math.ceil(W / RES);
    rH = Math.ceil(H / RES);
    offCanvas.width = rW;
    offCanvas.height = rH;

    var imgData = offCtx.createImageData(rW, rH);
    var data = imgData.data;

    buildGrid();

    var maxDist = Math.sqrt(W * W + H * H) * 0.35;
    if (metricMode === 1) maxDist = (W + H) * 0.35;
    if (metricMode === 2) maxDist = Math.max(W, H) * 0.35;

    for (var ry = 0; ry < rH; ry++) {
      var py = ry * RES + RES * 0.5;
      for (var rx = 0; rx < rW; rx++) {
        var px = rx * RES + RES * 0.5;
        var ds = findNearestTwo(px, py);
        var v = applyFunc(ds[0], ds[1], maxDist);
        var li = (v * (LUT_SIZE - 1)) | 0;
        if (li < 0) li = 0;
        if (li >= LUT_SIZE) li = LUT_SIZE - 1;
        var idx = (ry * rW + rx) * 4;
        data[idx] = colorLUT[li * 3];
        data[idx + 1] = colorLUT[li * 3 + 1];
        data[idx + 2] = colorLUT[li * 3 + 2];
        data[idx + 3] = 255;
      }
    }

    offCtx.putImageData(imgData, 0, 0);

    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(offCanvas, 0, 0, W, H);
  }

  function renderSeeds() {
    for (var i = 0; i < seeds.length; i++) {
      var s = seeds[i];
      // Outer glow
      ctx.fillStyle = 'rgba(0,245,212,0.06)';
      ctx.beginPath();
      ctx.arc(s.x, s.y, 10, 0, Math.PI * 2);
      ctx.fill();
      // Ring
      ctx.strokeStyle = 'rgba(0,245,212,0.2)';
      ctx.lineWidth = 0.6;
      ctx.beginPath();
      ctx.arc(s.x, s.y, 4, 0, Math.PI * 2);
      ctx.stroke();
      // Core
      ctx.fillStyle = 'rgba(100,255,230,0.6)';
      ctx.beginPath();
      ctx.arc(s.x, s.y, 1.5, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function updateSeeds() {
    var spd = speedSlider.value / 100;
    for (var i = 0; i < seeds.length; i++) {
      var s = seeds[i];
      if (i === dragging) continue;
      s.x += s.vx * spd;
      s.y += s.vy * spd;
      if (s.x < 0) { s.x = 0; s.vx *= -1; }
      if (s.x > W) { s.x = W; s.vx *= -1; }
      if (s.y < 0) { s.y = 0; s.vy *= -1; }
      if (s.y > H) { s.y = H; s.vy *= -1; }
    }
  }

  function updateHud() {
    hudSeeds.textContent = seeds.length;
    hudFunc.textContent = FUNC_NAMES[funcMode];
    hudMetric.textContent = METRIC_NAMES[metricMode];
    seedCountEl.textContent = seeds.length;
    speedVal.textContent = speedSlider.value;
  }

  function loop() {
    updateSeeds();
    renderNoise();
    renderSeeds();
    updateHud();
    requestAnimationFrame(loop);
  }

  // -- Events --
  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    return [
      (e.clientX - r.left) * (W / r.width),
      (e.clientY - r.top) * (H / r.height)
    ];
  }

  canvas.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    var p = getPos(e);
    var hit = findNearestSeed(p[0], p[1]);
    if (hit >= 0) {
      dragging = hit;
      dragStarted = false;
    } else {
      dragging = -1;
    }
  });

  canvas.addEventListener('mousemove', function(e) {
    if (dragging >= 0) {
      var p = getPos(e);
      seeds[dragging].x = p[0];
      seeds[dragging].y = p[1];
      dragStarted = true;
      if (typeof SFX !== 'undefined') SFX.play('move', 150);
    }
  });

  canvas.addEventListener('mouseup', function(e) {
    if (dragging >= 0) {
      if (!dragStarted) {
        // Was a click on a seed, not a drag — don't add new
      }
      dragging = -1;
      return;
    }
  });

  canvas.addEventListener('click', function(e) {
    if (dragStarted) { dragStarted = false; return; }
    if (e.button !== 0) return;
    var p = getPos(e);
    var hit = findNearestSeed(p[0], p[1]);
    if (hit >= 0) return; // clicked near existing seed
    if (seeds.length < MAX_SEEDS) {
      seeds.push(createSeed(p[0], p[1]));
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var p = getPos(e);
    var hit = findNearestSeed(p[0], p[1]);
    if (hit >= 0) {
      seeds.splice(hit, 1);
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  });

  // Touch support
  var touchId = null;
  var touchStart = null;
  var touchMoved = false;

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (touchId !== null) return;
    var t = e.changedTouches[0];
    touchId = t.identifier;
    var p = getPos(t);
    touchStart = p;
    touchMoved = false;
    var hit = findNearestSeed(p[0], p[1]);
    if (hit >= 0) {
      dragging = hit;
    }
  }, {passive: false});

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.changedTouches.length; i++) {
      var t = e.changedTouches[i];
      if (t.identifier === touchId && dragging >= 0) {
        var p = getPos(t);
        seeds[dragging].x = p[0];
        seeds[dragging].y = p[1];
        touchMoved = true;
      }
    }
  }, {passive: false});

  canvas.addEventListener('touchend', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.changedTouches.length; i++) {
      var t = e.changedTouches[i];
      if (t.identifier === touchId) {
        if (!touchMoved && dragging < 0) {
          var p = getPos(t);
          if (seeds.length < MAX_SEEDS) {
            seeds.push(createSeed(p[0], p[1]));
            if (typeof SFX !== 'undefined') SFX.play('spawn');
          }
        }
        touchId = null;
        dragging = -1;
        touchMoved = false;
      }
    }
  }, {passive: false});

  // Control buttons
  funcBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      funcMode = parseInt(this.getAttribute('data-fn'));
      funcBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  metricBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      metricMode = parseInt(this.getAttribute('data-mt'));
      metricBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  speedSlider.addEventListener('input', function() {
    speedVal.textContent = this.value;
  });

  resetBtn.addEventListener('click', function() {
    initSeeds();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Resize
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize);

  // Hide UI on H key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      document.body.classList.toggle('hide-ui');
    }
  });

  var hideStyle = document.createElement('style');
  hideStyle.textContent = '.hide-ui .hud,.hide-ui #info,.hide-ui .floating-nav,.hide-ui .drawer-controls,.hide-ui .drawer-toggle,.hide-ui .interaction-hints{opacity:0!important;pointer-events:none}';
  document.head.appendChild(hideStyle);

  // Start
  resize();
  buildColorLUT();
  initSeeds();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
