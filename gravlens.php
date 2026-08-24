<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gravlens');

$pageName = 'gravlens';
$pageTitle = 'GRAVLENS - PULSE';
$pageDesc = '重力レンズ。質量が時空を曲げ、背景の星の光が歪む。アインシュタインリングが浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/gravlens.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
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

<div class="hud">
  LENSES: <span id="hudN" class="v">1</span><br>
  TOTAL MASS: <span id="hudM" class="v">60</span>
</div>

<div id="info">
  gravitational lensing<br>
  deflection &alpha; = R<sub>E</sub><sup>2</sup> / r
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place mass'],
  ['type' => 'drag', 'label' => 'move mass'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">MASS</label>
    <div class="slider-row">
      <input type="range" id="massSlider" min="10" max="200" value="60">
      <span class="slider-value" id="massVal">60</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESET</label>
    <div class="btn-row">
      <button class="control-btn" id="pSingle">SINGLE</button>
      <button class="control-btn" id="pBinary">BINARY</button>
      <button class="control-btn" id="pCluster">CLUSTER</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="resetBtn">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, S, rW, rH, K;
  var tmpCv, tmpCtx, outImg, dst;
  var bg32;
  var lenses = [];
  var nextMass = 60;
  var dragging = -1, dox = 0, doy = 0;
  var lpTimer = null, lp = false;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    S = Math.max(2, Math.ceil(Math.min(W, H) / 280));
    rW = Math.ceil(W / S);
    rH = Math.ceil(H / S);
    K = Math.min(W, H) * 0.12;

    tmpCv = document.createElement('canvas');
    tmpCv.width = rW;
    tmpCv.height = rH;
    tmpCtx = tmpCv.getContext('2d');
    outImg = tmpCtx.createImageData(rW, rH);
    dst = new Uint32Array(outImg.data.buffer);

    for (var i = 0; i < lenses.length; i++) {
      lenses[i].re2 = lenses[i].mass * K;
    }
    makeBg();
  }

  function makeBg() {
    var cv = document.createElement('canvas');
    cv.width = rW; cv.height = rH;
    var c = cv.getContext('2d');
    var i, x, y;

    c.fillStyle = '#0a0a0f';
    c.fillRect(0, 0, rW, rH);

    var sp = Math.max(6, Math.round(36 / S));
    c.strokeStyle = 'rgba(0,245,212,0.07)';
    c.lineWidth = 0.5;
    for (x = sp; x < rW; x += sp) {
      c.beginPath(); c.moveTo(x, 0); c.lineTo(x, rH); c.stroke();
    }
    for (y = sp; y < rH; y += sp) {
      c.beginPath(); c.moveTo(0, y); c.lineTo(rW, y); c.stroke();
    }

    c.fillStyle = 'rgba(0,245,212,0.15)';
    for (x = sp; x < rW; x += sp) {
      for (y = sp; y < rH; y += sp) {
        c.beginPath(); c.arc(x, y, 0.6, 0, 6.283); c.fill();
      }
    }

    var re0 = Math.sqrt(60 * K) / S;
    var gals = [
      { dx: re0 * 1.0, dy: re0 * 0.6, rx: re0 * 0.45, ry: re0 * 0.2, a: 0.3, h: 182 },
      { dx: -re0 * 1.3, dy: -re0 * 0.4, rx: re0 * 0.35, ry: re0 * 0.15, a: 1.2, h: 268 }
    ];
    for (i = 0; i < gals.length; i++) {
      var gl = gals[i];
      c.save();
      c.translate(rW / 2 + gl.dx, rH / 2 + gl.dy);
      c.rotate(gl.a);
      c.scale(1, gl.ry / gl.rx);
      var gg = c.createRadialGradient(0, 0, 0, 0, 0, gl.rx);
      gg.addColorStop(0, 'hsla(' + gl.h + ',75%,55%,0.8)');
      gg.addColorStop(0.5, 'hsla(' + gl.h + ',60%,40%,0.35)');
      gg.addColorStop(1, 'hsla(' + gl.h + ',50%,30%,0)');
      c.fillStyle = gg;
      c.beginPath();
      c.arc(0, 0, gl.rx, 0, 6.283);
      c.fill();
      c.restore();
    }

    for (i = 0; i < 4; i++) {
      var gx = rW * (0.1 + Math.random() * 0.8);
      var gy = rH * (0.1 + Math.random() * 0.8);
      var grx = 5 + Math.random() * 8;
      var gry = 2 + Math.random() * 4;
      var ga = Math.random() * Math.PI;
      var gh = 175 + (Math.random() * 90 | 0);
      c.save();
      c.translate(gx, gy);
      c.rotate(ga);
      c.scale(1, gry / grx);
      var g2 = c.createRadialGradient(0, 0, 0, 0, 0, grx);
      g2.addColorStop(0, 'hsla(' + gh + ',70%,55%,0.6)');
      g2.addColorStop(0.5, 'hsla(' + gh + ',55%,35%,0.25)');
      g2.addColorStop(1, 'hsla(' + gh + ',45%,25%,0)');
      c.fillStyle = g2;
      c.beginPath();
      c.arc(0, 0, grx, 0, 6.283);
      c.fill();
      c.restore();
    }

    var sn = Math.round(rW * rH / 200);
    for (i = 0; i < sn; i++) {
      var b = Math.random();
      c.beginPath();
      c.arc(Math.random() * rW, Math.random() * rH, 0.3 + Math.random() * 0.9, 0, 6.283);
      c.fillStyle = 'rgba(' + (160 + 95 * b | 0) + ',' + (210 + 45 * b | 0) + ',' + (200 + 40 * b | 0) + ',' + (0.3 + 0.7 * b).toFixed(2) + ')';
      c.fill();
    }

    bg32 = new Uint32Array(c.getImageData(0, 0, rW, rH).data.buffer.slice(0));
  }

  function nearest(px, py, md) {
    var bi = -1, bd = md * md;
    for (var i = 0; i < lenses.length; i++) {
      var dx = px - lenses[i].x, dy = py - lenses[i].y;
      var d2 = dx * dx + dy * dy;
      if (d2 < bd) { bd = d2; bi = i; }
    }
    return bi;
  }

  function addLens(x, y, m) {
    lenses.push({ x: x, y: y, mass: m, re2: m * K });
  }

  function updateHUD() {
    document.getElementById('hudN').textContent = lenses.length;
    var t = 0;
    for (var i = 0; i < lenses.length; i++) t += lenses[i].mass;
    document.getElementById('hudM').textContent = t;
  }

  function render() {
    var nL = lenses.length;
    var py, px, iy, ix, sx, sy, i, L, dx, dy, r2, f, bx, by;

    for (py = 0; py < rH; py++) {
      iy = py * S;
      for (px = 0; px < rW; px++) {
        ix = px * S;
        sx = ix; sy = iy;

        for (i = 0; i < nL; i++) {
          L = lenses[i];
          dx = ix - L.x;
          dy = iy - L.y;
          r2 = dx * dx + dy * dy;
          if (r2 > 4) {
            f = L.re2 / r2;
            sx -= dx * f;
            sy -= dy * f;
          }
        }

        bx = (sx / S) | 0;
        by = (sy / S) | 0;
        if (bx < 0) bx = 0; else if (bx >= rW) bx = rW - 1;
        if (by < 0) by = 0; else if (by >= rH) by = rH - 1;

        dst[py * rW + px] = bg32[by * rW + bx];
      }
    }

    tmpCtx.putImageData(outImg, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(tmpCv, 0, 0, W, H);

    for (i = 0; i < nL; i++) {
      L = lenses[i];
      var re = Math.sqrt(L.re2);

      ctx.beginPath();
      ctx.arc(L.x, L.y, re, 0, 6.283);
      ctx.strokeStyle = 'rgba(0,245,212,0.06)';
      ctx.lineWidth = Math.max(6, re * 0.15);
      ctx.stroke();

      ctx.beginPath();
      ctx.arc(L.x, L.y, re, 0, 6.283);
      ctx.strokeStyle = 'rgba(0,245,212,0.18)';
      ctx.lineWidth = 1;
      ctx.stroke();

      var g = ctx.createRadialGradient(L.x, L.y, 0, L.x, L.y, 16);
      g.addColorStop(0, 'rgba(123,44,191,0.5)');
      g.addColorStop(1, 'rgba(123,44,191,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(L.x, L.y, 16, 0, 6.283);
      ctx.fill();

      ctx.beginPath();
      ctx.arc(L.x, L.y, 3, 0, 6.283);
      ctx.fillStyle = '#7b2cbf';
      ctx.fill();
    }

    updateHUD();
    requestAnimationFrame(render);
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    var idx = nearest(e.clientX, e.clientY, 30);
    if (idx >= 0) {
      dragging = idx;
      dox = e.clientX - lenses[idx].x;
      doy = e.clientY - lenses[idx].y;
    } else {
      addLens(e.clientX, e.clientY, nextMass);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (dragging >= 0) {
      lenses[dragging].x = e.clientX - dox;
      lenses[dragging].y = e.clientY - doy;
    }
  });

  C.addEventListener('mouseup', function() {
    if (dragging >= 0 && typeof SFX !== 'undefined') SFX.play('move', 100);
    dragging = -1;
  });

  C.addEventListener('mouseleave', function() { dragging = -1; });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var idx = nearest(e.clientX, e.clientY, 60);
    if (idx >= 0) {
      lenses.splice(idx, 1);
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    lp = false;
    var idx = nearest(t.clientX, t.clientY, 45);
    if (idx >= 0) {
      dragging = idx;
      dox = t.clientX - lenses[idx].x;
      doy = t.clientY - lenses[idx].y;
      lpTimer = setTimeout(function() {
        lp = true;
        if (dragging >= 0) {
          lenses.splice(dragging, 1);
          dragging = -1;
          if (typeof SFX !== 'undefined') SFX.play('pop');
        }
      }, 500);
    } else {
      addLens(t.clientX, t.clientY, nextMass);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
    if (dragging >= 0) {
      var t = e.touches[0];
      lenses[dragging].x = t.clientX - dox;
      lenses[dragging].y = t.clientY - doy;
    }
  }, { passive: false });

  C.addEventListener('touchend', function() {
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
    if (dragging >= 0 && !lp && typeof SFX !== 'undefined') SFX.play('move', 100);
    dragging = -1;
  });

  document.getElementById('massSlider').addEventListener('input', function() {
    nextMass = parseInt(this.value);
    document.getElementById('massVal').textContent = nextMass;
  });

  function preset(name) {
    lenses = [];
    var cx = W / 2, cy = H / 2;
    var r = Math.min(W, H) * 0.15;
    if (name === 'single') {
      addLens(cx, cy, 80);
    } else if (name === 'binary') {
      addLens(cx - r, cy, 60);
      addLens(cx + r, cy, 60);
    } else if (name === 'cluster') {
      for (var j = 0; j < 5; j++) {
        var a = j * 1.2566;
        addLens(cx + Math.cos(a) * r * 0.7, cy + Math.sin(a) * r * 0.7, 25 + (Math.random() * 35 | 0));
      }
    }
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  document.getElementById('pSingle').addEventListener('click', function() { preset('single'); });
  document.getElementById('pBinary').addEventListener('click', function() { preset('binary'); });
  document.getElementById('pCluster').addEventListener('click', function() { preset('cluster'); });
  document.getElementById('resetBtn').addEventListener('click', function() {
    lenses = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  addLens(W / 2, H / 2, 60);
  render();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
