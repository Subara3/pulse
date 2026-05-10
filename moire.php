<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('moire');

$pageName = 'moire';
$pageTitle = 'MOIRÉ - PULSE';
$pageDesc = 'モアレ縞。2つの周期パターンの微小な差が巨大な干渉縞を生む。触れて動かして、干渉の数学を体感する。';
$pageCanonical = 'https://pulse.subara3.com/moire.php';
$extraStyle = '
body{overflow:hidden}
#canvas{position:fixed;top:0;left:0;width:100%;height:100%;image-rendering:auto}
#hud{position:fixed;top:1rem;left:1rem;font-size:.7rem;color:rgba(0,245,212,.65);font-family:monospace;pointer-events:none;line-height:2;z-index:10}
#hud .v{color:#fff}
#controls{position:fixed;bottom:1rem;right:1rem;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;z-index:10}
.btn{background:rgba(0,245,212,.07);border:1px solid rgba(0,245,212,.28);color:#00f5d4;font-size:.66rem;padding:.28rem .62rem;cursor:pointer;font-family:monospace;letter-spacing:.07em;transition:background .15s}
.btn:hover{background:rgba(0,245,212,.18)}
.btn:active{background:rgba(0,245,212,.28)}
.ctrl-row{display:flex;align-items:center;gap:.5rem;justify-content:flex-end}
.ctrl-label{color:rgba(0,245,212,.55);font-family:monospace;font-size:.65rem;letter-spacing:.05em;min-width:3.2rem;text-align:right}
.ctrl-val{color:rgba(255,255,255,.7);font-family:monospace;font-size:.62rem;min-width:2.2rem;text-align:right}
input[type=range]{width:88px;accent-color:#00f5d4;cursor:pointer}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>MODE &nbsp;&nbsp;<span class="v" id="hud-mode">CIRCLES</span></div>
  <div id="hud-param-row">OFFSET <span class="v" id="hud-param">36</span>px</div>
  <div>FREQ &nbsp;&nbsp;<span class="v" id="hud-freq">12</span></div>
</div>
<div id="controls">
  <button class="btn" id="btn-mode">MODE</button>
  <div class="ctrl-row">
    <span class="ctrl-label">FREQ</span>
    <input type="range" id="sl-freq" min="6" max="30" value="12">
    <span class="ctrl-val" id="val-freq">12</span>
  </div>
  <button class="btn" id="btn-auto">AUTO OFF</button>
  <button class="btn" id="btn-reset">RESET</button>
</div>
<?php
$interactionHints = [
  ['type' => 'drag',  'label' => 'rotate / offset'],
  ['type' => 'wheel', 'label' => 'frequency'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<script>
(function () {
  var canvas = document.getElementById('canvas');
  var ctx    = canvas.getContext('2d', { willReadFrequently: true });

  /* ── Resolution (render at half res for performance) ──────────────── */
  var SC = 0.5;
  var W, H, rW, rH;

  function resize() {
    W  = window.innerWidth;
    H  = window.innerHeight;
    rW = Math.round(W * SC);
    rH = Math.round(H * SC);
    canvas.width  = rW;
    canvas.height = rH;
  }
  resize();
  window.addEventListener('resize', resize);

  /* ── Modes ────────────────────────────────────────────────────────── */
  var MODES = ['CIRCLES', 'LINES', 'RADIAL', 'GRID'];
  var mode = 0;

  /* ── State ────────────────────────────────────────────────────────── */
  var freq    = 12;
  var angle   = 0;
  var offsetX = 30;
  var offsetY = 20;
  var autoOn  = false;
  var time    = 0;

  /* ── Color LUT: intensity 0..255 → packed ABGR ────────────────────── */
  var LUT = new Uint32Array(256);
  for (var i = 0; i < 256; i++) {
    var t = i / 255;
    var r, g, b;
    if (t < 0.45) {
      var s = t / 0.45;
      r = Math.round(8 + s * 2);
      g = Math.round(8 + s * 160);
      b = Math.round(12 + s * 140);
    } else if (t < 0.75) {
      var s = (t - 0.45) / 0.30;
      r = Math.round(10 + s * 30);
      g = Math.round(168 + s * 77);
      b = Math.round(152 + s * 60);
    } else {
      var s = (t - 0.75) / 0.25;
      r = Math.round(40 + s * 200);
      g = Math.round(245 + s * 10);
      b = Math.round(212 + s * 43);
    }
    LUT[i] = (255 << 24) | (b << 16) | (g << 8) | r;
  }

  /* ── Pattern functions ────────────────────────────────────────────── */
  function circleVal(x, y, cx, cy, f) {
    var dx = x - cx, dy = y - cy;
    return (Math.cos(Math.sqrt(dx * dx + dy * dy) * f * 0.15) + 1) * 0.5;
  }

  function lineVal(x, y, theta, f) {
    return (Math.cos((x * Math.cos(theta) + y * Math.sin(theta)) * f * 0.15) + 1) * 0.5;
  }

  function radialVal(x, y, cx, cy, theta, f) {
    return (Math.cos((Math.atan2(y - cy, x - cx) + theta) * f * 2) + 1) * 0.5;
  }

  function gridVal(x, y, theta, f) {
    var c = Math.cos(theta), s = Math.sin(theta);
    var k = f * 0.15;
    var u = (Math.cos((x * c + y * s) * k) + 1) * 0.5;
    var v2 = (Math.cos((-x * s + y * c) * k) + 1) * 0.5;
    return (u + v2) * 0.5;
  }

  /* ── Render ───────────────────────────────────────────────────────── */
  function render() {
    var imgData = ctx.createImageData(rW, rH);
    var buf32   = new Uint32Array(imgData.data.buffer);
    var hx = rW * 0.5, hy = rH * 0.5;
    var ox = offsetX * SC, oy = offsetY * SC;

    for (var y = 0; y < rH; y++) {
      var off = y * rW;
      for (var x = 0; x < rW; x++) {
        var v1, v2;
        switch (mode) {
          case 0:
            v1 = circleVal(x, y, hx, hy, freq);
            v2 = circleVal(x, y, hx + ox, hy + oy, freq);
            break;
          case 1:
            v1 = lineVal(x, y, 0, freq);
            v2 = lineVal(x, y, angle, freq);
            break;
          case 2:
            v1 = radialVal(x, y, hx, hy, 0, freq);
            v2 = radialVal(x, y, hx, hy, angle, freq);
            break;
          case 3:
            v1 = gridVal(x, y, 0, freq);
            v2 = gridVal(x, y, angle, freq);
            break;
        }
        buf32[off + x] = LUT[Math.round(v1 * v2 * 255)];
      }
    }
    ctx.putImageData(imgData, 0, 0);
  }

  /* ── Auto animation ───────────────────────────────────────────────── */
  function autoStep() {
    time += 0.008;
    if (mode === 0) {
      offsetX = 55 * Math.sin(time * 1.1);
      offsetY = 55 * Math.cos(time * 0.7);
    } else {
      angle = 0.18 * Math.sin(time * 0.6);
    }
  }

  /* ── HUD ──────────────────────────────────────────────────────────── */
  var hudMode  = document.getElementById('hud-mode');
  var hudParam = document.getElementById('hud-param');
  var hudPRow  = document.getElementById('hud-param-row');
  var hudFreq  = document.getElementById('hud-freq');
  var lastHud  = 0;

  function updateHUD() {
    var now = Date.now();
    if (now - lastHud < 100) return;
    lastHud = now;
    hudMode.textContent = MODES[mode];
    if (mode === 0) {
      var d = Math.round(Math.sqrt(offsetX * offsetX + offsetY * offsetY));
      hudPRow.innerHTML = 'OFFSET <span class="v" id="hud-param">' + d + '</span>px';
    } else {
      hudPRow.innerHTML = 'ANGLE &nbsp;<span class="v" id="hud-param">' + (angle * 180 / Math.PI).toFixed(1) + '</span>\u00B0';
    }
    hudFreq.textContent = freq;
  }

  /* ── Loop ──────────────────────────────────────────────────────────── */
  function loop() {
    if (autoOn) autoStep();
    render();
    updateHUD();
    requestAnimationFrame(loop);
  }

  /* ── Interaction ──────────────────────────────────────────────────── */
  var dragging = false, dragX = 0, dragY = 0;

  function startDrag(x, y) { dragging = true; dragX = x; dragY = y; }

  function moveDrag(x, y) {
    if (!dragging) return;
    var dx = x - dragX, dy = y - dragY;
    dragX = x; dragY = y;
    if (mode === 0) { offsetX += dx; offsetY += dy; }
    else { angle += dx * 0.003; }
    if (typeof SFX !== 'undefined') SFX.play('move', 60);
  }

  function endDrag() { dragging = false; }

  canvas.addEventListener('mousedown',  function(e) { startDrag(e.clientX, e.clientY); });
  canvas.addEventListener('mousemove',  function(e) { moveDrag(e.clientX, e.clientY); });
  canvas.addEventListener('mouseup',    endDrag);
  canvas.addEventListener('mouseleave', endDrag);

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    startDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    moveDrag(e.touches[0].clientX, e.touches[0].clientY);
  }, { passive: false });
  canvas.addEventListener('touchend', function(e) { e.preventDefault(); endDrag(); }, { passive: false });

  canvas.addEventListener('wheel', function(e) {
    e.preventDefault();
    freq = Math.max(6, Math.min(30, freq - Math.sign(e.deltaY)));
    document.getElementById('sl-freq').value = freq;
    document.getElementById('val-freq').textContent = freq;
  }, { passive: false });

  /* ── Controls ─────────────────────────────────────────────────────── */
  document.getElementById('btn-mode').addEventListener('click', function() {
    mode = (mode + 1) % MODES.length;
    angle = 0; offsetX = 30; offsetY = 20; time = 0;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('sl-freq').addEventListener('input', function() {
    freq = parseInt(this.value);
    document.getElementById('val-freq').textContent = freq;
  });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoOn = !autoOn;
    this.textContent = autoOn ? 'AUTO ON' : 'AUTO OFF';
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    mode = 0; freq = 12; angle = 0; offsetX = 30; offsetY = 20; time = 0;
    autoOn = false;
    document.getElementById('btn-auto').textContent = 'AUTO OFF';
    document.getElementById('sl-freq').value = 12;
    document.getElementById('val-freq').textContent = '12';
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  /* ── Start ────────────────────────────────────────────────────────── */
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
