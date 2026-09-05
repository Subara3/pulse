<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dyson');

$pageName = 'dyson';
$pageTitle = 'DYSON - PULSE';
$pageDesc = 'ランダム行列のダイソン・ブラウン運動。固有値が対数的に反発し合いながらゆらぎ、半円分布とウィグナー統計が創発する。';
$pageCanonical = 'https://pulse.subara3.com/dyson.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .dim{color:rgba(181,140,255,.7)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="dim">DYSON</span><br>N <b id="h-n">60</b> &middot; &beta; <b id="h-beta">2</b><br>MODE <b id="h-mode">GUE</b></div>
<div class="mark">DYSON</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'drag', 'label' => 'push'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn active" id="btn-gue">GUE</button>
    <button class="control-btn" id="btn-goe">GOE</button>
    <button class="control-btn" id="btn-poisson">POISSON</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">N</span>
    <input type="range" id="sl-n" min="10" max="120" value="60">
    <span class="slider-value" id="sv-n">60</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">BETA</span>
    <input type="range" id="sl-beta" min="1" max="40" value="20">
    <span class="slider-value" id="sv-beta">2.0</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="20" value="10">
    <span class="slider-value" id="sv-speed">1.0</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">TRAIL</span>
    <input type="range" id="sl-trail" min="0" max="100" value="50">
    <span class="slider-value" id="sv-trail">50</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c = document.getElementById('canvas'), ctx = c.getContext('2d');
var W, H;
var TEAL = [0, 245, 212], PURP = [123, 44, 191];

function rgba(col, a) { return 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',' + a + ')'; }
function mixC(a, b, t) { return [a[0]+(b[0]-a[0])*t|0, a[1]+(b[1]-a[1])*t|0, a[2]+(b[2]-a[2])*t|0]; }
function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2*t, 3.2) : 1 - 0.5 * Math.pow(2*(1-t), 3.2);
  return 172 + e * 118;
}
function hsl2rgb(h, s, l) {
  h %= 360; if (h < 0) h += 360; s /= 100; l /= 100;
  var cc = (1 - Math.abs(2*l-1)) * s, x = cc * (1 - Math.abs(h/60%2-1)), m = l - cc/2, r, g, b;
  if (h < 60){r=cc;g=x;b=0} else if (h<120){r=x;g=cc;b=0} else if (h<180){r=0;g=cc;b=x}
  else if (h<240){r=0;g=x;b=cc} else if (h<300){r=x;g=0;b=cc} else {r=cc;g=0;b=x}
  return [(r+m)*255|0,(g+m)*255|0,(b+m)*255|0];
}

var N = 60;
var beta = 2.0;
var speed = 1.0;
var trailAlpha = 50;
var mode = 'gue';
var eigenvalues = [];
var trails = [];
var trailLen = 120;
var histogram = [];
var histBins = 60;
var spacingHist = [];
var spacingBins = 40;
var time = 0;
var dt = 0.0005;

function initEigenvalues() {
  eigenvalues = [];
  trails = [];
  histogram = new Array(histBins).fill(0);
  spacingHist = new Array(spacingBins).fill(0);
  time = 0;

  if (mode === 'poisson') {
    for (var i = 0; i < N; i++) {
      eigenvalues.push((Math.random() * 2 - 1) * 2);
      trails.push([]);
    }
  } else {
    for (var i = 0; i < N; i++) {
      var u = (i + 0.5) / N;
      eigenvalues.push(2 * Math.sin(Math.PI * (u - 0.5)));
      trails.push([]);
    }
    for (var k = 0; k < 200; k++) stepEigenvalues();
  }
}

function gaussRandom() {
  var u = 0, v = 0;
  while (u === 0) u = Math.random();
  while (v === 0) v = Math.random();
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
}

function stepEigenvalues() {
  var effBeta = mode === 'poisson' ? 0 : beta;
  var sqrtCoeff = Math.sqrt(2 * dt / (effBeta * N));
  var driftCoeff = dt / N;

  for (var i = 0; i < N; i++) {
    var drift = 0;
    if (effBeta > 0) {
      for (var j = 0; j < N; j++) {
        if (j === i) continue;
        var diff = eigenvalues[i] - eigenvalues[j];
        if (Math.abs(diff) < 1e-10) diff = diff >= 0 ? 1e-10 : -1e-10;
        drift += 1 / diff;
      }
    }
    var confinement = -eigenvalues[i] * dt * 0.5;
    eigenvalues[i] += driftCoeff * drift + confinement + sqrtCoeff * gaussRandom();
  }

  eigenvalues.sort(function(a, b) { return a - b; });

  for (var i = 0; i < N; i++) {
    trails[i].push(eigenvalues[i]);
    if (trails[i].length > trailLen) trails[i].shift();
  }

  updateHistogram();
  updateSpacingHist();
  time += dt;
}

function updateHistogram() {
  var bins = histogram;
  var range = 3.0;
  for (var i = 0; i < histBins; i++) bins[i] *= 0.995;
  for (var i = 0; i < N; i++) {
    var idx = Math.floor((eigenvalues[i] + range) / (2 * range) * histBins);
    if (idx >= 0 && idx < histBins) bins[idx] += 0.1;
  }
}

function updateSpacingHist() {
  var maxS = 4.0;
  for (var i = 0; i < spacingBins; i++) spacingHist[i] *= 0.995;
  var mean = 0;
  for (var i = 1; i < N; i++) mean += eigenvalues[i] - eigenvalues[i-1];
  mean /= (N - 1);
  if (mean < 1e-10) return;
  for (var i = 1; i < N; i++) {
    var s = (eigenvalues[i] - eigenvalues[i-1]) / mean;
    var idx = Math.floor(s / maxS * spacingBins);
    if (idx >= 0 && idx < spacingBins) spacingHist[idx] += 0.1;
  }
}

function resize() {
  var dpr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth; H = window.innerHeight;
  c.width = W * dpr; c.height = H * dpr;
  c.style.width = W + 'px'; c.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function mapX(val) {
  var range = 3.0;
  return W * 0.1 + (val + range) / (2 * range) * W * 0.8;
}

function draw() {
  var fade = 1 - trailAlpha / 255;
  ctx.fillStyle = 'rgba(10,10,15,' + (0.05 + fade * 0.35) + ')';
  ctx.fillRect(0, 0, W, H);

  var bandY = H * 0.35;
  var bandH = H * 0.12;

  ctx.save();
  ctx.globalAlpha = 0.08;
  ctx.fillStyle = rgba(TEAL, 1);
  ctx.fillRect(W * 0.1, bandY - 1, W * 0.8, bandH + 2);
  ctx.restore();

  var leftX = W * 0.1, rightX = W * 0.9;
  ctx.strokeStyle = 'rgba(255,255,255,0.08)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(leftX, bandY + bandH * 0.5);
  ctx.lineTo(rightX, bandY + bandH * 0.5);
  ctx.stroke();

  for (var i = 0; i < N; i++) {
    var trail = trails[i];
    if (trail.length < 2) continue;
    var t01 = i / (N - 1);
    var hue = brandHue(t01);
    var col = hsl2rgb(hue, 70, 55);

    ctx.beginPath();
    for (var k = 0; k < trail.length; k++) {
      var px = mapX(trail[k]);
      var age = k / trail.length;
      var py = bandY + bandH * 0.5 - (trail.length - k) * 0.4;
      if (k === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    ctx.strokeStyle = rgba(col, 0.15 * (trailAlpha / 100));
    ctx.lineWidth = 0.8;
    ctx.stroke();
  }

  for (var i = 0; i < N; i++) {
    var x = mapX(eigenvalues[i]);
    var y = bandY + bandH * 0.5;
    var t01 = i / (N - 1);
    var hue = brandHue(t01);
    var col = hsl2rgb(hue, 80, 60);

    ctx.beginPath();
    ctx.arc(x, y, 3.5, 0, Math.PI * 2);
    ctx.fillStyle = rgba(col, 0.9);
    ctx.fill();

    ctx.beginPath();
    ctx.arc(x, y, 7, 0, Math.PI * 2);
    var grd = ctx.createRadialGradient(x, y, 0, x, y, 7);
    grd.addColorStop(0, rgba(col, 0.4));
    grd.addColorStop(1, rgba(col, 0));
    ctx.fillStyle = grd;
    ctx.fill();
  }

  drawHistogram();
  drawSpacingChart();
  drawSemicircle();
}

function drawHistogram() {
  var hx = W * 0.1, hy = H * 0.58, hw = W * 0.8, hh = H * 0.18;
  var maxVal = 0;
  for (var i = 0; i < histBins; i++) if (histogram[i] > maxVal) maxVal = histogram[i];
  if (maxVal < 0.01) maxVal = 1;

  ctx.fillStyle = 'rgba(255,255,255,0.03)';
  ctx.fillRect(hx, hy, hw, hh);

  var bw = hw / histBins;
  for (var i = 0; i < histBins; i++) {
    var val = histogram[i] / maxVal;
    var barH = val * hh * 0.9;
    var t01 = i / histBins;
    var hue = brandHue(t01);
    var col = hsl2rgb(hue, 60, 50);
    ctx.fillStyle = rgba(col, 0.6);
    ctx.fillRect(hx + i * bw, hy + hh - barH, bw - 1, barH);
  }

  ctx.fillStyle = 'rgba(255,255,255,0.3)';
  ctx.font = '10px ui-monospace, monospace';
  ctx.fillText('DENSITY', hx, hy - 4);
}

function drawSemicircle() {
  if (mode === 'poisson') return;
  var hx = W * 0.1, hy = H * 0.58, hw = W * 0.8, hh = H * 0.18;
  var maxVal = 0;
  for (var i = 0; i < histBins; i++) if (histogram[i] > maxVal) maxVal = histogram[i];
  if (maxVal < 0.01) return;

  ctx.beginPath();
  ctx.strokeStyle = rgba(TEAL, 0.5);
  ctx.lineWidth = 1.5;
  var range = 3.0;
  var R = 2.0;
  for (var px = 0; px <= hw; px++) {
    var x = (px / hw) * 2 * range - range;
    if (Math.abs(x) > R) continue;
    var rho = (2 / (Math.PI * R * R)) * Math.sqrt(R * R - x * x);
    var scaledRho = rho * N * (2 * range / histBins);
    var barH = (scaledRho / maxVal) * hh * 0.9;
    if (px === 0 || Math.abs(x + R) < range/hw) ctx.moveTo(hx + px, hy + hh - barH);
    else ctx.lineTo(hx + px, hy + hh - barH);
  }
  ctx.stroke();
}

function drawSpacingChart() {
  var sx = W * 0.1, sy = H * 0.82, sw = W * 0.35, sh = H * 0.14;
  var maxVal = 0;
  for (var i = 0; i < spacingBins; i++) if (spacingHist[i] > maxVal) maxVal = spacingHist[i];
  if (maxVal < 0.01) maxVal = 1;

  ctx.fillStyle = 'rgba(255,255,255,0.03)';
  ctx.fillRect(sx, sy, sw, sh);

  var bw = sw / spacingBins;
  for (var i = 0; i < spacingBins; i++) {
    var val = spacingHist[i] / maxVal;
    var barH = val * sh * 0.85;
    var col = mixC(PURP, TEAL, i / spacingBins);
    ctx.fillStyle = rgba(col, 0.5);
    ctx.fillRect(sx + i * bw, sy + sh - barH, bw - 1, barH);
  }

  if (mode !== 'poisson') {
    ctx.beginPath();
    ctx.strokeStyle = rgba(PURP, 0.7);
    ctx.lineWidth = 1.5;
    var maxS = 4.0;
    var maxTheo = 0;
    for (var px = 0; px <= sw; px++) {
      var s = (px / sw) * maxS;
      var p;
      if (beta >= 1.8) p = (Math.PI * s / 2) * Math.exp(-Math.PI * s * s / 4);
      else p = (Math.PI * s / 2) * Math.exp(-Math.PI * s * s / 4) * 0.7 + 0.3 * Math.exp(-s);
      if (p > maxTheo) maxTheo = p;
    }
    for (var px = 0; px <= sw; px++) {
      var s = (px / sw) * maxS;
      var p;
      if (beta >= 1.8) p = (Math.PI * s / 2) * Math.exp(-Math.PI * s * s / 4);
      else p = (Math.PI * s / 2) * Math.exp(-Math.PI * s * s / 4) * 0.7 + 0.3 * Math.exp(-s);
      var scaledP = p * N * (maxS / spacingBins);
      var barH = (scaledP / maxVal) * sh * 0.85;
      if (px === 0) ctx.moveTo(sx + px, sy + sh - barH);
      else ctx.lineTo(sx + px, sy + sh - barH);
    }
    ctx.stroke();
  } else {
    ctx.beginPath();
    ctx.strokeStyle = rgba(PURP, 0.7);
    ctx.lineWidth = 1.5;
    var maxS = 4.0;
    for (var px = 0; px <= sw; px++) {
      var s = (px / sw) * maxS;
      var p = Math.exp(-s);
      var scaledP = p * N * (maxS / spacingBins);
      var barH = (scaledP / maxVal) * sh * 0.85;
      if (px === 0) ctx.moveTo(sx + px, sy + sh - barH);
      else ctx.lineTo(sx + px, sy + sh - barH);
    }
    ctx.stroke();
  }

  ctx.fillStyle = 'rgba(255,255,255,0.3)';
  ctx.font = '10px ui-monospace, monospace';
  ctx.fillText('SPACING', sx, sy - 4);
}

var stepsPerFrame = 10;

function frame() {
  for (var i = 0; i < stepsPerFrame; i++) stepEigenvalues();
  draw();
  requestAnimationFrame(frame);
}

var pointer = { down: false, x: 0, y: 0, button: 0 };
var bandY, bandH;

function getBand() {
  bandY = H * 0.35;
  bandH = H * 0.12;
}

function handlePerturb(px, py) {
  getBand();
  var range = 3.0;
  var clickVal = ((px - W * 0.1) / (W * 0.8)) * 2 * range - range;
  var strength = 0.3;
  for (var i = 0; i < N; i++) {
    var diff = eigenvalues[i] - clickVal;
    var dist = Math.abs(diff);
    if (dist < 0.8) {
      var push = strength * Math.exp(-dist * dist * 4) * (diff >= 0 ? 1 : -1);
      eigenvalues[i] += push;
    }
  }
  SFX.play('ripple');
}

c.addEventListener('mousedown', function(e) {
  e.preventDefault();
  pointer.down = true;
  pointer.button = e.button;
  pointer.x = e.clientX;
  pointer.y = e.clientY;
  if (e.button === 2) {
    initEigenvalues();
    SFX.play('click');
  } else {
    handlePerturb(e.clientX, e.clientY);
  }
});

c.addEventListener('mousemove', function(e) {
  if (!pointer.down || pointer.button === 2) return;
  pointer.x = e.clientX;
  pointer.y = e.clientY;
  handlePerturb(e.clientX, e.clientY);
});

c.addEventListener('mouseup', function() { pointer.down = false; });
c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

c.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  pointer.down = true;
  pointer.x = t.clientX;
  pointer.y = t.clientY;
  handlePerturb(t.clientX, t.clientY);
}, { passive: false });

c.addEventListener('touchmove', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  pointer.x = t.clientX;
  pointer.y = t.clientY;
  handlePerturb(t.clientX, t.clientY);
}, { passive: false });

c.addEventListener('touchend', function() { pointer.down = false; });

var hN = document.getElementById('h-n');
var hBeta = document.getElementById('h-beta');
var hMode = document.getElementById('h-mode');
var slN = document.getElementById('sl-n');
var slBeta = document.getElementById('sl-beta');
var slSpeed = document.getElementById('sl-speed');
var slTrail = document.getElementById('sl-trail');
var svN = document.getElementById('sv-n');
var svBeta = document.getElementById('sv-beta');
var svSpeed = document.getElementById('sv-speed');
var svTrail = document.getElementById('sv-trail');

slN.addEventListener('input', function() {
  N = parseInt(this.value);
  svN.textContent = N;
  hN.textContent = N;
  initEigenvalues();
  SFX.play('toggle');
});

slBeta.addEventListener('input', function() {
  beta = parseInt(this.value) / 10;
  svBeta.textContent = beta.toFixed(1);
  hBeta.textContent = beta.toFixed(1);
  SFX.play('tick');
});

slSpeed.addEventListener('input', function() {
  speed = parseInt(this.value) / 10;
  stepsPerFrame = Math.max(1, Math.round(speed * 10));
  svSpeed.textContent = speed.toFixed(1);
});

slTrail.addEventListener('input', function() {
  trailAlpha = parseInt(this.value);
  svTrail.textContent = trailAlpha;
});

var modeBtns = {
  gue: document.getElementById('btn-gue'),
  goe: document.getElementById('btn-goe'),
  poisson: document.getElementById('btn-poisson')
};

function setMode(m) {
  mode = m;
  for (var k in modeBtns) modeBtns[k].classList.toggle('active', k === m);
  hMode.textContent = m.toUpperCase();
  if (m === 'gue') { beta = 2; slBeta.value = 20; svBeta.textContent = '2.0'; hBeta.textContent = '2.0'; }
  else if (m === 'goe') { beta = 1; slBeta.value = 10; svBeta.textContent = '1.0'; hBeta.textContent = '1.0'; }
  else { beta = 0; slBeta.value = 0; svBeta.textContent = '0.0'; hBeta.textContent = '0.0'; }
  initEigenvalues();
  SFX.play('toggle');
}

modeBtns.gue.addEventListener('click', function() { setMode('gue'); });
modeBtns.goe.addEventListener('click', function() { setMode('goe'); });
modeBtns.poisson.addEventListener('click', function() { setMode('poisson'); });

document.getElementById('btn-reset').addEventListener('click', function() {
  initEigenvalues();
  SFX.play('click');
});

window.addEventListener('resize', resize);
resize();
initEigenvalues();
requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
