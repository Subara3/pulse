<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('erosion');

$pageName = 'erosion';
$pageTitle = 'EROSION - PULSE';
$pageDesc = '水力浸食シミュレーション。水滴が地形を削り、河川の形が自然に生まれる。';
$pageCanonical = 'https://pulse.subara3.com/erosion.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", "Segoe UI", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.64rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .v { color: #00f5d4; font-weight: 700; }
@media (max-width: 768px) {
  .hud { top: 0.6rem; right: 0.6rem; font-size: 0.54rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  DROPS: <span id="hudDrops" class="v">0</span><br>
  TOTAL: <span id="hudTotal" class="v">0</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'rain burst'],
  ['type' => 'drag', 'label' => 'rain'],
  ['type' => 'right', 'label' => 'raise'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">RAIN</label>
      <span class="slider-value" id="valRain">50</span>
    </div>
    <input type="range" id="rngRain" min="10" max="200" step="10" value="50">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">EROSION</label>
      <span class="slider-value" id="valEro">0.30</span>
    </div>
    <input type="range" id="rngEro" min="5" max="100" step="5" value="30">
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="btnAuto">AUTO</button>
    <button class="control-btn" id="btnReset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', {alpha: false});

  var hudDrops = document.getElementById('hudDrops');
  var hudTotal = document.getElementById('hudTotal');
  var rngRain = document.getElementById('rngRain');
  var valRain = document.getElementById('valRain');
  var rngEro = document.getElementById('rngEro');
  var valEro = document.getElementById('valEro');
  var btnAuto = document.getElementById('btnAuto');
  var btnReset = document.getElementById('btnReset');

  var W, H, CELL = 3, cols, rows;
  var map, moisture;
  var offC, offCtx, offImg;
  var autoRain = true;
  var rainRate = 50;
  var erosionStr = 0.3;
  var totalDrops = 0;
  var drops = [];
  var mDown = false, mRight = false, mX = 0, mY = 0;

  var INERTIA = 0.05;
  var CAPACITY = 4;
  var DEP_SPEED = 0.3;
  var EVAP = 0.02;
  var GRAV = 4;
  var MAX_LIFE = 80;
  var MIN_SLOPE = 0.01;
  var MAX_DROPS = 6000;

  /* ---- Noise ---- */
  var P = new Uint8Array(512);

  function seedNoise(s) {
    var p = new Uint8Array(256);
    for (var i = 0; i < 256; i++) p[i] = i;
    for (var i = 255; i > 0; i--) {
      s = (s * 48271) % 2147483647;
      var j = s % (i + 1);
      var t = p[i]; p[i] = p[j]; p[j] = t;
    }
    for (var i = 0; i < 512; i++) P[i] = p[i & 255];
  }

  function noise2(x, y) {
    var ix = Math.floor(x), iy = Math.floor(y);
    var fx = x - ix, fy = y - iy;
    var ux = fx * fx * (3 - 2 * fx);
    var uy = fy * fy * (3 - 2 * fy);
    var n00 = P[(P[ix & 255] + (iy & 255)) & 255] / 255;
    var n10 = P[(P[(ix + 1) & 255] + (iy & 255)) & 255] / 255;
    var n01 = P[(P[ix & 255] + ((iy + 1) & 255)) & 255] / 255;
    var n11 = P[(P[(ix + 1) & 255] + ((iy + 1) & 255)) & 255] / 255;
    return (n00 * (1 - ux) + n10 * ux) * (1 - uy) +
           (n01 * (1 - ux) + n11 * ux) * uy;
  }

  function fbm(x, y, oct) {
    var v = 0, a = 1, t = 0;
    for (var i = 0; i < oct; i++) {
      v += noise2(x, y) * a;
      t += a;
      x *= 2; y *= 2;
      a *= 0.5;
    }
    return v / t;
  }

  /* ---- Heightmap ---- */
  function initMap() {
    seedNoise(Math.random() * 1e6 | 0);
    map = new Float32Array(cols * rows);
    moisture = new Float32Array(cols * rows);
    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        var h = fbm(x * 0.008, y * 0.008, 6);
        var slope = (1 - y / rows) * 0.12;
        map[y * cols + x] = h * 0.85 + slope + 0.05;
      }
    }
    drops = [];
    totalDrops = 0;
  }

  /* ---- Bilinear interpolation ---- */
  function getH(x, y) {
    var xi = x | 0, yi = y | 0;
    if (xi < 0 || xi >= cols - 1 || yi < 0 || yi >= rows - 1) return 0;
    var u = x - xi, v = y - yi;
    var i = yi * cols + xi;
    return map[i] * (1 - u) * (1 - v) + map[i + 1] * u * (1 - v) +
           map[i + cols] * (1 - u) * v + map[i + cols + 1] * u * v;
  }

  function getGrad(x, y) {
    var xi = x | 0, yi = y | 0;
    if (xi < 1 || xi >= cols - 2 || yi < 1 || yi >= rows - 2) return [0, 0];
    var u = x - xi, v = y - yi;
    var i = yi * cols + xi;
    var h00 = map[i], h10 = map[i + 1];
    var h01 = map[i + cols], h11 = map[i + cols + 1];
    return [
      (h10 - h00) * (1 - v) + (h11 - h01) * v,
      (h01 - h00) * (1 - u) + (h11 - h10) * u
    ];
  }

  function modTerrain(px, py, amt) {
    var xi = px | 0, yi = py | 0;
    if (xi < 0 || xi >= cols - 1 || yi < 0 || yi >= rows - 1) return;
    var u = px - xi, v = py - yi;
    var i = yi * cols + xi;
    map[i] += amt * (1 - u) * (1 - v);
    map[i + 1] += amt * u * (1 - v);
    map[i + cols] += amt * (1 - u) * v;
    map[i + cols + 1] += amt * u * v;
  }

  /* ---- Color palette ---- */
  var pal = new Uint8Array(256 * 3);

  function buildPalette() {
    var stops = [
      [0.00, 8,   12,  22],
      [0.15, 5,   38,  48],
      [0.30, 0,   82,  72],
      [0.48, 0,   155, 132],
      [0.62, 25,  100, 155],
      [0.78, 75,  52,  168],
      [1.00, 123, 44,  191]
    ];
    for (var i = 0; i < 256; i++) {
      var t = i / 255;
      var s0 = stops[0], s1 = stops[stops.length - 1];
      for (var j = 0; j < stops.length - 1; j++) {
        if (t <= stops[j + 1][0]) {
          s0 = stops[j]; s1 = stops[j + 1]; break;
        }
      }
      var f = (t - s0[0]) / (s1[0] - s0[0]);
      f = f * f * (3 - 2 * f);
      pal[i * 3]     = s0[1] + (s1[1] - s0[1]) * f | 0;
      pal[i * 3 + 1] = s0[2] + (s1[2] - s0[2]) * f | 0;
      pal[i * 3 + 2] = s0[3] + (s1[3] - s0[3]) * f | 0;
    }
  }

  /* ---- Water drops ---- */
  function spawnDrop(sx, sy) {
    if (drops.length >= MAX_DROPS) return;
    drops.push({x: sx, y: sy, dx: 0, dy: 0, spd: 1, w: 1, sed: 0, life: 0});
  }

  function stepDrops() {
    var i = 0;
    while (i < drops.length) {
      var d = drops[i];
      var dead = false;

      if (d.x < 2 || d.x >= cols - 2 || d.y < 2 || d.y >= rows - 2 || d.life >= MAX_LIFE) {
        dead = true;
      }

      if (!dead) {
        var g = getGrad(d.x, d.y);
        d.dx = d.dx * INERTIA - g[0] * (1 - INERTIA);
        d.dy = d.dy * INERTIA - g[1] * (1 - INERTIA);

        var len = Math.sqrt(d.dx * d.dx + d.dy * d.dy);
        if (len < 1e-6) {
          var a = Math.random() * Math.PI * 2;
          d.dx = Math.cos(a); d.dy = Math.sin(a);
        } else {
          d.dx /= len; d.dy /= len;
        }

        var nx = d.x + d.dx;
        var ny = d.y + d.dy;

        if (nx < 2 || nx >= cols - 2 || ny < 2 || ny >= rows - 2) {
          dead = true;
        } else {
          var oldH = getH(d.x, d.y);
          var newH = getH(nx, ny);
          var dH = newH - oldH;

          var cap = Math.max(-dH, MIN_SLOPE) * d.spd * d.w * CAPACITY;

          if (d.sed > cap || dH > 0) {
            var dep = dH > 0 ? Math.min(d.sed, dH) : (d.sed - cap) * DEP_SPEED;
            if (dep > 0) {
              d.sed -= dep;
              modTerrain(d.x, d.y, dep);
            }
          } else {
            var ero = Math.min((cap - d.sed) * erosionStr, -dH);
            if (ero > 0) {
              d.sed += ero;
              modTerrain(d.x, d.y, -ero);
            }
          }

          var mi = (d.y | 0) * cols + (d.x | 0);
          if (mi >= 0 && mi < cols * rows) {
            moisture[mi] = Math.min(moisture[mi] + 0.15, 1);
          }

          d.spd = Math.sqrt(Math.max(0.01, d.spd * d.spd + dH * GRAV));
          d.w *= (1 - EVAP);
          d.x = nx;
          d.y = ny;
          d.life++;
        }
      }

      if (dead) {
        drops[i] = drops[drops.length - 1];
        drops.pop();
      } else {
        i++;
      }
    }
  }

  function spawnRain(count, cx, cy, rad) {
    var spawned = 0;
    for (var k = 0; k < count; k++) {
      var x, y;
      if (cx !== undefined) {
        var a = Math.random() * Math.PI * 2;
        var r = Math.random() * rad;
        x = cx + Math.cos(a) * r;
        y = cy + Math.sin(a) * r;
      } else {
        x = 2 + Math.random() * (cols - 4);
        y = 2 + Math.random() * (rows - 4);
      }
      if (x >= 2 && x < cols - 2 && y >= 2 && y < rows - 2) {
        spawnDrop(x, y);
        spawned++;
      }
    }
    totalDrops += spawned;
  }

  /* ---- Render ---- */
  function renderMap() {
    var d = offImg.data;
    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        var idx = y * cols + x;
        var h = map[idx];
        if (h < 0) h = 0;
        if (h > 1) h = 1;
        var ci = (h * 255 | 0) * 3;
        var m = moisture[idx];
        var pi = idx * 4;

        var r = pal[ci], g = pal[ci + 1], b = pal[ci + 2];
        if (m > 0.01) {
          var f = m > 1 ? 0.8 : m * 0.8;
          r = r + (0 - r) * f | 0;
          g = g + (245 - g) * f | 0;
          b = b + (212 - b) * f | 0;
        }
        d[pi] = r;
        d[pi + 1] = g;
        d[pi + 2] = b;
        d[pi + 3] = 255;

        moisture[idx] *= 0.993;
      }
    }
    offCtx.putImageData(offImg, 0, 0);
  }

  function draw() {
    renderMap();
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(offC, 0, 0, W, H);

    if (drops.length > 0) {
      ctx.globalCompositeOperation = 'lighter';
      ctx.fillStyle = 'rgba(0,245,212,0.35)';
      for (var i = 0; i < drops.length; i++) {
        var d = drops[i];
        ctx.fillRect(d.x * CELL - 1, d.y * CELL - 1, 2, 2);
      }
      ctx.globalCompositeOperation = 'source-over';
    }

    hudDrops.textContent = drops.length;
    hudTotal.textContent = fmtNum(totalDrops);
  }

  function fmtNum(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return '' + n;
  }

  /* ---- Update ---- */
  function update() {
    if (autoRain) spawnRain(rainRate);
    if (mDown && !mRight) spawnRain(25, mX / CELL, mY / CELL, 12);
    if (mDown && mRight) {
      var cx = mX / CELL | 0, cy = mY / CELL | 0;
      var r = 10;
      for (var dy = -r; dy <= r; dy++) {
        for (var dx = -r; dx <= r; dx++) {
          var ix = cx + dx, iy = cy + dy;
          if (ix < 0 || ix >= cols || iy < 0 || iy >= rows) continue;
          var dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < r) {
            map[iy * cols + ix] += 0.005 * (1 - dist / r);
          }
        }
      }
    }
    stepDrops();
  }

  /* ---- Loop ---- */
  function loop() {
    update();
    draw();
    requestAnimationFrame(loop);
  }

  /* ---- Events ---- */
  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) { mRight = true; }
    mDown = true;
    mX = e.clientX; mY = e.clientY;
    if (!mRight) {
      spawnRain(100, mX / CELL, mY / CELL, 18);
      if (typeof SFX !== 'undefined') SFX.play('ripple');
    }
  });

  C.addEventListener('mousemove', function(e) {
    mX = e.clientX; mY = e.clientY;
  });

  window.addEventListener('mouseup', function() {
    mDown = false; mRight = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    mDown = true;
    var t = e.touches[0];
    mX = t.clientX; mY = t.clientY;
    spawnRain(100, mX / CELL, mY / CELL, 18);
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    mX = t.clientX; mY = t.clientY;
  }, {passive: false});

  C.addEventListener('touchend', function() { mDown = false; });

  /* ---- Controls ---- */
  rngRain.addEventListener('input', function() {
    rainRate = +this.value;
    valRain.textContent = rainRate;
  });

  rngEro.addEventListener('input', function() {
    erosionStr = +this.value / 100;
    valEro.textContent = erosionStr.toFixed(2);
  });

  btnAuto.addEventListener('click', function() {
    autoRain = !autoRain;
    this.classList.toggle('active', autoRain);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  btnReset.addEventListener('click', function() {
    initMap();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  /* ---- Init ---- */
  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    cols = Math.ceil(W / CELL);
    rows = Math.ceil(H / CELL);
    offC = document.createElement('canvas');
    offC.width = cols;
    offC.height = rows;
    offCtx = offC.getContext('2d');
    offImg = offCtx.createImageData(cols, rows);
    initMap();
  }

  window.addEventListener('resize', resize);
  buildPalette();
  resize();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
