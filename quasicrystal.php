<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('quasicrystal');

$pageName = 'quasicrystal';
$pageTitle = 'QUASICRYSTAL - PULSE';
$pageDesc = '準結晶干渉パターン。N本の平面波が重なり合い、周期的でも非周期的でもない準周期の秩序が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/quasicrystal.php';

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

<div class="hud">
  N: <span id="hudN" class="v">7</span><br>
  ZOOM: <span id="hudZ" class="v">1.00</span>x
</div>

<div id="info">
  quasicrystal interference<br>
  <span class="v">N plane waves · quasiperiodic symmetry</span><br>
  superposition of cosines
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">SYMMETRY (N)</label>
    <div class="slider-row">
      <input type="range" id="sN" min="3" max="12" value="7" step="1">
      <span class="slider-value" id="vN">7</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">FREQUENCY</label>
    <div class="slider-row">
      <input type="range" id="sFreq" min="5" max="80" value="30">
      <span class="slider-value" id="vFreq">0.030</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sSpeed" min="0" max="100" value="20">
      <span class="slider-value" id="vSpeed">0.020</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnAuto">AUTO</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESETS</label>
    <div class="btn-row">
      <button class="control-btn" id="pre5">5-FOLD</button>
      <button class="control-btn" id="pre7">7-FOLD</button>
      <button class="control-btn" id="pre12">12-FOLD</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PALETTE</label>
    <div class="btn-row">
      <button class="control-btn active" data-pal="0">CRYSTAL</button>
      <button class="control-btn" data-pal="1">DEEP</button>
      <button class="control-btn" data-pal="2">NEON</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, rW, rH, RES;
  var offC, offX, imgData;

  var N = 7;
  var freq = 0.030;
  var phase = 0;
  var speed = 0.020;
  var autoMode = true;
  var scale = 1.0;
  var panX = 0, panY = 0;
  var palIdx = 0;

  var cosA, sinA;
  var palette = new Uint8Array(256 * 3);
  var maxN = 12;
  var wRe = new Float64Array(maxN);
  var wIm = new Float64Array(maxN);
  var wCd = new Float64Array(maxN);
  var wSd = new Float64Array(maxN);

  var hudN = document.getElementById('hudN');
  var hudZ = document.getElementById('hudZ');
  var sN = document.getElementById('sN');
  var vN = document.getElementById('vN');
  var sFreq = document.getElementById('sFreq');
  var vFreq = document.getElementById('vFreq');
  var sSpeed = document.getElementById('sSpeed');
  var vSpeed = document.getElementById('vSpeed');

  function lerp(a, b, t) { return a + (b - a) * t; }

  function computeAngles() {
    cosA = new Float64Array(N);
    sinA = new Float64Array(N);
    for (var i = 0; i < N; i++) {
      var a = 2 * Math.PI * i / N;
      cosA[i] = Math.cos(a);
      sinA[i] = Math.sin(a);
    }
  }

  function buildPalette() {
    for (var i = 0; i < 256; i++) {
      var t = i / 255, r, g, b, s;
      if (palIdx === 0) {
        if (t < 0.25) {
          s = t / 0.25;
          r = lerp(10, 123, s); g = lerp(10, 44, s); b = lerp(15, 191, s);
        } else if (t < 0.50) {
          s = (t - 0.25) / 0.25;
          r = lerp(123, 0, s); g = lerp(44, 180, s); b = lerp(191, 180, s);
        } else if (t < 0.75) {
          s = (t - 0.50) / 0.25;
          r = lerp(0, 0, s); g = lerp(180, 245, s); b = lerp(180, 212, s);
        } else {
          s = (t - 0.75) / 0.25;
          r = lerp(0, 160, s); g = lerp(245, 255, s); b = lerp(212, 240, s);
        }
      } else if (palIdx === 1) {
        if (t < 0.30) {
          s = t / 0.30;
          r = lerp(10, 45, s); g = lerp(10, 15, s); b = lerp(15, 110, s);
        } else if (t < 0.60) {
          s = (t - 0.30) / 0.30;
          r = lerp(45, 0, s); g = lerp(15, 100, s); b = lerp(110, 130, s);
        } else {
          s = (t - 0.60) / 0.40;
          r = lerp(0, 0, s); g = lerp(100, 190, s); b = lerp(130, 180, s);
        }
      } else {
        if (t < 0.18) {
          s = t / 0.18;
          r = lerp(10, 0, s); g = lerp(10, 50, s); b = lerp(15, 60, s);
        } else if (t < 0.50) {
          s = (t - 0.18) / 0.32;
          r = lerp(0, 0, s); g = lerp(50, 210, s); b = lerp(60, 185, s);
        } else if (t < 0.80) {
          s = (t - 0.50) / 0.30;
          r = lerp(0, 0, s); g = lerp(210, 245, s); b = lerp(185, 212, s);
        } else {
          s = (t - 0.80) / 0.20;
          r = lerp(0, 180, s); g = lerp(245, 255, s); b = lerp(212, 248, s);
        }
      }
      palette[i * 3] = Math.round(r);
      palette[i * 3 + 1] = Math.round(g);
      palette[i * 3 + 2] = Math.round(b);
    }
  }

  function resize() {
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    RES = W < 768 ? 2 : 3;
    rW = Math.ceil(W / RES);
    rH = Math.ceil(H / RES);
    offC = document.createElement('canvas');
    offC.width = rW;
    offC.height = rH;
    offX = offC.getContext('2d');
    imgData = offX.createImageData(rW, rH);
  }

  function render() {
    var data = imgData.data;
    var invN = 1.0 / N;
    var halfW = rW * 0.5;
    var halfH = rH * 0.5;
    var sc = scale * RES;
    var wx0 = -halfW * sc + panX;

    for (var py = 0; py < rH; py++) {
      var wy = (py - halfH) * sc + panY;

      for (var i = 0; i < N; i++) {
        var arg0 = freq * (wx0 * cosA[i] + wy * sinA[i]) + phase;
        var delta = freq * cosA[i] * sc;
        wRe[i] = Math.cos(arg0);
        wIm[i] = Math.sin(arg0);
        wCd[i] = Math.cos(delta);
        wSd[i] = Math.sin(delta);
      }

      var rowOff = py * rW;

      for (var px = 0; px < rW; px++) {
        var sum = 0;
        for (var i = 0; i < N; i++) {
          sum += wRe[i];
          var nr = wRe[i] * wCd[i] - wIm[i] * wSd[i];
          wIm[i] = wRe[i] * wSd[i] + wIm[i] * wCd[i];
          wRe[i] = nr;
        }

        var idx = ((sum * invN + 1) * 127.5) | 0;
        if (idx > 255) idx = 255;
        else if (idx < 0) idx = 0;

        var off = (rowOff + px) << 2;
        var pi = idx * 3;
        data[off] = palette[pi];
        data[off + 1] = palette[pi + 1];
        data[off + 2] = palette[pi + 2];
        data[off + 3] = 255;
      }
    }

    offX.putImageData(imgData, 0, 0);
    X.imageSmoothingEnabled = true;
    X.imageSmoothingQuality = 'high';
    X.drawImage(offC, 0, 0, rW, rH, 0, 0, W, H);
  }

  function updateHUD() {
    hudN.textContent = N;
    hudZ.textContent = (1 / scale).toFixed(2);
  }

  function frame() {
    requestAnimationFrame(frame);
    if (autoMode) phase += speed;
    render();
    updateHUD();
  }

  // --- Interaction: drag to pan ---
  var isDrag = false, lastMX, lastMY;

  C.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    isDrag = true;
    lastMX = e.clientX;
    lastMY = e.clientY;
  });
  C.addEventListener('mousemove', function(e) {
    if (!isDrag) return;
    panX -= (e.clientX - lastMX) * scale * RES;
    panY -= (e.clientY - lastMY) * scale * RES;
    lastMX = e.clientX;
    lastMY = e.clientY;
    if (typeof SFX !== 'undefined') SFX.play('move', 120);
  });
  C.addEventListener('mouseup', function() { isDrag = false; });
  C.addEventListener('mouseleave', function() { isDrag = false; });

  // --- Scroll to zoom ---
  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var f = e.deltaY > 0 ? 1.08 : 1 / 1.08;
    scale *= f;
    scale = Math.max(0.05, Math.min(30, scale));
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  }, { passive: false });

  // --- Touch ---
  var tDist0 = 0, tScale0 = 1;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      isDrag = true;
      lastMX = e.touches[0].clientX;
      lastMY = e.touches[0].clientY;
    } else if (e.touches.length === 2) {
      isDrag = false;
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      tDist0 = Math.sqrt(dx * dx + dy * dy);
      tScale0 = scale;
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 1 && isDrag) {
      var tx = e.touches[0].clientX, ty = e.touches[0].clientY;
      panX -= (tx - lastMX) * scale * RES;
      panY -= (ty - lastMY) * scale * RES;
      lastMX = tx;
      lastMY = ty;
    } else if (e.touches.length === 2) {
      var dx = e.touches[0].clientX - e.touches[1].clientX;
      var dy = e.touches[0].clientY - e.touches[1].clientY;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (tDist0 > 0) {
        scale = tScale0 * (tDist0 / d);
        scale = Math.max(0.05, Math.min(30, scale));
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    if (e.touches.length === 0) isDrag = false;
  }, { passive: false });

  // --- Controls ---
  function syncSliders() {
    sN.value = N;
    vN.textContent = N;
    sFreq.value = Math.round(freq * 1000);
    vFreq.textContent = freq.toFixed(3);
  }

  sN.addEventListener('input', function() {
    N = parseInt(this.value);
    vN.textContent = N;
    computeAngles();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  sFreq.addEventListener('input', function() {
    freq = this.value / 1000;
    vFreq.textContent = freq.toFixed(3);
  });

  sSpeed.addEventListener('input', function() {
    speed = this.value / 1000;
    vSpeed.textContent = speed.toFixed(3);
  });

  document.getElementById('btnAuto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    panX = 0; panY = 0; scale = 1.0; phase = 0;
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // Presets
  function applyPreset(n, f) {
    N = n; freq = f;
    computeAngles();
    panX = 0; panY = 0; scale = 1.0; phase = 0;
    syncSliders();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  document.getElementById('pre5').addEventListener('click', function() { applyPreset(5, 0.025); });
  document.getElementById('pre7').addEventListener('click', function() { applyPreset(7, 0.030); });
  document.getElementById('pre12').addEventListener('click', function() { applyPreset(12, 0.018); });

  // Palette
  var palBtns = document.querySelectorAll('[data-pal]');
  function handlePal(btn) {
    btn.addEventListener('click', function() {
      palIdx = parseInt(this.getAttribute('data-pal'));
      for (var j = 0; j < palBtns.length; j++) palBtns[j].classList.remove('active');
      this.classList.add('active');
      buildPalette();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }
  for (var i = 0; i < palBtns.length; i++) handlePal(palBtns[i]);

  // --- Init ---
  computeAngles();
  buildPalette();
  resize();
  addEventListener('resize', resize);
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>