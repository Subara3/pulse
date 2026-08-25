<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('shear');

$pageName = 'shear';
$pageTitle = 'SHEAR - PULSE';
$pageDesc = 'ケルビン・ヘルムホルツ不安定性。二層の流体がすれ違うとき、界面が渦を巻いて螺旋状に巻き上がる。';
$pageCanonical = 'https://pulse.subara3.com/shear.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden;margin:0}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
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

<div class="hud" id="hud">
  VELOCITY <span class="v" id="hud-vel">1.5</span><br>
  VORTICES <span class="v" id="hud-nv">100</span>
</div>

<div id="info">
  <span class="v">Kelvin-Helmholtz</span><br>
  shear instability &middot; vortex roll-up
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'drag', 'label' => 'push'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">VELOCITY</span>
      <input type="range" id="sl-vel" min="5" max="30" value="15">
      <span class="slider-value" id="sv-vel">1.5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">CORE</span>
      <input type="range" id="sl-core" min="3" max="20" value="8">
      <span class="slider-value" id="sv-core">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">WAVES</span>
      <input type="range" id="sl-wave" min="1" max="6" value="2">
      <span class="slider-value" id="sv-wave">2</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
    <button class="control-btn active" id="btn-run">RUN</button>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-vel="10" data-core="12" data-wave="1">single roll</button>
    <button class="preset-btn active" data-vel="15" data-core="8" data-wave="2">double helix</button>
    <button class="preset-btn" data-vel="20" data-core="6" data-wave="4">cascade</button>
    <button class="preset-btn" data-vel="28" data-core="4" data-wave="6">turbulent</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function() {
'use strict';

var c = document.getElementById('canvas');
var ctx = c.getContext('2d');
var W, H, dpr, simW, simH;

var U = 1.5;
var coreSize = 8;
var waveNum = 2;
var NV = 100;
var NP = 5000;
var running = true;

var blobX, blobY, blobG;
var partX, partY, partBucket;
var k1x, k1y, midX, midY;
var yc, pertAmp;

var GW = 45, GH = 30;
var gdx, gdy;
var gridU, gridV;

var NBUCKETS = 16;
var bucketStyle = [];
var bucketList;

var hudVel = document.getElementById('hud-vel');
var hudNV = document.getElementById('hud-nv');
var slVel = document.getElementById('sl-vel');
var svVel = document.getElementById('sv-vel');
var slCore = document.getElementById('sl-core');
var svCore = document.getElementById('sv-core');
var slWave = document.getElementById('sl-wave');
var svWave = document.getElementById('sv-wave');

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function buildColors() {
  for (var b = 0; b < NBUCKETS; b++) {
    var t = b / (NBUCKETS - 1);
    var h = brandHue(t);
    var s = 78 - 12 * Math.abs(t * 2 - 1);
    var l = 40 + 14 * Math.sin(t * Math.PI);
    var rgb = P.hsl(h, s, l);
    bucketStyle[b] = 'rgb(' + (rgb[0] | 0) + ',' + (rgb[1] | 0) + ',' + (rgb[2] | 0) + ')';
  }
}

function resize() {
  dpr = Math.min(2, window.devicePixelRatio || 1);
  simW = window.innerWidth;
  simH = window.innerHeight;
  W = Math.floor(simW * dpr);
  H = Math.floor(simH * dpr);
  c.width = W;
  c.height = H;
  c.style.width = simW + 'px';
  c.style.height = simH + 'px';
}

function init() {
  yc = simH / 2;
  pertAmp = simH * 0.025;
  gdx = simW / GW;
  gdy = simH / GH;
  gridU = new Float64Array(GW * GH);
  gridV = new Float64Array(GW * GH);

  var area = simW * simH;
  NP = Math.min(6000, Math.max(2500, Math.floor(area / 350)));

  blobX = new Float64Array(NV);
  blobY = new Float64Array(NV);
  blobG = new Float64Array(NV);
  k1x = new Float64Array(NV);
  k1y = new Float64Array(NV);
  midX = new Float64Array(NV);
  midY = new Float64Array(NV);

  var blobDx = simW / NV;
  var gamma = 2 * U * blobDx;

  for (var i = 0; i < NV; i++) {
    blobX[i] = (i + 0.5) * blobDx;
    blobY[i] = yc + pertAmp * Math.sin(2 * Math.PI * waveNum * blobX[i] / simW);
    blobG[i] = gamma;
  }

  partX = new Float64Array(NP);
  partY = new Float64Array(NP);
  partBucket = new Uint8Array(NP);
  bucketList = [];
  for (var b = 0; b < NBUCKETS; b++) bucketList[b] = [];

  for (var i = 0; i < NP; i++) {
    partX[i] = Math.random() * simW;
    if (Math.random() < 0.55) {
      var r = (Math.random() + Math.random() + Math.random()) / 3;
      partY[i] = yc + (r * 2 - 1) * simH * 0.38;
    } else {
      partY[i] = Math.random() * simH;
    }
    partY[i] = Math.max(1, Math.min(simH - 1, partY[i]));

    var dy = partY[i] - yc;
    var t = 0.5 + 0.5 * Math.tanh(dy / (simH * 0.05));
    t += (Math.random() - 0.5) * 0.02;
    if (t < 0) t = 0;
    if (t > 0.999) t = 0.999;
    var b = Math.floor(t * NBUCKETS);
    partBucket[i] = b;
    bucketList[b].push(i);
  }

  hudVel.textContent = U.toFixed(1);
  hudNV.textContent = NV;

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

function step() {
  var halfW = simW * 0.5;
  var invTwoPi = 0.15915494309;
  var d2 = coreSize * coreSize;
  var i, j, dx, dy, r2, f, u, v;

  for (i = 0; i < NV; i++) {
    u = 0; v = 0;
    for (j = 0; j < NV; j++) {
      if (j === i) continue;
      dx = blobX[i] - blobX[j];
      if (dx > halfW) dx -= simW;
      else if (dx < -halfW) dx += simW;
      dy = blobY[i] - blobY[j];
      r2 = dx * dx + dy * dy + d2;
      f = blobG[j] * invTwoPi / r2;
      u += f * dy;
      v -= f * dx;
    }
    k1x[i] = u;
    k1y[i] = v;
    midX[i] = blobX[i] + u * 0.5;
    midY[i] = blobY[i] + v * 0.5;
  }

  for (i = 0; i < NV; i++) {
    u = 0; v = 0;
    for (j = 0; j < NV; j++) {
      if (j === i) continue;
      dx = midX[i] - midX[j];
      if (dx > halfW) dx -= simW;
      else if (dx < -halfW) dx += simW;
      dy = midY[i] - midY[j];
      r2 = dx * dx + dy * dy + d2;
      f = blobG[j] * invTwoPi / r2;
      u += f * dy;
      v -= f * dx;
    }
    blobX[i] += u;
    blobY[i] += v;
    blobX[i] = ((blobX[i] % simW) + simW) % simW;
    if (blobY[i] < simH * 0.02) blobY[i] = simH * 0.02;
    if (blobY[i] > simH * 0.98) blobY[i] = simH * 0.98;
  }

  var shearFactor = 0.7 * U;
  var shearWidth = simH * 0.12;
  for (var gy = 0; gy < GH; gy++) {
    var py = (gy + 0.5) * gdy;
    for (var gx = 0; gx < GW; gx++) {
      var px = (gx + 0.5) * gdx;
      u = 0; v = 0;
      for (j = 0; j < NV; j++) {
        dx = px - blobX[j];
        if (dx > halfW) dx -= simW;
        else if (dx < -halfW) dx += simW;
        dy = py - blobY[j];
        r2 = dx * dx + dy * dy + d2;
        f = blobG[j] * invTwoPi / r2;
        u += f * dy;
        v -= f * dx;
      }
      u += shearFactor * Math.tanh((py - yc) / shearWidth);
      var idx = gy * GW + gx;
      gridU[idx] = u;
      gridV[idx] = v;
    }
  }

  for (i = 0; i < NP; i++) {
    var fx = partX[i] / gdx - 0.5;
    var fy = partY[i] / gdy - 0.5;
    var ix = Math.floor(fx);
    var iy = Math.floor(fy);
    var tx = fx - ix;
    var ty = fy - iy;

    var i0 = ((ix % GW) + GW) % GW;
    var i1 = ((ix + 1) % GW + GW) % GW;
    var j0, j1;
    if (iy < 0) { j0 = 0; j1 = 0; ty = 0; }
    else if (iy >= GH - 1) { j0 = GH - 1; j1 = GH - 1; ty = 0; }
    else { j0 = iy; j1 = iy + 1; }

    var a00 = (1 - tx) * (1 - ty), a10 = tx * (1 - ty);
    var a01 = (1 - tx) * ty,       a11 = tx * ty;
    u = gridU[j0 * GW + i0] * a00 + gridU[j0 * GW + i1] * a10
      + gridU[j1 * GW + i0] * a01 + gridU[j1 * GW + i1] * a11;
    v = gridV[j0 * GW + i0] * a00 + gridV[j0 * GW + i1] * a10
      + gridV[j1 * GW + i0] * a01 + gridV[j1 * GW + i1] * a11;

    partX[i] += u;
    partY[i] += v;
    partX[i] = ((partX[i] % simW) + simW) % simW;
    if (partY[i] < 0) partY[i] += simH;
    if (partY[i] >= simH) partY[i] -= simH;
  }
}

function draw() {
  ctx.fillStyle = 'rgba(10, 10, 15, 0.18)';
  ctx.fillRect(0, 0, W, H);

  var ps = Math.max(2, Math.round(2 * dpr));

  for (var b = 0; b < NBUCKETS; b++) {
    ctx.fillStyle = bucketStyle[b];
    var list = bucketList[b];
    for (var j = 0, len = list.length; j < len; j++) {
      var idx = list[j];
      ctx.fillRect((partX[idx] * dpr) | 0, (partY[idx] * dpr) | 0, ps, ps);
    }
  }
}

function loop() {
  requestAnimationFrame(loop);
  if (running) step();
  draw();
}

function perturb(cx, cy) {
  var radius = simH * 0.1;
  var r2max = radius * radius;
  var strength = simH * 0.05;
  var halfW = simW * 0.5;
  for (var i = 0; i < NV; i++) {
    var dx = blobX[i] - cx;
    if (dx > halfW) dx -= simW;
    if (dx < -halfW) dx += simW;
    var dy = blobY[i] - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist < radius && dist > 0.1) {
      var factor = strength * Math.pow(1 - dist / radius, 2);
      blobY[i] += factor * dy / dist;
    }
  }
}

var isDown = false;
c.addEventListener('mousedown', function(e) {
  if (e.button === 2) return;
  isDown = true;
  perturb(e.clientX, e.clientY);
  if (window.SFX) SFX.play('ripple');
});
c.addEventListener('mousemove', function(e) {
  if (!isDown) return;
  perturb(e.clientX, e.clientY);
  if (window.SFX) SFX.play('move', 80);
});
c.addEventListener('mouseup', function() { isDown = false; });
c.addEventListener('mouseleave', function() { isDown = false; });

c.addEventListener('touchstart', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    perturb(e.touches[i].clientX, e.touches[i].clientY);
  }
  if (window.SFX) SFX.play('ripple');
}, { passive: false });
c.addEventListener('touchmove', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    perturb(e.touches[i].clientX, e.touches[i].clientY);
  }
  if (window.SFX) SFX.play('move', 80);
}, { passive: false });
c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

slVel.addEventListener('input', function() {
  var oldU = U;
  U = parseInt(this.value) / 10;
  svVel.textContent = U.toFixed(1);
  hudVel.textContent = U.toFixed(1);
  if (oldU > 0.01) {
    var ratio = U / oldU;
    for (var i = 0; i < NV; i++) blobG[i] *= ratio;
  }
});

slCore.addEventListener('input', function() {
  coreSize = parseInt(this.value);
  svCore.textContent = coreSize;
});

slWave.addEventListener('input', function() {
  waveNum = parseInt(this.value);
  svWave.textContent = waveNum;
  init();
  if (window.SFX) SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click', function() {
  init();
  if (window.SFX) SFX.play('click');
});

document.getElementById('btn-run').addEventListener('click', function() {
  running = !running;
  this.textContent = running ? 'RUN' : 'PAUSE';
  this.classList.toggle('active', running);
  if (window.SFX) SFX.play('toggle');
});

var presetBtns = document.querySelectorAll('.preset-btn');
presetBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    presetBtns.forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    U = parseInt(this.dataset.vel) / 10;
    coreSize = parseInt(this.dataset.core);
    waveNum = parseInt(this.dataset.wave);
    slVel.value = this.dataset.vel;
    slCore.value = this.dataset.core;
    slWave.value = this.dataset.wave;
    svVel.textContent = U.toFixed(1);
    svCore.textContent = coreSize;
    svWave.textContent = waveNum;
    init();
    if (window.SFX) SFX.play('click');
  });
});

window.addEventListener('resize', function() {
  resize();
  init();
});

buildColors();
resize();
init();
loop();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
