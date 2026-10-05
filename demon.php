<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('demon');

$pageName = 'demon';
$pageTitle = 'DEMON - PULSE';
$pageDesc = '壁の小窓を開け閉めして、速い粒と遅い粒をより分ける。マクスウェルの悪魔になって、片方を熱く、もう片方を冷たくできるか。';
$pageCanonical = 'https://pulse.subara3.com/demon.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <span class="sub" id="h-mode">PLAY</span><br>
  LEFT <b id="h-tl">1.00</b> RIGHT <b id="h-tr">1.00</b><br>
  RATIO <b id="h-ratio">1.00</b><br>
  GATE <b id="h-gate">CLOSED</b>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'gate'],
  ['type' => 'drag', 'label' => 'gate pos'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="control-btn active" data-mode="PLAY">PLAY</button>
    <button class="control-btn" data-mode="AUTO">AUTO</button>
    <button class="control-btn" data-mode="RANDOM">RANDOM</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">PARTICLES</span>
    <input type="range" id="sl-count" min="10" max="60" value="25" step="5">
    <span class="slider-value" id="sv-count">25</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">GATE SIZE</span>
    <input type="range" id="sl-gate" min="20" max="120" value="60" step="10">
    <span class="slider-value" id="sv-gate">60</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, dpr;
var particles = [];
var gateOpen = false;
var gateY = 0.5;
var gateSizePx = 60;
var wallX;
var mode = 'PLAY';
var pCount = 25;
var cleared = false;
var draggingGate = false;
var RADIUS = 4;
var MIN_SPD = 0.8;
var MAX_SPD = 4.0;
var autoTimer = 0;
var randomTimer = 0;

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function spdToColor(spd, alpha) {
  var t = (spd - MIN_SPD) / (MAX_SPD - MIN_SPD);
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var h = brandHue(t);
  return 'hsla(' + h + ',80%,' + (45 + t * 15) + '%,' + (alpha || 1) + ')';
}

function resize() {
  dpr = window.devicePixelRatio || 1;
  W = C.width = window.innerWidth * dpr;
  H = C.height = window.innerHeight * dpr;
  C.style.width = window.innerWidth + 'px';
  C.style.height = window.innerHeight + 'px';
  wallX = W * 0.5;
}

function initParticles() {
  particles = [];
  for (var i = 0; i < pCount * 2; i++) {
    var side = i < pCount ? 0 : 1;
    var spd = MIN_SPD + Math.random() * (MAX_SPD - MIN_SPD);
    var angle = Math.random() * Math.PI * 2;
    var margin = RADIUS * dpr + 4;
    var px, py;
    if (side === 0) {
      px = margin + Math.random() * (wallX - margin * 2);
    } else {
      px = wallX + margin + Math.random() * (W - wallX - margin * 2);
    }
    py = margin + Math.random() * (H - margin * 2);
    particles.push({
      x: px, y: py,
      vx: Math.cos(angle) * spd * dpr,
      vy: Math.sin(angle) * spd * dpr,
      spd: spd,
      r: (RADIUS + (spd - MIN_SPD) / (MAX_SPD - MIN_SPD) * 2) * dpr
    });
  }
  cleared = false;
}

function gateTop() {
  var gs = gateSizePx * dpr;
  return gateY * H - gs * 0.5;
}
function gateBot() {
  var gs = gateSizePx * dpr;
  return gateY * H + gs * 0.5;
}

function step() {
  var gs = gateSizePx * dpr;
  var gt = gateTop(), gb = gateBot();
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    p.x += p.vx;
    p.y += p.vy;

    if (p.x - p.r < 0) { p.x = p.r; p.vx = Math.abs(p.vx); }
    if (p.x + p.r > W) { p.x = W - p.r; p.vx = -Math.abs(p.vx); }
    if (p.y - p.r < 0) { p.y = p.r; p.vy = Math.abs(p.vy); }
    if (p.y + p.r > H) { p.y = H - p.r; p.vy = -Math.abs(p.vy); }

    var wasLeft = (p.x - p.vx) < wallX;
    var isLeft = p.x < wallX;
    if (wasLeft !== isLeft) {
      if (gateOpen && p.y > gt && p.y < gb) {
        // pass through
      } else {
        if (wasLeft) {
          p.x = wallX - p.r;
          p.vx = -Math.abs(p.vx);
        } else {
          p.x = wallX + p.r;
          p.vx = Math.abs(p.vx);
        }
      }
    }
  }
}

function autoLogic() {
  autoTimer++;
  if (autoTimer % 3 !== 0) return;
  var gs = gateSizePx * dpr;
  var gt = gateTop(), gb = gateBot();
  var zone = 60 * dpr;
  var shouldOpen = false;
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    if (p.y < gt || p.y > gb) continue;
    var distToWall = Math.abs(p.x - wallX);
    if (distToWall > zone) continue;
    var movingRight = p.vx > 0;
    var onLeft = p.x < wallX;
    if (onLeft && movingRight && p.spd > (MIN_SPD + MAX_SPD) * 0.5) {
      shouldOpen = true; break;
    }
    if (!onLeft && !movingRight && p.spd < (MIN_SPD + MAX_SPD) * 0.5) {
      shouldOpen = true; break;
    }
  }
  if (shouldOpen !== gateOpen) {
    gateOpen = shouldOpen;
    if (gateOpen) SFX.play('tick');
  }
}

function randomLogic() {
  randomTimer++;
  if (randomTimer % 30 === 0) {
    gateOpen = Math.random() < 0.3;
    if (gateOpen) SFX.play('tick');
  }
}

function calcTemp(side) {
  var sum = 0, n = 0;
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    var onLeft = p.x < wallX;
    if ((side === 0 && onLeft) || (side === 1 && !onLeft)) {
      sum += p.spd * p.spd;
      n++;
    }
  }
  return n > 0 ? sum / n : 0;
}

function drawWall() {
  var gt = gateTop(), gb = gateBot();
  ctx.strokeStyle = 'rgba(255,255,255,0.25)';
  ctx.lineWidth = 2 * dpr;
  ctx.beginPath();
  ctx.moveTo(wallX, 0);
  ctx.lineTo(wallX, gt);
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(wallX, gb);
  ctx.lineTo(wallX, H);
  ctx.stroke();

  if (gateOpen) {
    ctx.strokeStyle = 'rgba(0,245,212,0.3)';
    ctx.lineWidth = 1 * dpr;
    ctx.setLineDash([4 * dpr, 6 * dpr]);
    ctx.beginPath();
    ctx.moveTo(wallX, gt);
    ctx.lineTo(wallX, gb);
    ctx.stroke();
    ctx.setLineDash([]);

    var grad = ctx.createRadialGradient(wallX, (gt + gb) * 0.5, 0, wallX, (gt + gb) * 0.5, gateSizePx * dpr * 0.8);
    grad.addColorStop(0, 'rgba(0,245,212,0.12)');
    grad.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(wallX - gateSizePx * dpr, gt, gateSizePx * dpr * 2, gb - gt);
  } else {
    ctx.strokeStyle = 'rgba(123,44,191,0.5)';
    ctx.lineWidth = 3 * dpr;
    ctx.beginPath();
    ctx.moveTo(wallX, gt);
    ctx.lineTo(wallX, gb);
    ctx.stroke();
  }

  ctx.fillStyle = 'rgba(255,255,255,0.15)';
  ctx.beginPath();
  ctx.arc(wallX, gt, 3 * dpr, 0, Math.PI * 2);
  ctx.fill();
  ctx.beginPath();
  ctx.arc(wallX, gb, 3 * dpr, 0, Math.PI * 2);
  ctx.fill();
}

function drawTempBars() {
  var tl = calcTemp(0), tr = calcTemp(1);
  var maxT = MAX_SPD * MAX_SPD;
  var barW = 80 * dpr, barH = 6 * dpr;
  var bx = wallX - barW - 20 * dpr, by = 14 * dpr;

  ctx.fillStyle = 'rgba(255,255,255,0.06)';
  ctx.fillRect(bx, by, barW, barH);
  var frac = Math.min(tl / maxT, 1);
  ctx.fillStyle = spdToColor(Math.sqrt(tl), 0.7);
  ctx.fillRect(bx, by, barW * frac, barH);

  var bx2 = wallX + 20 * dpr;
  ctx.fillStyle = 'rgba(255,255,255,0.06)';
  ctx.fillRect(bx2, by, barW, barH);
  frac = Math.min(tr / maxT, 1);
  ctx.fillStyle = spdToColor(Math.sqrt(tr), 0.7);
  ctx.fillRect(bx2, by, barW * frac, barH);
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  ctx.fillStyle = 'rgba(0,245,212,0.015)';
  ctx.fillRect(0, 0, wallX, H);
  ctx.fillStyle = 'rgba(123,44,191,0.015)';
  ctx.fillRect(wallX, 0, W - wallX, H);

  drawWall();

  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    ctx.beginPath();
    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
    ctx.fillStyle = spdToColor(p.spd, 0.9);
    ctx.fill();

    var glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.r * 3);
    glow.addColorStop(0, spdToColor(p.spd, 0.15));
    glow.addColorStop(1, 'transparent');
    ctx.fillStyle = glow;
    ctx.fillRect(p.x - p.r * 3, p.y - p.r * 3, p.r * 6, p.r * 6);
  }

  drawTempBars();
}

function updateHUD() {
  var tl = calcTemp(0), tr = calcTemp(1);
  var ratio = 1;
  if (tl > 0 && tr > 0) {
    ratio = tl > tr ? tl / tr : tr / tl;
  }
  document.getElementById('h-tl').textContent = tl.toFixed(2);
  document.getElementById('h-tr').textContent = tr.toFixed(2);
  document.getElementById('h-ratio').textContent = ratio.toFixed(2);
  document.getElementById('h-gate').textContent = gateOpen ? 'OPEN' : 'CLOSED';
  document.getElementById('h-gate').style.color = gateOpen ? '#00f5d4' : 'rgba(181,140,255,0.9)';

  if (!cleared && ratio >= 2.0) {
    cleared = true;
    if (window.PULSE) PULSE.clear();
    SFX.play('complete');
  }
}

var frame = 0;
function loop() {
  requestAnimationFrame(loop);
  step();
  if (mode === 'AUTO') autoLogic();
  if (mode === 'RANDOM') randomLogic();
  draw();
  frame++;
  if (frame % 10 === 0) updateHUD();
}

function evPos(e) {
  var r = C.getBoundingClientRect();
  var cx, cy;
  if (e.touches) {
    cx = e.touches[0].clientX;
    cy = e.touches[0].clientY;
  } else {
    cx = e.clientX;
    cy = e.clientY;
  }
  return { x: (cx - r.left) * dpr, y: (cy - r.top) * dpr };
}

function nearGateHandle(p) {
  var gt = gateTop(), gb = gateBot();
  var dTop = Math.abs(p.x - wallX) + Math.abs(p.y - gt);
  var dBot = Math.abs(p.x - wallX) + Math.abs(p.y - gb);
  return (dTop < 30 * dpr || dBot < 30 * dpr);
}

function onDown(e) {
  e.preventDefault();
  var p = evPos(e);
  if (nearGateHandle(p)) {
    draggingGate = true;
    return;
  }
  if (mode === 'PLAY') {
    gateOpen = !gateOpen;
    SFX.play('toggle');
  }
}

function onMove(e) {
  if (!draggingGate) return;
  e.preventDefault();
  var p = evPos(e);
  gateY = Math.max(0.1, Math.min(0.9, p.y / H));
}

function onUp(e) {
  if (draggingGate) {
    draggingGate = false;
    SFX.play('move');
  }
}

function onContext(e) { e.preventDefault(); }

C.addEventListener('mousedown', onDown);
C.addEventListener('mousemove', onMove);
C.addEventListener('mouseup', onUp);
C.addEventListener('contextmenu', onContext);
C.addEventListener('touchstart', onDown, {passive: false});
C.addEventListener('touchmove', onMove, {passive: false});
C.addEventListener('touchend', onUp);

var modeBtns = document.querySelectorAll('[data-mode]');
modeBtns.forEach(function(b) {
  b.addEventListener('click', function() {
    modeBtns.forEach(function(bb) { bb.classList.remove('active'); });
    b.classList.add('active');
    mode = b.dataset.mode;
    document.getElementById('h-mode').textContent = mode;
    gateOpen = false;
    SFX.play('toggle');
  });
});

var slCount = document.getElementById('sl-count');
var svCount = document.getElementById('sv-count');
slCount.addEventListener('input', function() {
  pCount = parseInt(slCount.value);
  svCount.textContent = pCount;
  initParticles();
  SFX.play('click');
});

var slGate = document.getElementById('sl-gate');
var svGate = document.getElementById('sv-gate');
slGate.addEventListener('input', function() {
  gateSizePx = parseInt(slGate.value);
  svGate.textContent = gateSizePx;
  SFX.play('click');
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initParticles();
  gateOpen = false;
  SFX.play('click');
});

window.addEventListener('resize', function() {
  var oldW = W, oldH = H;
  resize();
  var sx = W / oldW, sy = H / oldH;
  wallX = W * 0.5;
  for (var i = 0; i < particles.length; i++) {
    particles[i].x *= sx;
    particles[i].y *= sy;
  }
});

resize();
initParticles();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
