<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('atoms');

$pageName = 'atoms';
$pageTitle = 'ATOMS - PULSE';
$pageDesc = 'レナード・ジョーンズ分子動力学。引力と斥力が織りなす結晶化・融解・気化の相転移を体験する。';
$pageCanonical = 'https://pulse.subara3.com/atoms.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  TEMP <span class="v" id="hud-temp">0.00</span><br>
  KE <span class="v" id="hud-ke">0.00</span><br>
  PE <span class="v" id="hud-pe">0.00</span><br>
  PHASE <span class="v" id="hud-phase">SOLID</span>
</div>

<div id="info">
  <span class="v">Lennard-Jones 6-12</span><br>
  V(r) = 4&epsilon;[(&sigma;/r)&sup1;&sup2; &minus; (&sigma;/r)&sup6;]
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add'],
  ['type' => 'drag', 'label' => 'force'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">TEMP</span>
      <input type="range" id="sl-temp" min="0" max="100" value="15" step="1">
      <span class="slider-value" id="sv-temp">0.15</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">ATOMS</span>
      <input type="range" id="sl-count" min="50" max="400" value="200" step="10">
      <span class="slider-value" id="sv-count">200</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">BOND</span>
      <input type="range" id="sl-epsilon" min="10" max="100" value="50" step="5">
      <span class="slider-value" id="sv-epsilon">1.0</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-atoms">ATOMS</button>
      <button class="control-btn" id="btn-bonds">BONDS</button>
      <button class="control-btn" id="btn-energy">ENERGY</button>
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
var W, H, dpr;

var sigma = 20;
var epsilon = 1.0;
var cutoff = 2.5 * sigma;
var dt = 0.3;
var targetTemp = 0.15;
var displayMode = 'atoms';
var particles = [];
var N = 200;
var dragIdx = -1;
var dragX = 0, dragY = 0;
var isDragging = false;
var pointerDown = false;
var mx = 0, my = 0;

function resize() {
  dpr = Math.min(window.devicePixelRatio || 1, 2);
  W = window.innerWidth;
  H = window.innerHeight;
  C.width = W * dpr;
  C.height = H * dpr;
  C.style.width = W + 'px';
  C.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}
window.addEventListener('resize', resize);
resize();

function initParticles(n) {
  particles = [];
  var cols = Math.ceil(Math.sqrt(n * W / H));
  var rows = Math.ceil(n / cols);
  var spacing = sigma * 1.12;
  var ox = (W - (cols - 1) * spacing) / 2;
  var oy = (H - (rows - 1) * spacing) / 2;
  for (var i = 0; i < n; i++) {
    var col = i % cols;
    var row = Math.floor(i / cols);
    var offx = (row % 2) * spacing * 0.5;
    particles.push({
      x: ox + col * spacing + offx + (Math.random() - 0.5) * 2,
      y: oy + row * spacing + (Math.random() - 0.5) * 2,
      vx: (Math.random() - 0.5) * 0.5,
      vy: (Math.random() - 0.5) * 0.5,
      fx: 0, fy: 0,
      pe: 0
    });
  }
  rescaleTemp(targetTemp);
}

function rescaleTemp(t) {
  var ke = 0;
  for (var i = 0; i < particles.length; i++) {
    var p = particles[i];
    ke += p.vx * p.vx + p.vy * p.vy;
  }
  if (ke < 1e-10) {
    for (var i = 0; i < particles.length; i++) {
      particles[i].vx = (Math.random() - 0.5) * 0.5;
      particles[i].vy = (Math.random() - 0.5) * 0.5;
    }
    ke = 0;
    for (var i = 0; i < particles.length; i++) {
      ke += particles[i].vx * particles[i].vx + particles[i].vy * particles[i].vy;
    }
  }
  var currentT = ke / (2 * particles.length);
  if (currentT < 1e-10) return;
  var scale = Math.sqrt(t / currentT);
  for (var i = 0; i < particles.length; i++) {
    particles[i].vx *= scale;
    particles[i].vy *= scale;
  }
}

function computeForces() {
  var n = particles.length;
  for (var i = 0; i < n; i++) {
    particles[i].fx = 0;
    particles[i].fy = 0;
    particles[i].pe = 0;
  }
  var s2 = sigma * sigma;
  var rc2 = cutoff * cutoff;
  for (var i = 0; i < n; i++) {
    var pi = particles[i];
    for (var j = i + 1; j < n; j++) {
      var pj = particles[j];
      var dx = pi.x - pj.x;
      var dy = pi.y - pj.y;
      var r2 = dx * dx + dy * dy;
      if (r2 < rc2 && r2 > 0.01) {
        var ir2 = s2 / r2;
        var ir6 = ir2 * ir2 * ir2;
        var ir12 = ir6 * ir6;
        var f = 24 * epsilon * (2 * ir12 - ir6) / r2;
        var fx = f * dx;
        var fy = f * dy;
        pi.fx += fx;
        pi.fy += fy;
        pj.fx -= fx;
        pj.fy -= fy;
        var pe = 4 * epsilon * (ir12 - ir6);
        pi.pe += pe * 0.5;
        pj.pe += pe * 0.5;
      }
    }
  }
}

function step() {
  var n = particles.length;
  for (var i = 0; i < n; i++) {
    var p = particles[i];
    if (i === dragIdx) continue;
    p.vx += p.fx * dt * 0.5;
    p.vy += p.fy * dt * 0.5;
    p.x += p.vx * dt;
    p.y += p.vy * dt;
  }
  computeForces();
  for (var i = 0; i < n; i++) {
    var p = particles[i];
    if (i === dragIdx) continue;
    p.vx += p.fx * dt * 0.5;
    p.vy += p.fy * dt * 0.5;
  }
  // walls
  var margin = sigma * 0.5;
  for (var i = 0; i < n; i++) {
    var p = particles[i];
    if (p.x < margin) { p.x = margin; p.vx = Math.abs(p.vx) * 0.8; }
    if (p.x > W - margin) { p.x = W - margin; p.vx = -Math.abs(p.vx) * 0.8; }
    if (p.y < margin) { p.y = margin; p.vy = Math.abs(p.vy) * 0.8; }
    if (p.y > H - margin) { p.y = H - margin; p.vy = -Math.abs(p.vy) * 0.8; }
  }
  // thermostat — Berendsen
  var ke = 0;
  var count = 0;
  for (var i = 0; i < n; i++) {
    if (i === dragIdx) continue;
    ke += particles[i].vx * particles[i].vx + particles[i].vy * particles[i].vy;
    count++;
  }
  if (count > 0) {
    var currentT = ke / (2 * count);
    if (currentT > 1e-10) {
      var tau = 20;
      var lam = Math.sqrt(1 + dt / tau * (targetTemp / currentT - 1));
      lam = Math.min(Math.max(lam, 0.9), 1.1);
      for (var i = 0; i < n; i++) {
        if (i === dragIdx) continue;
        particles[i].vx *= lam;
        particles[i].vy *= lam;
      }
    }
  }
  // drag force
  if (isDragging && dragIdx >= 0) {
    var p = particles[dragIdx];
    var k = 2.0;
    p.vx = (dragX - p.x) * k;
    p.vy = (dragY - p.y) * k;
    p.x += p.vx * dt * 0.5;
    p.y += p.vy * dt * 0.5;
  }
}

function getKE() {
  var ke = 0;
  for (var i = 0; i < particles.length; i++) {
    ke += particles[i].vx * particles[i].vx + particles[i].vy * particles[i].vy;
  }
  return ke * 0.5;
}

function getPE() {
  var pe = 0;
  for (var i = 0; i < particles.length; i++) pe += particles[i].pe;
  return pe;
}

function getMeasuredTemp() {
  var n = particles.length;
  if (n === 0) return 0;
  var ke = 0;
  for (var i = 0; i < n; i++) {
    ke += particles[i].vx * particles[i].vx + particles[i].vy * particles[i].vy;
  }
  return ke / (2 * n);
}

function detectPhase() {
  var n = particles.length;
  if (n < 10) return '—';
  var bondCount = 0;
  var bondThresh = sigma * 1.3;
  var bt2 = bondThresh * bondThresh;
  for (var i = 0; i < n; i++) {
    for (var j = i + 1; j < n; j++) {
      var dx = particles[i].x - particles[j].x;
      var dy = particles[i].y - particles[j].y;
      if (dx * dx + dy * dy < bt2) bondCount++;
    }
  }
  var avgBonds = 2 * bondCount / n;
  if (avgBonds > 4.5) return 'SOLID';
  if (avgBonds > 2.0) return 'LIQUID';
  return 'GAS';
}

function speedColor(vx, vy) {
  var speed = Math.sqrt(vx * vx + vy * vy);
  var t = Math.min(speed / 3.0, 1);
  var r, g, b;
  if (t < 0.5) {
    var u = t * 2;
    r = Math.floor(0 + u * 123);
    g = Math.floor(245 - u * 201);
    b = Math.floor(212 - u * 21);
  } else {
    var u = (t - 0.5) * 2;
    r = Math.floor(123 + u * 77);
    g = Math.floor(44 + u * 20);
    b = Math.floor(191 + u * 64);
  }
  return 'rgb(' + r + ',' + g + ',' + b + ')';
}

function energyColor(pe) {
  var t = Math.min(Math.max((pe + 2) / 4, 0), 1);
  var r, g, b;
  if (t < 0.5) {
    var u = t * 2;
    r = Math.floor(0 + u * 123);
    g = Math.floor(245 - u * 201);
    b = Math.floor(212 - u * 21);
  } else {
    var u = (t - 0.5) * 2;
    r = Math.floor(123 + u * 77);
    g = Math.floor(44 + u * 20);
    b = Math.floor(191 + u * 64);
  }
  return 'rgb(' + r + ',' + g + ',' + b + ')';
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var n = particles.length;
  var bondThresh = sigma * 1.4;
  var bt2 = bondThresh * bondThresh;
  var rad = sigma * 0.45;

  if (displayMode === 'bonds' || displayMode === 'atoms') {
    // draw bonds
    if (displayMode === 'bonds') {
      ctx.lineWidth = 1;
      for (var i = 0; i < n; i++) {
        for (var j = i + 1; j < n; j++) {
          var dx = particles[i].x - particles[j].x;
          var dy = particles[i].y - particles[j].y;
          var d2 = dx * dx + dy * dy;
          if (d2 < bt2) {
            var d = Math.sqrt(d2);
            var a = 1 - d / bondThresh;
            ctx.strokeStyle = 'rgba(0,245,212,' + (a * 0.4).toFixed(2) + ')';
            ctx.beginPath();
            ctx.moveTo(particles[i].x, particles[i].y);
            ctx.lineTo(particles[j].x, particles[j].y);
            ctx.stroke();
          }
        }
      }
    }
    // draw atoms
    for (var i = 0; i < n; i++) {
      var p = particles[i];
      var col = speedColor(p.vx, p.vy);
      ctx.beginPath();
      ctx.arc(p.x, p.y, rad, 0, Math.PI * 2);
      ctx.fillStyle = col;
      ctx.fill();
      // glow
      var speed = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      var glowR = rad + 2 + speed * 1.5;
      var grad = ctx.createRadialGradient(p.x, p.y, rad * 0.5, p.x, p.y, glowR);
      grad.addColorStop(0, col.replace('rgb', 'rgba').replace(')', ',0.3)'));
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.beginPath();
      ctx.arc(p.x, p.y, glowR, 0, Math.PI * 2);
      ctx.fillStyle = grad;
      ctx.fill();
    }
  } else if (displayMode === 'energy') {
    for (var i = 0; i < n; i++) {
      var p = particles[i];
      var col = energyColor(p.pe);
      ctx.beginPath();
      ctx.arc(p.x, p.y, rad * 1.2, 0, Math.PI * 2);
      ctx.fillStyle = col;
      ctx.fill();
      var grad = ctx.createRadialGradient(p.x, p.y, rad * 0.3, p.x, p.y, rad * 2);
      grad.addColorStop(0, col.replace('rgb', 'rgba').replace(')', ',0.35)'));
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.beginPath();
      ctx.arc(p.x, p.y, rad * 2, 0, Math.PI * 2);
      ctx.fillStyle = grad;
      ctx.fill();
    }
  }

  // HUD
  var measT = getMeasuredTemp();
  var ke = getKE();
  var pe = getPE();
  var phase = detectPhase();
  document.getElementById('hud-temp').textContent = measT.toFixed(2);
  document.getElementById('hud-ke').textContent = (ke / Math.max(n, 1)).toFixed(2);
  document.getElementById('hud-pe').textContent = (pe / Math.max(n, 1)).toFixed(2);
  document.getElementById('hud-phase').textContent = phase;
}

function findNearest(x, y) {
  var best = -1, bd = Infinity;
  for (var i = 0; i < particles.length; i++) {
    var dx = particles[i].x - x;
    var dy = particles[i].y - y;
    var d = dx * dx + dy * dy;
    if (d < bd) { bd = d; best = i; }
  }
  if (bd < sigma * sigma * 4) return best;
  return -1;
}

function addParticle(x, y) {
  particles.push({
    x: x, y: y,
    vx: (Math.random() - 0.5) * targetTemp * 2,
    vy: (Math.random() - 0.5) * targetTemp * 2,
    fx: 0, fy: 0, pe: 0
  });
  if (typeof SFX !== 'undefined') SFX.play('spawn');
}

function removeParticle(x, y) {
  var idx = findNearest(x, y);
  if (idx >= 0) {
    particles.splice(idx, 1);
    if (dragIdx === idx) dragIdx = -1;
    else if (dragIdx > idx) dragIdx--;
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }
}

function getEventPos(e) {
  if (e.touches && e.touches.length > 0) {
    return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  }
  return { x: e.clientX, y: e.clientY };
}

var longPressTimer = null;
var isLongPress = false;

C.addEventListener('mousedown', function(e) {
  if (e.button === 2) {
    e.preventDefault();
    removeParticle(e.clientX, e.clientY);
    return;
  }
  if (e.button === 0) {
    pointerDown = true;
    mx = e.clientX;
    my = e.clientY;
    var idx = findNearest(e.clientX, e.clientY);
    if (idx >= 0) {
      dragIdx = idx;
      isDragging = true;
      dragX = e.clientX;
      dragY = e.clientY;
    } else {
      addParticle(e.clientX, e.clientY);
    }
  }
});

C.addEventListener('mousemove', function(e) {
  mx = e.clientX;
  my = e.clientY;
  if (isDragging && dragIdx >= 0) {
    dragX = e.clientX;
    dragY = e.clientY;
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  }
});

C.addEventListener('mouseup', function(e) {
  pointerDown = false;
  isDragging = false;
  dragIdx = -1;
});

C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var pos = getEventPos(e);
  mx = pos.x;
  my = pos.y;
  isLongPress = false;
  longPressTimer = setTimeout(function() {
    isLongPress = true;
    removeParticle(pos.x, pos.y);
  }, 400);
  var idx = findNearest(pos.x, pos.y);
  if (idx >= 0) {
    dragIdx = idx;
    isDragging = true;
    dragX = pos.x;
    dragY = pos.y;
  }
  pointerDown = true;
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
  var pos = getEventPos(e);
  mx = pos.x;
  my = pos.y;
  if (isDragging && dragIdx >= 0) {
    dragX = pos.x;
    dragY = pos.y;
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  }
}, { passive: false });

C.addEventListener('touchend', function(e) {
  if (longPressTimer) {
    clearTimeout(longPressTimer);
    longPressTimer = null;
    if (!isLongPress && !isDragging) {
      addParticle(mx, my);
    } else if (!isLongPress && isDragging) {
      // was dragging
    }
  }
  pointerDown = false;
  isDragging = false;
  dragIdx = -1;
  isLongPress = false;
});

// Controls
var slTemp = document.getElementById('sl-temp');
var svTemp = document.getElementById('sv-temp');
var slCount = document.getElementById('sl-count');
var svCount = document.getElementById('sv-count');
var slEps = document.getElementById('sl-epsilon');
var svEps = document.getElementById('sv-epsilon');

slTemp.addEventListener('input', function() {
  targetTemp = parseFloat(this.value) / 100;
  svTemp.textContent = targetTemp.toFixed(2);
});

slCount.addEventListener('input', function() {
  N = parseInt(this.value);
  svCount.textContent = N;
  initParticles(N);
  if (typeof SFX !== 'undefined') SFX.play('click');
});

slEps.addEventListener('input', function() {
  epsilon = parseFloat(this.value) / 50;
  svEps.textContent = epsilon.toFixed(1);
});

var modeBtns = ['btn-atoms', 'btn-bonds', 'btn-energy'];
var modes = ['atoms', 'bonds', 'energy'];
modeBtns.forEach(function(id, i) {
  document.getElementById(id).addEventListener('click', function() {
    displayMode = modes[i];
    modeBtns.forEach(function(bid) {
      document.getElementById(bid).classList.remove('active');
    });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initParticles(N);
  if (typeof SFX !== 'undefined') SFX.play('click');
});

computeForces();
initParticles(N);
computeForces();

var substeps = 3;
function loop() {
  for (var s = 0; s < substeps; s++) step();
  draw();
  requestAnimationFrame(loop);
}
requestAnimationFrame(loop);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
