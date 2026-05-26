<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lyapunov');

$pageName = 'lyapunov';
$pageTitle = 'LYAPUNOV - PULSE';
$pageDesc = 'リャプノフフラクタル。ロジスティック写像のパラメータ空間にリャプノフ指数を描くと、安定とカオスの境界がフラクタル模様を作る。';
$pageCanonical = 'https://pulse.subara3.com/lyapunov.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;image-rendering:auto}
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
  SEQ: <span class="v" id="hud-seq">AB</span><br>
  ZOOM: <span class="v" id="hud-zoom">1.0×</span><br>
  A: <span class="v" id="hud-a">—</span><br>
  B: <span class="v" id="hud-b">—</span><br>
  λ: <span class="v" id="hud-lambda">—</span>
</div>

<div id="info">
  <span class="v">lyapunov</span><br>
  x → r·x·(1−x)<br>
  r alternates A, B
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
  ['type' => 'click', 'label' => 'probe'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label>ITERS</label>
    <input type="range" id="sl-iters" min="40" max="200" step="10" value="80">
    <span class="slider-value" id="sv-iters">80</span>
  </div>
  <div class="slider-row">
    <label>WARMUP</label>
    <input type="range" id="sl-warmup" min="10" max="60" step="5" value="20">
    <span class="slider-value" id="sv-warmup">20</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" data-seq="AB">AB</button>
    <button class="control-btn" data-seq="AABB">AABB</button>
    <button class="control-btn" data-seq="ABAB">ABAB</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" data-seq="AAABB">AAABB</button>
    <button class="control-btn" data-seq="ABBB">ABBB</button>
    <button class="control-btn" data-seq="BBBBA">BBBBA</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-fit">FIT</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H;

  var RW = 0, RH = 0;
  var offCanvas = document.createElement('canvas');
  var offCtx = offCanvas.getContext('2d');
  var SCALE = 0.5;

  var vx0 = 2.0, vy0 = 2.0, vx1 = 4.0, vy1 = 4.0;
  var seq = [0, 1];
  var seqStr = 'AB';
  var iters = 80;
  var warmup = 20;
  var dirty = true;
  var renderRow = 0;
  var rowsPerFrame = 0;
  var mouseWX = -1, mouseWY = -1;

  var hudSeq = document.getElementById('hud-seq');
  var hudZoom = document.getElementById('hud-zoom');
  var hudA = document.getElementById('hud-a');
  var hudB = document.getElementById('hud-b');
  var hudLambda = document.getElementById('hud-lambda');

  function resize() {
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W;
    C.height = H;
    RW = Math.ceil(W * SCALE);
    RH = Math.ceil(H * SCALE);
    offCanvas.width = RW;
    offCanvas.height = RH;
    rowsPerFrame = Math.max(4, Math.ceil(RH / 30));
    dirty = true;
    renderRow = 0;
  }
  window.addEventListener('resize', resize);
  resize();

  function parseSeq(s) {
    var a = [];
    for (var i = 0; i < s.length; i++) a.push(s.charAt(i) === 'A' ? 0 : 1);
    return a;
  }

  function lyap(A, B, wN, iN, sa) {
    var x = 0.5;
    var sL = sa.length;
    var p0 = A, p1 = B;
    for (var i = 0; i < wN; i++) {
      var r = sa[i % sL] === 0 ? p0 : p1;
      x = r * x * (1 - x);
      if (x <= 1e-12 || x >= 1 - 1e-12) return NaN;
    }
    var sum = 0;
    for (var i = 0; i < iN; i++) {
      var r = sa[(wN + i) % sL] === 0 ? p0 : p1;
      x = r * x * (1 - x);
      if (x <= 1e-12 || x >= 1 - 1e-12) return NaN;
      var d = Math.abs(r * (1 - 2 * x));
      if (d < 1e-15) return -10;
      sum += Math.log(d);
    }
    return sum / iN;
  }

  function colorMap(lam) {
    if (isNaN(lam)) return 0xFF0F0A0A;
    if (lam < -3) lam = -3;
    if (lam > 1.5) lam = 1.5;
    var r, g, b;
    if (lam < 0) {
      var t = Math.min(1, -lam / 2.0);
      t = Math.sqrt(t);
      r = Math.floor(10 * (1 - t));
      g = Math.floor(30 + t * 215);
      b = Math.floor(25 + t * 187);
    } else {
      var t = Math.min(1, lam / 1.0);
      t = Math.sqrt(t);
      r = Math.floor(10 + t * 113);
      g = Math.floor(30 - t * 20);
      b = Math.floor(25 + t * 166);
    }
    if (Math.abs(lam) < 0.03) {
      var e = 1 - Math.abs(lam) / 0.03;
      r = Math.min(255, Math.floor(r + (255 - r) * e * 0.5));
      g = Math.min(255, Math.floor(g + (255 - g) * e * 0.5));
      b = Math.min(255, Math.floor(b + (255 - b) * e * 0.5));
    }
    return (255 << 24) | (b << 16) | (g << 8) | r;
  }

  function renderRows(startY, count) {
    var endY = Math.min(RH, startY + count);
    if (startY >= endY) return;
    var imgData = offCtx.getImageData(0, startY, RW, endY - startY);
    var buf32 = new Uint32Array(imgData.data.buffer);
    var vw = vx1 - vx0;
    var vh = vy1 - vy0;
    for (var y = startY; y < endY; y++) {
      var B = vy0 + ((y + 0.5) / RH) * vh;
      var row = (y - startY) * RW;
      for (var x = 0; x < RW; x++) {
        var A = vx0 + ((x + 0.5) / RW) * vw;
        buf32[row + x] = colorMap(lyap(A, B, warmup, iters, seq));
      }
    }
    offCtx.putImageData(imgData, 0, startY);
  }

  function blitToMain() {
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(offCanvas, 0, 0, W, H);
  }

  function s2w(sx, sy) {
    var vw = vx1 - vx0, vh = vy1 - vy0;
    return [vx0 + (sx / W) * vw, vy0 + (sy / H) * vh];
  }

  function updateHUD() {
    hudSeq.textContent = seqStr;
    var zoom = 2.0 / (vx1 - vx0);
    hudZoom.textContent = zoom.toFixed(1) + '×';
    if (mouseWX >= 0) {
      hudA.textContent = mouseWX.toFixed(3);
      hudB.textContent = mouseWY.toFixed(3);
      var l = lyap(mouseWX, mouseWY, warmup, iters, seq);
      hudLambda.textContent = isNaN(l) ? 'div' : l.toFixed(4);
    } else {
      hudA.textContent = '—';
      hudB.textContent = '—';
      hudLambda.textContent = '—';
    }
  }

  function loop() {
    requestAnimationFrame(loop);
    if (dirty) {
      if (renderRow === 0) {
        offCtx.fillStyle = '#0a0a0f';
        offCtx.fillRect(0, 0, RW, RH);
      }
      renderRows(renderRow, rowsPerFrame);
      renderRow += rowsPerFrame;
      blitToMain();
      if (renderRow >= RH) {
        dirty = false;
        renderRow = 0;
      }
    }
    updateHUD();
  }

  function triggerRender() {
    dirty = true;
    renderRow = 0;
  }

  // Mouse
  var dragging = false, dragSX, dragSY, dragVX0, dragVY0, dragVX1, dragVY1, dragMoved;

  C.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    dragging = true;
    dragSX = e.clientX; dragSY = e.clientY;
    dragVX0 = vx0; dragVY0 = vy0; dragVX1 = vx1; dragVY1 = vy1;
    dragMoved = false;
  });

  C.addEventListener('mousemove', function(e) {
    var w = s2w(e.clientX, e.clientY);
    mouseWX = w[0]; mouseWY = w[1];
    if (!dragging) return;
    var dx = e.clientX - dragSX, dy = e.clientY - dragSY;
    if (Math.abs(dx) + Math.abs(dy) > 3) dragMoved = true;
    var vw = dragVX1 - dragVX0, vh = dragVY1 - dragVY0;
    var shiftX = -(dx / W) * vw, shiftY = -(dy / H) * vh;
    vx0 = dragVX0 + shiftX; vx1 = dragVX1 + shiftX;
    vy0 = dragVY0 + shiftY; vy1 = dragVY1 + shiftY;
    triggerRender();
  });

  window.addEventListener('mouseup', function() {
    if (dragging && !dragMoved) {
      if (typeof SFX !== 'undefined') SFX.play('click');
    }
    dragging = false;
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var w = s2w(e.clientX, e.clientY);
    var factor = e.deltaY > 0 ? 1.18 : 1 / 1.18;
    var vw = (vx1 - vx0) * factor, vh = (vy1 - vy0) * factor;
    if (vw < 0.0005 || vw > 4) return;
    var rx = e.clientX / W, ry = e.clientY / H;
    vx0 = w[0] - rx * vw;
    vy0 = w[1] - ry * vh;
    vx1 = vx0 + vw;
    vy1 = vy0 + vh;
    triggerRender();
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  }, { passive: false });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // Touch
  var tId = null, tSX, tSY, tMoved;
  var pinch = null;

  function tdist(a, b) { return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      var t = e.touches[0];
      tId = t.identifier; tSX = t.clientX; tSY = t.clientY; tMoved = false;
      dragVX0 = vx0; dragVY0 = vy0; dragVX1 = vx1; dragVY1 = vy1;
    } else if (e.touches.length === 2) {
      pinch = { d: tdist(e.touches[0], e.touches[1]), vx0: vx0, vy0: vy0, vx1: vx1, vy1: vy1,
        cx: (e.touches[0].clientX + e.touches[1].clientX) / 2,
        cy: (e.touches[0].clientY + e.touches[1].clientY) / 2 };
      tId = null;
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 1 && tId !== null && e.touches[0].identifier === tId) {
      var t = e.touches[0];
      var dx = t.clientX - tSX, dy = t.clientY - tSY;
      if (Math.abs(dx) + Math.abs(dy) > 6) tMoved = true;
      var vw = dragVX1 - dragVX0, vh = dragVY1 - dragVY0;
      vx0 = dragVX0 - (dx / W) * vw;
      vy0 = dragVY0 - (dy / H) * vh;
      vx1 = vx0 + vw; vy1 = vy0 + vh;
      triggerRender();
    } else if (e.touches.length === 2 && pinch) {
      var d = tdist(e.touches[0], e.touches[1]);
      var scale = pinch.d / d;
      var ow = pinch.vx1 - pinch.vx0, oh = pinch.vy1 - pinch.vy0;
      var nw = Math.max(0.0005, Math.min(4, ow * scale));
      var nh = Math.max(0.0005, Math.min(4, oh * scale));
      var w = s2w(pinch.cx, pinch.cy);
      var rx = pinch.cx / W, ry = pinch.cy / H;
      vx0 = w[0] - rx * nw; vy0 = w[1] - ry * nh;
      vx1 = vx0 + nw; vy1 = vy0 + nh;
      triggerRender();
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (tId !== null && !tMoved) {
        var w = s2w(tSX, tSY);
        mouseWX = w[0]; mouseWY = w[1];
        if (typeof SFX !== 'undefined') SFX.play('click');
      }
      tId = null; pinch = null;
    }
  });

  // Controls
  var slIters = document.getElementById('sl-iters');
  var slWarmup = document.getElementById('sl-warmup');
  var svIters = document.getElementById('sv-iters');
  var svWarmup = document.getElementById('sv-warmup');

  slIters.addEventListener('input', function() {
    iters = parseInt(this.value); svIters.textContent = iters;
    triggerRender();
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  slWarmup.addEventListener('input', function() {
    warmup = parseInt(this.value); svWarmup.textContent = warmup;
    triggerRender();
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });

  var seqBtns = document.querySelectorAll('[data-seq]');
  for (var i = 0; i < seqBtns.length; i++) {
    seqBtns[i].addEventListener('click', function() {
      for (var j = 0; j < seqBtns.length; j++) seqBtns[j].classList.remove('active');
      this.classList.add('active');
      seqStr = this.getAttribute('data-seq');
      seq = parseSeq(seqStr);
      triggerRender();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  document.getElementById('btn-fit').addEventListener('click', function() {
    vx0 = 2; vy0 = 2; vx1 = 4; vy1 = 4;
    triggerRender();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    vx0 = 2; vy0 = 2; vx1 = 4; vy1 = 4;
    iters = 80; warmup = 20; seqStr = 'AB'; seq = [0, 1];
    slIters.value = 80; svIters.textContent = '80';
    slWarmup.value = 20; svWarmup.textContent = '20';
    for (var j = 0; j < seqBtns.length; j++) {
      seqBtns[j].classList.remove('active');
      if (seqBtns[j].getAttribute('data-seq') === 'AB') seqBtns[j].classList.add('active');
    }
    triggerRender();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  triggerRender();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
