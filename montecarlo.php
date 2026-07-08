<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('montecarlo');

$pageName = 'montecarlo';
$pageTitle = 'MONTECARLO - PULSE';
$pageDesc = 'モンテカルロ法。ランダムな点を降らせて面積を推定する。サンプル数が増えるほど真の値に収束していく確率的計算。';
$pageCanonical = 'https://pulse.subara3.com/montecarlo.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;left:1rem;top:3.5rem;z-index:60;color:rgba(255,255,255,.42);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}.hud .sub{color:rgba(123,44,191,.92)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  TARGET <b id="h-shape">CIRCLE</b><br>
  SAMPLES <b id="h-samples">0</b><br>
  ESTIMATE <b id="h-estimate">—</b><br>
  <span class="sub">TRUE</span> <b id="h-true">—</b><br>
  <span class="sub">ERROR</span> <b id="h-error">—</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop'],
  ['type' => 'drag', 'label' => 'rain'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row" id="shape-btns">
    <button class="control-btn active" data-s="0">CIRCLE</button>
    <button class="control-btn" data-s="1">DIAMOND</button>
    <button class="control-btn" data-s="2">STAR</button>
    <button class="control-btn" data-s="3">HEART</button>
    <button class="control-btn" data-s="4">RING</button>
    <button class="control-btn" data-s="5">CROSS</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="100" value="20" step="1"><span class="slider-value" id="sv-speed">20</span></div></div>
  <div><div class="slider-row"><span class="slider-label">DOT SIZE</span><input type="range" id="sl-dot" min="1" max="6" value="2" step="1"><span class="slider-value" id="sv-dot">2</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-burst">BURST ×1K</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H, cx, cy, R;
var samples = [], nIn = 0, nTotal = 0;
var shapeIdx = 0;
var autoMode = false, dropSpeed = 20, dotSize = 2;
var dragging = false;
var convergence = [];
var maxConvPoints = 400;

var shapes = [
  { name: 'CIRCLE', trueArea: Math.PI / 4,
    test: function(x, y) { return x*x + y*y <= 1; }
  },
  { name: 'DIAMOND', trueArea: 0.5,
    test: function(x, y) { return Math.abs(x) + Math.abs(y) <= 1; }
  },
  { name: 'STAR', trueArea: null,
    test: function(x, y) {
      var a = Math.atan2(y, x);
      var r = Math.sqrt(x*x + y*y);
      var k = 5, inner = 0.38;
      var sector = Math.PI / k;
      var aa = ((a % (2*sector)) + 2*sector) % (2*sector);
      var rr;
      if (aa < sector) rr = 1.0 - (1.0 - inner) * aa / sector;
      else rr = inner + (1.0 - inner) * (aa - sector) / sector;
      return r <= rr;
    }
  },
  { name: 'HEART', trueArea: null,
    test: function(x, y) {
      var xx = x, yy = -y * 0.9 + 0.2;
      var v = xx*xx + yy*yy - 0.6;
      return v*v*v - xx*xx * yy*yy*yy <= 0;
    }
  },
  { name: 'RING', trueArea: Math.PI / 4 - Math.PI * 0.16,
    test: function(x, y) { var d = x*x + y*y; return d <= 1 && d >= 0.16; }
  },
  { name: 'CROSS', trueArea: 5.0/9.0,
    test: function(x, y) {
      var ax = Math.abs(x), ay = Math.abs(y);
      return (ax <= 1.0/3.0) || (ay <= 1.0/3.0);
    }
  }
];

function computeStarArea() {
  var count = 0, total = 100000;
  for (var i = 0; i < total; i++) {
    var x = Math.random() * 2 - 1, y = Math.random() * 2 - 1;
    if (shapes[2].test(x, y)) count++;
  }
  shapes[2].trueArea = count / total;
}
function computeHeartArea() {
  var count = 0, total = 100000;
  for (var i = 0; i < total; i++) {
    var x = Math.random() * 2 - 1, y = Math.random() * 2 - 1;
    if (shapes[3].test(x, y)) count++;
  }
  shapes[3].trueArea = count / total;
}
computeStarArea();
computeHeartArea();

function resize() {
  var pr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth; H = window.innerHeight;
  C.width = W * pr; C.height = H * pr;
  C.style.width = W + 'px'; C.style.height = H + 'px';
  X.setTransform(pr, 0, 0, pr, 0, 0);
  cx = W * 0.5;
  cy = H * 0.48;
  R = Math.min(W, H) * 0.32;
}
window.addEventListener('resize', resize);
resize();

function toWorld(sx, sy) {
  return [(sx - cx) / R, (sy - cy) / R];
}

function toScreen(wx, wy) {
  return [cx + wx * R, cy + wy * R];
}

function resetSamples() {
  samples = [];
  nIn = 0;
  nTotal = 0;
  convergence = [];
  if (typeof SFX !== 'undefined') SFX.play('click');
}

function addSample(wx, wy) {
  var inside = shapes[shapeIdx].test(wx, wy);
  samples.push({ x: wx, y: wy, inside: inside, t: Date.now() });
  nTotal++;
  if (inside) nIn++;

  var step = nTotal < 100 ? 1 : nTotal < 1000 ? 10 : 50;
  if (nTotal % step === 0 || nTotal <= 50) {
    convergence.push({ n: nTotal, est: 4.0 * nIn / nTotal });
    if (convergence.length > maxConvPoints) convergence.shift();
  }
}

function dropRandom(count) {
  for (var i = 0; i < count; i++) {
    var wx = Math.random() * 2 - 1;
    var wy = Math.random() * 2 - 1;
    addSample(wx, wy);
  }
}

function drawShape(shape) {
  X.save();
  X.translate(cx, cy);
  X.scale(R, R);

  X.beginPath();
  var res = 300;
  var first = true;
  for (var a = 0; a <= Math.PI * 2; a += Math.PI * 2 / res) {
    for (var r = 0; r <= 1.0; r += 0.002) {
      var xx = Math.cos(a) * r;
      var yy = Math.sin(a) * r;
    }
  }
  X.restore();

  X.save();
  X.globalAlpha = 0.08;
  var step = Math.max(1, Math.floor(2 / (R * 0.01)));
  for (var px = cx - R; px <= cx + R; px += step) {
    for (var py = cy - R; py <= cy + R; py += step) {
      var w = toWorld(px, py);
      if (shape.test(w[0], w[1])) {
        X.fillStyle = '#00f5d4';
        X.fillRect(px, py, step, step);
      }
    }
  }
  X.restore();
}

function drawBoundary() {
  X.strokeStyle = 'rgba(0,245,212,0.3)';
  X.lineWidth = 1;
  X.strokeRect(cx - R, cy - R, R * 2, R * 2);

  X.strokeStyle = 'rgba(123,44,191,0.25)';
  X.lineWidth = 0.5;
  X.beginPath();
  X.moveTo(cx, cy - R); X.lineTo(cx, cy + R);
  X.moveTo(cx - R, cy); X.lineTo(cx + R, cy);
  X.stroke();
}

function drawConvergence() {
  if (convergence.length < 2) return;
  var gw = Math.min(260, W * 0.28);
  var gh = 80;
  var gx = W - gw - 16;
  var gy = H - gh - 60;

  X.save();
  X.fillStyle = 'rgba(10,10,15,0.75)';
  X.fillRect(gx - 8, gy - 18, gw + 16, gh + 30);
  X.strokeStyle = 'rgba(255,255,255,0.1)';
  X.lineWidth = 1;
  X.strokeRect(gx - 8, gy - 18, gw + 16, gh + 30);

  X.font = '9px "JetBrains Mono", monospace';
  X.fillStyle = 'rgba(255,255,255,0.35)';
  X.fillText('CONVERGENCE', gx, gy - 6);

  var trueVal = shapes[shapeIdx].trueArea * 4;
  var maxN = convergence[convergence.length - 1].n;
  var minE = trueVal - 1.5, maxE = trueVal + 1.5;

  X.strokeStyle = 'rgba(123,44,191,0.5)';
  X.lineWidth = 1;
  X.setLineDash([4, 4]);
  var trueY = gy + gh - ((trueVal - minE) / (maxE - minE)) * gh;
  X.beginPath();
  X.moveTo(gx, trueY);
  X.lineTo(gx + gw, trueY);
  X.stroke();
  X.setLineDash([]);

  X.strokeStyle = '#00f5d4';
  X.lineWidth = 1.5;
  X.beginPath();
  for (var i = 0; i < convergence.length; i++) {
    var px = gx + (convergence[i].n / maxN) * gw;
    var py = gy + gh - ((convergence[i].est - minE) / (maxE - minE)) * gh;
    py = Math.max(gy, Math.min(gy + gh, py));
    if (i === 0) X.moveTo(px, py);
    else X.lineTo(px, py);
  }
  X.stroke();
  X.restore();
}

function draw() {
  X.clearRect(0, 0, W, H);

  drawShape(shapes[shapeIdx]);
  drawBoundary();

  var now = Date.now();
  for (var i = 0; i < samples.length; i++) {
    var s = samples[i];
    var sc = toScreen(s.x, s.y);
    var age = now - s.t;
    var alpha = age < 300 ? 0.3 + 0.7 * (age / 300) : 1.0;
    var sz = age < 200 ? dotSize * (1 + 2 * (1 - age / 200)) : dotSize;

    if (s.inside) {
      X.fillStyle = 'rgba(0,245,212,' + (0.7 * alpha) + ')';
    } else {
      X.fillStyle = 'rgba(123,44,191,' + (0.55 * alpha) + ')';
    }
    X.beginPath();
    X.arc(sc[0], sc[1], sz, 0, Math.PI * 2);
    X.fill();
  }

  drawConvergence();

  var hSamples = document.getElementById('h-samples');
  var hEst = document.getElementById('h-estimate');
  var hTrue = document.getElementById('h-true');
  var hError = document.getElementById('h-error');
  var hShape = document.getElementById('h-shape');

  hShape.textContent = shapes[shapeIdx].name;
  hSamples.textContent = nTotal.toLocaleString();

  if (nTotal > 0) {
    var ratio = nIn / nTotal;
    var areaEst = ratio * 4;
    var trueVal = shapes[shapeIdx].trueArea * 4;
    var err = Math.abs(areaEst - trueVal) / trueVal * 100;
    hEst.textContent = areaEst.toFixed(6);
    hTrue.textContent = trueVal.toFixed(6);
    hError.textContent = err.toFixed(3) + '%';
  } else {
    hEst.textContent = '—';
    hTrue.textContent = '—';
    hError.textContent = '—';
  }
}

var lastAuto = 0;
function loop(ts) {
  if (autoMode) {
    var elapsed = ts - lastAuto;
    if (elapsed > 16) {
      dropRandom(dropSpeed);
      lastAuto = ts;
      if (nTotal % 200 < dropSpeed) {
        if (typeof SFX !== 'undefined') SFX.play('tick', 80);
      }
    }
  }
  draw();
  requestAnimationFrame(loop);
}

dropRandom(50);
requestAnimationFrame(loop);

C.addEventListener('click', function(e) {
  if (e.button === 2) return;
  var rect = C.getBoundingClientRect();
  var sx = e.clientX - rect.left;
  var sy = e.clientY - rect.top;
  var w = toWorld(sx, sy);
  if (w[0] >= -1 && w[0] <= 1 && w[1] >= -1 && w[1] <= 1) {
    addSample(w[0], w[1]);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  } else {
    dropRandom(10);
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  resetSamples();
});

C.addEventListener('mousedown', function(e) {
  if (e.button === 0) dragging = true;
});
window.addEventListener('mouseup', function() { dragging = false; });
C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  dropRandom(Math.max(1, Math.floor(dropSpeed / 4)));
  if (typeof SFX !== 'undefined') SFX.play('tick', 60);
});

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  dragging = true;
  var t = e.touches[0];
  var rect = C.getBoundingClientRect();
  var w = toWorld(t.clientX - rect.left, t.clientY - rect.top);
  if (w[0] >= -1 && w[0] <= 1 && w[1] >= -1 && w[1] <= 1) {
    addSample(w[0], w[1]);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragging) return;
  dropRandom(Math.max(1, Math.floor(dropSpeed / 4)));
  if (typeof SFX !== 'undefined') SFX.play('tick', 80);
}, { passive: false });

C.addEventListener('touchend', function() { dragging = false; });

var shapeBtns = document.querySelectorAll('#shape-btns .control-btn');
shapeBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    shapeBtns.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    shapeIdx = parseInt(btn.dataset.s);
    resetSamples();
    dropRandom(50);
  });
});

var slSpeed = document.getElementById('sl-speed');
var svSpeed = document.getElementById('sv-speed');
slSpeed.addEventListener('input', function() {
  dropSpeed = parseInt(slSpeed.value);
  svSpeed.textContent = dropSpeed;
});

var slDot = document.getElementById('sl-dot');
var svDot = document.getElementById('sv-dot');
slDot.addEventListener('input', function() {
  dotSize = parseInt(slDot.value);
  svDot.textContent = dotSize;
});

var btnAuto = document.getElementById('btn-auto');
btnAuto.addEventListener('click', function() {
  autoMode = !autoMode;
  btnAuto.classList.toggle('active', autoMode);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

var btnBurst = document.getElementById('btn-burst');
btnBurst.addEventListener('click', function() {
  dropRandom(1000);
  if (typeof SFX !== 'undefined') SFX.play('grow');
});

var btnReset = document.getElementById('btn-reset');
btnReset.addEventListener('click', function() {
  resetSamples();
  dropRandom(50);
});

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
