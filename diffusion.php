<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('diffusion');

$pageName = 'diffusion';
$pageTitle = 'DIFFUSION - PULSE';
$pageDesc = '熱拡散シミュレーション。熱源と冷源を描き、熱方程式に従う温度の拡散を観察する。';
$pageCanonical = 'https://pulse.subara3.com/diffusion.php';
$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:60px;left:16px;color:rgba(0,245,212,.5);font:11px/1.6 "SF Mono","Fira Code",monospace;pointer-events:none;z-index:10;text-transform:uppercase;letter-spacing:.04em}
.hud .v{color:#00f5d4}
#info{position:fixed;bottom:16px;left:16px;color:rgba(0,245,212,.3);font:11px/1.6 "SF Mono","Fira Code",monospace;pointer-events:none;z-index:10;max-width:280px}
#info .v{color:rgba(0,245,212,.55)}
@media(max-width:768px){
  .hud{top:48px;left:10px;font-size:10px}
  #info{bottom:10px;left:10px;font-size:10px;max-width:180px}
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
MODE: <span id="hudMode" class="v">HOT</span><br>
BRUSH: <span id="hudBrush" class="v">4</span><br>
TEMP: <span id="hudTemp" class="v">—</span>
</div>
<div id="info">
heat equation — <span class="v">∂T/∂t = α∇²T</span><br>
paint sources, draw walls
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'paint'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'erase'],
  ['type' => 'wheel', 'label' => 'brush'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btnHot">HOT</button>
    <button class="control-btn" id="btnCold">COLD</button>
    <button class="control-btn" id="btnWall">WALL</button>
    <button class="control-btn" id="btnErase">ERASE</button>
  </div>
  <div class="slider-row">
    <span>RATE</span>
    <input type="range" id="sldRate" min="2" max="24" value="18">
    <span id="valRate">0.18</span>
  </div>
  <div class="slider-row">
    <span>BRUSH</span>
    <input type="range" id="sldBrush" min="1" max="20" value="4">
    <span id="valBrush">4</span>
  </div>
  <div class="slider-row">
    <span>SPEED</span>
    <input type="range" id="sldSpeed" min="1" max="10" value="3">
    <span id="valSpeed">3</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnPause">PAUSE</button>
    <button class="control-btn" id="btnSeed">SEED</button>
    <button class="control-btn" id="btnClear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, gw, gh, cs;
  var T, B, wal;
  var oC = document.createElement('canvas'), oX, imgD;

  var paused = false, mode = 0, br = 4, rate = 0.18, spf = 3;
  var lmd = false, rmd = false, mx = -1, my = -1, pmx, pmy;

  var modeN = ['HOT', 'COLD', 'WALL', 'ERASE'];
  var eH = document.getElementById('hudMode');
  var eB = document.getElementById('hudBrush');
  var eT = document.getElementById('hudTemp');

  var lut = new Uint8Array(1024);

  function buildLUT() {
    var stops = [
      [0, 50, 18, 100],
      [0.2, 100, 35, 165],
      [0.35, 70, 25, 120],
      [0.47, 18, 12, 30],
      [0.53, 12, 20, 25],
      [0.65, 0, 80, 70],
      [0.8, 0, 160, 140],
      [1, 0, 245, 212]
    ];
    for (var i = 0; i < 256; i++) {
      var v = i / 255, a, b;
      for (var j = 0; j < stops.length - 1; j++) {
        if (v <= stops[j + 1][0]) { a = stops[j]; b = stops[j + 1]; break; }
      }
      if (!a) { a = stops[stops.length - 2]; b = stops[stops.length - 1]; }
      var f = (v - a[0]) / (b[0] - a[0]);
      f = f * f * (3 - 2 * f);
      lut[i * 4] = a[1] + (b[1] - a[1]) * f + 0.5 | 0;
      lut[i * 4 + 1] = a[2] + (b[2] - a[2]) * f + 0.5 | 0;
      lut[i * 4 + 2] = a[3] + (b[3] - a[3]) * f + 0.5 | 0;
      lut[i * 4 + 3] = 255;
    }
  }

  function initGrid() {
    T = new Float32Array(gw * gh).fill(0.5);
    B = new Float32Array(gw * gh);
    wal = new Uint8Array(gw * gh);
  }

  function resize() {
    W = window.innerWidth; H = window.innerHeight;
    C.width = W; C.height = H;
    cs = W < 600 ? 4 : 3;
    var nw = Math.ceil(W / cs), nh = Math.ceil(H / cs);
    var oT = T, oW = wal, ow = gw, oh = gh;
    gw = nw; gh = nh;
    initGrid();
    if (oT) {
      var mw = Math.min(ow || 0, gw), mh = Math.min(oh || 0, gh);
      for (var y = 0; y < mh; y++)
        for (var x = 0; x < mw; x++) {
          T[y * gw + x] = oT[y * ow + x];
          wal[y * gw + x] = oW[y * ow + x];
        }
    } else {
      seed();
    }
    oC.width = gw; oC.height = gh;
    oX = oC.getContext('2d');
    imgD = oX.createImageData(gw, gh);
  }

  function seed() {
    T.fill(0.5); wal.fill(0);
    var n = 4 + (Math.random() * 4 | 0);
    for (var i = 0; i < n; i++) {
      var cx = gw * 0.15 + Math.random() * gw * 0.7 | 0;
      var cy = gh * 0.15 + Math.random() * gh * 0.7 | 0;
      var r = 6 + (Math.random() * 10 | 0);
      var v = i % 2 === 0 ? 1.0 : 0.0;
      fillCircle(cx, cy, r, v, false);
    }
  }

  function fillCircle(cx, cy, r, val, isWall) {
    for (var dy = -r; dy <= r; dy++)
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy > r * r) continue;
        var x = cx + dx, y = cy + dy;
        if (x < 0 || x >= gw || y < 0 || y >= gh) continue;
        var i = y * gw + x;
        if (isWall) wal[i] = 1;
        else T[i] = val;
      }
  }

  function applyBrush(gx, gy, m) {
    for (var dy = -br; dy <= br; dy++)
      for (var dx = -br; dx <= br; dx++) {
        if (dx * dx + dy * dy > br * br) continue;
        var x = gx + dx, y = gy + dy;
        if (x < 0 || x >= gw || y < 0 || y >= gh) continue;
        var i = y * gw + x;
        if (m === 0) T[i] = 1.0;
        else if (m === 1) T[i] = 0.0;
        else if (m === 2) wal[i] = 1;
        else { wal[i] = 0; T[i] = 0.5; }
      }
  }

  function drawLine(x0, y0, x1, y1, m) {
    var dx = x1 - x0, dy = y1 - y0;
    var st = Math.max(Math.abs(dx), Math.abs(dy), 1);
    for (var s = 0; s <= st; s++) {
      var t = st > 0 ? s / st : 0;
      applyBrush(x0 + dx * t | 0, y0 + dy * t | 0, m);
    }
  }

  function step() {
    for (var y = 1; y < gh - 1; y++) {
      for (var x = 1; x < gw - 1; x++) {
        var i = y * gw + x;
        if (wal[i]) { B[i] = T[i]; continue; }
        var sum = 0, cnt = 0;
        if (!wal[i - 1]) { sum += T[i - 1]; cnt++; }
        if (!wal[i + 1]) { sum += T[i + 1]; cnt++; }
        if (!wal[i - gw]) { sum += T[i - gw]; cnt++; }
        if (!wal[i + gw]) { sum += T[i + gw]; cnt++; }
        B[i] = cnt > 0 ? T[i] + rate * (sum / cnt - T[i]) : T[i];
      }
    }
    for (var x = 0; x < gw; x++) {
      B[x] = B[gw + x];
      B[(gh - 1) * gw + x] = B[(gh - 2) * gw + x];
    }
    for (var y = 0; y < gh; y++) {
      B[y * gw] = B[y * gw + 1];
      B[y * gw + gw - 1] = B[y * gw + gw - 2];
    }
    var tmp = T; T = B; B = tmp;
  }

  function render() {
    var d = imgD.data;
    for (var i = 0, n = gw * gh; i < n; i++) {
      var p = i << 2;
      if (wal[i]) {
        d[p] = 35; d[p + 1] = 35; d[p + 2] = 50; d[p + 3] = 255;
      } else {
        var ci = T[i] * 255 + 0.5 | 0;
        if (ci < 0) ci = 0; if (ci > 255) ci = 255;
        ci <<= 2;
        d[p] = lut[ci]; d[p + 1] = lut[ci + 1]; d[p + 2] = lut[ci + 2]; d[p + 3] = 255;
      }
    }
    oX.putImageData(imgD, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(oC, 0, 0, W, H);

    if (mx >= 0 && my >= 0) {
      var r = br * cs;
      ctx.strokeStyle = mode === 0 ? 'rgba(0,245,212,.4)' :
                        mode === 1 ? 'rgba(123,44,191,.5)' :
                        mode === 2 ? 'rgba(255,255,255,.25)' :
                                     'rgba(255,255,255,.2)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(mx, my, r, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  function hud() {
    eH.textContent = modeN[mode];
    eB.textContent = br;
    if (mx >= 0 && my >= 0) {
      var gx = mx / cs | 0, gy = my / cs | 0;
      if (gx >= 0 && gx < gw && gy >= 0 && gy < gh) {
        eT.textContent = T[gy * gw + gx].toFixed(2);
      } else { eT.textContent = '—'; }
    } else { eT.textContent = '—'; }
  }

  function s2g(sx, sy) { return [sx / cs | 0, sy / cs | 0]; }

  C.addEventListener('mousedown', function(e) {
    mx = e.clientX; my = e.clientY; pmx = mx; pmy = my;
    if (e.button === 0) {
      lmd = true;
      var g = s2g(mx, my);
      applyBrush(g[0], g[1], mode);
      SFX.play(mode < 2 ? 'spawn' : 'tick');
    } else if (e.button === 2) {
      rmd = true;
      var g = s2g(mx, my);
      applyBrush(g[0], g[1], 3);
      SFX.play('pop');
    }
  });

  window.addEventListener('mousemove', function(e) {
    mx = e.clientX; my = e.clientY;
    if (lmd) {
      var g0 = s2g(pmx, pmy), g1 = s2g(mx, my);
      drawLine(g0[0], g0[1], g1[0], g1[1], mode);
    }
    if (rmd) {
      var g0 = s2g(pmx, pmy), g1 = s2g(mx, my);
      drawLine(g0[0], g0[1], g1[0], g1[1], 3);
    }
    pmx = mx; pmy = my;
    hud();
  });

  window.addEventListener('mouseup', function(e) {
    if (e.button === 0) lmd = false;
    if (e.button === 2) rmd = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    br += e.deltaY < 0 ? 1 : -1;
    if (br < 1) br = 1; if (br > 20) br = 20;
    sB.value = br; vB.textContent = br;
    hud();
    SFX.play('tick', 80);
  }, {passive: false});

  var tch = false;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      tch = true;
      mx = e.touches[0].clientX; my = e.touches[0].clientY;
      pmx = mx; pmy = my;
      var g = s2g(mx, my);
      applyBrush(g[0], g[1], mode);
      SFX.play(mode < 2 ? 'spawn' : 'tick');
    }
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (tch && e.touches.length === 1) {
      mx = e.touches[0].clientX; my = e.touches[0].clientY;
      var g0 = s2g(pmx, pmy), g1 = s2g(mx, my);
      drawLine(g0[0], g0[1], g1[0], g1[1], mode);
      pmx = mx; pmy = my;
    }
  }, {passive: false});

  C.addEventListener('touchend', function() { tch = false; });

  var sR = document.getElementById('sldRate'), vR = document.getElementById('valRate');
  var sB = document.getElementById('sldBrush'), vB = document.getElementById('valBrush');
  var sS = document.getElementById('sldSpeed'), vS = document.getElementById('valSpeed');
  var mb = [
    document.getElementById('btnHot'),
    document.getElementById('btnCold'),
    document.getElementById('btnWall'),
    document.getElementById('btnErase')
  ];

  function setMode(m) {
    mode = m;
    mb.forEach(function(b, i) { b.classList.toggle('active', i === m); });
    C.style.cursor = m === 2 ? 'cell' : m === 3 ? 'not-allowed' : 'crosshair';
    hud();
    SFX.play('toggle');
  }

  mb.forEach(function(b, i) {
    b.addEventListener('click', function() { setMode(i); });
  });

  sR.addEventListener('input', function() {
    rate = +this.value / 100;
    vR.textContent = rate.toFixed(2);
  });

  sB.addEventListener('input', function() {
    br = +this.value;
    vB.textContent = br;
    hud();
  });

  sS.addEventListener('input', function() {
    spf = +this.value;
    vS.textContent = spf;
  });

  document.getElementById('btnPause').addEventListener('click', function() {
    paused = !paused;
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', paused);
    SFX.play('toggle');
  });

  document.getElementById('btnSeed').addEventListener('click', function() {
    seed();
    SFX.play('click');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    T.fill(0.5); wal.fill(0);
    SFX.play('click');
  });

  var lt = 0;
  function loop(t) {
    requestAnimationFrame(loop);
    if (!lt) { lt = t; return; }
    lt = t;
    if (!paused) {
      for (var i = 0; i < spf; i++) step();
    }
    render();
  }

  buildLUT();
  resize();
  window.addEventListener('resize', resize);
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
