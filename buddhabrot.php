<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('buddhabrot');

$pageName = 'buddhabrot';
$pageTitle = 'BUDDHABROT - PULSE';
$pageDesc = 'ブッダブロ。マンデルブロ集合から脱出する軌跡を重ね合わせると、星雲のような光の構造が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/buddhabrot.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:4.5rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  SAMPLES <span class="v" id="h-samples">0</span><br>
  MODE <span class="v" id="h-mode">BUDDHA</span><br>
  ITER <span class="v" id="h-iter">200</span>
</div>

<div id="info">
  <span class="v">Buddhabrot</span><br>
  escape trajectory density
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'zoom in'],
  ['type' => 'right', 'label' => 'zoom out'],
  ['type' => 'drag', 'label' => 'pan'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">ITER</span>
      <input type="range" id="sl-iter" min="0" max="6" value="2" step="1">
      <span class="slider-value" id="sv-iter">200</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="5" value="3" step="1">
      <span class="slider-value" id="sv-speed">3</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">GAMMA</span>
      <input type="range" id="sl-gamma" min="20" max="100" value="55" step="5">
      <span class="slider-value" id="sv-gamma">0.55</span>
    </div>
  </div>
  <div>
    <div class="btn-row" style="flex-wrap:wrap">
      <button class="control-btn active" data-m="buddha">BUDDHA</button>
      <button class="control-btn" data-m="anti">ANTI</button>
      <button class="control-btn" data-m="nebula">NEBULA</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, bufW, bufH;
var TWO_PI = Math.PI * 2;

var viewCx = -0.4, viewCy = 0;
var viewScale = 2.2;

var maxIter = 200;
var iterSteps = [20, 50, 200, 500, 1000, 3000, 8000];
var speedLevel = 3;
var gamma = 0.55;
var mode = 'buddha';

var buf, bufNR, bufNG, bufNB;
var maxD, maxNR, maxNG, maxNB;
var totalSamples = 0;
var imgData;
var renderDirty = true;

var MAX_TRAJ = 10000;
var trajX = new Float64Array(MAX_TRAJ);
var trajY = new Float64Array(MAX_TRAJ);

var dragging = false, panning = false;
var dragSX = 0, dragSY = 0;
var clickSX = 0, clickSY = 0;
var panStartCx, panStartCy;

var hSamples = document.getElementById('h-samples');
var hMode = document.getElementById('h-mode');
var hIter = document.getElementById('h-iter');
var slIter = document.getElementById('sl-iter');
var slSpeed = document.getElementById('sl-speed');
var slGamma = document.getElementById('sl-gamma');
var svIter = document.getElementById('sv-iter');
var svSpeed = document.getElementById('sv-speed');
var svGamma = document.getElementById('sv-gamma');

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
  bufW = W;
  bufH = H;
  resetBuffers();
}

function resetBuffers() {
  var len = bufW * bufH;
  buf = new Uint32Array(len);
  bufNR = new Uint32Array(len);
  bufNG = new Uint32Array(len);
  bufNB = new Uint32Array(len);
  maxD = 0; maxNR = 0; maxNG = 0; maxNB = 0;
  totalSamples = 0;
  imgData = ctx.createImageData(bufW, bufH);
  renderDirty = true;
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

function inMainBulbs(cx, cy) {
  var q = (cx - 0.25) * (cx - 0.25) + cy * cy;
  if (q * (q + cx - 0.25) < 0.25 * cy * cy) return true;
  if ((cx + 1) * (cx + 1) + cy * cy < 0.0625) return true;
  return false;
}

function sampleBatch(count, iterLimit, targetBuf) {
  var sc = Math.min(W, H) / (viewScale * 2);
  var halfW = W * 0.5, halfH = H * 0.5;
  var isAnti = (mode === 'anti');
  var localMax = 0;

  for (var n = 0; n < count; n++) {
    var cx = -2.5 + Math.random() * 4;
    var cy = Math.random() * 4 - 2;

    if (!isAnti && inMainBulbs(cx, cy)) continue;

    var zx = 0, zy = 0;
    var escaped = false;
    var tLen = 0;

    for (var i = 0; i < iterLimit; i++) {
      var zx2 = zx * zx;
      var zy2 = zy * zy;
      if (zx2 + zy2 > 4) { escaped = true; break; }
      if (tLen < MAX_TRAJ) {
        trajX[tLen] = zx;
        trajY[tLen] = zy;
        tLen++;
      }
      var nzx = zx2 - zy2 + cx;
      zy = 2 * zx * zy + cy;
      zx = nzx;
    }

    var record = isAnti ? !escaped : escaped;
    if (!record) continue;

    for (var j = 0; j < tLen; j++) {
      var px = ((trajX[j] - viewCx) * sc + halfW) | 0;
      var py = ((trajY[j] - viewCy) * sc + halfH) | 0;
      if (px >= 0 && px < bufW && py >= 0 && py < bufH) {
        var idx = py * bufW + px;
        targetBuf[idx]++;
        if (targetBuf[idx] > localMax) localMax = targetBuf[idx];
      }
      var my = ((-trajY[j] - viewCy) * sc + halfH) | 0;
      if (my >= 0 && my < bufH && px >= 0 && px < bufW) {
        var midx = my * bufW + px;
        targetBuf[midx]++;
        if (targetBuf[midx] > localMax) localMax = targetBuf[midx];
      }
    }
  }

  totalSamples += count;
  return localMax;
}

var nebulaPhase = 0;

function doSampling() {
  var batchSizes = [500, 1000, 2000, 4000, 8000];
  var count = batchSizes[Math.min(speedLevel - 1, batchSizes.length - 1)];

  if (mode === 'nebula') {
    var iters = [50, 500, Math.min(maxIter, 5000)];
    var bufs = [bufNR, bufNG, bufNB];
    var phase = nebulaPhase % 3;
    var m = sampleBatch(count, iters[phase], bufs[phase]);
    if (phase === 0 && m > maxNR) maxNR = m;
    if (phase === 1 && m > maxNG) maxNG = m;
    if (phase === 2 && m > maxNB) maxNB = m;
    nebulaPhase++;
  } else {
    var m = sampleBatch(count, maxIter, buf);
    if (m > maxD) maxD = m;
  }
  renderDirty = true;
}

function renderImage() {
  if (!renderDirty) return;
  renderDirty = false;

  var data = imgData.data;
  var len = bufW * bufH;
  var g = gamma;

  if (mode === 'nebula') {
    var lR = Math.log(1 + maxNR) || 1;
    var lG = Math.log(1 + maxNG) || 1;
    var lB = Math.log(1 + maxNB) || 1;

    for (var i = 0; i < len; i++) {
      var vR = Math.pow(Math.log(1 + bufNR[i]) / lR, g);
      var vG = Math.pow(Math.log(1 + bufNG[i]) / lG, g);
      var vB = Math.pow(Math.log(1 + bufNB[i]) / lB, g);

      var r = vR * 0 + vG * 40 + vB * 123;
      var gr = vR * 245 + vG * 140 + vB * 44;
      var b = vR * 212 + vG * 200 + vB * 191;

      var pi = i * 4;
      data[pi]     = Math.min(255, r) | 0;
      data[pi + 1] = Math.min(255, gr) | 0;
      data[pi + 2] = Math.min(255, b) | 0;
      data[pi + 3] = 255;
    }
  } else {
    var logMax = Math.log(1 + maxD) || 1;

    for (var i = 0; i < len; i++) {
      var v = buf[i];
      var br = Math.pow(Math.log(1 + v) / logMax, g);

      var r, gr2, b;
      if (br < 0.005) {
        r = 10; gr2 = 10; b = 15;
      } else if (br < 0.4) {
        var t = br / 0.4;
        r = 10 + 113 * t;
        gr2 = 10 + 34 * t;
        b = 15 + 176 * t;
      } else {
        var t = (br - 0.4) / 0.6;
        t = t * t * (3 - 2 * t);
        r = 123 - 123 * t;
        gr2 = 44 + 201 * t;
        b = 191 + 21 * t;
      }

      var pi = i * 4;
      data[pi]     = r | 0;
      data[pi + 1] = gr2 | 0;
      data[pi + 2] = b | 0;
      data[pi + 3] = 255;
    }
  }

  ctx.putImageData(imgData, 0, 0);
}

function formatNum(n) {
  if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
  if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
  return n.toString();
}

function updateHUD() {
  hSamples.textContent = formatNum(totalSamples);
  hMode.textContent = mode.toUpperCase();
  hIter.textContent = maxIter;
}

function setMode(m) {
  if (m === mode) return;
  mode = m;
  var btns = document.querySelectorAll('[data-m]');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.toggle('active', btns[i].dataset.m === m);
  }
  resetBuffers();
  if (typeof SFX !== 'undefined') SFX.play('toggle');
}

slIter.addEventListener('input', function() {
  var idx = parseInt(this.value);
  maxIter = iterSteps[idx];
  svIter.textContent = maxIter;
  resetBuffers();
  if (typeof SFX !== 'undefined') SFX.play('tick');
});

slSpeed.addEventListener('input', function() {
  speedLevel = parseInt(this.value);
  svSpeed.textContent = speedLevel;
});

slGamma.addEventListener('input', function() {
  gamma = parseInt(this.value) / 100;
  svGamma.textContent = gamma.toFixed(2);
  renderDirty = true;
});

var mBtns = document.querySelectorAll('[data-m]');
for (var b = 0; b < mBtns.length; b++) {
  mBtns[b].addEventListener('click', (function(btn) {
    return function() { setMode(btn.dataset.m); };
  })(mBtns[b]));
}

document.getElementById('btn-reset').addEventListener('click', function() {
  viewCx = -0.4; viewCy = 0; viewScale = 2.2;
  resetBuffers();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

function getPos(e) {
  if (e.touches && e.touches.length) return {x: e.touches[0].clientX, y: e.touches[0].clientY};
  return {x: e.clientX, y: e.clientY};
}

function screenToComplex(sx, sy) {
  var sc = Math.min(W, H) / (viewScale * 2);
  return [(sx - W * 0.5) / sc + viewCx, (sy - H * 0.5) / sc + viewCy];
}

function zoomAt(sx, sy, factor) {
  var c = screenToComplex(sx, sy);
  var t = 0.3;
  viewCx += (c[0] - viewCx) * t;
  viewCy += (c[1] - viewCy) * t;
  viewScale *= factor;
  resetBuffers();
}

C.addEventListener('mousedown', function(e) {
  if (e.button === 2) return;
  var p = getPos(e);
  dragging = true; panning = false;
  clickSX = dragSX = p.x;
  clickSY = dragSY = p.y;
  panStartCx = viewCx;
  panStartCy = viewCy;
});

C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  var p = getPos(e);
  var dx = p.x - clickSX, dy = p.y - clickSY;
  if (!panning && (dx * dx + dy * dy > 36)) panning = true;
  if (panning) {
    var sc = Math.min(W, H) / (viewScale * 2);
    viewCx = panStartCx - (p.x - clickSX) / sc;
    viewCy = panStartCy - (p.y - clickSY) / sc;
    resetBuffers();
  }
});

C.addEventListener('mouseup', function(e) {
  if (dragging && !panning) {
    zoomAt(clickSX, clickSY, 0.5);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  dragging = false; panning = false;
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  var p = getPos(e);
  zoomAt(p.x, p.y, 2);
  if (typeof SFX !== 'undefined') SFX.play('pop');
});

C.addEventListener('wheel', function(e) {
  e.preventDefault();
  var factor = e.deltaY > 0 ? 1.15 : 0.87;
  zoomAt(e.clientX, e.clientY, factor);
  if (typeof SFX !== 'undefined') SFX.play('tick', 80);
}, {passive: false});

var touchDist0 = 0;
var touchMidX0 = 0, touchMidY0 = 0;
var pinching = false;

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 2) {
    pinching = true;
    dragging = false;
    var dx = e.touches[1].clientX - e.touches[0].clientX;
    var dy = e.touches[1].clientY - e.touches[0].clientY;
    touchDist0 = Math.sqrt(dx * dx + dy * dy);
    touchMidX0 = (e.touches[0].clientX + e.touches[1].clientX) * 0.5;
    touchMidY0 = (e.touches[0].clientY + e.touches[1].clientY) * 0.5;
    panStartCx = viewCx;
    panStartCy = viewCy;
  } else if (e.touches.length === 1) {
    var p = getPos(e);
    dragging = true; panning = false; pinching = false;
    clickSX = dragSX = p.x;
    clickSY = dragSY = p.y;
    panStartCx = viewCx;
    panStartCy = viewCy;
  }
}, {passive: false});

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (pinching && e.touches.length === 2) {
    var dx = e.touches[1].clientX - e.touches[0].clientX;
    var dy = e.touches[1].clientY - e.touches[0].clientY;
    var dist = Math.sqrt(dx * dx + dy * dy);
    var factor = touchDist0 / dist;
    var midX = (e.touches[0].clientX + e.touches[1].clientX) * 0.5;
    var midY = (e.touches[0].clientY + e.touches[1].clientY) * 0.5;
    viewScale *= factor;
    var sc = Math.min(W, H) / (viewScale * 2);
    viewCx = panStartCx - (midX - touchMidX0) / sc;
    viewCy = panStartCy - (midY - touchMidY0) / sc;
    touchDist0 = dist;
    touchMidX0 = midX;
    touchMidY0 = midY;
    panStartCx = viewCx;
    panStartCy = viewCy;
    resetBuffers();
  } else if (dragging && e.touches.length === 1) {
    var p = getPos(e);
    var ddx = p.x - clickSX, ddy = p.y - clickSY;
    if (!panning && (ddx * ddx + ddy * ddy > 36)) panning = true;
    if (panning) {
      var sc = Math.min(W, H) / (viewScale * 2);
      viewCx = panStartCx - (p.x - clickSX) / sc;
      viewCy = panStartCy - (p.y - clickSY) / sc;
      resetBuffers();
    }
  }
}, {passive: false});

C.addEventListener('touchend', function(e) {
  if (pinching) {
    pinching = false;
    return;
  }
  if (dragging && !panning && e.changedTouches.length) {
    zoomAt(clickSX, clickSY, 0.5);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  dragging = false; panning = false;
});

var fc = 0;
function animate() {
  doSampling();

  if (++fc % 3 === 0) {
    renderImage();
    updateHUD();
  }

  requestAnimationFrame(animate);
}

window.addEventListener('resize', resize);
resize();
requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
