<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('plume');

$pageName = 'plume';
$pageTitle = 'PLUME - PULSE';
$pageDesc = '重い液が軽い液の上にあると、境目が指のように垂れ下がり、きのこの形をした渦になって沈んでいく。';
$pageCanonical = 'https://pulse.subara3.com/plume.php';

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
  ATWOOD <span class="v" id="hud-at">0.50</span><br>
  MODE <span class="v" id="hud-mode">MULTI</span>
</div>

<div id="info">
  <span class="v">Rayleigh-Taylor</span><br>
  buoyancy instability &middot; mushroom plumes
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'perturb'],
  ['type' => 'drag', 'label' => 'push'],
  ['type' => 'right', 'label' => 'inject'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">ATWOOD</span>
      <input type="range" id="sl-at" min="10" max="90" value="50">
      <span class="slider-value" id="sv-at">0.50</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">GRAVITY</span>
      <input type="range" id="sl-grav" min="1" max="20" value="10">
      <span class="slider-value" id="sv-grav">10</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
    <button class="control-btn active" id="btn-run">RUN</button>
  </div>
  <div class="preset-group">
    <button class="preset-btn" data-mode="0">SINGLE</button>
    <button class="preset-btn active" data-mode="1">MULTI</button>
    <button class="preset-btn" data-mode="2">RANDOM</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
'use strict';

var c = document.getElementById('canvas');
var ctx = c.getContext('2d');
var W, H, dpr;

var gw, gh, N;
var den, den0, vx, vy, vx0, vy0, prs, dvg;
var offCvs, offCtx, img;

var atwood = 0.5;
var grav = 10;
var mode = 1;
var running = true;

var TR = 0, TG = 245, TB = 212;
var PR = 123, PG = 44, PB = 191;
var BR = 10, BG = 10, BB = 15;

var hudAt = document.getElementById('hud-at');
var hudMode = document.getElementById('hud-mode');
var slAt = document.getElementById('sl-at');
var svAt = document.getElementById('sv-at');
var slGrav = document.getElementById('sl-grav');
var svGrav = document.getElementById('sv-grav');
var modeNames = ['SINGLE', 'MULTI', 'RANDOM'];

function resize() {
  dpr = Math.min(2, window.devicePixelRatio || 1);
  W = Math.floor(window.innerWidth * dpr);
  H = Math.floor(window.innerHeight * dpr);
  c.width = W; c.height = H;
  c.style.width = window.innerWidth + 'px';
  c.style.height = window.innerHeight + 'px';

  var area = window.innerWidth * window.innerHeight;
  var cellPx = Math.max(3, Math.ceil(Math.sqrt(area / 55000)));
  gw = Math.floor(window.innerWidth / cellPx);
  gh = Math.floor(window.innerHeight / cellPx);
  if (gw < 40) gw = 40;
  if (gh < 30) gh = 30;
  N = gw * gh;

  den = new Float32Array(N);
  den0 = new Float32Array(N);
  vx = new Float32Array(N);
  vy = new Float32Array(N);
  vx0 = new Float32Array(N);
  vy0 = new Float32Array(N);
  prs = new Float32Array(N);
  dvg = new Float32Array(N);

  offCvs = document.createElement('canvas');
  offCvs.width = gw; offCvs.height = gh;
  offCtx = offCvs.getContext('2d');
  img = offCtx.createImageData(gw, gh);

  initState();
}

function initState() {
  var mid = gh * 0.45;
  for (var j = 0; j < gh; j++) {
    for (var i = 0; i < gw; i++) {
      var idx = j * gw + i;
      var x = i / gw;
      var pert = 0;
      if (mode === 0) {
        pert = 4 * Math.cos(2 * Math.PI * 3 * x);
      } else if (mode === 1) {
        pert = 3 * (Math.cos(2 * Math.PI * 2 * x + 0.3)
             + 0.6 * Math.cos(2 * Math.PI * 5 * x + 1.7)
             + 0.3 * Math.cos(2 * Math.PI * 11 * x + 0.5));
      } else {
        pert = 4 * (Math.random() * 2 - 1);
      }
      var iy = mid + pert;
      den[idx] = 0.5 * (1 - Math.tanh((j - iy) / 1.5));
      vx[idx] = 0;
      vy[idx] = 0;
    }
  }
  prs.fill(0);
  dvg.fill(0);
}

function addBuoyancy() {
  var g = grav * 0.003 * atwood;
  for (var k = 0; k < N; k++) {
    vy[k] += g * (den[k] - 0.5);
  }
}

function advect(d, d0, u, v) {
  for (var j = 1; j < gh - 1; j++) {
    for (var i = 1; i < gw - 1; i++) {
      var idx = j * gw + i;
      var px = i - u[idx];
      var py = j - v[idx];
      if (px < 0.5) px = 0.5;
      if (px > gw - 1.5) px = gw - 1.5;
      if (py < 0.5) py = 0.5;
      if (py > gh - 1.5) py = gh - 1.5;
      var i0 = px | 0, i1 = i0 + 1;
      var j0 = py | 0, j1 = j0 + 1;
      var s = px - i0, t = py - j0;
      d[idx] = (1 - s) * ((1 - t) * d0[j0 * gw + i0] + t * d0[j1 * gw + i0])
             + s * ((1 - t) * d0[j0 * gw + i1] + t * d0[j1 * gw + i1]);
    }
  }
  for (var i = 0; i < gw; i++) {
    d[i] = d[gw + i];
    d[(gh - 1) * gw + i] = d[(gh - 2) * gw + i];
  }
  for (var j = 0; j < gh; j++) {
    d[j * gw] = d[j * gw + 1];
    d[j * gw + gw - 1] = d[j * gw + gw - 2];
  }
}

function project() {
  var i, j, idx, k;
  for (j = 1; j < gh - 1; j++) {
    for (i = 1; i < gw - 1; i++) {
      idx = j * gw + i;
      dvg[idx] = -0.5 * (vx[idx + 1] - vx[idx - 1] + vy[idx + gw] - vy[idx - gw]);
      prs[idx] = 0;
    }
  }
  for (k = 0; k < 10; k++) {
    for (j = 1; j < gh - 1; j++) {
      for (i = 1; i < gw - 1; i++) {
        idx = j * gw + i;
        prs[idx] = (dvg[idx] + prs[idx - 1] + prs[idx + 1] + prs[idx - gw] + prs[idx + gw]) * 0.25;
      }
    }
    for (i = 0; i < gw; i++) {
      prs[i] = prs[gw + i];
      prs[(gh - 1) * gw + i] = prs[(gh - 2) * gw + i];
    }
    for (j = 0; j < gh; j++) {
      prs[j * gw] = prs[j * gw + 1];
      prs[j * gw + gw - 1] = prs[j * gw + gw - 2];
    }
  }
  for (j = 1; j < gh - 1; j++) {
    for (i = 1; i < gw - 1; i++) {
      idx = j * gw + i;
      vx[idx] -= 0.5 * (prs[idx + 1] - prs[idx - 1]);
      vy[idx] -= 0.5 * (prs[idx + gw] - prs[idx - gw]);
    }
  }
  for (i = 0; i < gw; i++) {
    vx[i] = vx[gw + i]; vy[i] = 0;
    vx[(gh - 1) * gw + i] = vx[(gh - 2) * gw + i]; vy[(gh - 1) * gw + i] = 0;
  }
  for (j = 0; j < gh; j++) {
    vx[j * gw] = 0; vy[j * gw] = vy[j * gw + 1];
    vx[j * gw + gw - 1] = 0; vy[j * gw + gw - 1] = vy[j * gw + gw - 2];
  }
}

function step() {
  addBuoyancy();
  vx0.set(vx); vy0.set(vy);
  advect(vx, vx0, vx0, vy0);
  advect(vy, vy0, vx0, vy0);
  project();
  den0.set(den);
  advect(den, den0, vx, vy);
  for (var k = 0; k < N; k++) {
    if (den[k] < 0) den[k] = 0;
    if (den[k] > 1) den[k] = 1;
  }
}

function render() {
  var data = img.data;
  for (var j = 0; j < gh; j++) {
    for (var i = 0; i < gw; i++) {
      var idx = j * gw + i;
      var d = den[idx];
      var gx = 0, gy = 0;
      if (i > 0 && i < gw - 1) gx = den[idx + 1] - den[idx - 1];
      if (j > 0 && j < gh - 1) gy = den[idx + gw] - den[idx - gw];
      var gradSq = gx * gx + gy * gy;
      var spdSq = vx[idx] * vx[idx] + vy[idx] * vy[idx];

      var edge = gradSq * 70;
      if (edge > 1) edge = 1;
      var flow = spdSq * 300;
      if (flow > 0.5) flow = 0.5;
      var bright = edge > flow ? edge : flow;
      var intensity = 0.07 + bright * 0.93;

      var cr = TR + d * (PR - TR);
      var cg = TG + d * (PG - TG);
      var cb = TB + d * (PB - TB);

      var pi = idx * 4;
      data[pi]     = (BR + (cr - BR) * intensity) | 0;
      data[pi + 1] = (BG + (cg - BG) * intensity) | 0;
      data[pi + 2] = (BB + (cb - BB) * intensity) | 0;
      data[pi + 3] = 255;
    }
  }
  offCtx.putImageData(img, 0, 0);
  ctx.imageSmoothingEnabled = true;
  ctx.drawImage(offCvs, 0, 0, W, H);
}

function loop() {
  requestAnimationFrame(loop);
  if (running) {
    step();
    step();
  }
  render();
}

function screenToGrid(ex, ey) {
  return {
    i: Math.floor(ex / window.innerWidth * gw),
    j: Math.floor(ey / window.innerHeight * gh)
  };
}

function perturb(ex, ey) {
  var g = screenToGrid(ex, ey);
  var rad = Math.max(gw, gh) * 0.05;
  var r2 = rad * rad;
  for (var dj = -rad | 0; dj <= rad; dj++) {
    for (var di = -rad | 0; di <= rad; di++) {
      var ci = g.i + di, cj = g.j + dj;
      if (ci < 1 || ci >= gw - 1 || cj < 1 || cj >= gh - 1) continue;
      var dist2 = di * di + dj * dj;
      if (dist2 > r2) continue;
      var idx = cj * gw + ci;
      var f = (1 - dist2 / r2);
      vy[idx] += (den[idx] > 0.5 ? 1 : -1) * f * 0.8;
    }
  }
}

function pushFluid(ex, ey, dx, dy) {
  var g = screenToGrid(ex, ey);
  var rad = Math.max(gw, gh) * 0.06;
  var r2 = rad * rad;
  for (var dj = -rad | 0; dj <= rad; dj++) {
    for (var di = -rad | 0; di <= rad; di++) {
      var ci = g.i + di, cj = g.j + dj;
      if (ci < 1 || ci >= gw - 1 || cj < 1 || cj >= gh - 1) continue;
      var dist2 = di * di + dj * dj;
      if (dist2 > r2) continue;
      var idx = cj * gw + ci;
      var f = (1 - dist2 / r2) * 0.3;
      vx[idx] += dx * f;
      vy[idx] += dy * f;
    }
  }
}

function inject(ex, ey) {
  var g = screenToGrid(ex, ey);
  var rad = Math.max(gw, gh) * 0.04;
  var r2 = rad * rad;
  var centerDen = den[g.j * gw + g.i] || 0.5;
  var target = centerDen > 0.5 ? 0 : 1;
  for (var dj = -rad | 0; dj <= rad; dj++) {
    for (var di = -rad | 0; di <= rad; di++) {
      var ci = g.i + di, cj = g.j + dj;
      if (ci < 0 || ci >= gw || cj < 0 || cj >= gh) continue;
      var dist2 = di * di + dj * dj;
      if (dist2 > r2) continue;
      var idx = cj * gw + ci;
      var f = 1 - dist2 / r2;
      den[idx] = den[idx] + (target - den[idx]) * f * 0.9;
    }
  }
}

var isDown = false, lastMX, lastMY;

c.addEventListener('mousedown', function(e) {
  if (e.button === 2) return;
  isDown = true;
  lastMX = e.clientX; lastMY = e.clientY;
  perturb(e.clientX, e.clientY);
  if (window.SFX) SFX.play('ripple');
});
c.addEventListener('mousemove', function(e) {
  if (!isDown) return;
  var dx = (e.clientX - lastMX) / window.innerWidth * gw * 0.15;
  var dy = (e.clientY - lastMY) / window.innerHeight * gh * 0.15;
  pushFluid(e.clientX, e.clientY, dx, dy);
  lastMX = e.clientX; lastMY = e.clientY;
  if (window.SFX) SFX.play('move', 80);
});
c.addEventListener('mouseup', function() { isDown = false; });
c.addEventListener('mouseleave', function() { isDown = false; });

c.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  inject(e.clientX, e.clientY);
  if (window.SFX) SFX.play('spawn');
});

c.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  isDown = true;
  lastMX = t.clientX; lastMY = t.clientY;
  perturb(t.clientX, t.clientY);
  if (window.SFX) SFX.play('ripple');
}, { passive: false });
c.addEventListener('touchmove', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  var dx = (t.clientX - lastMX) / window.innerWidth * gw * 0.15;
  var dy = (t.clientY - lastMY) / window.innerHeight * gh * 0.15;
  pushFluid(t.clientX, t.clientY, dx, dy);
  lastMX = t.clientX; lastMY = t.clientY;
  if (window.SFX) SFX.play('move', 80);
}, { passive: false });
c.addEventListener('touchend', function() { isDown = false; });

slAt.addEventListener('input', function() {
  atwood = parseInt(this.value) / 100;
  svAt.textContent = atwood.toFixed(2);
  hudAt.textContent = atwood.toFixed(2);
});

slGrav.addEventListener('input', function() {
  grav = parseInt(this.value);
  svGrav.textContent = grav;
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initState();
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
    mode = parseInt(this.dataset.mode);
    hudMode.textContent = modeNames[mode];
    initState();
    if (window.SFX) SFX.play('toggle');
  });
});

window.addEventListener('resize', function() { resize(); });

resize();
loop();

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
