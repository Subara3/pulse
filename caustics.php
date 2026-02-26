<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('caustics');

$pageName = 'caustics';
$pageTitle = 'CAUSTICS - PULSE';
$pageDesc = 'Optical chaos. Surface ripples bend light into slowly drifting caustics.';
$pageCanonical = 'https://pulse.subara3.com/caustics.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", "Segoe UI", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.64rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1.3rem; bottom: 1.3rem; color: rgba(255,255,255,0.22); font-size: 0.58rem; letter-spacing: 0.05em; line-height: 1.75; max-width: min(74vw, 430px); }

@media (max-width: 768px) {
  .hud { top: 0.6rem; right: 0.6rem; font-size: 0.54rem; }
  .info { left: 0.9rem; bottom: 0.9rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  ENERGY: <span id="hudEnergy" class="v">0.00</span><br>
  REFRACT: <span id="hudRefract" class="v">1.8</span><br>
  DROPS: <span id="hudDrops" class="v">ON</span>
</div>

<div class="ui info">
  TAP / DRAG: DROP WAVES<br>
  LIGHT BENDS ALONG SURFACE SLOPE<br>
  SLOW CAUSTICS FOR LONG WATCHING
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>REFRACT</label>
    <input type="range" id="refractSlider" min="8" max="34" value="18">
  </div>
  <div class="slider-wrap">
    <label>RIPPLE</label>
    <input type="range" id="rippleSlider" min="8" max="40" value="22">
  </div>
  <div class="slider-wrap">
    <label>DAMP</label>
    <input type="range" id="dampSlider" min="2" max="30" value="11">
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

  var hudEnergy = document.getElementById('hudEnergy');
  var hudRefract = document.getElementById('hudRefract');
  var hudDrops = document.getElementById('hudDrops');

  var refractSlider = document.getElementById('refractSlider');
  var rippleSlider = document.getElementById('rippleSlider');
  var dampSlider = document.getElementById('dampSlider');
  var autoBtn = document.getElementById('autoBtn');
  var paletteBtn = document.getElementById('paletteBtn');
  var resetBtn = document.getElementById('resetBtn');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0, GW = 0, GH = 0;
  var phaseA, phaseB, energyMap, lightMap;
  var imageData, px;

  var pointerDown = false;
  var pointerX = 0;
  var pointerY = 0;
  var autoDrops = true;
  var paletteMode = 0;
  var lastDrop = 0;

  var PALETTES = [
    function(v) {
      var t = Math.min(1, v);
      return [6 + t * 18, 16 + t * 220, 28 + t * 190];
    },
    function(v) {
      var t = Math.min(1, v);
      return [10 + t * 95, 10 + t * 60, 24 + t * 220];
    },
    function(v) {
      var t = Math.min(1, v);
      return [8 + t * 180, 14 + t * 160, 20 + t * 205];
    }
  ];

  function idx(x, y) {
    if (x < 0) x += GW; else if (x >= GW) x -= GW;
    if (y < 0) y += GH; else if (y >= GH) y -= GH;
    return y * GW + x;
  }

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);

    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';

    var scale = W < 768 ? 3 : 2;
    GW = Math.max(140, Math.floor(canvas.width / scale));
    GH = Math.max(90, Math.floor(canvas.height / scale));

    phaseA = new Float32Array(GW * GH);
    phaseB = new Float32Array(GW * GH);
    energyMap = new Float32Array(GW * GH);
    lightMap = new Float32Array(GW * GH);

    imageData = ctx.createImageData(GW, GH);
    px = imageData.data;

    seedInitial();
  }

  function addDrop(nx, ny, power, rad) {
    var cx = Math.floor(nx * GW);
    var cy = Math.floor(ny * GH);
    var r = rad;
    for (var y = -r; y <= r; y++) {
      for (var x = -r; x <= r; x++) {
        var d = Math.sqrt(x * x + y * y);
        if (d > r) continue;
        var p = idx(cx + x, cy + y);
        var wave = Math.cos(d / r * Math.PI) * 0.5 + 0.5;
        phaseA[p] += power * wave;
      }
    }
  }

  function seedInitial() {
    for (var i = 0; i < phaseA.length; i++) {
      phaseA[i] = 0;
      phaseB[i] = 0;
      energyMap[i] = 0;
      lightMap[i] = 0;
    }
    addDrop(0.3, 0.35, 1.7, 16);
    addDrop(0.68, 0.58, 1.3, 18);
    addDrop(0.52, 0.48, -1.1, 20);
  }

  function stepWaves() {
    var damp = 1 - (parseInt(dampSlider.value, 10) / 1000 + 0.006);
    var total = 0;

    for (var y = 0; y < GH; y++) {
      var yOff = y * GW;
      for (var x = 0; x < GW; x++) {
        var p = yOff + x;
        var c = phaseA[p];
        var n = phaseA[idx(x, y - 1)];
        var s = phaseA[idx(x, y + 1)];
        var e = phaseA[idx(x + 1, y)];
        var w = phaseA[idx(x - 1, y)];

        var lap = (n + s + e + w) * 0.5 - c;
        var v = (2 * c - phaseB[p]) + lap * 0.13;
        v *= damp;

        phaseB[p] = v;
        total += Math.abs(v);
      }
    }

    var tmp = phaseA;
    phaseA = phaseB;
    phaseB = tmp;

    hudEnergy.textContent = (total / phaseA.length).toFixed(2);
  }

  function buildCaustics() {
    var refract = parseInt(refractSlider.value, 10) / 10;
    hudRefract.textContent = refract.toFixed(1);

    for (var i = 0; i < lightMap.length; i++) {
      lightMap[i] *= 0.93;
      energyMap[i] *= 0.91;
    }

    for (var y = 1; y < GH - 1; y += 1) {
      for (var x = 1; x < GW - 1; x += 1) {
        var p = idx(x, y);
        var dx = phaseA[idx(x + 1, y)] - phaseA[idx(x - 1, y)];
        var dy = phaseA[idx(x, y + 1)] - phaseA[idx(x, y - 1)];

        var tx = Math.round(x + dx * refract * 2.2);
        var ty = Math.round(y + dy * refract * 2.2);
        var q = idx(tx, ty);

        var curvature = Math.abs(dx) + Math.abs(dy);
        var gain = 0.08 + curvature * 0.52;

        lightMap[q] += gain;
        energyMap[p] = Math.min(1.4, energyMap[p] + curvature * 0.2);
      }
    }
  }

  function draw() {
    var pal = PALETTES[paletteMode];

    for (var i = 0; i < lightMap.length; i++) {
      var glow = Math.min(1.2, lightMap[i]);
      var surf = Math.min(1.0, energyMap[i] * 0.8);
      var mix = Math.min(1.15, glow * 0.82 + surf * 0.35);
      var rgb = pal(mix);
      var o = i * 4;
      px[o] = rgb[0] | 0;
      px[o + 1] = rgb[1] | 0;
      px[o + 2] = rgb[2] | 0;
      px[o + 3] = 255;
    }

    ctx.putImageData(imageData, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(canvas, 0, 0, GW, GH, 0, 0, canvas.width, canvas.height);

    if (pointerDown) {
      ctx.beginPath();
      ctx.arc(pointerX * DPR, pointerY * DPR, 26 * DPR, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,0.32)';
      ctx.lineWidth = 1.8 * DPR;
      ctx.stroke();
    }
  }

  function maybeAutoDrop(t) {
    if (!autoDrops) return;
    if (t - lastDrop < 1300) return;
    lastDrop = t;

    var nx = 0.5 + Math.sin(t * 0.00011) * 0.34 + (Math.random() - 0.5) * 0.08;
    var ny = 0.5 + Math.cos(t * 0.00009) * 0.24 + (Math.random() - 0.5) * 0.08;
    var p = parseInt(rippleSlider.value, 10) / 14;
    addDrop(Math.max(0.02, Math.min(0.98, nx)), Math.max(0.02, Math.min(0.98, ny)), p, 12 + (Math.random() * 10 | 0));
    if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
  }

  function pointerToNorm(e) {
    var t = e.touches ? e.touches[0] : e;
    pointerX = t.clientX;
    pointerY = t.clientY;
  }

  canvas.addEventListener('pointerdown', function(e) {
    pointerDown = true;
    pointerToNorm(e);
    var p = parseInt(rippleSlider.value, 10) / 10;
    addDrop(pointerX / W, pointerY / H, p, W < 768 ? 12 : 15);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  canvas.addEventListener('pointermove', function(e) {
    pointerToNorm(e);
    if (pointerDown) {
      var p = parseInt(rippleSlider.value, 10) / 22;
      addDrop(pointerX / W, pointerY / H, p, W < 768 ? 7 : 9);
      if (typeof SFX !== 'undefined') SFX.play('move', 90);
    }
  });

  window.addEventListener('pointerup', function() {
    pointerDown = false;
  });

  canvas.addEventListener('touchstart', function(e) { e.preventDefault(); }, { passive: false });

  autoBtn.addEventListener('click', function() {
    autoDrops = !autoDrops;
    autoBtn.textContent = 'AUTO: ' + (autoDrops ? 'ON' : 'OFF');
    hudDrops.textContent = autoDrops ? 'ON' : 'OFF';
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  paletteBtn.addEventListener('click', function() {
    paletteMode = (paletteMode + 1) % PALETTES.length;
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });

  resetBtn.addEventListener('click', function() {
    seedInitial();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  refractSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  rippleSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  dampSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  window.addEventListener('resize', resize);

  function loop(t) {
    stepWaves();
    buildCaustics();
    maybeAutoDrop(t || performance.now());
    draw();
    requestAnimationFrame(loop);
  }

  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
