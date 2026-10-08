<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cassini');

$pageName = 'cassini';
$pageTitle = 'CASSINI - PULSE';
$pageDesc = 'つまみを回すと、2つの輪がゆっくり近づき、8の字でつながり、1つの曲線に溶ける。焦点からの距離の積が形を決めている。';
$pageCanonical = 'https://pulse.subara3.com/cassini.php';
$extraStyle = '
#canvas{position:fixed;top:0;left:0;touch-action:none}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px);pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
.mode-bar{position:fixed;top:1rem;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:2px;background:rgba(10,10,15,.55);border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:2px;backdrop-filter:blur(12px)}
.mode-btn{background:transparent;border:none;color:rgba(255,255,255,.45);font:11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em;padding:5px 10px;cursor:pointer;border-radius:4px;transition:color .2s,background .2s}
.mode-btn.active{color:#00f5d4;background:rgba(0,245,212,.12)}
.mode-btn:hover{color:rgba(255,255,255,.8)}
@media(max-width:760px){.mode-bar{top:.75rem}.mode-btn{font-size:10px;padding:4px 7px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  METRIC <b id="h-metric">PROD</b><br>
  FOCI <b id="h-foci">2</b><br>
  <span class="sub" id="h-mode">AUTO</span>
</div>
<div class="mode-bar">
  <button class="mode-btn active" data-m="product">PROD</button>
  <button class="mode-btn" data-m="sum">SUM</button>
  <button class="mode-btn" data-m="diff">DIFF</button>
  <button class="mode-btn" data-m="ratio">RATIO</button>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add focus'],
  ['type' => 'drag',  'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">LEVEL</span>
    <input type="range" id="sl-level" min="0" max="1000" value="500">
    <span class="slider-value" id="v-level">AUTO</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">RINGS</span>
    <input type="range" id="sl-rings" min="4" max="30" value="14">
    <span class="slider-value" id="v-rings">14</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var FC = document.createElement('canvas'), fctx = FC.getContext('2d');
var SCALE = 3;
var W, H, fw, fh;

var foci = [];
var metric = 'product';
var autoAnim = true;
var animT = 0;
var fieldMax = 1;
var dragging = -1;
var dragOff = {x:0, y:0};
var needRender = true;
var lastAC = -1;

var slLevel = document.getElementById('sl-level');
var slRings = document.getElementById('sl-rings');
var elMetric = document.getElementById('h-metric');
var elFoci = document.getElementById('h-foci');
var elMode = document.getElementById('h-mode');
var elLevelV = document.getElementById('v-level');
var elRingsV = document.getElementById('v-rings');

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function hsl2rgb(h, s, l) {
  h = ((h % 360) + 360) % 360 / 360;
  var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
  var p = 2 * l - q;
  function f(tt) {
    if (tt < 0) tt += 1; if (tt > 1) tt -= 1;
    if (tt < 1/6) return p + (q - p) * 6 * tt;
    if (tt < 1/2) return q;
    if (tt < 2/3) return p + (q - p) * (2/3 - tt) * 6;
    return p;
  }
  return [f(h + 1/3) * 255, f(h) * 255, f(h - 1/3) * 255];
}

function resize() {
  W = window.innerWidth; H = window.innerHeight;
  C.width = W; C.height = H;
  fw = Math.ceil(W / SCALE); fh = Math.ceil(H / SCALE);
  FC.width = fw; FC.height = fh;
  needRender = true;
}

function initFoci() {
  var cx = W * 0.5, cy = H * 0.5;
  var sp = Math.min(W, H) * 0.15;
  foci = [{x: cx - sp, y: cy}, {x: cx + sp, y: cy}];
  updateMax();
}

function fociDist() {
  if (foci.length < 2) return 100;
  var dx = foci[1].x - foci[0].x, dy = foci[1].y - foci[0].y;
  return Math.sqrt(dx * dx + dy * dy);
}

function updateMax() {
  var D = fociDist();
  var diag = Math.sqrt(W * W + H * H);
  switch (metric) {
    case 'product':
      var k = foci.length;
      fieldMax = Math.pow(D * 1.2, k);
      if (fieldMax < 1) fieldMax = Math.pow(diag * 0.3, k);
      break;
    case 'sum':
      fieldMax = D * 2 + diag * 0.4;
      break;
    case 'diff':
      fieldMax = D * 0.9 + 10;
      break;
    case 'ratio':
      fieldMax = 3;
      break;
  }
  needRender = true;
}

function computeMetric(dists) {
  var i;
  switch (metric) {
    case 'product':
      var v = 1;
      for (i = 0; i < dists.length; i++) v *= dists[i];
      return v;
    case 'sum':
      var v = 0;
      for (i = 0; i < dists.length; i++) v += dists[i];
      return v;
    case 'diff':
      var mn = dists[0], mx = dists[0];
      for (i = 1; i < dists.length; i++) {
        if (dists[i] < mn) mn = dists[i];
        if (dists[i] > mx) mx = dists[i];
      }
      return mx - mn;
    case 'ratio':
      var mn = dists[0], mx = dists[0];
      for (i = 1; i < dists.length; i++) {
        if (dists[i] < mn) mn = dists[i];
        if (dists[i] > mx) mx = dists[i];
      }
      return Math.log(mx / (mn + 1)) + 1;
  }
  return 0;
}

function getActiveContour() {
  if (autoAnim) {
    var speed = metric === 'ratio' ? 0.35 : 0.4;
    var t = (Math.sin(animT * speed) + 1) * 0.5;
    t = t * t * (3 - 2 * t);
    return t * fieldMax * 0.85 + fieldMax * 0.02;
  }
  return (slLevel.value / 1000) * fieldMax;
}

function renderField(ac) {
  var imgData = fctx.createImageData(fw, fh);
  var px = imgData.data;
  var sx = W / fw, sy = H / fh;
  var rings = parseInt(slRings.value) || 14;
  var ci = fieldMax / rings;
  var cw2 = 0.003 * 0.003;
  var acw2 = 0.007 * 0.007;
  var nf = foci.length;
  var fociX = new Float64Array(nf);
  var fociY = new Float64Array(nf);
  for (var f = 0; f < nf; f++) {
    fociX[f] = foci[f].x;
    fociY[f] = foci[f].y;
  }
  var acNorm = ac / ci;

  for (var fy = 0; fy < fh; fy++) {
    var cy = (fy + 0.5) * sy;
    for (var fx = 0; fx < fw; fx++) {
      var cx = (fx + 0.5) * sx;

      var dists = new Array(nf);
      for (var f = 0; f < nf; f++) {
        var ddx = cx - fociX[f], ddy = cy - fociY[f];
        dists[f] = Math.sqrt(ddx * ddx + ddy * ddy);
      }

      var v = computeMetric(dists);

      var norm = v / fieldMax;
      if (norm > 1) norm = 1;

      var vn = v / ci;
      var phase = vn - Math.floor(vn);
      var edgeDist = phase < 0.5 ? phase : 1 - phase;
      var ed2 = edgeDist * edgeDist;
      var ringGlow = Math.exp(-ed2 / cw2);

      var aDist = Math.abs(vn - acNorm);
      var ad2 = aDist * aDist;
      var activeGlow = Math.exp(-ad2 / acw2);

      var hue = brandHue(norm);
      var rgb = hsl2rgb(hue, 0.65, 0.08 + ringGlow * 0.22);

      var bright = activeGlow;
      var r = rgb[0] + bright * (0 - rgb[0]) + bright * 20;
      var g = rgb[1] + bright * (245 - rgb[1]);
      var b = rgb[2] + bright * (212 - rgb[2]);

      if (r > 255) r = 255;
      if (g > 255) g = 255;
      if (b > 255) b = 255;

      var idx = (fy * fw + fx) * 4;
      px[idx] = r;
      px[idx + 1] = g;
      px[idx + 2] = b;
      px[idx + 3] = 255;
    }
  }
  fctx.putImageData(imgData, 0, 0);
}

function drawFociMarkers() {
  for (var i = 0; i < foci.length; i++) {
    var x = foci[i].x, y = foci[i].y;
    ctx.beginPath();
    ctx.arc(x, y, 14, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0,245,212,0.25)';
    ctx.lineWidth = 1;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(x, y, 3, 0, Math.PI * 2);
    ctx.fillStyle = '#fff';
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x - 8, y); ctx.lineTo(x + 8, y);
    ctx.moveTo(x, y - 8); ctx.lineTo(x, y + 8);
    ctx.strokeStyle = 'rgba(255,255,255,0.3)';
    ctx.lineWidth = 0.5;
    ctx.stroke();
  }
}

function draw() {
  var ac = getActiveContour();
  if (ac !== lastAC || needRender) {
    renderField(ac);
    lastAC = ac;
    needRender = false;
  }

  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(FC, 0, 0, W, H);

  drawFociMarkers();
}

function tick() {
  if (autoAnim) animT += 1 / 60;
  draw();
  requestAnimationFrame(tick);
}

function nearestFocus(x, y, radius) {
  var best = -1, bestD = radius * radius;
  for (var i = 0; i < foci.length; i++) {
    var dx = x - foci[i].x, dy = y - foci[i].y;
    var d2 = dx * dx + dy * dy;
    if (d2 < bestD) { bestD = d2; best = i; }
  }
  return best;
}

C.addEventListener('pointerdown', function(e) {
  e.preventDefault();
  C.setPointerCapture(e.pointerId);
  var x = e.clientX, y = e.clientY;
  var idx = nearestFocus(x, y, 30);
  if (idx >= 0) {
    dragging = idx;
    dragOff.x = foci[idx].x - x;
    dragOff.y = foci[idx].y - y;
    return;
  }
  if (foci.length < 6) {
    foci.push({x: x, y: y});
    updateMax();
    elFoci.textContent = foci.length;
    SFX.play('spawn');
  }
});

C.addEventListener('pointermove', function(e) {
  if (dragging >= 0) {
    foci[dragging].x = e.clientX + dragOff.x;
    foci[dragging].y = e.clientY + dragOff.y;
    updateMax();
    SFX.play('move', 120);
  }
});

C.addEventListener('pointerup', function(e) {
  C.releasePointerCapture(e.pointerId);
  dragging = -1;
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  var x = e.clientX, y = e.clientY;
  var idx = nearestFocus(x, y, 30);
  if (idx >= 0 && foci.length > 2) {
    foci.splice(idx, 1);
    updateMax();
    elFoci.textContent = foci.length;
    SFX.play('pop');
  }
});

var modeBtns = document.querySelectorAll('.mode-btn');
modeBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    modeBtns.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    metric = btn.getAttribute('data-m');
    elMetric.textContent = btn.textContent;
    updateMax();
    SFX.play('toggle');
  });
});

slLevel.addEventListener('input', function() {
  autoAnim = false;
  elMode.textContent = 'MANUAL';
  elLevelV.textContent = Math.round(slLevel.value / 10);
  needRender = true;
});

slRings.addEventListener('input', function() {
  elRingsV.textContent = slRings.value;
  needRender = true;
  SFX.play('tick', 80);
});

document.getElementById('btn-auto').addEventListener('click', function() {
  autoAnim = !autoAnim;
  elMode.textContent = autoAnim ? 'AUTO' : 'MANUAL';
  elLevelV.textContent = autoAnim ? 'AUTO' : Math.round(slLevel.value / 10);
  SFX.play('click');
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initFoci();
  metric = 'product';
  autoAnim = true;
  animT = 0;
  elMetric.textContent = 'PROD';
  elFoci.textContent = '2';
  elMode.textContent = 'AUTO';
  elLevelV.textContent = 'AUTO';
  slRings.value = 14;
  elRingsV.textContent = '14';
  modeBtns.forEach(function(b) { b.classList.remove('active'); });
  modeBtns[0].classList.add('active');
  SFX.play('click');
});

window.addEventListener('resize', function() { resize(); updateMax(); });
resize();
initFoci();
tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
