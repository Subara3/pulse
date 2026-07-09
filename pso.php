<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pso');

$pageName = 'pso';
$pageTitle = 'PSO - PULSE';
$pageDesc = '粒子群最適化。群れが適応度の地形を探索し、個体の記憶と集団の知識が最適解へ導く。';
$pageCanonical = 'https://pulse.subara3.com/pso.php';

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
  LANDSCAPE <b id="h-mode">PEAKS</b><br>
  BEST <b id="h-best">&mdash;</b><br>
  GEN <b id="h-gen">0</b><br>
  <span class="sub">SWARM</span> <b id="h-swarm">30</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add peak'],
  ['type' => 'drag', 'label' => 'draw peaks'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div><div class="btn-row" id="mode-btns">
    <button class="control-btn active" data-m="0">PEAKS</button>
    <button class="control-btn" data-m="1">RASTRIGIN</button>
    <button class="control-btn" data-m="2">ACKLEY</button>
    <button class="control-btn" data-m="3">SCHWEFEL</button>
  </div></div>
  <div><div class="slider-row"><span class="slider-label">INERTIA</span><input type="range" id="sl-w" min="10" max="99" value="72" step="1"><span class="slider-value" id="sv-w">0.72</span></div></div>
  <div><div class="slider-row"><span class="slider-label">COGNITIVE</span><input type="range" id="sl-c1" min="0" max="40" value="15" step="1"><span class="slider-value" id="sv-c1">1.5</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SOCIAL</span><input type="range" id="sl-c2" min="0" max="40" value="15" step="1"><span class="slider-value" id="sv-c2">1.5</span></div></div>
  <div><div class="slider-row"><span class="slider-label">SWARM</span><input type="range" id="sl-n" min="5" max="80" value="30" step="1"><span class="slider-value" id="sv-n">30</span></div></div>
  <div><div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
var C = document.getElementById('canvas'), X = C.getContext('2d');
var W, H;
var omega = 0.72, c1 = 1.5, c2 = 1.5;
var nPart = 30, maxVel = 0.008;
var mode = 0;
var particles = [], gbest = null, gbestVal = -Infinity;
var iteration = 0;
var peaks = [];
var trailLen = 50;
var bestHistory = [];
var maxHist = 300;

var lC, lX, lRes = 3;
var landscapeDirty = true;
var lastLandscapeRender = 0;
var flashTime = 0, flashX = 0, flashY = 0;
var dragging = false;

var modeNames = ['PEAKS', 'RASTRIGIN', 'ACKLEY', 'SCHWEFEL'];

function resize() {
  var pr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth; H = window.innerHeight;
  C.width = W * pr; C.height = H * pr;
  C.style.width = W + 'px'; C.style.height = H + 'px';
  X.setTransform(pr, 0, 0, pr, 0, 0);
  landscapeDirty = true;
}
window.addEventListener('resize', resize);
resize();

function toSx(x) { return x * W; }
function toSy(y) { return y * H; }
function fromSx(sx) { return sx / W; }
function fromSy(sy) { return sy / H; }

function fitness(x, y) {
  switch (mode) {
    case 0: return fitPeaks(x, y);
    case 1: return fitRastrigin(x, y);
    case 2: return fitAckley(x, y);
    case 3: return fitSchwefel(x, y);
  }
  return 0;
}

function fitPeaks(x, y) {
  var sum = 0;
  for (var i = 0; i < peaks.length; i++) {
    var p = peaks[i];
    var dx = x - p.x, dy = y - p.y;
    sum += p.h * Math.exp(-(dx * dx + dy * dy) / (2 * p.s * p.s));
  }
  return sum;
}

function fitRastrigin(x, y) {
  var xx = (x - 0.5) * 10.24, yy = (y - 0.5) * 10.24;
  return -(20 + xx * xx + yy * yy - 10 * (Math.cos(6.2832 * xx) + Math.cos(6.2832 * yy))) / 80;
}

function fitAckley(x, y) {
  var xx = (x - 0.5) * 10, yy = (y - 0.5) * 10;
  var a = -20 * Math.exp(-0.2 * Math.sqrt(0.5 * (xx * xx + yy * yy)));
  var b = -Math.exp(0.5 * (Math.cos(6.2832 * xx) + Math.cos(6.2832 * yy)));
  return -(a + b + Math.E + 20) / 23;
}

function fitSchwefel(x, y) {
  var xx = (x - 0.5) * 1000, yy = (y - 0.5) * 1000;
  var f = 838 - (xx * Math.sin(Math.sqrt(Math.abs(xx))) + yy * Math.sin(Math.sqrt(Math.abs(yy))));
  return -f / 1676;
}

function lerpColor(t) {
  var stops = [
    [0.0, 10, 10, 18],
    [0.2, 25, 12, 48],
    [0.45, 45, 20, 78],
    [0.65, 15, 65, 80],
    [0.85, 0, 140, 120],
    [1.0, 0, 210, 180]
  ];
  var i = 0;
  while (i < stops.length - 2 && stops[i + 1][0] < t) i++;
  var a = stops[i], b = stops[i + 1];
  var u = (t - a[0]) / (b[0] - a[0]);
  u = Math.max(0, Math.min(1, u));
  return [
    a[1] + u * (b[1] - a[1]),
    a[2] + u * (b[2] - a[2]),
    a[3] + u * (b[3] - a[3])
  ];
}

function renderLandscape() {
  var w = Math.ceil(W / lRes), h = Math.ceil(H / lRes);
  if (!lC || lC.width !== w || lC.height !== h) {
    lC = document.createElement('canvas');
    lC.width = w; lC.height = h;
    lX = lC.getContext('2d');
  }
  var img = lX.createImageData(w, h);
  var data = img.data;
  var vals = new Float32Array(w * h);
  var fMin = Infinity, fMax = -Infinity;

  for (var j = 0; j < h; j++) {
    for (var i = 0; i < w; i++) {
      var f = fitness((i + 0.5) / w, (j + 0.5) / h);
      vals[j * w + i] = f;
      if (f < fMin) fMin = f;
      if (f > fMax) fMax = f;
    }
  }

  var range = fMax - fMin || 1;
  for (var j = 0; j < h; j++) {
    for (var i = 0; i < w; i++) {
      var idx = (j * w + i) * 4;
      var t = (vals[j * w + i] - fMin) / range;
      var c = lerpColor(t);

      var nContours = 12;
      var cv = t * nContours;
      var frac = cv - Math.floor(cv);
      var cd = Math.min(frac, 1 - frac);
      var boost = 0;
      if (cd < 0.04) boost = (1 - cd / 0.04) * 22;

      data[idx]     = Math.min(255, (c[0] + boost) | 0);
      data[idx + 1] = Math.min(255, (c[1] + boost) | 0);
      data[idx + 2] = Math.min(255, (c[2] + boost) | 0);
      data[idx + 3] = 255;
    }
  }
  lX.putImageData(img, 0, 0);
}

function initPeaks() {
  peaks = [];
  for (var i = 0; i < 4; i++) {
    peaks.push({
      x: 0.15 + Math.random() * 0.7,
      y: 0.15 + Math.random() * 0.7,
      h: 0.4 + Math.random() * 0.6,
      s: 0.06 + Math.random() * 0.06
    });
  }
}

function initParticles() {
  particles = [];
  gbest = null;
  gbestVal = -Infinity;
  iteration = 0;
  bestHistory = [];

  for (var i = 0; i < nPart; i++) {
    var x = Math.random(), y = Math.random();
    var f = fitness(x, y);
    particles.push({
      x: x, y: y,
      vx: (Math.random() - 0.5) * maxVel * 2,
      vy: (Math.random() - 0.5) * maxVel * 2,
      px: x, py: y, pv: f,
      trail: [{x: x, y: y}]
    });
    if (f > gbestVal) {
      gbestVal = f;
      gbest = {x: x, y: y};
    }
  }
  bestHistory.push({i: 0, v: gbestVal});
}

function step() {
  if (!gbest) return;
  var improved = false;
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    var r1x = Math.random(), r1y = Math.random();
    var r2x = Math.random(), r2y = Math.random();

    p.vx = omega * p.vx + c1 * r1x * (p.px - p.x) + c2 * r2x * (gbest.x - p.x);
    p.vy = omega * p.vy + c1 * r1y * (p.py - p.y) + c2 * r2y * (gbest.y - p.y);

    var spd = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
    if (spd > maxVel) { p.vx *= maxVel / spd; p.vy *= maxVel / spd; }

    p.x += p.vx;
    p.y += p.vy;

    if (p.x < 0) { p.x = -p.x; p.vx = Math.abs(p.vx); }
    if (p.x > 1) { p.x = 2 - p.x; p.vx = -Math.abs(p.vx); }
    if (p.y < 0) { p.y = -p.y; p.vy = Math.abs(p.vy); }
    if (p.y > 1) { p.y = 2 - p.y; p.vy = -Math.abs(p.vy); }
    p.x = Math.max(0, Math.min(1, p.x));
    p.y = Math.max(0, Math.min(1, p.y));

    var f = fitness(p.x, p.y);
    if (f > p.pv) { p.pv = f; p.px = p.x; p.py = p.y; }
    if (f > gbestVal) {
      gbestVal = f;
      gbest.x = p.x; gbest.y = p.y;
      improved = true;
    }

    p.trail.push({x: p.x, y: p.y});
    if (p.trail.length > trailLen) p.trail.shift();
  }

  iteration++;

  if (improved || iteration % 5 === 0) {
    bestHistory.push({i: iteration, v: gbestVal});
    if (bestHistory.length > maxHist) bestHistory.shift();
  }

  if (improved) {
    flashTime = Date.now();
    flashX = gbest.x; flashY = gbest.y;
    if (typeof SFX !== 'undefined') SFX.play('grow', 300);
  }
}

function drawConvergence() {
  if (bestHistory.length < 2) return;
  var gw = Math.min(200, W * 0.22);
  var gh = 55;
  var gx = W - gw - 16;
  var gy = H - gh - 56;

  X.save();
  X.fillStyle = 'rgba(10,10,15,0.75)';
  X.fillRect(gx - 8, gy - 18, gw + 16, gh + 28);
  X.strokeStyle = 'rgba(255,255,255,0.08)';
  X.lineWidth = 1;
  X.strokeRect(gx - 8, gy - 18, gw + 16, gh + 28);

  X.font = '9px "JetBrains Mono", monospace';
  X.fillStyle = 'rgba(255,255,255,0.3)';
  X.fillText('BEST FITNESS', gx, gy - 6);

  var vMin = bestHistory[0].v, vMax = bestHistory[0].v;
  for (var j = 1; j < bestHistory.length; j++) {
    if (bestHistory[j].v < vMin) vMin = bestHistory[j].v;
    if (bestHistory[j].v > vMax) vMax = bestHistory[j].v;
  }
  var vRange = vMax - vMin;
  if (vRange < 0.001) vRange = 0.001;
  var iMin = bestHistory[0].i;
  var iMax = bestHistory[bestHistory.length - 1].i;
  var iRange = iMax - iMin || 1;

  X.strokeStyle = '#00f5d4';
  X.lineWidth = 1.5;
  X.beginPath();
  for (var j = 0; j < bestHistory.length; j++) {
    var px = gx + ((bestHistory[j].i - iMin) / iRange) * gw;
    var py = gy + gh - ((bestHistory[j].v - vMin) / vRange) * gh;
    py = Math.max(gy, Math.min(gy + gh, py));
    if (j === 0) X.moveTo(px, py); else X.lineTo(px, py);
  }
  X.stroke();
  X.restore();
}

function draw() {
  X.clearRect(0, 0, W, H);

  var now = Date.now();
  if (landscapeDirty && now - lastLandscapeRender > 80) {
    renderLandscape();
    landscapeDirty = false;
    lastLandscapeRender = now;
  }
  if (lC) {
    X.imageSmoothingEnabled = true;
    X.drawImage(lC, 0, 0, W, H);
  }

  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    if (p.trail.length < 2) continue;
    X.strokeStyle = 'rgba(0,245,212,0.12)';
    X.lineWidth = 0.8;
    X.beginPath();
    X.moveTo(toSx(p.trail[0].x), toSy(p.trail[0].y));
    for (var j = 1; j < p.trail.length; j++) {
      X.lineTo(toSx(p.trail[j].x), toSy(p.trail[j].y));
    }
    X.stroke();
  }

  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    X.fillStyle = 'rgba(123,44,191,0.3)';
    X.beginPath();
    X.arc(toSx(p.px), toSy(p.py), 2.5, 0, 6.2832);
    X.fill();
  }

  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    var sx = toSx(p.x), sy = toSy(p.y);

    X.fillStyle = 'rgba(0,245,212,0.1)';
    X.beginPath();
    X.arc(sx, sy, 8, 0, 6.2832);
    X.fill();

    X.fillStyle = 'rgba(0,245,212,0.85)';
    X.beginPath();
    X.arc(sx, sy, 3, 0, 6.2832);
    X.fill();

    var vLen = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
    if (vLen > 0.0005) {
      var aLen = Math.min(vLen / maxVel * 20, 22);
      X.strokeStyle = 'rgba(0,245,212,0.3)';
      X.lineWidth = 1;
      X.beginPath();
      X.moveTo(sx, sy);
      X.lineTo(sx + (p.vx / vLen) * aLen, sy + (p.vy / vLen) * aLen);
      X.stroke();
    }
  }

  if (gbest) {
    var gsx = toSx(gbest.x), gsy = toSy(gbest.y);
    var pulse = 1 + 0.25 * Math.sin(now * 0.004);

    X.fillStyle = 'rgba(123,44,191,0.08)';
    X.beginPath();
    X.arc(gsx, gsy, 22 * pulse, 0, 6.2832);
    X.fill();

    X.fillStyle = 'rgba(123,44,191,0.22)';
    X.beginPath();
    X.arc(gsx, gsy, 11 * pulse, 0, 6.2832);
    X.fill();

    X.fillStyle = '#7b2cbf';
    X.beginPath();
    X.arc(gsx, gsy, 5, 0, 6.2832);
    X.fill();

    X.strokeStyle = 'rgba(123,44,191,0.45)';
    X.lineWidth = 1;
    X.beginPath();
    X.moveTo(gsx - 14, gsy); X.lineTo(gsx - 7, gsy);
    X.moveTo(gsx + 7, gsy); X.lineTo(gsx + 14, gsy);
    X.moveTo(gsx, gsy - 14); X.lineTo(gsx, gsy - 7);
    X.moveTo(gsx, gsy + 7); X.lineTo(gsx, gsy + 14);
    X.stroke();
  }

  var flashAge = now - flashTime;
  if (flashAge < 600) {
    var fa = 1 - flashAge / 600;
    var fr = (flashAge / 600) * 35;
    X.strokeStyle = 'rgba(0,245,212,' + (fa * 0.4) + ')';
    X.lineWidth = 1.5;
    X.beginPath();
    X.arc(toSx(flashX), toSy(flashY), fr, 0, 6.2832);
    X.stroke();
  }

  drawConvergence();

  document.getElementById('h-mode').textContent = modeNames[mode];
  document.getElementById('h-best').textContent = gbestVal.toFixed(4);
  document.getElementById('h-gen').textContent = iteration;
  document.getElementById('h-swarm').textContent = particles.length;
}

function loop() {
  step();
  draw();
  requestAnimationFrame(loop);
}

initPeaks();
initParticles();
requestAnimationFrame(loop);

function handleClick(sx, sy) {
  var wx = fromSx(sx), wy = fromSy(sy);
  if (mode === 0) {
    peaks.push({
      x: wx, y: wy,
      h: 0.5 + Math.random() * 0.5,
      s: 0.05 + Math.random() * 0.04
    });
    landscapeDirty = true;
    reevalAll();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  } else {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var dx = p.x - wx, dy = p.y - wy;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < 0.15 && d > 0.001) {
        var force = 0.006 / d;
        p.vx += dx * force;
        p.vy += dy * force;
      }
    }
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }
}

function reevalAll() {
  gbestVal = -Infinity;
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    p.pv = fitness(p.px, p.py);
    var f = fitness(p.x, p.y);
    if (f > p.pv) { p.pv = f; p.px = p.x; p.py = p.y; }
    if (p.pv > gbestVal) {
      gbestVal = p.pv;
      if (gbest) { gbest.x = p.px; gbest.y = p.py; }
      else gbest = {x: p.px, y: p.py};
    }
  }
}

function resetAll() {
  if (mode === 0) initPeaks();
  landscapeDirty = true;
  initParticles();
  if (typeof SFX !== 'undefined') SFX.play('click');
}

C.addEventListener('click', function(e) {
  if (e.button === 2) return;
  var r = C.getBoundingClientRect();
  handleClick(e.clientX - r.left, e.clientY - r.top);
});

C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  resetAll();
});

C.addEventListener('mousedown', function(e) {
  if (e.button === 0) dragging = true;
});
window.addEventListener('mouseup', function() { dragging = false; });
C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  var r = C.getBoundingClientRect();
  var wx = fromSx(e.clientX - r.left), wy = fromSy(e.clientY - r.top);
  if (mode === 0) {
    peaks.push({x: wx, y: wy, h: 0.2, s: 0.04});
    landscapeDirty = true;
    reevalAll();
    if (typeof SFX !== 'undefined') SFX.play('tick', 120);
  } else {
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var dx = p.x - wx, dy = p.y - wy;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < 0.12 && d > 0.001) {
        p.vx += dx / d * 0.004;
        p.vy += dy / d * 0.004;
      }
    }
  }
});

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  dragging = true;
  var t = e.touches[0];
  var r = C.getBoundingClientRect();
  handleClick(t.clientX - r.left, t.clientY - r.top);
}, {passive: false});

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragging) return;
  var t = e.touches[0];
  var r = C.getBoundingClientRect();
  var wx = fromSx(t.clientX - r.left), wy = fromSy(t.clientY - r.top);
  if (mode === 0) {
    peaks.push({x: wx, y: wy, h: 0.2, s: 0.04});
    landscapeDirty = true;
    reevalAll();
    if (typeof SFX !== 'undefined') SFX.play('tick', 150);
  }
}, {passive: false});

C.addEventListener('touchend', function() { dragging = false; });

var modeBtns = document.querySelectorAll('#mode-btns .control-btn');
modeBtns.forEach(function(btn) {
  btn.addEventListener('click', function() {
    modeBtns.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    mode = parseInt(btn.dataset.m);
    if (mode === 0) initPeaks();
    landscapeDirty = true;
    initParticles();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

var slW = document.getElementById('sl-w'), svW = document.getElementById('sv-w');
slW.addEventListener('input', function() {
  omega = parseInt(slW.value) / 100;
  svW.textContent = omega.toFixed(2);
});

var slC1 = document.getElementById('sl-c1'), svC1 = document.getElementById('sv-c1');
slC1.addEventListener('input', function() {
  c1 = parseInt(slC1.value) / 10;
  svC1.textContent = c1.toFixed(1);
});

var slC2 = document.getElementById('sl-c2'), svC2 = document.getElementById('sv-c2');
slC2.addEventListener('input', function() {
  c2 = parseInt(slC2.value) / 10;
  svC2.textContent = c2.toFixed(1);
});

var slN = document.getElementById('sl-n'), svN = document.getElementById('sv-n');
slN.addEventListener('input', function() {
  nPart = parseInt(slN.value);
  svN.textContent = nPart;
  initParticles();
});

document.getElementById('btn-reset').addEventListener('click', resetAll);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
