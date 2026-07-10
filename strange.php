<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('strange');

$pageName = 'strange';
$pageTitle = 'STRANGE - PULSE';
$pageDesc = 'Sprott二次写像の奇妙なアトラクタ探索。12係数のパラメータ空間からカオス軌道を発見し、密度蓄積で描く。';
$pageCanonical = 'https://pulse.subara3.com/strange.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
.hud .code{color:rgba(0,245,212,.7);font-size:.56rem;letter-spacing:.08em}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  CODE <span class="code" id="h-code">............</span><br>
  POINTS <b id="h-pts">0</b><br>
  LYAPUNOV <b id="h-lyap">&mdash;</b><br>
  <span class="sub">GEN</span> <b id="h-gen">0</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'discover'],
  ['type' => 'drag', 'label' => 'mutate'],
  ['type' => 'right', 'label' => 'search'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row">
    <button class="control-btn" id="btn-search">SEARCH</button>
    <button class="control-btn" id="btn-mutate">MUTATE</button>
    <button class="control-btn" id="btn-clear">CLEAR</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="8" value="4" step="1"><span class="slider-value" id="sv-speed">4</span></div></div>
  <div><div class="slider-row"><span class="slider-label">TRAIL</span><input type="range" id="sl-fade" min="0" max="100" value="2" step="1"><span class="slider-value" id="sv-fade">2</span></div></div>
  <div><div class="slider-row"><span class="slider-label">PALETTE</span><input type="range" id="sl-pal" min="0" max="3" value="0" step="1"><span class="slider-value" id="sv-pal">TEAL</span></div></div>
  <div><div class="btn-row" id="history-btns"></div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H, pr;
var buf, bW, bH, bData;

var coefs = new Float64Array(12);
var code = '';
var lyapunov = 0;
var totalPts = 0;
var generation = 0;
var iterPerFrame = 4000;
var fadeAmt = 2;
var palIdx = 0;
var history = [];
var maxHist = 8;
var running = true;

var xe = 0.1, ye = 0.1;
var xMin, xMax, yMin, yMax;
var settled = false;
var settleIter = 0;
var settleMax = 5000;

var palettes = [
  [[0,245,212],[0,180,170],[0,120,140]],
  [[123,44,191],[160,80,220],[90,20,160]],
  [[0,245,212],[60,140,200],[123,44,191]],
  [[80,200,190],[0,245,212],[140,60,200]]
];
var palNames = ['TEAL','PURPLE','MIXED','AURORA'];

function resize() {
  pr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth; H = window.innerHeight;
  C.width = W * pr; C.height = H * pr;
  C.style.width = W + 'px'; C.style.height = H + 'px';
  X.setTransform(pr, 0, 0, pr, 0, 0);
  bW = Math.ceil(W * pr);
  bH = Math.ceil(H * pr);
  buf = new Uint32Array(bW * bH);
  resetAccum();
}

function resetAccum() {
  if (buf) buf.fill(0);
  totalPts = 0;
}

function encodeCoefs(c) {
  var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXY';
  var s = '';
  for (var i = 0; i < 12; i++) {
    var v = Math.round((c[i] + 1.2) / 0.1);
    v = Math.max(0, Math.min(24, v));
    s += chars[v];
  }
  return s;
}

function decodeCode(s) {
  var c = new Float64Array(12);
  for (var i = 0; i < 12; i++) {
    var v = s.charCodeAt(i) - 65;
    c[i] = v * 0.1 - 1.2;
  }
  return c;
}

function randomCoefs() {
  var c = new Float64Array(12);
  for (var i = 0; i < 12; i++) {
    c[i] = (Math.floor(Math.random() * 25) * 0.1) - 1.2;
  }
  return c;
}

function mutateCoefs(c) {
  var m = new Float64Array(c);
  var n = 1 + Math.floor(Math.random() * 3);
  for (var i = 0; i < n; i++) {
    var idx = Math.floor(Math.random() * 12);
    var delta = (Math.random() < 0.5 ? -1 : 1) * 0.1;
    m[idx] = Math.max(-1.2, Math.min(1.2, Math.round((m[idx] + delta) * 10) / 10));
  }
  return m;
}

function evalMap(c, x, y) {
  var xn = c[0] + c[1]*x + c[2]*x*x + c[3]*x*y + c[4]*y + c[5]*y*y;
  var yn = c[6] + c[7]*x + c[8]*x*x + c[9]*x*y + c[10]*y + c[11]*y*y;
  return [xn, yn];
}

function testAttractor(c) {
  var x = 0.1, y = 0.1;
  var lsum = 0, lcount = 0;
  var xmi = Infinity, xma = -Infinity, ymi = Infinity, yma = -Infinity;

  for (var i = 0; i < settleMax; i++) {
    var r = evalMap(c, x, y);
    var xn = r[0], yn = r[1];

    if (Math.abs(xn) > 1e6 || Math.abs(yn) > 1e6) return null;
    if (isNaN(xn) || isNaN(yn)) return null;

    if (i > 200) {
      if (Math.abs(xn - x) < 1e-10 && Math.abs(yn - y) < 1e-10) return null;

      xmi = Math.min(xmi, xn);
      xma = Math.max(xma, xn);
      ymi = Math.min(ymi, yn);
      yma = Math.max(yma, yn);

      var dx1 = c[1] + 2*c[2]*x + c[3]*y;
      var dy1 = c[10] + 2*c[11]*y + c[9]*x;
      var jac = Math.sqrt(dx1*dx1 + dy1*dy1);
      if (jac > 1e-12) {
        lsum += Math.log(jac);
        lcount++;
      }
    }
    x = xn; y = yn;
  }

  var lyap = lcount > 0 ? lsum / lcount : 0;
  if (lyap < 0.01) return null;

  var rangeX = xma - xmi, rangeY = yma - ymi;
  if (rangeX < 0.01 || rangeY < 0.01) return null;

  return {
    lyap: lyap,
    xMin: xmi, xMax: xma,
    yMin: ymi, yMax: yma
  };
}

function searchAttractor() {
  for (var attempt = 0; attempt < 2000; attempt++) {
    var c = randomCoefs();
    var result = testAttractor(c);
    if (result) {
      setAttractor(c, result);
      return true;
    }
  }
  return false;
}

function setAttractor(c, result) {
  coefs = c;
  code = encodeCoefs(c);
  lyapunov = result.lyap;
  var pad = 0.1;
  var rx = result.xMax - result.xMin;
  var ry = result.yMax - result.yMin;
  xMin = result.xMin - rx * pad;
  xMax = result.xMax + rx * pad;
  yMin = result.yMin - ry * pad;
  yMax = result.yMax + ry * pad;
  xe = 0.1; ye = 0.1;
  settled = false;
  settleIter = 0;
  resetAccum();
  generation++;
  addHistory(c, code);
  updateHUD();
}

function addHistory(c, cd) {
  for (var i = 0; i < history.length; i++) {
    if (history[i].code === cd) return;
  }
  history.unshift({coefs: new Float64Array(c), code: cd});
  if (history.length > maxHist) history.pop();
  renderHistoryBtns();
}

function renderHistoryBtns() {
  var cont = document.getElementById('history-btns');
  cont.innerHTML = '';
  for (var i = 0; i < history.length; i++) {
    var btn = document.createElement('button');
    btn.className = 'control-btn' + (history[i].code === code ? ' active' : '');
    btn.textContent = history[i].code.substring(0, 4);
    btn.title = history[i].code;
    btn.dataset.idx = i;
    btn.addEventListener('click', function() {
      var h = history[parseInt(this.dataset.idx)];
      var result = testAttractor(h.coefs);
      if (result) {
        setAttractor(h.coefs, result);
        if (typeof SFX !== 'undefined') SFX.play('toggle');
      }
    });
    cont.appendChild(btn);
  }
}

function iterate(n) {
  if (!settled) {
    for (var i = 0; i < 500; i++) {
      var r = evalMap(coefs, xe, ye);
      xe = r[0]; ye = r[1];
      settleIter++;
    }
    if (settleIter >= 500) settled = true;
    return;
  }

  var w = bW, h = bH;
  var rxInv = w / (xMax - xMin);
  var ryInv = h / (yMax - yMin);

  for (var i = 0; i < n; i++) {
    var r = evalMap(coefs, xe, ye);
    xe = r[0]; ye = r[1];

    if (Math.abs(xe) > 1e6 || Math.abs(ye) > 1e6) {
      searchAttractor();
      return;
    }

    var px = ((xe - xMin) * rxInv) | 0;
    var py = ((ye - yMin) * ryInv) | 0;

    if (px >= 0 && px < w && py >= 0 && py < h) {
      buf[py * w + px]++;
    }
  }
  totalPts += n;
}

function draw() {
  var img = X.createImageData(W, H);
  var data = img.data;
  var pal = palettes[palIdx];

  var maxVal = 0;
  var len = bW * bH;
  for (var i = 0; i < len; i++) {
    if (buf[i] > maxVal) maxVal = buf[i];
  }
  if (maxVal === 0) maxVal = 1;
  var logMax = Math.log(maxVal + 1);

  var sW = Math.ceil(W), sH = Math.ceil(H);
  var scaleX = bW / sW, scaleY = bH / sH;

  for (var sy = 0; sy < sH; sy++) {
    for (var sx = 0; sx < sW; sx++) {
      var bx = (sx * scaleX) | 0;
      var by = (sy * scaleY) | 0;
      if (bx >= bW) bx = bW - 1;
      if (by >= bH) by = bH - 1;

      var v = buf[by * bW + bx];
      var idx = (sy * sW + sx) * 4;

      if (v === 0) {
        data[idx] = 10; data[idx+1] = 10; data[idx+2] = 15; data[idx+3] = 255;
      } else {
        var t = Math.log(v + 1) / logMax;
        var c0, c1, blend;
        if (t < 0.5) {
          blend = t * 2;
          c0 = pal[2]; c1 = pal[1];
        } else {
          blend = (t - 0.5) * 2;
          c0 = pal[1]; c1 = pal[0];
        }
        data[idx]   = (c0[0] + (c1[0] - c0[0]) * blend) | 0;
        data[idx+1] = (c0[1] + (c1[1] - c0[1]) * blend) | 0;
        data[idx+2] = (c0[2] + (c1[2] - c0[2]) * blend) | 0;
        data[idx+3] = 255;
      }
    }
  }
  X.putImageData(img, 0, 0);
}

function updateHUD() {
  document.getElementById('h-code').textContent = code;
  document.getElementById('h-lyap').textContent = lyapunov.toFixed(3);
  document.getElementById('h-gen').textContent = generation;
}

function loop() {
  var speed = [500, 1000, 2000, 4000, 8000, 16000, 32000, 64000];
  iterate(speed[iterPerFrame - 1] || 4000);
  document.getElementById('h-pts').textContent = totalPts > 1000000
    ? (totalPts / 1000000).toFixed(1) + 'M'
    : totalPts > 1000
    ? (totalPts / 1000).toFixed(0) + 'K'
    : totalPts;
  draw();
  if (running) requestAnimationFrame(loop);
}

window.addEventListener('resize', resize);
resize();
searchAttractor();
if (typeof SFX !== 'undefined') SFX.play('spawn');
requestAnimationFrame(loop);

function doSearch() {
  if (searchAttractor()) {
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
}

function doMutate() {
  for (var attempt = 0; attempt < 200; attempt++) {
    var m = mutateCoefs(coefs);
    var result = testAttractor(m);
    if (result) {
      setAttractor(m, result);
      if (typeof SFX !== 'undefined') SFX.play('grow');
      return;
    }
  }
  doSearch();
}

C.addEventListener('click', function(e) {
  if (e.button === 2) return;
  doSearch();
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  doSearch();
});

var dragStart = null;
C.addEventListener('mousedown', function(e) {
  if (e.button === 0) dragStart = {x: e.clientX, y: e.clientY};
});
window.addEventListener('mouseup', function() {
  if (dragStart) {
    dragStart = null;
  }
});
C.addEventListener('mousemove', function(e) {
  if (!dragStart) return;
  var dx = e.clientX - dragStart.x;
  var dy = e.clientY - dragStart.y;
  if (Math.abs(dx) + Math.abs(dy) > 30) {
    dragStart = null;
    doMutate();
  }
});

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  dragStart = {x: e.touches[0].clientX, y: e.touches[0].clientY};
}, {passive: false});
C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragStart) return;
  var dx = e.touches[0].clientX - dragStart.x;
  var dy = e.touches[0].clientY - dragStart.y;
  if (Math.abs(dx) + Math.abs(dy) > 40) {
    dragStart = null;
    doMutate();
  }
}, {passive: false});
C.addEventListener('touchend', function(e) {
  if (dragStart) {
    dragStart = null;
    doSearch();
  }
});

document.getElementById('btn-search').addEventListener('click', function() {
  doSearch();
});
document.getElementById('btn-mutate').addEventListener('click', function() {
  doMutate();
});
document.getElementById('btn-clear').addEventListener('click', function() {
  resetAccum();
  xe = 0.1; ye = 0.1;
  settled = false; settleIter = 0;
  if (typeof SFX !== 'undefined') SFX.play('click');
});

var slSpeed = document.getElementById('sl-speed'), svSpeed = document.getElementById('sv-speed');
slSpeed.addEventListener('input', function() {
  iterPerFrame = parseInt(slSpeed.value);
  svSpeed.textContent = iterPerFrame;
});

var slFade = document.getElementById('sl-fade'), svFade = document.getElementById('sv-fade');
slFade.addEventListener('input', function() {
  fadeAmt = parseInt(slFade.value);
  svFade.textContent = fadeAmt;
});

var slPal = document.getElementById('sl-pal'), svPal = document.getElementById('sv-pal');
slPal.addEventListener('input', function() {
  palIdx = parseInt(slPal.value);
  svPal.textContent = palNames[palIdx];
});
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
