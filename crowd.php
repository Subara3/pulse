<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crowd');

$pageName = 'crowd';
$pageTitle = 'CROWD - PULSE';
$pageDesc = '群衆シミュレーション。社会力モデルで歩行者が自律的に動き、レーン形成やボトルネックが自然発生する。';
$pageCanonical = 'https://pulse.subara3.com/crowd.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
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
  AGENTS <span class="v" id="hud-agents">0</span><br>
  LANES <span class="v" id="hud-lanes">—</span><br>
  FLOW <span class="v" id="hud-flow">0</span>/s
</div>

<div id="info">
  <span class="v">Social Force Model</span><br>
  Helbing &amp; Moln&aacute;r 1995
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'wall'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">AGENTS</span>
      <input type="range" id="sl-count" min="20" max="300" value="120" step="10">
      <span class="slider-value" id="sv-count">120</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="20" max="120" value="60" step="5">
      <span class="slider-value" id="sv-speed">60</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">REPULSE</span>
      <input type="range" id="sl-repulse" min="10" max="100" value="40" step="5">
      <span class="slider-value" id="sv-repulse">40</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-corridor">CORRIDOR</button>
      <button class="control-btn" id="btn-exit">EXIT</button>
      <button class="control-btn" id="btn-cross">CROSS</button>
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

var agents = [];
var walls = [];
var userWalls = [];
var mode = 'corridor';
var agentCount = 120;
var desiredSpeed = 60;
var repulseStr = 40;
var flowCount = 0;
var flowTime = 0;
var flowRate = 0;
var laneScore = 0;

var hudAgents = document.getElementById('hud-agents');
var hudLanes = document.getElementById('hud-lanes');
var hudFlow = document.getElementById('hud-flow');

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

function Agent(x, y, gx, gy, group) {
  this.x = x;
  this.y = y;
  this.vx = 0;
  this.vy = 0;
  this.gx = gx;
  this.gy = gy;
  this.group = group;
  this.r = 3.5;
  this.trail = [];
  this.arrived = false;
}

function Wall(x1, y1, x2, y2) {
  this.x1 = x1;
  this.y1 = y1;
  this.x2 = x2;
  this.y2 = y2;
  var dx = x2 - x1, dy = y2 - y1;
  this.len = Math.sqrt(dx * dx + dy * dy);
  this.nx = -dy / (this.len || 1);
  this.ny = dx / (this.len || 1);
}

function distToWall(px, py, w) {
  var dx = w.x2 - w.x1, dy = w.y2 - w.y1;
  var t = ((px - w.x1) * dx + (py - w.y1) * dy) / (w.len * w.len || 1);
  t = Math.max(0, Math.min(1, t));
  var cx = w.x1 + t * dx, cy = w.y1 + t * dy;
  var ddx = px - cx, ddy = py - cy;
  return Math.sqrt(ddx * ddx + ddy * ddy);
}

function wallNormal(px, py, w) {
  var dx = w.x2 - w.x1, dy = w.y2 - w.y1;
  var t = ((px - w.x1) * dx + (py - w.y1) * dy) / (w.len * w.len || 1);
  t = Math.max(0, Math.min(1, t));
  var cx = w.x1 + t * dx, cy = w.y1 + t * dy;
  var ddx = px - cx, ddy = py - cy;
  var d = Math.sqrt(ddx * ddx + ddy * ddy);
  if (d < 0.01) return { nx: w.nx, ny: w.ny, d: d };
  return { nx: ddx / d, ny: ddy / d, d: d };
}

function setupCorridor() {
  walls = [];
  userWalls = [];
  var margin = 80;
  var corridorH = H * 0.35;
  var top = (H - corridorH) / 2;
  var bot = top + corridorH;
  walls.push(new Wall(0, top, W, top));
  walls.push(new Wall(0, bot, W, bot));

  agents = [];
  var half = Math.floor(agentCount / 2);
  for (var i = 0; i < half; i++) {
    var x = Math.random() * W * 0.3 + 20;
    var y = top + 15 + Math.random() * (corridorH - 30);
    agents.push(new Agent(x, y, W + 50, y, 0));
  }
  for (var i = 0; i < agentCount - half; i++) {
    var x = W - Math.random() * W * 0.3 - 20;
    var y = top + 15 + Math.random() * (corridorH - 30);
    agents.push(new Agent(x, y, -50, y, 1));
  }
}

function setupExit() {
  walls = [];
  userWalls = [];
  var cx = W / 2, cy = H / 2;
  var rw = Math.min(W, H) * 0.35;
  var rh = rw * 0.8;
  var doorW = 30;

  walls.push(new Wall(cx - rw, cy - rh, cx + rw, cy - rh));
  walls.push(new Wall(cx - rw, cy + rh, cx + rw, cy + rh));
  walls.push(new Wall(cx - rw, cy - rh, cx - rw, cy + rh));
  walls.push(new Wall(cx + rw, cy - rh, cx + rw, cy - doorW / 2));
  walls.push(new Wall(cx + rw, cy + doorW / 2, cx + rw, cy + rh));

  agents = [];
  for (var i = 0; i < agentCount; i++) {
    var x = cx - rw + 15 + Math.random() * (rw * 2 - 50);
    var y = cy - rh + 15 + Math.random() * (rh * 2 - 30);
    agents.push(new Agent(x, y, cx + rw + 100, cy, 0));
  }
}

function setupCross() {
  walls = [];
  userWalls = [];
  var cx = W / 2, cy = H / 2;
  var arm = Math.min(W, H) * 0.15;
  var len = Math.min(W, H) * 0.4;

  walls.push(new Wall(cx - arm, 0, cx - arm, cy - arm));
  walls.push(new Wall(cx + arm, 0, cx + arm, cy - arm));
  walls.push(new Wall(cx - arm, cy + arm, cx - arm, H));
  walls.push(new Wall(cx + arm, cy + arm, cx + arm, H));
  walls.push(new Wall(0, cy - arm, cx - arm, cy - arm));
  walls.push(new Wall(cx + arm, cy - arm, W, cy - arm));
  walls.push(new Wall(0, cy + arm, cx - arm, cy + arm));
  walls.push(new Wall(cx + arm, cy + arm, W, cy + arm));

  agents = [];
  var quarter = Math.floor(agentCount / 4);
  for (var i = 0; i < quarter; i++) {
    var x = cx - arm + 8 + Math.random() * (arm * 2 - 16);
    var y = 10 + Math.random() * (cy - arm - 20);
    agents.push(new Agent(x, y, cx, H + 50, 0));
  }
  for (var i = 0; i < quarter; i++) {
    var x = cx - arm + 8 + Math.random() * (arm * 2 - 16);
    var y = cy + arm + 10 + Math.random() * (H - cy - arm - 20);
    agents.push(new Agent(x, y, cx, -50, 1));
  }
  for (var i = 0; i < quarter; i++) {
    var x = 10 + Math.random() * (cx - arm - 20);
    var y = cy - arm + 8 + Math.random() * (arm * 2 - 16);
    agents.push(new Agent(x, y, W + 50, cy, 2));
  }
  for (var i = 0; i < agentCount - quarter * 3; i++) {
    var x = cx + arm + 10 + Math.random() * (W - cx - arm - 20);
    var y = cy - arm + 8 + Math.random() * (arm * 2 - 16);
    agents.push(new Agent(x, y, -50, cy, 3));
  }
}

function initScene() {
  flowCount = 0;
  flowTime = performance.now();
  flowRate = 0;
  if (mode === 'corridor') setupCorridor();
  else if (mode === 'exit') setupExit();
  else setupCross();
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
}

function respawnAgent(a) {
  if (mode === 'corridor') {
    var corridorH = H * 0.35;
    var top = (H - corridorH) / 2;
    if (a.group === 0) {
      a.x = -5;
      a.y = top + 15 + Math.random() * (corridorH - 30);
      a.gx = W + 50;
      a.gy = a.y;
    } else {
      a.x = W + 5;
      a.y = top + 15 + Math.random() * (corridorH - 30);
      a.gx = -50;
      a.gy = a.y;
    }
  } else if (mode === 'exit') {
    var cx = W / 2, cy = H / 2;
    var rw = Math.min(W, H) * 0.35;
    var rh = rw * 0.8;
    a.x = cx - rw + 15 + Math.random() * (rw * 2 - 50);
    a.y = cy - rh + 15 + Math.random() * (rh * 2 - 30);
    a.gx = cx + rw + 100;
    a.gy = cy;
  } else {
    var cx = W / 2, cy = H / 2;
    var arm = Math.min(W, H) * 0.15;
    if (a.group === 0) {
      a.x = cx - arm + 8 + Math.random() * (arm * 2 - 16);
      a.y = 10 + Math.random() * (cy - arm - 20);
    } else if (a.group === 1) {
      a.x = cx - arm + 8 + Math.random() * (arm * 2 - 16);
      a.y = cy + arm + 10 + Math.random() * (H - cy - arm - 20);
    } else if (a.group === 2) {
      a.x = 10 + Math.random() * (cx - arm - 20);
      a.y = cy - arm + 8 + Math.random() * (arm * 2 - 16);
    } else {
      a.x = cx + arm + 10 + Math.random() * (W - cx - arm - 20);
      a.y = cy - arm + 8 + Math.random() * (arm * 2 - 16);
    }
  }
  a.vx = 0;
  a.vy = 0;
  a.trail = [];
  a.arrived = false;
}

var groupColors = [
  [0, 245, 212],
  [123, 44, 191],
  [0, 200, 180],
  [160, 80, 220]
];

function update(dt) {
  if (dt > 0.05) dt = 0.05;
  var spd = desiredSpeed / 1000;
  var tau = 0.3;
  var A = repulseStr * 0.8;
  var B = 12;
  var Aw = repulseStr * 1.5;
  var Bw = 8;
  var allWalls = walls.concat(userWalls);

  for (var i = 0; i < agents.length; i++) {
    var a = agents[i];
    var dx = a.gx - a.x, dy = a.gy - a.y;
    var d = Math.sqrt(dx * dx + dy * dy);

    if (d < 15 || a.x < -30 || a.x > W + 30 || a.y < -30 || a.y > H + 30) {
      flowCount++;
      respawnAgent(a);
      continue;
    }

    var ex = dx / d, ey = dy / d;
    var fxDes = (spd * ex - a.vx) / tau;
    var fyDes = (spd * ey - a.vy) / tau;

    var fxSoc = 0, fySoc = 0;
    for (var j = 0; j < agents.length; j++) {
      if (i === j) continue;
      var b = agents[j];
      var sx = a.x - b.x, sy = a.y - b.y;
      var sd = Math.sqrt(sx * sx + sy * sy);
      var rr = a.r + b.r;
      if (sd < 0.5) sd = 0.5;
      if (sd < rr * 8) {
        var force = A * Math.exp((rr - sd) / B);
        fxSoc += force * sx / sd;
        fySoc += force * sy / sd;
        if (sd < rr) {
          var overlap = rr - sd;
          fxSoc += 200 * overlap * sx / sd;
          fySoc += 200 * overlap * sy / sd;
        }
      }
    }

    var fxW = 0, fyW = 0;
    for (var j = 0; j < allWalls.length; j++) {
      var wn = wallNormal(a.x, a.y, allWalls[j]);
      if (wn.d < a.r * 10) {
        var wf = Aw * Math.exp((a.r - wn.d) / Bw);
        fxW += wf * wn.nx;
        fyW += wf * wn.ny;
        if (wn.d < a.r) {
          fxW += 500 * (a.r - wn.d) * wn.nx;
          fyW += 500 * (a.r - wn.d) * wn.ny;
        }
      }
    }

    var fx = fxDes + fxSoc + fxW + (Math.random() - 0.5) * 2;
    var fy = fyDes + fySoc + fyW + (Math.random() - 0.5) * 2;

    a.vx += fx * dt;
    a.vy += fy * dt;

    var speed = Math.sqrt(a.vx * a.vx + a.vy * a.vy);
    var maxSpd = spd * 2.5;
    if (speed > maxSpd) {
      a.vx = a.vx / speed * maxSpd;
      a.vy = a.vy / speed * maxSpd;
    }

    a.x += a.vx * dt * 1000;
    a.y += a.vy * dt * 1000;

    a.trail.push({ x: a.x, y: a.y });
    if (a.trail.length > 20) a.trail.shift();
  }

  var now = performance.now();
  var elapsed = (now - flowTime) / 1000;
  if (elapsed > 1) {
    flowRate = Math.round(flowCount / elapsed);
    flowCount = 0;
    flowTime = now;
  }

  computeLanes();
}

function computeLanes() {
  if (mode !== 'corridor' || agents.length < 10) {
    laneScore = 0;
    return;
  }
  var corridorH = H * 0.35;
  var top = (H - corridorH) / 2;
  var strips = 8;
  var stripH = corridorH / strips;
  var correct = 0, total = 0;

  for (var i = 0; i < agents.length; i++) {
    var a = agents[i];
    var strip = Math.floor((a.y - top) / stripH);
    if (strip < 0 || strip >= strips) continue;
    total++;
    var neighbors = 0, sameDir = 0;
    for (var j = 0; j < agents.length; j++) {
      if (i === j) continue;
      var b = agents[j];
      var bs = Math.floor((b.y - top) / stripH);
      if (bs === strip) {
        neighbors++;
        if (a.group === b.group) sameDir++;
      }
    }
    if (neighbors > 0 && sameDir / neighbors > 0.6) correct++;
  }
  laneScore = total > 0 ? correct / total : 0;
}

function draw() {
  ctx.fillStyle = 'rgba(10,10,15,0.25)';
  ctx.fillRect(0, 0, W, H);

  var allWalls = walls.concat(userWalls);
  ctx.strokeStyle = 'rgba(255,255,255,0.25)';
  ctx.lineWidth = 2;
  for (var i = 0; i < allWalls.length; i++) {
    var w = allWalls[i];
    ctx.beginPath();
    ctx.moveTo(w.x1, w.y1);
    ctx.lineTo(w.x2, w.y2);
    ctx.stroke();
  }

  for (var i = 0; i < agents.length; i++) {
    var a = agents[i];
    var c = groupColors[a.group % groupColors.length];

    if (a.trail.length > 2) {
      ctx.beginPath();
      ctx.moveTo(a.trail[0].x, a.trail[0].y);
      for (var j = 1; j < a.trail.length; j++) {
        ctx.lineTo(a.trail[j].x, a.trail[j].y);
      }
      ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.12)';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }

    ctx.beginPath();
    ctx.arc(a.x, a.y, a.r, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.85)';
    ctx.fill();

    var speed = Math.sqrt(a.vx * a.vx + a.vy * a.vy);
    if (speed > 0.02) {
      ctx.beginPath();
      ctx.arc(a.x, a.y, a.r + 2, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.2)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }
  }

  hudAgents.textContent = agents.length;
  if (mode === 'corridor') {
    hudLanes.textContent = Math.round(laneScore * 100) + '%';
  } else {
    hudLanes.textContent = '—';
  }
  hudFlow.textContent = flowRate;
}

var lastTime = 0;
function loop(t) {
  var dt = lastTime ? (t - lastTime) / 1000 : 0.016;
  lastTime = t;
  update(dt);
  draw();
  requestAnimationFrame(loop);
}

resize();
initScene();
requestAnimationFrame(loop);

window.addEventListener('resize', function() {
  resize();
  initScene();
});

var isDrawing = false;
var drawStart = null;
var isErasing = false;

function getPos(e) {
  if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
  return { x: e.clientX, y: e.clientY };
}

C.addEventListener('mousedown', function(e) {
  if (e.button === 2) {
    isErasing = true;
    eraseNear(e.clientX, e.clientY);
    return;
  }
  isDrawing = true;
  drawStart = { x: e.clientX, y: e.clientY };
});

C.addEventListener('mousemove', function(e) {
  if (isErasing) {
    eraseNear(e.clientX, e.clientY);
    return;
  }
  if (!isDrawing || !drawStart) return;
  var p = { x: e.clientX, y: e.clientY };
  var dx = p.x - drawStart.x, dy = p.y - drawStart.y;
  if (Math.sqrt(dx * dx + dy * dy) > 15) {
    userWalls.push(new Wall(drawStart.x, drawStart.y, p.x, p.y));
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
    drawStart = p;
  }
});

C.addEventListener('mouseup', function(e) {
  if (isDrawing && drawStart) {
    var p = { x: e.clientX, y: e.clientY };
    var dx = p.x - drawStart.x, dy = p.y - drawStart.y;
    if (Math.sqrt(dx * dx + dy * dy) > 5) {
      userWalls.push(new Wall(drawStart.x, drawStart.y, p.x, p.y));
    }
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  isDrawing = false;
  isErasing = false;
  drawStart = null;
});

C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

var touchStart = null;
var touchLong = false;
var touchTimer = null;

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 2) {
    isErasing = true;
    var p = getPos(e);
    eraseNear(p.x, p.y);
    return;
  }
  var p = getPos(e);
  isDrawing = true;
  drawStart = p;
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (isErasing && e.touches.length >= 2) {
    eraseNear(e.touches[0].clientX, e.touches[0].clientY);
    return;
  }
  if (!isDrawing || !drawStart) return;
  var p = getPos(e);
  var dx = p.x - drawStart.x, dy = p.y - drawStart.y;
  if (Math.sqrt(dx * dx + dy * dy) > 12) {
    userWalls.push(new Wall(drawStart.x, drawStart.y, p.x, p.y));
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
    drawStart = p;
  }
}, { passive: false });

C.addEventListener('touchend', function(e) {
  if (isDrawing && drawStart) {
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
  isDrawing = false;
  isErasing = false;
  drawStart = null;
});

function eraseNear(x, y) {
  var r = 50;
  var before = userWalls.length;
  userWalls = userWalls.filter(function(w) {
    var cx = (w.x1 + w.x2) / 2, cy = (w.y1 + w.y2) / 2;
    var dx = cx - x, dy = cy - y;
    return Math.sqrt(dx * dx + dy * dy) > r;
  });
  if (userWalls.length < before && typeof SFX !== 'undefined') {
    SFX.play('pop');
  }
}

function setMode(m) {
  mode = m;
  document.getElementById('btn-corridor').classList.toggle('active', m === 'corridor');
  document.getElementById('btn-exit').classList.toggle('active', m === 'exit');
  document.getElementById('btn-cross').classList.toggle('active', m === 'cross');
  initScene();
  if (typeof SFX !== 'undefined') SFX.play('toggle');
}

document.getElementById('btn-corridor').addEventListener('click', function() { setMode('corridor'); });
document.getElementById('btn-exit').addEventListener('click', function() { setMode('exit'); });
document.getElementById('btn-cross').addEventListener('click', function() { setMode('cross'); });
document.getElementById('btn-reset').addEventListener('click', function() {
  initScene();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

var slCount = document.getElementById('sl-count');
var slSpeed = document.getElementById('sl-speed');
var slRepulse = document.getElementById('sl-repulse');
var svCount = document.getElementById('sv-count');
var svSpeed = document.getElementById('sv-speed');
var svRepulse = document.getElementById('sv-repulse');

slCount.addEventListener('input', function() {
  agentCount = +this.value;
  svCount.textContent = this.value;
  initScene();
});
slSpeed.addEventListener('input', function() {
  desiredSpeed = +this.value;
  svSpeed.textContent = this.value;
});
slRepulse.addEventListener('input', function() {
  repulseStr = +this.value;
  svRepulse.textContent = this.value;
});

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
