<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('swarmalator');

$pageName = 'swarmalator';
$pageTitle = 'SWARMALATOR - PULSE';
$pageDesc = 'スウォーマレーター。空間を泳ぎ位相を同期する粒子群。結合パラメータの変化で5つの集団パターンが相転移する。';
$pageCanonical = 'https://pulse.subara3.com/swarmalator.php';

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
  N <span class="v" id="h-n">150</span><br>
  ORDER <span class="v" id="h-order">0.00</span>
</div>

<div id="info">
  <span class="v">Swarmalator</span><br>
  sync + swarm coupling
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'drag', 'label' => 'attract'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">J</span>
      <input type="range" id="sl-j" min="-100" max="100" value="10" step="1">
      <span class="slider-value" id="sv-j">0.10</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">K</span>
      <input type="range" id="sl-k" min="-100" max="100" value="-75" step="1">
      <span class="slider-value" id="sv-k">-0.75</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">N</span>
      <input type="range" id="sl-n" min="30" max="250" value="150" step="10">
      <span class="slider-value" id="sv-n">150</span>
    </div>
  </div>
  <div>
    <div class="btn-row" style="flex-wrap:wrap">
      <button class="control-btn" data-p="sync">SYNC</button>
      <button class="control-btn" data-p="async">ASYNC</button>
      <button class="control-btn active" data-p="phasewave">PHASE</button>
      <button class="control-btn" data-p="splinter">SPLINTER</button>
      <button class="control-btn" data-p="active">ACTIVE</button>
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
var W, H, cx, cy, sc;
var TWO_PI = Math.PI * 2;

var J = 0.1, K = -0.75;
var N = 150;
var dt = 0.03, soft2 = 0.04;
var viewScale = 3.0;

var px, py, pth, pw;
var cosT, sinT, dpx, dpy, dpth;

var dragging = false, dragSX = 0, dragSY = 0;
var clickSX = 0, clickSY = 0, didDrag = false;

var presets = {
  sync:      {j:  1.0, k:  1.0},
  async:     {j: -0.5, k:  0.0},
  phasewave: {j:  0.1, k: -0.75},
  splinter:  {j:  0.1, k: -0.3},
  active:    {j:  1.0, k: -1.0}
};

var hN = document.getElementById('h-n');
var hOrd = document.getElementById('h-order');
var slJ = document.getElementById('sl-j');
var slK = document.getElementById('sl-k');
var slN = document.getElementById('sl-n');
var svJ = document.getElementById('sv-j');
var svK = document.getElementById('sv-k');
var svN = document.getElementById('sv-n');

var LUT = 121;
var glowLUT = new Array(LUT);
var coreLUT = new Array(LUT);

function hsl2rgb(h, s, l) {
  s /= 100; l /= 100;
  var a = s * Math.min(l, 1 - l);
  var f = function(n) {
    var k = (n + h / 30) % 12;
    return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1));
  };
  return [f(0) * 255 | 0, f(8) * 255 | 0, f(4) * 255 | 0];
}

function buildLUT() {
  for (var i = 0; i < LUT; i++) {
    var c = hsl2rgb(170 + i, 75, 52);
    glowLUT[i] = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.07)';
    coreLUT[i] = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.88)';
  }
}

function cidx(theta) {
  var i = (theta / TWO_PI * LUT) | 0;
  return i < 0 ? 0 : i >= LUT ? LUT - 1 : i;
}

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
  cx = W / 2;
  cy = H / 2;
  sc = Math.min(W, H) / (viewScale * 2);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

function initParticles() {
  px = new Float64Array(N);
  py = new Float64Array(N);
  pth = new Float64Array(N);
  pw = new Float64Array(N);
  cosT = new Float64Array(N);
  sinT = new Float64Array(N);
  dpx = new Float64Array(N);
  dpy = new Float64Array(N);
  dpth = new Float64Array(N);

  for (var i = 0; i < N; i++) {
    var a = Math.random() * TWO_PI;
    var r = 0.3 + Math.random() * 1.5;
    px[i] = r * Math.cos(a);
    py[i] = r * Math.sin(a);
    pth[i] = Math.random() * TWO_PI;
    pw[i] = (Math.random() - 0.5) * 0.02;
  }

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

function step() {
  var i, j;
  for (i = 0; i < N; i++) {
    cosT[i] = Math.cos(pth[i]);
    sinT[i] = Math.sin(pth[i]);
  }

  for (i = 0; i < N; i++) {
    var fx = 0, fy = 0, ft = 0;
    var xi = px[i], yi = py[i];
    var ci = cosT[i], si = sinT[i];

    for (j = 0; j < N; j++) {
      if (i === j) continue;
      var dx = px[j] - xi;
      var dy = py[j] - yi;
      var d = Math.sqrt(dx * dx + dy * dy + soft2);
      var invD = 1 / d;
      var ux = dx * invD, uy = dy * invD;

      var cosDT = cosT[j] * ci + sinT[j] * si;
      var sinDT = sinT[j] * ci - cosT[j] * si;

      var fNet = (1 + J * cosDT) - invD;
      fx += ux * fNet;
      fy += uy * fNet;
      ft += sinDT * invD;
    }

    dpx[i] = fx / N;
    dpy[i] = fy / N;
    dpth[i] = pw[i] + K * ft / N;
  }

  if (dragging) {
    var mx = (dragSX - cx) / sc;
    var my = (dragSY - cy) / sc;
    for (i = 0; i < N; i++) {
      var adx = mx - px[i];
      var ady = my - py[i];
      var ad = Math.sqrt(adx * adx + ady * ady + 0.04);
      var af = 0.4 / (ad * ad);
      dpx[i] += adx * af;
      dpy[i] += ady * af;
    }
  }

  for (i = 0; i < N; i++) {
    px[i] += dpx[i] * dt;
    py[i] += dpy[i] * dt;
    pth[i] += dpth[i] * dt;
    pth[i] = ((pth[i] % TWO_PI) + TWO_PI) % TWO_PI;
  }
}

function computeOrder() {
  var sx = 0, sy = 0;
  for (var i = 0; i < N; i++) {
    sx += cosT[i];
    sy += sinT[i];
  }
  return Math.sqrt(sx * sx + sy * sy) / N;
}

function autoScale() {
  var maxR2 = 0;
  for (var i = 0; i < N; i++) {
    var r2 = px[i] * px[i] + py[i] * py[i];
    if (r2 > maxR2) maxR2 = r2;
  }
  var target = Math.max(Math.sqrt(maxR2) * 1.4, 1.5);
  viewScale += (target - viewScale) * 0.01;
  sc = Math.min(W, H) / (viewScale * 2);
}

function render() {
  ctx.globalCompositeOperation = 'source-over';
  ctx.fillStyle = 'rgba(10,10,15,0.13)';
  ctx.fillRect(0, 0, W, H);

  ctx.globalCompositeOperation = 'lighter';
  for (var i = 0; i < N; i++) {
    var sx = cx + px[i] * sc;
    var sy = cy + py[i] * sc;
    ctx.beginPath();
    ctx.arc(sx, sy, 7, 0, TWO_PI);
    ctx.fillStyle = glowLUT[cidx(pth[i])];
    ctx.fill();
  }

  ctx.globalCompositeOperation = 'source-over';
  for (var i = 0; i < N; i++) {
    var sx = cx + px[i] * sc;
    var sy = cy + py[i] * sc;
    ctx.beginPath();
    ctx.arc(sx, sy, 2.5, 0, TWO_PI);
    ctx.fillStyle = coreLUT[cidx(pth[i])];
    ctx.fill();
  }
}

function updateSliders() {
  slJ.value = Math.round(J * 100);
  slK.value = Math.round(K * 100);
  slN.value = N;
  svJ.textContent = J.toFixed(2);
  svK.textContent = K.toFixed(2);
  svN.textContent = N;
}

function clearPresets() {
  var btns = document.querySelectorAll('[data-p]');
  for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
}

function setPreset(name) {
  var p = presets[name];
  if (!p) return;
  J = p.j; K = p.k;
  updateSliders();
  initParticles();
  clearPresets();
  var btn = document.querySelector('[data-p="' + name + '"]');
  if (btn) btn.classList.add('active');
  if (typeof SFX !== 'undefined') SFX.play('toggle');
}

slJ.addEventListener('input', function() {
  J = parseInt(this.value) / 100;
  svJ.textContent = J.toFixed(2);
  clearPresets();
});
slK.addEventListener('input', function() {
  K = parseInt(this.value) / 100;
  svK.textContent = K.toFixed(2);
  clearPresets();
});
slN.addEventListener('input', function() {
  var v = parseInt(this.value);
  svN.textContent = v;
  if (v !== N) {
    N = v;
    initParticles();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }
});

var pBtns = document.querySelectorAll('[data-p]');
for (var b = 0; b < pBtns.length; b++) {
  pBtns[b].addEventListener('click', (function(btn) {
    return function() { setPreset(btn.dataset.p); };
  })(pBtns[b]));
}

document.getElementById('btn-reset').addEventListener('click', function() {
  initParticles();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

function getPos(e) {
  if (e.touches && e.touches.length) return {x: e.touches[0].clientX, y: e.touches[0].clientY};
  return {x: e.clientX, y: e.clientY};
}

C.addEventListener('mousedown', function(e) {
  var p = getPos(e);
  dragging = true; didDrag = false;
  dragSX = clickSX = p.x;
  dragSY = clickSY = p.y;
});
C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  var p = getPos(e);
  dragSX = p.x; dragSY = p.y;
  var dx = dragSX - clickSX, dy = dragSY - clickSY;
  if (dx * dx + dy * dy > 25) didDrag = true;
  if (didDrag && typeof SFX !== 'undefined') SFX.play('move', 120);
});
C.addEventListener('mouseup', function() {
  if (!didDrag && dragging) {
    perturb(clickSX, clickSY);
  }
  dragging = false;
});
C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var p = getPos(e);
  dragging = true; didDrag = false;
  dragSX = clickSX = p.x;
  dragSY = clickSY = p.y;
}, {passive: false});
C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragging) return;
  var p = getPos(e);
  dragSX = p.x; dragSY = p.y;
  var dx = dragSX - clickSX, dy = dragSY - clickSY;
  if (dx * dx + dy * dy > 25) didDrag = true;
  if (didDrag && typeof SFX !== 'undefined') SFX.play('move', 120);
}, {passive: false});
C.addEventListener('touchend', function() {
  if (!didDrag && dragging) {
    perturb(clickSX, clickSY);
  }
  dragging = false;
});
C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

function perturb(sx, sy) {
  var mx = (sx - cx) / sc;
  var my = (sy - cy) / sc;
  for (var i = 0; i < N; i++) {
    var dx = px[i] - mx;
    var dy = py[i] - my;
    var d = Math.sqrt(dx * dx + dy * dy) + 0.1;
    if (d < 2.5) {
      var push = (2.5 - d) * 0.25;
      px[i] += dx / d * push;
      py[i] += dy / d * push;
      pth[i] += (Math.random() - 0.5) * 0.8;
      pth[i] = ((pth[i] % TWO_PI) + TWO_PI) % TWO_PI;
    }
  }
  if (typeof SFX !== 'undefined') SFX.play('ripple');
}

var fc = 0;
function animate() {
  var sPerFrame = N > 200 ? 2 : 3;
  for (var s = 0; s < sPerFrame; s++) step();
  autoScale();
  render();

  if (++fc % 8 === 0) {
    hN.textContent = N;
    hOrd.textContent = computeOrder().toFixed(2);
  }

  requestAnimationFrame(animate);
}

window.addEventListener('resize', resize);
buildLUT();
resize();
initParticles();
updateSliders();
requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
