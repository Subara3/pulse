<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('orbital');

$pageName = 'orbital';
$pageTitle = 'ORBITAL - PULSE';
$pageDesc = '水素原子の電子軌道。量子数(n,l,m)で決まる波動関数の確率密度を、断面図とモンテカルロ粒子雲で描く。';
$pageCanonical = 'https://pulse.subara3.com/orbital.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  n=<b id="h-n">2</b> l=<b id="h-l">1</b> m=<b id="h-m">0</b><br>
  <b id="h-name">2p</b><br>
  E = <b id="h-e">-3.40</b> eV
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'scatter'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="slider-row"><span class="slider-label">n</span><input type="range" id="sl-n" min="1" max="5" value="2"><span class="slider-value" id="sv-n">2</span></div></div>
  <div><div class="slider-row"><span class="slider-label">l</span><input type="range" id="sl-l" min="0" max="1" value="1"><span class="slider-value" id="sv-l">1</span></div></div>
  <div><div class="slider-row"><span class="slider-label">m</span><input type="range" id="sl-m" min="-1" max="1" value="0"><span class="slider-value" id="sv-m">0</span></div></div>
  <div><div class="slider-row"><span class="slider-label">ANGLE</span><input type="range" id="sl-phi" min="0" max="360" value="0"><span class="slider-value" id="sv-phi">0°</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
  <div class="preset-group">
    <button class="preset-btn" data-n="1" data-l="0" data-m="0">1s</button>
    <button class="preset-btn" data-n="2" data-l="0" data-m="0">2s</button>
    <button class="preset-btn" data-n="2" data-l="1" data-m="0">2p</button>
    <button class="preset-btn" data-n="3" data-l="0" data-m="0">3s</button>
    <button class="preset-btn" data-n="3" data-l="1" data-m="0">3p</button>
    <button class="preset-btn" data-n="3" data-l="2" data-m="0">3d</button>
    <button class="preset-btn" data-n="4" data-l="3" data-m="0">4f</button>
    <button class="preset-btn" data-n="5" data-l="4" data-m="0">5g</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';

var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H;

var qn = 2, ql = 1, qm = 0, phi0 = 0;
var t = 0, dirty = true;

var GR = 280;
var oC = document.createElement('canvas');
oC.width = oC.height = GR;
var oX = oC.getContext('2d');
var gW = new Float32Array(GR * GR);
var gD = new Float32Array(GR * GR);
var gMx = 1, gRm = 10;

var pC = document.createElement('canvas'), pX;

function makeGlow(col) {
  var c = document.createElement('canvas');
  c.width = c.height = 10;
  var cx = c.getContext('2d');
  var g = cx.createRadialGradient(5, 5, 0, 5, 5, 5);
  g.addColorStop(0, col);
  g.addColorStop(1, 'rgba(0,0,0,0)');
  cx.fillStyle = g;
  cx.fillRect(0, 0, 10, 10);
  return c;
}
var glT = makeGlow('rgba(0,245,212,0.6)');
var glP = makeGlow('rgba(123,44,191,0.5)');

var hN = document.getElementById('h-n');
var hL = document.getElementById('h-l');
var hM = document.getElementById('h-m');
var hNm = document.getElementById('h-name');
var hE = document.getElementById('h-e');
var slN = document.getElementById('sl-n');
var slL = document.getElementById('sl-l');
var slM = document.getElementById('sl-m');
var slA = document.getElementById('sl-phi');
var svN = document.getElementById('sv-n');
var svL = document.getElementById('sv-l');
var svM = document.getElementById('sv-m');
var svA = document.getElementById('sv-phi');

var ON = ['s', 'p', 'd', 'f', 'g'];

function aLag(k, a, x) {
  if (k === 0) return 1;
  var p0 = 1, p1 = 1 + a - x;
  for (var i = 2; i <= k; i++) {
    var c = ((2 * i - 1 + a - x) * p1 - (i - 1 + a) * p0) / i;
    p0 = p1; p1 = c;
  }
  return p1;
}

function aLeg(l, m, x) {
  if (m > l) return 0;
  var s = Math.sqrt(Math.max(0, 1 - x * x));
  var pm = 1;
  if (m > 0) {
    var d = 1;
    for (var i = 1; i <= m; i++) { pm *= -d * s; d += 2; }
  }
  if (l === m) return pm;
  var p1 = x * (2 * m + 1) * pm;
  if (l === m + 1) return p1;
  for (var j = m + 2; j <= l; j++) {
    var c = (x * (2 * j - 1) * p1 - (j + m - 1) * pm) / (j - m);
    pm = p1; p1 = c;
  }
  return p1;
}

function wfn(r, ct, ph) {
  var rho = 2 * r / qn;
  var R = Math.pow(rho, ql) * Math.exp(-rho / 2) * aLag(qn - ql - 1, 2 * ql + 1, rho);
  var am = Math.abs(qm);
  var P = aLeg(ql, am, ct);
  var Y = qm > 0 ? P * Math.cos(qm * ph) : qm < 0 ? P * Math.sin(am * ph) : P;
  return R * Y;
}

function compute() {
  gMx = 0;
  gRm = qn * qn * 3 + 3;
  var sc = 2 * gRm / GR;

  for (var iy = 0; iy < GR; iy++) {
    var vv = (GR / 2 - iy - 0.5) * sc;
    for (var ix = 0; ix < GR; ix++) {
      var hh = (ix - GR / 2 + 0.5) * sc;
      var r = Math.sqrt(hh * hh + vv * vv);
      if (r < 0.01) r = 0.01;
      var ct = vv / r;
      var ph = hh >= 0 ? phi0 : phi0 + Math.PI;
      var v = wfn(r, ct, ph);
      var idx = iy * GR + ix;
      gW[idx] = v;
      var d = v * v;
      gD[idx] = d;
      if (d > gMx) gMx = d;
    }
  }

  if (gMx < 1e-30) gMx = 1e-30;

  var img = oX.createImageData(GR, GR);
  var da = img.data;
  for (var i = 0; i < GR * GR; i++) {
    var dn = gD[i] / gMx;
    var br = Math.pow(dn, 0.35);
    if (br < 0.003) { da[i * 4 + 3] = 0; continue; }
    var w = gW[i];
    if (w >= 0) {
      da[i * 4] = 10 * br | 0;
      da[i * 4 + 1] = 245 * br | 0;
      da[i * 4 + 2] = 212 * br | 0;
    } else {
      da[i * 4] = 123 * br | 0;
      da[i * 4 + 1] = 44 * br | 0;
      da[i * 4 + 2] = 191 * br | 0;
    }
    da[i * 4 + 3] = Math.min(255, br * 400 | 0);
  }
  oX.putImageData(img, 0, 0);
  dirty = false;
}

function spawn(cnt) {
  if (gMx <= 0 || !pX) return;
  var sz = Math.min(W, H) * 0.85;
  var dx = (W - sz) / 2, dy = (H - sz) / 2;
  var att = cnt * 12, ad = 0;
  for (var i = 0; i < att && ad < cnt; i++) {
    var ix = Math.random() * GR | 0;
    var iy = Math.random() * GR | 0;
    if (Math.random() < gD[iy * GR + ix] / gMx) {
      var px = dx + (ix + Math.random()) / GR * sz;
      var py = dy + (iy + Math.random()) / GR * sz;
      pX.drawImage(gW[iy * GR + ix] >= 0 ? glT : glP, px - 5, py - 5);
      ad++;
    }
  }
}

function burst(sx, sy, cnt) {
  if (gMx <= 0 || !pX) return;
  var sz = Math.min(W, H) * 0.85;
  var dx = (W - sz) / 2, dy = (H - sz) / 2;
  var fx = (sx - dx) / sz * GR, fy = (sy - dy) / sz * GR;
  var rd = 30, ad = 0;
  for (var i = 0; i < cnt * 15 && ad < cnt; i++) {
    var ix = (fx + (Math.random() - 0.5) * rd * 2) | 0;
    var iy = (fy + (Math.random() - 0.5) * rd * 2) | 0;
    if (ix < 0 || ix >= GR || iy < 0 || iy >= GR) continue;
    var idx = iy * GR + ix;
    if (Math.random() < gD[idx] / gMx * 2) {
      var px = dx + (ix + Math.random()) / GR * sz;
      var py = dy + (iy + Math.random()) / GR * sz;
      pX.drawImage(gW[idx] >= 0 ? glT : glP, px - 5, py - 5);
      ad++;
    }
  }
}

function draw() {
  if (dirty) compute();

  X.fillStyle = '#0a0a0f';
  X.fillRect(0, 0, W, H);

  var sz = Math.min(W, H) * 0.85;
  var dx = (W - sz) / 2, dy = (H - sz) / 2;

  var pulse = 0.82 + 0.18 * Math.sin(t * 0.7);
  X.globalAlpha = pulse;
  X.imageSmoothingEnabled = true;
  X.drawImage(oC, dx, dy, sz, sz);
  X.globalAlpha = 1;

  pX.fillStyle = 'rgba(10,10,15,0.018)';
  pX.fillRect(0, 0, W, H);
  spawn(15);
  X.drawImage(pC, 0, 0);

  t += 0.016;
  requestAnimationFrame(draw);
}

function clearPart() {
  if (pX) { pX.fillStyle = '#0a0a0f'; pX.fillRect(0, 0, W, H); }
}

function updQ() {
  qn = +slN.value;
  slL.max = qn - 1;
  if (+slL.value >= qn) slL.value = qn - 1;
  ql = +slL.value;
  slM.min = -ql; slM.max = ql;
  if (+slM.value > ql) slM.value = ql;
  if (+slM.value < -ql) slM.value = -ql;
  qm = +slM.value;

  svN.textContent = qn; svL.textContent = ql; svM.textContent = qm;
  hN.textContent = qn; hL.textContent = ql; hM.textContent = qm;
  hNm.textContent = qn + (ON[ql] || ql);
  hE.textContent = (-13.6 / (qn * qn)).toFixed(2);

  clearPart();
  dirty = true;
}

slN.addEventListener('input', function() { updQ(); SFX.play('toggle'); });
slL.addEventListener('input', function() { updQ(); SFX.play('toggle'); });
slM.addEventListener('input', function() { updQ(); SFX.play('toggle'); });
slA.addEventListener('input', function() {
  phi0 = +slA.value * Math.PI / 180;
  svA.textContent = slA.value + '°';
  dirty = true;
});

var prBtns = document.querySelectorAll('.preset-btn');
for (var i = 0; i < prBtns.length; i++) {
  prBtns[i].addEventListener('click', function() {
    slN.value = qn = +this.dataset.n;
    slL.max = qn - 1;
    slL.value = ql = +this.dataset.l;
    slM.min = -ql; slM.max = ql;
    slM.value = qm = +this.dataset.m;
    updQ();
    SFX.play('click');
  });
}

document.getElementById('btn-reset').addEventListener('click', function() {
  clearPart();
  SFX.play('click');
});

C.addEventListener('click', function(e) {
  burst(e.clientX, e.clientY, 300);
  SFX.play('spawn');
});
C.addEventListener('touchstart', function(e) {
  if (e.touches.length === 1) {
    burst(e.touches[0].clientX, e.touches[0].clientY, 300);
    SFX.play('spawn');
  }
}, { passive: true });

function setup() {
  W = C.width = innerWidth;
  H = C.height = innerHeight;
  pC.width = W; pC.height = H;
  pX = pC.getContext('2d');
  dirty = true;
}

window.addEventListener('resize', setup);
setup();
updQ();
draw();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
