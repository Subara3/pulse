<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('isofield');

$pageName = 'isofield';
$pageTitle = 'ISOFIELD - PULSE';
$pageDesc = '等高線フィールド。漂うソースが作る見えない場を、ゆっくり光る輪郭線として描く。';
$pageCanonical = 'https://pulse.subara3.com/isofield.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1.2rem; bottom: 1.2rem; color: rgba(255,255,255,0.24); font-size: 0.56rem; line-height: 1.75; letter-spacing: 0.05em; max-width: min(74vw, 420px); }
@media (max-width: 768px) {
  .hud { top: 0.65rem; right: 0.65rem; font-size: 0.54rem; }
  .info { left: 0.85rem; bottom: 0.9rem; font-size: 0.49rem; max-width: 72vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  SOURCES: <span id="hudSources" class="v">0</span><br>
  LAYERS: <span id="hudLayers" class="v">8</span><br>
  DRIFT: <span id="hudDrift" class="v">0.30</span>
</div>

<div class="ui info">
  TAP: ADD SOURCE<br>
  DRAG: BEND FLOW<br>
  RIGHT-CLICK: REMOVE NEAREST<br>
  SLOW CONTOURS FROM A HIDDEN FIELD
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'source'],
  ['type' => 'drag', 'label' => 'bend'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>LAYERS</label>
    <input type="range" id="layersSlider" min="4" max="14" value="8">
  </div>
  <div class="slider-wrap">
    <label>DRIFT</label>
    <input type="range" id="driftSlider" min="5" max="70" value="30">
  </div>
  <div class="slider-wrap">
    <label>SOFTNESS</label>
    <input type="range" id="softSlider" min="8" max="40" value="20">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO: ON</button>
    <button class="control-btn" id="paletteBtn">PALETTE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudSources = document.getElementById('hudSources');
  var hudLayers = document.getElementById('hudLayers');
  var hudDrift = document.getElementById('hudDrift');

  var layersSlider = document.getElementById('layersSlider');
  var driftSlider = document.getElementById('driftSlider');
  var softSlider = document.getElementById('softSlider');
  var autoBtn = document.getElementById('autoBtn');
  var paletteBtn = document.getElementById('paletteBtn');
  var resetBtn = document.getElementById('resetBtn');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0, GW = 0, GH = 0, cell = 8;
  var field = new Float32Array(0);
  var pointers = new Map();
  var autoSpawn = true;
  var lastAuto = 0;
  var palette = 0;

  var PALETTES = [
    { bg: '#0a0a0f', a: [0,245,212], b: [123,44,191] },
    { bg: '#0a0a0f', a: [96,224,255], b: [120,56,208] },
    { bg: '#0a0a0f', a: [18,216,195], b: [86,43,170] }
  ];

  var blobs = [];

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function mix(a, b, t) {
    return [
      (a[0] + (b[0] - a[0]) * t) | 0,
      (a[1] + (b[1] - a[1]) * t) | 0,
      (a[2] + (b[2] - a[2]) * t) | 0
    ];
  }

  function rgba(rgb, a) {
    return 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + a + ')';
  }

  function createBlob(nx, ny, power) {
    blobs.push({
      x: nx,
      y: ny,
      vx: (Math.random() - 0.5) * 0.0008,
      vy: (Math.random() - 0.5) * 0.0008,
      p: power || (0.9 + Math.random() * 0.9)
    });
    hudSources.textContent = blobs.length;
  }

  function seed() {
    blobs = [];
    createBlob(0.28, 0.32, 1.4);
    createBlob(0.64, 0.42, 1.0);
    createBlob(0.56, 0.70, 1.35);
    createBlob(0.37, 0.66, 1.12);
    createBlob(0.74, 0.60, 0.95);
  }

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);

    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';

    cell = W < 700 ? 10 : 8;
    GW = Math.floor(W / cell) + 3;
    GH = Math.floor(H / cell) + 3;
    field = new Float32Array(GW * GH);
  }

  function pointField(nx, ny) {
    var v = 0;
    var softness = parseInt(softSlider.value, 10) / 100;
    for (var i = 0; i < blobs.length; i++) {
      var b = blobs[i];
      var dx = nx - b.x;
      var dy = ny - b.y;
      var d2 = dx * dx + dy * dy + softness * 0.01;
      v += b.p / d2;
    }
    return v;
  }

  function updateField() {
    for (var y = 0; y < GH; y++) {
      var ny = (y - 1) / (GH - 3);
      for (var x = 0; x < GW; x++) {
        var nx = (x - 1) / (GW - 3);
        field[y * GW + x] = pointField(nx, ny);
      }
    }
  }

  function lerpPoint(x1, y1, v1, x2, y2, v2, iso) {
    var t = (iso - v1) / (v2 - v1 + 1e-6);
    return [x1 + (x2 - x1) * t, y1 + (y2 - y1) * t];
  }

  function segment(a, b) {
    ctx.beginPath();
    ctx.moveTo(a[0], a[1]);
    ctx.lineTo(b[0], b[1]);
    ctx.stroke();
  }

  function drawContours() {
    var layers = parseInt(layersSlider.value, 10);
    var p = PALETTES[palette];
    ctx.fillStyle = p.bg;
    ctx.fillRect(0, 0, W, H);

    var minV = Infinity, maxV = -Infinity;
    for (var i = 0; i < field.length; i++) {
      var f = field[i];
      if (f < minV) minV = f;
      if (f > maxV) maxV = f;
    }

    var span = Math.max(0.001, maxV - minV);

    for (var l = 0; l < layers; l++) {
      var t = (l + 1) / (layers + 1);
      var iso = minV + span * (0.18 + t * 0.72);
      var rgb = mix(p.a, p.b, t);
      ctx.strokeStyle = rgba(rgb, 0.18 + t * 0.48);
      ctx.lineWidth = 0.8 + t * 2.0;

      for (var y = 0; y < GH - 1; y++) {
        for (var x = 0; x < GW - 1; x++) {
          var i0 = y * GW + x;
          var i1 = i0 + 1;
          var i2 = i0 + GW + 1;
          var i3 = i0 + GW;

          var v0 = field[i0], v1 = field[i1], v2 = field[i2], v3 = field[i3];
          var mask = (v0 > iso ? 1 : 0) | (v1 > iso ? 2 : 0) | (v2 > iso ? 4 : 0) | (v3 > iso ? 8 : 0);
          if (mask === 0 || mask === 15) continue;

          var px = (x - 1) * cell;
          var py = (y - 1) * cell;

          var top = lerpPoint(px, py, v0, px + cell, py, v1, iso);
          var right = lerpPoint(px + cell, py, v1, px + cell, py + cell, v2, iso);
          var bottom = lerpPoint(px, py + cell, v3, px + cell, py + cell, v2, iso);
          var left = lerpPoint(px, py, v0, px, py + cell, v3, iso);

          switch (mask) {
            case 1: case 14: segment(left, top); break;
            case 2: case 13: segment(top, right); break;
            case 3: case 12: segment(left, right); break;
            case 4: case 11: segment(right, bottom); break;
            case 5: segment(left, top); segment(right, bottom); break;
            case 6: case 9: segment(top, bottom); break;
            case 7: case 8: segment(left, bottom); break;
            case 10: segment(top, right); segment(left, bottom); break;
          }
        }
      }
    }

    for (var b = 0; b < blobs.length; b++) {
      var bb = blobs[b];
      var x = bb.x * W;
      var y = bb.y * H;
      var g = ctx.createRadialGradient(x, y, 0, x, y, 40);
      g.addColorStop(0, 'rgba(255,255,255,0.12)');
      g.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(x, y, 40, 0, Math.PI * 2);
      ctx.fill();
    }

    hudLayers.textContent = String(layers);
    hudDrift.textContent = (parseInt(driftSlider.value, 10) / 100).toFixed(2);
  }

  function updateBlobs(t) {
    var drift = parseInt(driftSlider.value, 10) / 100;
    for (var i = 0; i < blobs.length; i++) {
      var b = blobs[i];
      var wob = t * 0.00012 + i * 0.8;
      b.vx += Math.cos(wob) * 0.00002 * drift;
      b.vy += Math.sin(wob * 0.9) * 0.00002 * drift;

      pointers.forEach(function(p) {
        var dx = b.x - p.x;
        var dy = b.y - p.y;
        var d = Math.sqrt(dx * dx + dy * dy) + 0.0001;
        var f = clamp(0.002 / (d * d), 0, 0.0022);
        b.vx += dx / d * f;
        b.vy += dy / d * f;
      });

      b.vx *= 0.985;
      b.vy *= 0.985;
      b.x += b.vx;
      b.y += b.vy;

      if (b.x < 0.03 || b.x > 0.97) b.vx *= -1;
      if (b.y < 0.03 || b.y > 0.97) b.vy *= -1;
      b.x = clamp(b.x, 0.03, 0.97);
      b.y = clamp(b.y, 0.03, 0.97);
    }
  }

  function nearestBlob(nx, ny) {
    var best = -1;
    var bestD = Infinity;
    for (var i = 0; i < blobs.length; i++) {
      var dx = nx - blobs[i].x;
      var dy = ny - blobs[i].y;
      var d = dx * dx + dy * dy;
      if (d < bestD) {
        bestD = d;
        best = i;
      }
    }
    return best;
  }

  function pointerNorm(e) {
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX / W, y: t.clientY / H };
  }

  canvas.addEventListener('pointerdown', function(e) {
    var p = pointerNorm(e);
    pointers.set(e.pointerId, p);
    createBlob(p.x, p.y, 0.85 + Math.random() * 0.9);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  canvas.addEventListener('pointermove', function(e) {
    if (!pointers.has(e.pointerId)) return;
    var p = pointerNorm(e);
    pointers.set(e.pointerId, p);
    if (typeof SFX !== 'undefined') SFX.play('move', 90);
  });

  function clearPointer(e) {
    pointers.delete(e.pointerId);
  }
  canvas.addEventListener('pointerup', clearPointer);
  canvas.addEventListener('pointercancel', clearPointer);

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    if (blobs.length <= 1) return;
    var p = pointerNorm(e);
    var i = nearestBlob(p.x, p.y);
    if (i >= 0) {
      blobs.splice(i, 1);
      hudSources.textContent = blobs.length;
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  });

  layersSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  driftSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  softSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  autoBtn.addEventListener('click', function() {
    autoSpawn = !autoSpawn;
    autoBtn.textContent = 'AUTO: ' + (autoSpawn ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  paletteBtn.addEventListener('click', function() {
    palette = (palette + 1) % PALETTES.length;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    seed();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);

  function autoPulse(t) {
    if (!autoSpawn) return;
    if (t - lastAuto < 2800) return;
    if (blobs.length > 15) return;
    lastAuto = t;
    var nx = 0.5 + Math.sin(t * 0.00019) * 0.28;
    var ny = 0.5 + Math.cos(t * 0.00013) * 0.22;
    createBlob(clamp(nx, 0.08, 0.92), clamp(ny, 0.08, 0.92), 0.8 + Math.random() * 0.5);
    if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
  }

  function loop(t) {
    updateBlobs(t || performance.now());
    autoPulse(t || performance.now());
    updateField();
    drawContours();
    requestAnimationFrame(loop);
  }

  resize();
  seed();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
