<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fourcolor');

$pageName = 'fourcolor';
$pageTitle = 'FOUR COLOR - PULSE';
$pageDesc = '四色定理。平面グラフを4色で塗り分ける——バックトラッキングが衝突を見つけては戻り、必ず解にたどり着く。';
$pageCanonical = 'https://pulse.subara3.com/fourcolor.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
.mark{position:fixed;left:1rem;bottom:1rem;z-index:20;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.24em;color:rgba(255,255,255,.18)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}.mark{font-size:9px}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">FOUR COLOR</span><br>REGIONS <b id="h-n">0</b><br>STEPS <b id="h-steps">0</b><br>BACKTRACKS <b id="h-bt">0</b><br><span class="sub" id="h-state">READY</span></div>
<div class="mark">FOUR COLOR</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add point'],
  ['type' => 'drag', 'label' => 'move point'],
  ['type' => 'right', 'label' => 'regenerate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">REGIONS</span>
    <input type="range" id="sl-n" min="8" max="80" value="30" step="1">
    <span class="slider-value" id="sv-n">30</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="20" value="5">
    <span class="slider-value" id="sv-speed">5</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-solve">SOLVE</button>
    <button class="control-btn" id="btn-scramble">SCRAMBLE</button>
    <button class="control-btn" id="btn-regen">REGENERATE</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c = document.getElementById('canvas'), ctx = c.getContext('2d');
var W, H;

var COLORS = [
  [0, 200, 175],
  [100, 55, 190],
  [30, 160, 210],
  [160, 110, 230]
];

var sites = [];
var nTarget = 30;
var speed = 5;
var neighbors = [];
var cells = [];
var coloring = [];
var solveStack = [];
var solving = false;
var solved = false;
var stepCount = 0;
var btCount = 0;
var currentIdx = -1;
var dragging = false;
var dragIdx = -1;
var animPhase = 0;
var cellMap = null;
var cellMapW = 0, cellMapH = 0;
var pulseTargets = [];
var offCanvas = null, offCtx = null;
var borderPixels = [];

function rgba(col, a) {
  return 'rgba('+Math.round(col[0])+','+Math.round(col[1])+','+Math.round(col[2])+','+a+')';
}

function dist2(a, b) {
  var dx = a[0]-b[0], dy = a[1]-b[1];
  return dx*dx+dy*dy;
}

function generateSites(n) {
  sites = [];
  var pad = 50;
  for (var i = 0; i < n; i++) {
    sites.push([
      pad + Math.random()*(W-2*pad),
      pad + Math.random()*(H-2*pad)
    ]);
  }
  relaxSites(4);
  buildGraph();
  resetColoring();
}

function relaxSites(iters) {
  for (var it = 0; it < iters; it++) {
    var cx = new Float64Array(sites.length);
    var cy = new Float64Array(sites.length);
    var cnt = new Int32Array(sites.length);
    var step = 6;
    for (var x = 0; x < W; x += step) {
      for (var y = 0; y < H; y += step) {
        var best = 0, bestD = Infinity;
        for (var i = 0; i < sites.length; i++) {
          var dx2 = x-sites[i][0], dy2 = y-sites[i][1];
          var dd = dx2*dx2+dy2*dy2;
          if (dd < bestD) { bestD = dd; best = i; }
        }
        cx[best] += x; cy[best] += y; cnt[best]++;
      }
    }
    for (var i2 = 0; i2 < sites.length; i2++) {
      if (cnt[i2] > 0) {
        sites[i2][0] = Math.max(30, Math.min(W-30, cx[i2]/cnt[i2]));
        sites[i2][1] = Math.max(30, Math.min(H-30, cy[i2]/cnt[i2]));
      }
    }
  }
}

function buildCellMap() {
  var scale = 3;
  cellMapW = Math.ceil(W/scale);
  cellMapH = Math.ceil(H/scale);
  cellMap = new Int16Array(cellMapW * cellMapH);
  for (var x = 0; x < cellMapW; x++) {
    for (var y = 0; y < cellMapH; y++) {
      var px = x*scale, py = y*scale;
      var best = 0, bestD = Infinity;
      for (var i = 0; i < sites.length; i++) {
        var dx = px-sites[i][0], dy = py-sites[i][1];
        var d = dx*dx+dy*dy;
        if (d < bestD) { bestD = d; best = i; }
      }
      cellMap[y*cellMapW+x] = best;
    }
  }
  offCanvas = document.createElement('canvas');
  offCanvas.width = cellMapW;
  offCanvas.height = cellMapH;
  offCtx = offCanvas.getContext('2d');

  borderPixels = [];
  for (var x2 = 1; x2 < cellMapW-1; x2++) {
    for (var y2 = 1; y2 < cellMapH-1; y2++) {
      var idx = y2*cellMapW+x2;
      var me = cellMap[idx];
      if (me !== cellMap[idx+1] || me !== cellMap[(y2+1)*cellMapW+x2]) {
        borderPixels.push(idx);
      }
    }
  }
}

function buildGraph() {
  buildCellMap();
  neighbors = [];
  for (var i = 0; i < sites.length; i++) neighbors.push({});

  for (var x = 0; x < cellMapW-1; x++) {
    for (var y = 0; y < cellMapH-1; y++) {
      var a = cellMap[y*cellMapW+x];
      var b = cellMap[y*cellMapW+x+1];
      var d = cellMap[(y+1)*cellMapW+x];
      if (a !== b) { neighbors[a][b] = 1; neighbors[b][a] = 1; }
      if (a !== d) { neighbors[a][d] = 1; neighbors[d][a] = 1; }
    }
  }

  for (var i2 = 0; i2 < sites.length; i2++) {
    neighbors[i2] = Object.keys(neighbors[i2]).map(Number);
  }
}

function resetColoring() {
  coloring = new Int8Array(sites.length);
  for (var i = 0; i < sites.length; i++) coloring[i] = -1;
  solveStack = [];
  solving = false;
  solved = false;
  stepCount = 0;
  btCount = 0;
  currentIdx = -1;
  pulseTargets = [];
  updateHud();
}

function startSolve() {
  for (var i = 0; i < sites.length; i++) coloring[i] = -1;
  solveStack = [{idx:0, colorTried:0}];
  solving = true;
  solved = false;
  stepCount = 0;
  btCount = 0;
  currentIdx = 0;
  pulseTargets = [];
  document.getElementById('h-state').textContent = 'SOLVING';
  SFX.play('toggle');
}

function solveStep() {
  if (!solving || solveStack.length === 0) {
    solving = false;
    solved = coloring[0] >= 0;
    document.getElementById('h-state').textContent = solved ? 'SOLVED' : 'FAILED';
    if (solved) SFX.play('complete');
    return;
  }

  var frame = solveStack[solveStack.length - 1];
  var idx = frame.idx;
  currentIdx = idx;

  while (frame.colorTried < 4) {
    var color = frame.colorTried;
    frame.colorTried++;
    stepCount++;

    var valid = true;
    var nb = neighbors[idx];
    for (var j = 0; j < nb.length; j++) {
      if (coloring[nb[j]] === color) { valid = false; break; }
    }

    if (valid) {
      coloring[idx] = color;
      pulseTargets.push({idx: idx, time: animPhase});

      if (idx === sites.length - 1) {
        solving = false;
        solved = true;
        currentIdx = -1;
        document.getElementById('h-state').textContent = 'SOLVED';
        SFX.play('complete');
        return;
      }

      solveStack.push({idx: idx+1, colorTried: 0});
      SFX.play('tick', 30);
      return;
    }
  }

  coloring[idx] = -1;
  solveStack.pop();
  btCount++;

  if (solveStack.length > 0) {
    currentIdx = solveStack[solveStack.length-1].idx;
  }
  SFX.play('pop', 50);
}

function scrambleColors() {
  for (var i = 0; i < sites.length; i++) {
    coloring[i] = Math.floor(Math.random()*4);
  }
  solving = false;
  solved = false;
  currentIdx = -1;
  stepCount = 0;
  btCount = 0;
  pulseTargets = [];
  var conflicts = countConflicts();
  document.getElementById('h-state').textContent = conflicts > 0 ? conflicts+' CONFLICTS' : 'VALID';
  SFX.play('toggle');
}

function countConflicts() {
  var conflicts = 0;
  for (var i = 0; i < sites.length; i++) {
    var nb = neighbors[i];
    for (var j = 0; j < nb.length; j++) {
      if (nb[j] > i && coloring[i] >= 0 && coloring[i] === coloring[nb[j]]) conflicts++;
    }
  }
  return conflicts;
}

function updateHud() {
  document.getElementById('h-n').textContent = sites.length;
  document.getElementById('h-steps').textContent = stepCount;
  document.getElementById('h-bt').textContent = btCount;
}

function draw() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  animPhase += 0.016;

  if (!cellMap || sites.length === 0 || !offCtx) return;

  var imgData = offCtx.createImageData(cellMapW, cellMapH);
  var d = imgData.data;
  var pulseAlpha = solving ? 0.45 + 0.15*Math.sin(animPhase*8) : 0;

  for (var i = 0; i < cellMapW * cellMapH; i++) {
    var owner = cellMap[i];
    var off = i * 4;
    if (coloring[owner] >= 0) {
      var col = COLORS[coloring[owner]];
      var alpha = (owner === currentIdx && solving) ? pulseAlpha : 0.30;
      d[off]   = 10 + (col[0]-10)*alpha;
      d[off+1] = 10 + (col[1]-10)*alpha;
      d[off+2] = 15 + (col[2]-15)*alpha;
    } else {
      d[off]   = 14;
      d[off+1] = 14;
      d[off+2] = 22;
    }
    d[off+3] = 255;
  }

  for (var b = 0; b < borderPixels.length; b++) {
    var off2 = borderPixels[b]*4;
    d[off2]   = 0; d[off2+1] = 61; d[off2+2] = 53;
    d[off2+3] = 255;
  }

  offCtx.putImageData(imgData, 0, 0);
  ctx.save();
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(offCanvas, 0, 0, W, H);
  ctx.restore();

  // Draw edges for neighbor graph
  ctx.lineWidth = 1;
  for (var i2 = 0; i2 < sites.length; i2++) {
    var nb = neighbors[i2];
    for (var j = 0; j < nb.length; j++) {
      if (nb[j] <= i2) continue;
      var conflict = coloring[i2] >= 0 && coloring[i2] === coloring[nb[j]];
      ctx.strokeStyle = conflict ? 'rgba(181,140,255,0.5)' : 'rgba(0,245,212,0.06)';
      ctx.lineWidth = conflict ? 2 : 1;
      ctx.beginPath();
      ctx.moveTo(sites[i2][0], sites[i2][1]);
      ctx.lineTo(sites[nb[j]][0], sites[nb[j]][1]);
      ctx.stroke();
    }
  }

  // Draw pulse rings
  for (var p = pulseTargets.length-1; p >= 0; p--) {
    var pt = pulseTargets[p];
    var age = animPhase - pt.time;
    if (age > 1.5) { pulseTargets.splice(p, 1); continue; }
    var r3 = age * 40;
    var a3 = Math.max(0, 1 - age/1.5);
    var pcol = coloring[pt.idx] >= 0 ? COLORS[coloring[pt.idx]] : [0,245,212];
    ctx.strokeStyle = rgba(pcol, a3*0.4);
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.arc(sites[pt.idx][0], sites[pt.idx][1], r3, 0, Math.PI*2);
    ctx.stroke();
  }

  // Draw sites
  for (var i3 = 0; i3 < sites.length; i3++) {
    var sx = sites[i3][0], sy = sites[i3][1];
    var cr = i3 === currentIdx && solving ? 6 : 3.5;
    var col2 = coloring[i3] >= 0 ? COLORS[coloring[i3]] : [60,60,80];

    if (i3 === currentIdx && solving) {
      var glow = ctx.createRadialGradient(sx, sy, 0, sx, sy, 18);
      glow.addColorStop(0, rgba([0,245,212], 0.35));
      glow.addColorStop(1, 'rgba(10,10,15,0)');
      ctx.fillStyle = glow;
      ctx.beginPath();
      ctx.arc(sx, sy, 18, 0, Math.PI*2);
      ctx.fill();
    }

    ctx.fillStyle = rgba(col2, 0.85);
    ctx.beginPath();
    ctx.arc(sx, sy, cr, 0, Math.PI*2);
    ctx.fill();
  }

  // Progress bar
  if (solving || solved) {
    var barW = Math.min(300, W*0.3);
    var barH = 3;
    var barX = W*0.5 - barW*0.5;
    var barY = H - 24;
    var colored = 0;
    for (var ci = 0; ci < sites.length; ci++) {
      if (coloring[ci] >= 0) colored++;
    }
    var progress = sites.length > 0 ? colored/sites.length : 0;
    ctx.fillStyle = 'rgba(255,255,255,0.04)';
    ctx.fillRect(barX, barY, barW, barH);
    ctx.fillStyle = solved ? rgba(COLORS[0], 0.6) : 'rgba(0,245,212,0.4)';
    ctx.fillRect(barX, barY, barW*progress, barH);
  }
}

function findNearestSite(px, py) {
  var best = -1, bestD = Infinity;
  for (var i = 0; i < sites.length; i++) {
    var d = dist2([px,py], sites[i]);
    if (d < bestD) { bestD = d; best = i; }
  }
  return bestD < 1600 ? best : -1;
}

function onDown(e) {
  e.preventDefault();
  var rect = c.getBoundingClientRect();
  var px, py, isRight = false;
  if (e.touches) {
    px = e.touches[0].clientX - rect.left;
    py = e.touches[0].clientY - rect.top;
    if (e.touches.length >= 2) isRight = true;
  } else {
    px = e.clientX - rect.left;
    py = e.clientY - rect.top;
    isRight = e.button === 2;
  }

  if (isRight) {
    nTarget = parseInt(document.getElementById('sl-n').value);
    generateSites(nTarget);
    startSolve();
    return;
  }

  var idx = findNearestSite(px, py);
  if (idx >= 0) {
    dragging = true;
    dragIdx = idx;
  } else {
    sites.push([px, py]);
    buildGraph();
    resetColoring();
    SFX.play('spawn');
    startSolve();
  }
}

function onMove(e) {
  if (!dragging) return;
  e.preventDefault();
  var rect = c.getBoundingClientRect();
  var px, py;
  if (e.touches) {
    px = e.touches[0].clientX - rect.left;
    py = e.touches[0].clientY - rect.top;
  } else {
    px = e.clientX - rect.left;
    py = e.clientY - rect.top;
  }
  sites[dragIdx][0] = Math.max(20, Math.min(W-20, px));
  sites[dragIdx][1] = Math.max(20, Math.min(H-20, py));
  SFX.play('move', 100);
}

function onUp() {
  if (dragging) {
    dragging = false;
    resetColoring();
    startSolve();
    SFX.play('ripple');
  }
}

c.addEventListener('mousedown', onDown);
c.addEventListener('mousemove', onMove);
c.addEventListener('mouseup', onUp);
c.addEventListener('mouseleave', onUp);
c.addEventListener('touchstart', onDown, {passive:false});
c.addEventListener('touchmove', onMove, {passive:false});
c.addEventListener('touchend', onUp);
c.addEventListener('contextmenu', function(e){e.preventDefault();});

document.getElementById('sl-n').addEventListener('input', function(){
  nTarget = parseInt(this.value);
  document.getElementById('sv-n').textContent = nTarget;
  SFX.play('tick', 50);
});

document.getElementById('sl-speed').addEventListener('input', function(){
  speed = parseInt(this.value);
  document.getElementById('sv-speed').textContent = speed;
  SFX.play('tick', 50);
});

document.getElementById('btn-solve').addEventListener('click', function(){
  startSolve();
});

document.getElementById('btn-scramble').addEventListener('click', function(){
  scrambleColors();
});

document.getElementById('btn-regen').addEventListener('click', function(){
  nTarget = parseInt(document.getElementById('sl-n').value);
  generateSites(nTarget);
  startSolve();
});

function init() {
  W = c.width = window.innerWidth;
  H = c.height = window.innerHeight;
  generateSites(nTarget);
  startSolve();
}

window.addEventListener('resize', function(){
  W = c.width = window.innerWidth;
  H = c.height = window.innerHeight;
  buildGraph();
});

init();

function loop() {
  if (solving) {
    for (var s = 0; s < speed; s++) {
      solveStep();
      if (!solving) break;
    }
  }
  draw();
  updateHud();
  requestAnimationFrame(loop);
}
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
