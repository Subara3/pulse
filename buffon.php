<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('buffon');

$pageName = 'buffon';
$pageTitle = 'BUFFON - PULSE';
$pageDesc = "ビュフォンの針。平行線の上に針を落とし、交差確率からπを推定する幾何学的確率の古典実験。";
$pageCanonical = 'https://pulse.subara3.com/buffon.php';

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
  NEEDLES <b id="h-total">0</b><br>
  CROSSING <b id="h-cross">0</b><br>
  π ESTIMATE <b id="h-pi">—</b><br>
  <span class="sub">TRUE π</span> <b id="h-true">3.141593</b><br>
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
  <div><div class="slider-row"><span class="slider-label">NEEDLE LENGTH</span><input type="range" id="sl-len" min="20" max="100" value="50" step="1"><span class="slider-value" id="sv-len">1.00</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SPEED</span><input type="range" id="sl-speed" min="1" max="100" value="10" step="1"><span class="slider-value" id="sv-speed">10</span></div></div>
  <div><div class="slider-row"><span class="slider-label">LINE GAP</span><input type="range" id="sl-gap" min="30" max="120" value="60" step="1"><span class="slider-value" id="sv-gap">60</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-burst">BURST ×100</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H;
var pr = 1;

var needles = [];
var nTotal = 0, nCross = 0;
var autoMode = false, dropSpeed = 10;
var dragging = false;
var lineGap = 60;
var needleLenRatio = 1.0;
var convergence = [];
var maxConv = 400;
var maxNeedles = 8000;

function resize() {
  pr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth; H = window.innerHeight;
  C.width = W * pr; C.height = H * pr;
  C.style.width = W + 'px'; C.style.height = H + 'px';
  X.setTransform(pr, 0, 0, pr, 0, 0);
}
window.addEventListener('resize', resize);
resize();

function needleLen() {
  return lineGap * needleLenRatio;
}

function doesCross(cy, halfLen, angle) {
  var dy = halfLen * Math.cos(angle);
  var y1 = cy - dy;
  var y2 = cy + dy;
  var top = Math.min(y1, y2);
  var bot = Math.max(y1, y2);
  var firstLine = Math.ceil(top / lineGap) * lineGap;
  return firstLine <= bot;
}

function dropNeedle(cx, cy) {
  var angle = Math.random() * Math.PI;
  var halfLen = needleLen() * 0.5;
  var cross = doesCross(cy, halfLen, angle);
  nTotal++;
  if (cross) nCross++;

  needles.push({
    cx: cx, cy: cy, angle: angle,
    halfLen: halfLen, cross: cross, t: Date.now()
  });
  if (needles.length > maxNeedles) {
    needles.splice(0, needles.length - maxNeedles);
  }

  var step = nTotal < 100 ? 1 : nTotal < 1000 ? 5 : 50;
  if (nTotal % step === 0 || nTotal <= 50) {
    if (nCross > 0) {
      convergence.push({ n: nTotal, est: (2 * needleLenRatio * nTotal) / (nCross) });
      if (convergence.length > maxConv) convergence.shift();
    }
  }
}

function dropRandom(count) {
  for (var i = 0; i < count; i++) {
    var cx = Math.random() * W;
    var cy = Math.random() * H;
    dropNeedle(cx, cy);
  }
}

function resetAll() {
  needles = [];
  nTotal = 0;
  nCross = 0;
  convergence = [];
  SFX.play('click');
}

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function drawLines() {
  X.strokeStyle = 'rgba(0,245,212,0.12)';
  X.lineWidth = 1;
  for (var y = 0; y <= H + lineGap; y += lineGap) {
    X.beginPath();
    X.moveTo(0, y);
    X.lineTo(W, y);
    X.stroke();
  }
}

function drawNeedles() {
  var now = Date.now();
  for (var i = 0; i < needles.length; i++) {
    var n = needles[i];
    var age = now - n.t;
    var alpha = age < 300 ? 0.3 + 0.7 * (age / 300) : 1.0;

    var dx = n.halfLen * Math.sin(n.angle);
    var dy = n.halfLen * Math.cos(n.angle);
    var x1 = n.cx - dx, y1 = n.cy - dy;
    var x2 = n.cx + dx, y2 = n.cy + dy;

    if (n.cross) {
      X.strokeStyle = 'rgba(0,245,212,' + (0.75 * alpha) + ')';
      X.lineWidth = 1.8;
    } else {
      X.strokeStyle = 'rgba(123,44,191,' + (0.6 * alpha) + ')';
      X.lineWidth = 1.2;
    }
    X.beginPath();
    X.moveTo(x1, y1);
    X.lineTo(x2, y2);
    X.stroke();

    if (age < 400) {
      var glow = (1 - age / 400) * 0.4;
      X.strokeStyle = n.cross
        ? 'rgba(0,245,212,' + glow + ')'
        : 'rgba(123,44,191,' + glow + ')';
      X.lineWidth = 4;
      X.beginPath();
      X.moveTo(x1, y1);
      X.lineTo(x2, y2);
      X.stroke();
    }
  }
}

function drawConvergence() {
  if (convergence.length < 2) return;
  var gw = Math.min(260, W * 0.28);
  var gh = 80;
  var gx = W - gw - 16;
  var gy = H - gh - 60;

  X.save();
  X.fillStyle = 'rgba(10,10,15,0.8)';
  X.fillRect(gx - 8, gy - 18, gw + 16, gh + 30);
  X.strokeStyle = 'rgba(255,255,255,0.08)';
  X.lineWidth = 1;
  X.strokeRect(gx - 8, gy - 18, gw + 16, gh + 30);

  X.font = '9px "JetBrains Mono", monospace';
  X.fillStyle = 'rgba(255,255,255,0.35)';
  X.fillText('CONVERGENCE TO π', gx, gy - 6);

  var minV = Math.PI - 1.5, maxV = Math.PI + 1.5;

  X.strokeStyle = 'rgba(123,44,191,0.5)';
  X.lineWidth = 1;
  X.setLineDash([4, 4]);
  var trueY = gy + gh - ((Math.PI - minV) / (maxV - minV)) * gh;
  X.beginPath();
  X.moveTo(gx, trueY);
  X.lineTo(gx + gw, trueY);
  X.stroke();
  X.setLineDash([]);

  var maxN = convergence[convergence.length - 1].n;
  X.strokeStyle = '#00f5d4';
  X.lineWidth = 1.5;
  X.beginPath();
  for (var i = 0; i < convergence.length; i++) {
    var px = gx + (convergence[i].n / maxN) * gw;
    var py = gy + gh - ((convergence[i].est - minV) / (maxV - minV)) * gh;
    py = Math.max(gy, Math.min(gy + gh, py));
    if (i === 0) X.moveTo(px, py);
    else X.lineTo(px, py);
  }
  X.stroke();
  X.restore();
}

function drawNeedleDiagram() {
  var dw = Math.min(140, W * 0.16);
  var dh = dw;
  var dx = W - dw - 16;
  var dy = 70;

  X.save();
  X.fillStyle = 'rgba(10,10,15,0.8)';
  X.fillRect(dx - 8, dy - 16, dw + 16, dh + 24);
  X.strokeStyle = 'rgba(255,255,255,0.08)';
  X.lineWidth = 1;
  X.strokeRect(dx - 8, dy - 16, dw + 16, dh + 24);

  X.font = '9px "JetBrains Mono", monospace';
  X.fillStyle = 'rgba(255,255,255,0.35)';
  X.fillText('P(cross) = 2L / πD', dx, dy - 4);

  var cx = dx + dw * 0.5;
  var cy = dy + dh * 0.5;
  var lineSpacing = dw * 0.6;

  X.strokeStyle = 'rgba(0,245,212,0.2)';
  X.lineWidth = 1;
  X.beginPath();
  X.moveTo(dx, cy - lineSpacing * 0.5);
  X.lineTo(dx + dw, cy - lineSpacing * 0.5);
  X.stroke();
  X.beginPath();
  X.moveTo(dx, cy + lineSpacing * 0.5);
  X.lineTo(dx + dw, cy + lineSpacing * 0.5);
  X.stroke();

  var t = Date.now() * 0.001;
  var demoAngle = Math.sin(t * 0.7) * Math.PI * 0.4 + Math.PI * 0.5;
  var demoLen = lineSpacing * needleLenRatio * 0.45;
  var ddx = demoLen * Math.sin(demoAngle);
  var ddy = demoLen * Math.cos(demoAngle);

  var demoCross = Math.abs(ddy) > lineSpacing * 0.5 * 0.9;
  X.strokeStyle = demoCross ? 'rgba(0,245,212,0.8)' : 'rgba(123,44,191,0.7)';
  X.lineWidth = 2;
  X.beginPath();
  X.moveTo(cx - ddx, cy - ddy);
  X.lineTo(cx + ddx, cy + ddy);
  X.stroke();

  X.fillStyle = 'rgba(255,255,255,0.25)';
  X.font = '8px "JetBrains Mono", monospace';
  X.fillText('L/D = ' + needleLenRatio.toFixed(2), dx + 2, dy + dh + 4);
  X.restore();
}

function updateHUD() {
  document.getElementById('h-total').textContent = nTotal.toLocaleString();
  document.getElementById('h-cross').textContent = nCross.toLocaleString();
  if (nCross > 0) {
    var piEst = (2 * needleLenRatio * nTotal) / nCross;
    var err = Math.abs(piEst - Math.PI) / Math.PI * 100;
    document.getElementById('h-pi').textContent = piEst.toFixed(6);
    document.getElementById('h-error').textContent = err.toFixed(3) + '%';
  } else {
    document.getElementById('h-pi').textContent = '—';
    document.getElementById('h-error').textContent = '—';
  }
}

function draw() {
  X.clearRect(0, 0, W, H);
  drawLines();
  drawNeedles();
  drawConvergence();
  drawNeedleDiagram();
  updateHUD();
}

var lastAuto = 0;
function loop(ts) {
  if (autoMode) {
    var elapsed = ts - lastAuto;
    if (elapsed > 16) {
      dropRandom(dropSpeed);
      lastAuto = ts;
      if (nTotal % 200 < dropSpeed) {
        SFX.play('tick', 80);
      }
    }
  }
  draw();
  requestAnimationFrame(loop);
}

dropRandom(30);
requestAnimationFrame(loop);

C.addEventListener('click', function(e) {
  if (e.button === 2) return;
  var rect = C.getBoundingClientRect();
  var sx = e.clientX - rect.left;
  var sy = e.clientY - rect.top;
  dropNeedle(sx, sy);
  SFX.play('spawn');
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  resetAll();
  dropRandom(30);
});

C.addEventListener('mousedown', function(e) {
  if (e.button === 0) dragging = true;
});
window.addEventListener('mouseup', function() { dragging = false; });
C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  var rect = C.getBoundingClientRect();
  for (var i = 0; i < 3; i++) {
    dropNeedle(
      e.clientX - rect.left + (Math.random() - 0.5) * 40,
      e.clientY - rect.top + (Math.random() - 0.5) * 40
    );
  }
  SFX.play('tick', 60);
});

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  dragging = true;
  var t = e.touches[0];
  var rect = C.getBoundingClientRect();
  dropNeedle(t.clientX - rect.left, t.clientY - rect.top);
  SFX.play('spawn');
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragging) return;
  var t = e.touches[0];
  var rect = C.getBoundingClientRect();
  for (var i = 0; i < 2; i++) {
    dropNeedle(
      t.clientX - rect.left + (Math.random() - 0.5) * 30,
      t.clientY - rect.top + (Math.random() - 0.5) * 30
    );
  }
  SFX.play('tick', 80);
}, { passive: false });

C.addEventListener('touchend', function() { dragging = false; });

var slLen = document.getElementById('sl-len');
var svLen = document.getElementById('sv-len');
slLen.addEventListener('input', function() {
  needleLenRatio = parseInt(slLen.value) / 50;
  svLen.textContent = needleLenRatio.toFixed(2);
  resetAll();
  dropRandom(30);
});

var slSpeed = document.getElementById('sl-speed');
var svSpeed = document.getElementById('sv-speed');
slSpeed.addEventListener('input', function() {
  dropSpeed = parseInt(slSpeed.value);
  svSpeed.textContent = dropSpeed;
});

var slGap = document.getElementById('sl-gap');
var svGap = document.getElementById('sv-gap');
slGap.addEventListener('input', function() {
  lineGap = parseInt(slGap.value);
  svGap.textContent = lineGap;
  resetAll();
  dropRandom(30);
});

document.getElementById('btn-auto').addEventListener('click', function() {
  autoMode = !autoMode;
  this.classList.toggle('active', autoMode);
  SFX.play('toggle');
});

document.getElementById('btn-burst').addEventListener('click', function() {
  dropRandom(100);
  SFX.play('grow');
});

document.getElementById('btn-reset').addEventListener('click', function() {
  resetAll();
  dropRandom(30);
});

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
