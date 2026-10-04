<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chemotaxis');

$pageName = 'chemotaxis';
$pageTitle = 'CHEMOTAXIS - PULSE';
$pageDesc = '匂いの元を置くと、まっすぐ泳いでは向きを変える細菌がゆっくり集まってくる。匂いに向かうときだけ方向転換が減る。たったそれだけの偏りで群れが生まれる。';
$pageCanonical = 'https://pulse.subara3.com/chemotaxis.php';
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
  <span class="sub" id="h-mode">SWARM</span><br>
  BACTERIA <b id="h-count">250</b><br>
  SOURCES <b id="h-src">2</b><br>
  <span id="h-extra"></span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'source'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row"><label class="slider-label">MODE</label></div>
  <div class="btn-row">
    <button class="control-btn active" data-mode="SWARM">SWARM</button>
    <button class="control-btn" data-mode="VS">VS</button>
    <button class="control-btn" data-mode="GOAL">GOAL</button>
  </div>
  <div class="slider-row">
    <span class="slider-label">SENSITIVITY</span>
    <input type="range" id="sl-sens" min="1" max="10" value="5" step="1">
    <span class="slider-value" id="sv-sens">5</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">BACTERIA</span>
    <input type="range" id="sl-count" min="50" max="500" value="250" step="50">
    <span class="slider-value" id="sv-count">250</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-reset">RESET</button>
    <button class="control-btn" id="btn-clear">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, dpr;

var sources = [];
var bacteria = [];
var mode = 'SWARM';
var sens = 5;
var bCount = 250;
var SPD = 2;
var BASE_P = 0.025;
var SIGMA = 130;
var MAX_SRC = 8;
var TUMBLE_DUR = 4;

var CELL = 12;
var fW, fH, fld;
var fCV, fCX;
var fDirty = true;
var fImg = null;

var goalX, goalY, goalR = 70, goalPct = 0, goalDone = false;
var dragIdx = -1, dragging = false;
var frame = 0;
var t0 = performance.now();

function resize() {
  dpr = window.devicePixelRatio || 1;
  W = C.width = window.innerWidth * dpr;
  H = C.height = window.innerHeight * dpr;
  C.style.width = window.innerWidth + 'px';
  C.style.height = window.innerHeight + 'px';
  fW = Math.ceil(W / CELL);
  fH = Math.ceil(H / CELL);
  fCV = document.createElement('canvas');
  fCV.width = fW; fCV.height = fH;
  fCX = fCV.getContext('2d');
  fld = new Float32Array(fW * fH);
  fDirty = true;
}

function initSources() {
  sources = [
    {x: W * 0.3, y: H * 0.45, s: 1},
    {x: W * 0.7, y: H * 0.55, s: 1}
  ];
  fDirty = true;
}

function makeBact(grp) {
  return {
    x: Math.random() * W,
    y: Math.random() * H,
    a: Math.random() * Math.PI * 2,
    run: true,
    tt: 0,
    grp: grp || 0,
    tr: []
  };
}

function initBacteria() {
  bacteria = [];
  goalDone = false;
  goalPct = 0;
  if (mode === 'VS') {
    var half = Math.floor(bCount / 2);
    for (var i = 0; i < half; i++) bacteria.push(makeBact(0));
    for (var i = 0; i < half; i++) bacteria.push(makeBact(1));
  } else {
    for (var i = 0; i < bCount; i++) bacteria.push(makeBact(0));
  }
  if (mode === 'GOAL') {
    goalX = W * (0.3 + Math.random() * 0.4);
    goalY = H * (0.3 + Math.random() * 0.4);
    goalR = Math.min(W, H) * 0.08;
    sources = [];
    fDirty = true;
  }
}

function computeField() {
  var s2 = SIGMA * SIGMA;
  for (var j = 0; j < fH; j++) {
    for (var i = 0; i < fW; i++) {
      var px = (i + 0.5) * CELL;
      var py = (j + 0.5) * CELL;
      var c = 0;
      for (var k = 0; k < sources.length; k++) {
        var dx = px - sources[k].x, dy = py - sources[k].y;
        c += sources[k].s / (1 + (dx * dx + dy * dy) / s2);
      }
      fld[j * fW + i] = c;
    }
  }
  renderField();
  fDirty = false;
}

function renderField() {
  var id = fCX.createImageData(fW, fH);
  var d = id.data;
  var mx = 0;
  for (var i = 0; i < fld.length; i++) if (fld[i] > mx) mx = fld[i];
  if (mx < 0.001) mx = 1;
  for (var j = 0; j < fH; j++) {
    for (var i = 0; i < fW; i++) {
      var idx = (j * fW + i) * 4;
      var t = Math.min(fld[j * fW + i] / mx, 1);
      if (t < 0.005) { d[idx+3] = 0; continue; }
      var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                      : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
      d[idx]     = Math.round(80 * (1 - e));
      d[idx + 1] = Math.round(25 + e * 175);
      d[idx + 2] = Math.round(130 + e * 50);
      d[idx + 3] = Math.round(Math.pow(t, 0.6) * 0.55 * 255);
    }
  }
  fCX.putImageData(id, 0, 0);
  fImg = fCV;
}

function getConc(x, y) {
  var fi = x / CELL - 0.5, fj = y / CELL - 0.5;
  var i = fi | 0, j = fj | 0;
  if (i < 0) i = 0; if (i >= fW - 1) i = fW - 2;
  if (j < 0) j = 0; if (j >= fH - 1) j = fH - 2;
  var u = fi - i, v = fj - j;
  return fld[j*fW+i]*(1-u)*(1-v) + fld[j*fW+i+1]*u*(1-v)
       + fld[(j+1)*fW+i]*(1-u)*v + fld[(j+1)*fW+i+1]*u*v;
}

function updateBacteria() {
  var mult = sens * 20;
  for (var i = 0; i < bacteria.length; i++) {
    var b = bacteria[i];
    if (b.tt > 0) {
      b.tt--;
      b.a += (Math.random() - 0.5) * 1.2;
      continue;
    }
    var c0 = getConc(b.x, b.y);
    var sp = SPD * dpr;
    b.x += Math.cos(b.a) * sp;
    b.y += Math.sin(b.a) * sp;
    if (b.x < 0) b.x += W; if (b.x >= W) b.x -= W;
    if (b.y < 0) b.y += H; if (b.y >= H) b.y -= H;
    var c1 = getConc(b.x, b.y);
    var dc = c1 - c0;
    var m = b.grp === 1 ? mult * 0.15 : mult;
    var p = BASE_P * Math.exp(-m * dc);
    if (p > 0.35) p = 0.35;
    if (Math.random() < p) {
      b.a = Math.random() * Math.PI * 2;
      b.tt = TUMBLE_DUR;
      b.run = false;
    } else {
      b.run = true;
    }
    var maxTr = mode === 'VS' ? 8 : 12;
    b.tr.push(b.x); b.tr.push(b.y);
    if (b.tr.length > maxTr * 2) { b.tr.shift(); b.tr.shift(); }
  }
}

function nearSrc(px, py, r) {
  for (var i = 0; i < sources.length; i++) {
    var dx = px - sources[i].x, dy = py - sources[i].y;
    if (dx * dx + dy * dy < r * r) return i;
  }
  return -1;
}

function drawField() {
  if (fDirty) computeField();
  if (fImg) {
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(fImg, 0, 0, W, H);
  }
}

function drawSources() {
  var pulse = 0.5 + 0.3 * Math.sin(frame * 0.05);
  for (var i = 0; i < sources.length; i++) {
    var s = sources[i];
    ctx.beginPath();
    ctx.arc(s.x, s.y, 8 * dpr, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(0,245,212,' + (0.3 + pulse * 0.3) + ')';
    ctx.fill();
    ctx.beginPath();
    ctx.arc(s.x, s.y, (14 + pulse * 6) * dpr, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0,245,212,' + (0.15 + pulse * 0.15) + ')';
    ctx.lineWidth = 1.5 * dpr;
    ctx.stroke();
  }
}

function drawBacteria() {
  var sz = 3 * dpr;
  ctx.lineWidth = 1 * dpr;
  for (var g = 0; g <= (mode === 'VS' ? 1 : 0); g++) {
    var tealR = 0, tealG = 245, tealB = 212;
    var purR = 181, purG = 140, purB = 255;
    var cr = g === 0 ? tealR : purR;
    var cg = g === 0 ? tealG : purG;
    var cb = g === 0 ? tealB : purB;

    ctx.strokeStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.15)';
    ctx.beginPath();
    for (var i = 0; i < bacteria.length; i++) {
      var b = bacteria[i];
      if (b.grp !== g) continue;
      if (b.tr.length < 4) continue;
      ctx.moveTo(b.tr[b.tr.length - 4], b.tr[b.tr.length - 3]);
      ctx.lineTo(b.tr[b.tr.length - 2], b.tr[b.tr.length - 1]);
    }
    ctx.stroke();

    ctx.fillStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',0.85)';
    ctx.beginPath();
    for (var i = 0; i < bacteria.length; i++) {
      var b = bacteria[i];
      if (b.grp !== g) continue;
      ctx.moveTo(b.x + sz, b.y);
      ctx.arc(b.x, b.y, sz, 0, Math.PI * 2);
    }
    ctx.fill();

    for (var i = 0; i < bacteria.length; i++) {
      var b = bacteria[i];
      if (b.grp !== g) continue;
      if (!b.run) {
        ctx.beginPath();
        ctx.arc(b.x, b.y, sz + 2 * dpr, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(255,255,255,0.3)';
        ctx.lineWidth = 0.5 * dpr;
        ctx.stroke();
      }
    }
  }
}

function drawGoal() {
  if (mode !== 'GOAL') return;
  ctx.beginPath();
  ctx.arc(goalX, goalY, goalR * dpr, 0, Math.PI * 2);
  ctx.strokeStyle = 'rgba(181,140,255,0.5)';
  ctx.lineWidth = 2 * dpr;
  ctx.setLineDash([6 * dpr, 4 * dpr]);
  ctx.stroke();
  ctx.setLineDash([]);

  ctx.font = (11 * dpr) + 'px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.fillStyle = 'rgba(181,140,255,0.7)';
  ctx.textAlign = 'center';
  ctx.fillText('TARGET', goalX, goalY - goalR * dpr - 8 * dpr);

  var inside = 0;
  for (var i = 0; i < bacteria.length; i++) {
    var dx = bacteria[i].x - goalX, dy = bacteria[i].y - goalY;
    if (dx * dx + dy * dy < goalR * goalR * dpr * dpr) inside++;
  }
  goalPct = bacteria.length > 0 ? Math.round(inside / bacteria.length * 100) : 0;

  ctx.fillStyle = goalPct >= 80 ? 'rgba(0,245,212,0.9)' : 'rgba(255,255,255,0.6)';
  ctx.fillText(goalPct + '%', goalX, goalY + 5 * dpr);

  if (goalPct >= 80 && !goalDone) {
    goalDone = true;
    SFX.play('complete');
    if (window.PULSE) PULSE.clear();
  }
}

function drawVsHud() {
  if (mode !== 'VS' || sources.length === 0) return;
  var near0 = 0, near1 = 0, r2 = SIGMA * SIGMA * 1.5;
  for (var i = 0; i < bacteria.length; i++) {
    var b = bacteria[i];
    for (var j = 0; j < sources.length; j++) {
      var dx = b.x - sources[j].x, dy = b.y - sources[j].y;
      if (dx * dx + dy * dy < r2) {
        if (b.grp === 0) near0++; else near1++;
        break;
      }
    }
  }
  var total0 = 0, total1 = 0;
  for (var i = 0; i < bacteria.length; i++) {
    if (bacteria[i].grp === 0) total0++; else total1++;
  }
  var p0 = total0 > 0 ? Math.round(near0 / total0 * 100) : 0;
  var p1 = total1 > 0 ? Math.round(near1 / total1 * 100) : 0;
  document.getElementById('h-extra').innerHTML =
    '<span style="color:#00f5d4">HIGH ' + p0 + '%</span> · <span class="sub">LOW ' + p1 + '%</span>';
}

function updateHud() {
  document.getElementById('h-mode').textContent = mode;
  document.getElementById('h-count').textContent = bacteria.length;
  document.getElementById('h-src').textContent = sources.length;
  if (mode === 'GOAL') {
    document.getElementById('h-extra').innerHTML =
      (goalDone ? '<b>DONE</b> ' : '') + 'IN TARGET <b>' + goalPct + '%</b> / 80%';
  } else if (mode !== 'VS') {
    document.getElementById('h-extra').textContent = '';
  }
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  drawField();
  drawBacteria();
  drawSources();
  drawGoal();
  if (mode === 'VS') drawVsHud();
  updateHud();
}

function loop() {
  frame++;
  updateBacteria();
  draw();
  requestAnimationFrame(loop);
}

function evPos(e) {
  var r = C.getBoundingClientRect();
  var t = e.touches ? e.touches[0] : e;
  return {x: (t.clientX - r.left) * dpr, y: (t.clientY - r.top) * dpr};
}

function onDown(e) {
  if (e.button === 2) return;
  e.preventDefault();
  var p = evPos(e);
  var hit = nearSrc(p.x, p.y, 30 * dpr);
  if (hit >= 0) {
    dragIdx = hit;
    dragging = false;
  } else {
    dragIdx = -1;
  }
}

function onMove(e) {
  if (dragIdx < 0) return;
  e.preventDefault();
  dragging = true;
  var p = evPos(e);
  sources[dragIdx].x = p.x;
  sources[dragIdx].y = p.y;
  fDirty = true;
  SFX.play('move', 100);
}

function onUp(e) {
  if (e.button === 2) return;
  e.preventDefault();
  var p;
  if (e.changedTouches) {
    p = {x: (e.changedTouches[0].clientX - C.getBoundingClientRect().left) * dpr,
         y: (e.changedTouches[0].clientY - C.getBoundingClientRect().top) * dpr};
  } else {
    p = {x: (e.clientX - C.getBoundingClientRect().left) * dpr,
         y: (e.clientY - C.getBoundingClientRect().top) * dpr};
  }
  if (dragIdx >= 0 && !dragging) {
    dragIdx = -1;
    return;
  }
  if (dragIdx >= 0) {
    dragIdx = -1;
    fDirty = true;
    return;
  }
  if (sources.length < MAX_SRC) {
    sources.push({x: p.x, y: p.y, s: 1});
    fDirty = true;
    SFX.play('spawn');
  }
}

function onContext(e) {
  e.preventDefault();
  var r = C.getBoundingClientRect();
  var px = (e.clientX - r.left) * dpr;
  var py = (e.clientY - r.top) * dpr;
  var hit = nearSrc(px, py, 40 * dpr);
  if (hit >= 0) {
    sources.splice(hit, 1);
    fDirty = true;
    SFX.play('pop');
  }
}

C.addEventListener('mousedown', onDown);
C.addEventListener('mousemove', onMove);
C.addEventListener('mouseup', onUp);
C.addEventListener('touchstart', onDown, {passive: false});
C.addEventListener('touchmove', onMove, {passive: false});
C.addEventListener('touchend', onUp, {passive: false});
C.addEventListener('contextmenu', onContext);

var modeBtns = document.querySelectorAll('[data-mode]');
for (var i = 0; i < modeBtns.length; i++) {
  modeBtns[i].addEventListener('click', function() {
    mode = this.getAttribute('data-mode');
    for (var j = 0; j < modeBtns.length; j++) modeBtns[j].classList.remove('active');
    this.classList.add('active');
    SFX.play('toggle');
    initSources();
    initBacteria();
  });
}

document.getElementById('sl-sens').addEventListener('input', function() {
  sens = parseInt(this.value);
  document.getElementById('sv-sens').textContent = sens;
  SFX.play('tick', 50);
});

document.getElementById('sl-count').addEventListener('input', function() {
  bCount = parseInt(this.value);
  document.getElementById('sv-count').textContent = bCount;
  SFX.play('tick', 50);
  initBacteria();
});

document.getElementById('btn-reset').addEventListener('click', function() {
  SFX.play('click');
  initSources();
  initBacteria();
});

document.getElementById('btn-clear').addEventListener('click', function() {
  SFX.play('pop');
  sources = [];
  fDirty = true;
});

window.addEventListener('resize', function() {
  var oldW = W, oldH = H;
  resize();
  var sx = W / oldW, sy = H / oldH;
  for (var i = 0; i < sources.length; i++) {
    sources[i].x *= sx; sources[i].y *= sy;
  }
  for (var i = 0; i < bacteria.length; i++) {
    bacteria[i].x *= sx; bacteria[i].y *= sy;
    bacteria[i].tr = [];
  }
  if (mode === 'GOAL') { goalX *= sx; goalY *= sy; }
  fDirty = true;
});

resize();
initSources();
initBacteria();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
