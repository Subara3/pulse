<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('truss');

$pageName = 'truss';
$pageTitle = 'TRUSS - PULSE';
$pageDesc = '節点と梁で橋を組んで、重りを渡らせる。力が色に変わり、限界を超えた梁が切れて崩れる。';
$pageCanonical = 'https://pulse.subara3.com/truss.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.hud .warn{color:#b58cff}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">TRUSS</span><br>BEAMS <b id="h-beams">0</b><br>STRAIN <b id="h-strain">&mdash;</b><br>GOAL <b id="h-goal">BUILD</b></div>
<div class="mark">TRUSS</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'node'],
  ['type' => 'drag', 'label' => 'beam'],
  ['type' => 'right', 'label' => 'delete'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="mode-btn active" id="btn-build">BUILD</button>
      <button class="mode-btn" id="btn-test">TEST</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label class="ctrl-label">WEIGHT</label>
      <input type="range" id="sl-weight" min="1" max="20" value="6">
      <span class="slider-value" id="sv-weight">6</span>
    </div>
    <div class="slider-row">
      <label class="ctrl-label">STRENGTH</label>
      <input type="range" id="sl-str" min="10" max="80" value="35">
      <span class="slider-value" id="sv-str">35</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-preset">PRESET</button>
      <button id="btn-clear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){ 'use strict';

var c = document.getElementById('canvas'), ctx = c.getContext('2d');
var W, H, dpr;
var TEAL = [0, 245, 212], PURPLE = [123, 44, 191];
var GRAVITY = 0.35, ITERS = 16, NODE_R = 5, SNAP = 28, BALL_R = 10;
var nodes = [], beams = [], parts = [];
var mode = 'build', simOn = false;
var groundY, pLX, pRX, pTopY, pW;
var mx = 0, my = 0, hovN = -1, connFrom = -1, downX = 0, downY = 0, isDown = false;
var ball = null, ballDelay = 0, ballDone = false;
var breakTh = 0.35, ballWt = 6;

function resize() {
  dpr = window.devicePixelRatio || 1;
  W = window.innerWidth; H = window.innerHeight;
  c.width = W * dpr; c.height = H * dpr;
  c.style.width = W + 'px'; c.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  groundY = H * 0.78;
  pW = Math.max(30, W * 0.035);
  pLX = W * 0.18; pRX = W * 0.82;
  pTopY = H * 0.50;
}

function rgba(co, a) { return 'rgba('+(co[0]|0)+','+(co[1]|0)+','+(co[2]|0)+','+a+')'; }
function mix(a, b, t) { return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t]; }
function dist(x1,y1,x2,y2) { var dx=x2-x1,dy=y2-y1; return Math.sqrt(dx*dx+dy*dy); }

function addNode(x, y, fixed) {
  nodes.push({x:x, y:y, px:x, py:y, fixed:!!fixed});
  return nodes.length - 1;
}

function addBeam(a, b) {
  for (var i = 0; i < beams.length; i++) {
    var B = beams[i];
    if ((B.a===a && B.b===b)||(B.a===b && B.b===a)) return -1;
  }
  var d = dist(nodes[a].x, nodes[a].y, nodes[b].x, nodes[b].y);
  if (d < 5) return -1;
  beams.push({a:a, b:b, len:d, broken:false, stress:0});
  return beams.length - 1;
}

function findNode(x, y) {
  var best = -1, bd = SNAP;
  for (var i = 0; i < nodes.length; i++) {
    var d = dist(nodes[i].x, nodes[i].y, x, y);
    if (d < bd) { bd = d; best = i; }
  }
  return best;
}

function findBeam(x, y) {
  var best = -1, bd = 12;
  for (var i = 0; i < beams.length; i++) {
    if (beams[i].broken) continue;
    var na = nodes[beams[i].a], nb = nodes[beams[i].b];
    var dx = nb.x - na.x, dy = nb.y - na.y;
    var l2 = dx*dx + dy*dy;
    if (l2 < 1) continue;
    var t = Math.max(0, Math.min(1, ((x-na.x)*dx+(y-na.y)*dy)/l2));
    var px = na.x+t*dx, py = na.y+t*dy;
    var d = dist(x, y, px, py);
    if (d < bd) { bd = d; best = i; }
  }
  return best;
}

function delNode(idx) {
  for (var i = beams.length - 1; i >= 0; i--) {
    if (beams[i].a === idx || beams[i].b === idx) beams.splice(i, 1);
  }
  for (var i = 0; i < beams.length; i++) {
    if (beams[i].a > idx) beams[i].a--;
    if (beams[i].b > idx) beams[i].b--;
  }
  nodes.splice(idx, 1);
}

function activeBeams() {
  var c = 0;
  for (var i = 0; i < beams.length; i++) if (!beams[i].broken) c++;
  return c;
}

function loadPreset() {
  nodes = []; beams = []; parts = [];
  ball = null; ballDone = false; simOn = false; mode = 'build';

  var gap = pRX - pLX, depth = gap / 5.5, seg = 4;
  var top = [], bot = [];
  for (var i = 0; i <= seg; i++) {
    var x = pLX + gap * i / seg;
    top.push(addNode(x, pTopY, i === 0 || i === seg));
  }
  for (var i = 0; i < seg; i++) {
    var x = pLX + gap * (i + 0.5) / seg;
    bot.push(addNode(x, pTopY + depth, false));
  }
  for (var i = 0; i < seg; i++) addBeam(top[i], top[i+1]);
  for (var i = 0; i < seg - 1; i++) addBeam(bot[i], bot[i+1]);
  for (var i = 0; i < seg; i++) {
    addBeam(top[i], bot[i]);
    addBeam(bot[i], top[i+1]);
  }

  updateBtns();
  updateHud();
}

function clearAll() {
  nodes = []; beams = []; parts = [];
  ball = null; ballDone = false; simOn = false; mode = 'build';
  addNode(pLX, pTopY, true);
  addNode(pRX, pTopY, true);
  updateBtns();
  updateHud();
}

function physics() {
  if (!simOn) return;
  for (var i = 0; i < nodes.length; i++) {
    var n = nodes[i];
    if (n.fixed) continue;
    var vx = (n.x - n.px) * 0.997, vy = (n.y - n.py) * 0.997;
    n.px = n.x; n.py = n.y;
    n.x += vx; n.y += vy + GRAVITY;
  }
  for (var it = 0; it < ITERS; it++) {
    for (var i = 0; i < beams.length; i++) {
      var B = beams[i];
      if (B.broken) continue;
      var na = nodes[B.a], nb = nodes[B.b];
      var dx = nb.x - na.x, dy = nb.y - na.y;
      var d = Math.sqrt(dx*dx + dy*dy);
      if (d < 0.001) continue;
      var diff = (d - B.len) / d * 0.5;
      var ox = dx * diff, oy = dy * diff;
      if (!na.fixed) { na.x += ox; na.y += oy; }
      if (!nb.fixed) { nb.x -= ox; nb.y -= oy; }
    }
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].fixed) continue;
      if (nodes[i].y > groundY) { nodes[i].y = groundY; if (nodes[i].py > groundY) nodes[i].py = groundY; }
    }
  }
  for (var i = beams.length - 1; i >= 0; i--) {
    var B = beams[i];
    if (B.broken) continue;
    var na = nodes[B.a], nb = nodes[B.b];
    var d = dist(na.x, na.y, nb.x, nb.y);
    B.stress = Math.abs(d - B.len) / B.len;
    if (B.stress > breakTh) {
      B.broken = true;
      spawnParts(na, nb);
      SFX.play('fail');
    }
  }
}

function startBall() {
  ball = {x: pLX - BALL_R, y: pTopY - BALL_R - 2, vy: 0, speed: Math.max(1, W/600),
          active: true, success: false, failed: false, onBeam: false};
  ballDelay = 60; ballDone = false;
}

function updateBall() {
  if (!ball || !ball.active) return;
  if (ballDelay > 0) { ballDelay--; return; }

  ball.x += ball.speed;
  var sup = null, supY = 1e9;

  for (var i = 0; i < beams.length; i++) {
    var B = beams[i];
    if (B.broken) continue;
    var na = nodes[B.a], nb = nodes[B.b];
    var x1 = na.x, y1 = na.y, x2 = nb.x, y2 = nb.y;
    var mnx = Math.min(x1,x2) - 3, mxx = Math.max(x1,x2) + 3;
    if (ball.x < mnx || ball.x > mxx) continue;
    var t = Math.abs(x2-x1) < 0.5 ? 0.5 : (ball.x-x1)/(x2-x1);
    t = Math.max(0, Math.min(1, t));
    var by = y1 + t * (y2 - y1);
    if (by < supY && by <= ball.y + BALL_R + 8) {
      supY = by; sup = {beam: B, y: by, t: t, na: na, nb: nb};
    }
  }

  if (sup) {
    ball.y = sup.y - BALL_R;
    ball.vy = 0;
    ball.onBeam = true;
    var f = ballWt * 0.25;
    if (!sup.na.fixed) { sup.na.y += f * (1 - sup.t); }
    if (!sup.nb.fixed) { sup.nb.y += f * sup.t; }
    SFX.play('move', 300);
  } else {
    ball.vy += GRAVITY;
    ball.y += ball.vy;
    ball.onBeam = false;
  }

  if (ball.x >= pRX + BALL_R * 2) {
    ball.active = false; ball.success = true;
    if (!ballDone) { SFX.play('complete'); ballDone = true; if (window.PULSE) PULSE.clear(); }
  }
  if (ball.y > H + 50) {
    ball.active = false; ball.failed = true;
    if (!ballDone) { SFX.play('fail'); ballDone = true; }
  }
}

function spawnParts(na, nb) {
  var cx = (na.x+nb.x)/2, cy = (na.y+nb.y)/2;
  for (var i = 0; i < 10; i++) {
    var a = Math.random()*Math.PI*2, s = 1+Math.random()*3;
    parts.push({x:cx, y:cy, vx:Math.cos(a)*s, vy:Math.sin(a)*s-2, life:1});
  }
}

function updateParts() {
  for (var i = parts.length - 1; i >= 0; i--) {
    var p = parts[i];
    p.x += p.vx; p.y += p.vy; p.vy += 0.12; p.life -= 0.018;
    if (p.life <= 0) parts.splice(i, 1);
  }
}

function draw() {
  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var gs = 40;
  ctx.fillStyle = 'rgba(255,255,255,0.025)';
  for (var gx = gs; gx < W; gx += gs)
    for (var gy = gs; gy < H; gy += gs)
      ctx.fillRect(gx-0.5, gy-0.5, 1, 1);

  ctx.fillStyle = 'rgba(0,245,212,0.04)';
  ctx.fillRect(0, groundY, W, H - groundY);
  ctx.strokeStyle = 'rgba(0,245,212,0.15)';
  ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(0, groundY); ctx.lineTo(W, groundY); ctx.stroke();

  var pH = groundY - pTopY;
  ctx.fillStyle = 'rgba(0,245,212,0.06)';
  ctx.strokeStyle = 'rgba(0,245,212,0.2)';
  ctx.lineWidth = 1;
  ctx.fillRect(pLX - pW, pTopY, pW, pH);
  ctx.strokeRect(pLX - pW, pTopY, pW, pH);
  ctx.fillRect(pRX, pTopY, pW, pH);
  ctx.strokeRect(pRX, pTopY, pW, pH);

  if (mode === 'build') {
    ctx.fillStyle = 'rgba(123,44,191,0.03)';
    ctx.fillRect(pLX, pTopY, pRX - pLX, pH);
  }

  for (var i = 0; i < beams.length; i++) {
    var B = beams[i];
    if (B.broken) continue;
    var na = nodes[B.a], nb = nodes[B.b];
    var col, lw;
    if (simOn) {
      var t = Math.min(B.stress / breakTh, 1);
      col = mix(TEAL, PURPLE, t);
      lw = 2 + t * 2.5;
      if (t > 0.6) {
        ctx.strokeStyle = rgba(PURPLE, 0.12 + 0.08 * Math.sin(Date.now()*0.01));
        ctx.lineWidth = lw + 6;
        ctx.beginPath(); ctx.moveTo(na.x, na.y); ctx.lineTo(nb.x, nb.y); ctx.stroke();
      }
    } else {
      col = TEAL; lw = 2;
    }
    ctx.strokeStyle = rgba(col, 0.85);
    ctx.lineWidth = lw;
    ctx.beginPath(); ctx.moveTo(na.x, na.y); ctx.lineTo(nb.x, nb.y); ctx.stroke();
  }

  if (connFrom >= 0 && mode === 'build' && isDown) {
    ctx.strokeStyle = rgba(TEAL, 0.3);
    ctx.lineWidth = 1;
    ctx.setLineDash([5, 5]);
    ctx.beginPath();
    ctx.moveTo(nodes[connFrom].x, nodes[connFrom].y);
    ctx.lineTo(mx, my);
    ctx.stroke();
    ctx.setLineDash([]);
  }

  for (var i = 0; i < nodes.length; i++) {
    var n = nodes[i], r = NODE_R;
    if (n.fixed) {
      ctx.fillStyle = rgba(TEAL, 0.2);
      ctx.beginPath();
      ctx.moveTo(n.x, n.y + r + 2);
      ctx.lineTo(n.x - r - 5, n.y + r + 15);
      ctx.lineTo(n.x + r + 5, n.y + r + 15);
      ctx.closePath(); ctx.fill();
    }
    var hi = (i === hovN && mode === 'build');
    var nc = hi ? [255,255,255] : TEAL;
    var na = hi ? 1 : 0.65;
    ctx.fillStyle = rgba(nc, 0.12);
    ctx.beginPath(); ctx.arc(n.x, n.y, r + 3, 0, Math.PI*2); ctx.fill();
    ctx.strokeStyle = rgba(nc, na);
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI*2); ctx.stroke();
    ctx.fillStyle = rgba(nc, na * 0.6);
    ctx.beginPath(); ctx.arc(n.x, n.y, 2, 0, Math.PI*2); ctx.fill();
  }

  if (ball) {
    ctx.fillStyle = rgba(TEAL, 0.08);
    ctx.beginPath(); ctx.arc(ball.x, ball.y, BALL_R + 6, 0, Math.PI*2); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,0.88)';
    ctx.beginPath(); ctx.arc(ball.x, ball.y, BALL_R, 0, Math.PI*2); ctx.fill();
    ctx.strokeStyle = rgba(TEAL, 0.5);
    ctx.lineWidth = 1.5;
    ctx.stroke();
  }

  for (var i = 0; i < parts.length; i++) {
    var p = parts[i];
    ctx.fillStyle = rgba(PURPLE, p.life * 0.7);
    ctx.fillRect(p.x - 2, p.y - 2, 4, 4);
  }

  if (ball && !ball.active) {
    ctx.font = '600 15px "Space Grotesk", sans-serif';
    ctx.textAlign = 'center';
    ctx.letterSpacing = '2px';
    if (ball.success) {
      ctx.fillStyle = rgba(TEAL, 0.85);
      ctx.fillText('CROSSED', W/2, pTopY - 30);
    } else if (ball.failed) {
      ctx.fillStyle = rgba(PURPLE, 0.85);
      ctx.fillText('COLLAPSED', W/2, pTopY - 30);
    }
    ctx.letterSpacing = '0px';
  }
}

function updateHud() {
  document.getElementById('h-beams').textContent = activeBeams();
  if (simOn) {
    var ms = 0;
    for (var i = 0; i < beams.length; i++)
      if (!beams[i].broken && beams[i].stress > ms) ms = beams[i].stress;
    var el = document.getElementById('h-strain');
    el.textContent = (ms / breakTh * 100).toFixed(0) + '%';
    el.style.color = ms > breakTh * 0.7 ? '#b58cff' : '#00f5d4';
    document.getElementById('h-goal').textContent = ball && ball.success ? 'CROSSED' :
      ball && ball.failed ? 'FAIL' : 'TESTING';
  } else {
    document.getElementById('h-strain').textContent = '—';
    document.getElementById('h-strain').style.color = '#00f5d4';
    document.getElementById('h-goal').textContent = 'BUILD';
  }
}

function ptrDown(x, y, right) {
  if (right) {
    if (mode !== 'build') return;
    var ni = findNode(x, y);
    if (ni >= 0 && !nodes[ni].fixed) { delNode(ni); SFX.play('pop'); updateHud(); return; }
    var bi = findBeam(x, y);
    if (bi >= 0) { beams.splice(bi, 1); SFX.play('pop'); updateHud(); return; }
    return;
  }
  if (mode !== 'build') return;
  isDown = true; downX = x; downY = y;
  var ni = findNode(x, y);
  connFrom = ni >= 0 ? ni : -1;
  if (ni < 0) {
    connFrom = addNode(x, y, false);
    SFX.play('spawn');
    updateHud();
  }
}

function ptrMove(x, y) {
  mx = x; my = y;
  if (mode === 'build') hovN = findNode(x, y);
}

function ptrUp(x, y) {
  if (!isDown || mode !== 'build') { isDown = false; connFrom = -1; return; }
  isDown = false;
  if (connFrom >= 0) {
    var target = findNode(x, y);
    if (target >= 0 && target !== connFrom) {
      if (addBeam(connFrom, target) >= 0) SFX.play('tick');
    } else if (target < 0 && dist(x, y, nodes[connFrom].x, nodes[connFrom].y) > 15) {
      var ni = addNode(x, y, false);
      addBeam(connFrom, ni);
      SFX.play('tick');
    }
  }
  connFrom = -1;
  updateHud();
}

c.addEventListener('mousedown', function(e) {
  e.preventDefault();
  var r = c.getBoundingClientRect();
  ptrDown(e.clientX - r.left, e.clientY - r.top, e.button === 2);
});
c.addEventListener('mousemove', function(e) {
  var r = c.getBoundingClientRect();
  ptrMove(e.clientX - r.left, e.clientY - r.top);
});
c.addEventListener('mouseup', function(e) {
  var r = c.getBoundingClientRect();
  ptrUp(e.clientX - r.left, e.clientY - r.top);
});
c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

var tId = null;
c.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var t = e.touches[0]; tId = t.identifier;
  var r = c.getBoundingClientRect();
  ptrDown(t.clientX - r.left, t.clientY - r.top, false);
}, {passive: false});
c.addEventListener('touchmove', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.touches.length; i++) {
    if (e.touches[i].identifier === tId) {
      var r = c.getBoundingClientRect();
      ptrMove(e.touches[i].clientX - r.left, e.touches[i].clientY - r.top);
      break;
    }
  }
}, {passive: false});
c.addEventListener('touchend', function(e) {
  e.preventDefault();
  for (var i = 0; i < e.changedTouches.length; i++) {
    if (e.changedTouches[i].identifier === tId) {
      var r = c.getBoundingClientRect();
      ptrUp(e.changedTouches[i].clientX - r.left, e.changedTouches[i].clientY - r.top);
      tId = null; break;
    }
  }
}, {passive: false});

function updateBtns() {
  document.getElementById('btn-build').classList.toggle('active', mode === 'build');
  document.getElementById('btn-test').classList.toggle('active', mode === 'test');
}

function setMode(m) {
  mode = m;
  if (m === 'test') {
    simOn = true;
    for (var i = 0; i < beams.length; i++) {
      beams[i].broken = false;
      beams[i].stress = 0;
      beams[i].len = dist(nodes[beams[i].a].x, nodes[beams[i].a].y,
                          nodes[beams[i].b].x, nodes[beams[i].b].y);
    }
    startBall();
    SFX.play('toggle');
  } else {
    simOn = false; ball = null; ballDone = false;
    for (var i = beams.length - 1; i >= 0; i--) {
      if (beams[i].broken) beams.splice(i, 1);
    }
    SFX.play('toggle');
  }
  updateBtns(); updateHud();
}

document.getElementById('btn-build').onclick = function() { setMode('build'); };
document.getElementById('btn-test').onclick = function() { setMode('test'); };
document.getElementById('btn-preset').onclick = function() { loadPreset(); SFX.play('click'); };
document.getElementById('btn-clear').onclick = function() { clearAll(); SFX.play('click'); };
document.getElementById('sl-weight').oninput = function() {
  ballWt = +this.value;
  document.getElementById('sv-weight').textContent = this.value;
};
document.getElementById('sl-str').oninput = function() {
  breakTh = +this.value / 100;
  document.getElementById('sv-str').textContent = this.value;
};

function frame() {
  physics();
  updateBall();
  updateParts();
  draw();
  updateHud();
  requestAnimationFrame(frame);
}

resize();
window.addEventListener('resize', function() { resize(); });
loadPreset();
requestAnimationFrame(frame);

})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
