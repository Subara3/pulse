<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hanoi');

$pageName = 'hanoi';
$pageTitle = 'HANOI - PULSE';
$pageDesc = 'ハノイの塔を自分の手で解くと、全状態のグラフがシェルピンスキー三角形になっている。最短手数で移せるか。';
$pageCanonical = 'https://pulse.subara3.com/hanoi.php';
$extraStyle = '
body{background:#0a0a0f;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none;cursor:pointer}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px)}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud"><span class="sub">HANOI</span><br>MOVES <b id="h-moves">0</b> / <b id="h-opt">7</b><br>DISCS <b id="h-discs">3</b><br><span class="sub" id="h-state">PLAY</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'select peg'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">DISCS</span>
    <input type="range" id="sl-discs" min="2" max="6" value="3" step="1">
    <span class="slider-value" id="sv-discs">3</span>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="btn-play">PLAY</button>
    <button class="control-btn" id="btn-auto">AUTO</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
'use strict';
var c = document.getElementById('canvas'), ctx = c.getContext('2d');
var W, H, dpr;

var numDiscs = 3;
var pegs = [[], [], []];
var moves = 0;
var optimal = 7;
var solved = false;
var selectedPeg = -1;
var liftedDisc = -1;
var autoMode = false;
var autoSolution = [];
var autoIdx = 0;
var autoTimer = 0;
var autoDelay = 400;
var challenged = false;

var graphNodes = {};
var visitedSet = {};
var pathList = [];
var edgeList = [];

var animDisc = -1, animFromPeg = -1, animToPeg = -1, animT = 0;
var animating = false;

var TEAL = [0, 245, 212];
var PURPLE = [123, 44, 191];

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function discColor(i) {
  var t = numDiscs <= 1 ? 0 : i / (numDiscs - 1);
  var h = brandHue(t);
  return 'hsl(' + h + ',80%,55%)';
}

function discColorDim(i) {
  var t = numDiscs <= 1 ? 0 : i / (numDiscs - 1);
  var h = brandHue(t);
  return 'hsl(' + h + ',60%,30%)';
}

function stateHash() {
  var d2p = new Array(numDiscs);
  for (var p = 0; p < 3; p++)
    for (var j = 0; j < pegs[p].length; j++)
      d2p[pegs[p][j]] = p;
  return d2p.join('');
}

function buildGraph() {
  graphNodes = {};
  edgeList = [];
  var total = Math.pow(3, numDiscs);
  var sqrt3h = Math.sqrt(3) / 2;
  var vx = [0.5, 0, 1];
  var vy = [0, sqrt3h, sqrt3h];

  for (var s = 0; s < total; s++) {
    var d2p = [];
    var tmp = s;
    for (var d = 0; d < numDiscs; d++) {
      d2p.push(tmp % 3);
      tmp = Math.floor(tmp / 3);
    }
    var hash = d2p.join('');
    var x = (vx[0] + vx[1] + vx[2]) / 3;
    var y = (vy[0] + vy[1] + vy[2]) / 3;
    for (var d = numDiscs - 1; d >= 0; d--) {
      x = (x + vx[d2p[d]]) / 2;
      y = (y + vy[d2p[d]]) / 2;
    }
    graphNodes[hash] = { x: x, y: y, d2p: d2p };
  }

  var keys = Object.keys(graphNodes);
  for (var i = 0; i < keys.length; i++) {
    var d2p = graphNodes[keys[i]].d2p;
    var topDiscs = [Infinity, Infinity, Infinity];
    for (var d = 0; d < numDiscs; d++) {
      if (d < topDiscs[d2p[d]]) topDiscs[d2p[d]] = d;
    }
    for (var p = 0; p < 3; p++) {
      var disc = topDiscs[p];
      if (disc === Infinity) continue;
      for (var tp = 0; tp < 3; tp++) {
        if (tp === p) continue;
        if (disc < topDiscs[tp] || topDiscs[tp] === Infinity) {
          var nd = d2p.slice();
          nd[disc] = tp;
          var nh = nd.join('');
          if (nh > keys[i]) {
            edgeList.push([keys[i], nh]);
          }
        }
      }
    }
  }
}

function resetPuzzle() {
  pegs = [[], [], []];
  for (var i = numDiscs - 1; i >= 0; i--) pegs[0].push(i);
  moves = 0;
  optimal = Math.pow(2, numDiscs) - 1;
  solved = false;
  challenged = false;
  selectedPeg = -1;
  liftedDisc = -1;
  animating = false;
  autoMode = false;
  autoSolution = [];
  autoIdx = 0;
  document.getElementById('btn-auto').classList.remove('active');
  document.getElementById('btn-play').classList.add('active');

  buildGraph();
  var h = stateHash();
  visitedSet = {};
  visitedSet[h] = true;
  pathList = [h];
  updateHud();
}

function updateHud() {
  document.getElementById('h-moves').textContent = moves;
  document.getElementById('h-opt').textContent = optimal;
  document.getElementById('h-discs').textContent = numDiscs;
  var st = solved ? (moves <= optimal ? 'OPTIMAL' : 'SOLVED') : (autoMode ? 'AUTO' : 'PLAY');
  document.getElementById('h-state').textContent = st;
}

function canMove(fromPeg, toPeg) {
  if (pegs[fromPeg].length === 0) return false;
  var disc = pegs[fromPeg][pegs[fromPeg].length - 1];
  if (pegs[toPeg].length === 0) return true;
  return disc < pegs[toPeg][pegs[toPeg].length - 1];
}

function doMove(fromPeg, toPeg, skipAnim) {
  var disc = pegs[fromPeg].pop();
  pegs[toPeg].push(disc);
  moves++;

  var h = stateHash();
  visitedSet[h] = true;
  pathList.push(h);

  if (!skipAnim) {
    animDisc = disc;
    animFromPeg = fromPeg;
    animToPeg = toPeg;
    animT = 0;
    animating = true;
  }

  SFX.play('tick');

  if (pegs[2].length === numDiscs) {
    solved = true;
    SFX.play('complete');
    if (moves <= optimal && !challenged) {
      challenged = true;
      if (window.PULSE) PULSE.clear();
    }
  }
  updateHud();
}

function solveHanoi(n, from, to, aux, result) {
  if (n === 0) return;
  solveHanoi(n - 1, from, aux, to, result);
  result.push([from, to]);
  solveHanoi(n - 1, aux, to, from, result);
}

function startAuto() {
  autoMode = true;
  autoSolution = [];
  solveHanoi(numDiscs, 0, 2, 1, autoSolution);
  autoIdx = 0;
  pegs = [[], [], []];
  for (var i = numDiscs - 1; i >= 0; i--) pegs[0].push(i);
  moves = 0;
  solved = false;
  challenged = false;
  animating = false;
  var h = stateHash();
  visitedSet = {};
  visitedSet[h] = true;
  pathList = [h];
  document.getElementById('btn-auto').classList.add('active');
  document.getElementById('btn-play').classList.remove('active');
  updateHud();
}

function pegX(p) {
  var puzzleLeft, puzzleW;
  if (W > H * 1.2) {
    puzzleLeft = 0;
    puzzleW = W * 0.45;
  } else {
    puzzleLeft = 0;
    puzzleW = W;
  }
  return puzzleLeft + puzzleW * (0.2 + p * 0.3);
}

function pegArea() {
  if (W > H * 1.2) {
    return { left: 0, top: 0, width: W * 0.45, height: H };
  }
  return { left: 0, top: 0, width: W, height: H * 0.5 };
}

function graphArea() {
  if (W > H * 1.2) {
    return { left: W * 0.48, top: 0, width: W * 0.52, height: H };
  }
  return { left: 0, top: H * 0.48, width: W, height: H * 0.52 };
}

function hitPeg(x, y) {
  var area = pegArea();
  if (x < area.left || x > area.left + area.width) return -1;
  if (y < area.top || y > area.top + area.height) return -1;
  var best = -1, bestDist = Infinity;
  for (var p = 0; p < 3; p++) {
    var px = pegX(p);
    var dist = Math.abs(x - px);
    if (dist < bestDist) { bestDist = dist; best = p; }
  }
  return best;
}

function drawPuzzle() {
  var area = pegArea();
  var baseY = area.top + area.height * 0.82;
  var pegH = area.height * 0.5;
  var maxDiscW = Math.min(area.width * 0.25, 120);
  var minDiscW = maxDiscW * 0.35;
  var discH = Math.min(pegH / (numDiscs + 1), 28);

  ctx.strokeStyle = 'rgba(0,245,212,0.12)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(area.left + 10, baseY);
  ctx.lineTo(area.left + area.width - 10, baseY);
  ctx.stroke();

  for (var p = 0; p < 3; p++) {
    var px = pegX(p);
    var grd = ctx.createLinearGradient(px, baseY - pegH, px, baseY);
    grd.addColorStop(0, 'rgba(0,245,212,0.02)');
    grd.addColorStop(1, 'rgba(0,245,212,0.08)');
    ctx.strokeStyle = grd;
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(px, baseY);
    ctx.lineTo(px, baseY - pegH);
    ctx.stroke();

    if (p === selectedPeg && !animating) {
      ctx.fillStyle = 'rgba(0,245,212,0.06)';
      ctx.fillRect(px - maxDiscW * 0.6, baseY - pegH - 10, maxDiscW * 1.2, pegH + 20);
    }

    for (var j = 0; j < pegs[p].length; j++) {
      var disc = pegs[p][j];
      if (animating && disc === animDisc) continue;
      var w = minDiscW + (maxDiscW - minDiscW) * disc / Math.max(numDiscs - 1, 1);
      var dy = baseY - (j + 1) * discH;
      var col = (p === selectedPeg && j === pegs[p].length - 1 && !animating) ? discColor(disc) : discColorDim(disc);
      ctx.fillStyle = col;
      roundRect(ctx, px - w / 2, dy, w, discH - 2, 4);
      ctx.fill();
      if (p === selectedPeg && j === pegs[p].length - 1 && !animating) {
        ctx.strokeStyle = '#00f5d4';
        ctx.lineWidth = 1.5;
        roundRect(ctx, px - w / 2, dy, w, discH - 2, 4);
        ctx.stroke();
      }
    }
  }

  if (animating) {
    var fromX = pegX(animFromPeg);
    var toX = pegX(animToPeg);
    var fromStack = 0;
    for (var j = 0; j < pegs[animFromPeg].length; j++)
      if (pegs[animFromPeg][j] !== animDisc) fromStack++;
    var toStack = pegs[animToPeg].length;
    for (var j = 0; j < pegs[animToPeg].length; j++)
      if (pegs[animToPeg][j] === animDisc) toStack--;

    var w = minDiscW + (maxDiscW - minDiscW) * animDisc / Math.max(numDiscs - 1, 1);
    var topY = baseY - pegH - discH * 1.5;
    var startY = baseY - (fromStack + 1) * discH;
    var endY = baseY - (toStack + 1) * discH;

    var t = animT;
    var cx, cy;
    if (t < 0.3) {
      var tt = t / 0.3;
      cx = fromX;
      cy = startY + (topY - startY) * tt;
    } else if (t < 0.7) {
      var tt = (t - 0.3) / 0.4;
      cx = fromX + (toX - fromX) * tt;
      cy = topY;
    } else {
      var tt = (t - 0.7) / 0.3;
      cx = toX;
      cy = topY + (endY - topY) * tt;
    }

    ctx.fillStyle = discColor(animDisc);
    roundRect(ctx, cx - w / 2, cy, w, discH - 2, 4);
    ctx.fill();
    ctx.strokeStyle = '#00f5d4';
    ctx.lineWidth = 1.5;
    roundRect(ctx, cx - w / 2, cy, w, discH - 2, 4);
    ctx.stroke();
  }

  var labels = ['A', 'B', 'C'];
  ctx.font = '11px ui-monospace,SFMono-Regular,Menlo,monospace';
  ctx.textAlign = 'center';
  ctx.fillStyle = 'rgba(255,255,255,0.3)';
  for (var p = 0; p < 3; p++) {
    ctx.fillText(labels[p], pegX(p), baseY + 18);
  }

  if (solved) {
    ctx.font = 'bold 16px "Space Grotesk",sans-serif';
    ctx.fillStyle = moves <= optimal ? '#00f5d4' : 'rgba(181,140,255,0.9)';
    ctx.textAlign = 'center';
    var msg = moves <= optimal ? 'OPTIMAL!' : 'SOLVED';
    ctx.fillText(msg, area.left + area.width / 2, area.top + area.height * 0.12);
  }
}

function drawGraph() {
  var area = graphArea();
  var total = Math.pow(3, numDiscs);
  if (total > 729) return;

  var sqrt3h = Math.sqrt(3) / 2;
  var padding = Math.min(area.width, area.height) * 0.08;
  var gw = area.width - padding * 2;
  var gh = area.height - padding * 2;
  var scale = Math.min(gw, gh / sqrt3h);
  var ox = area.left + (area.width - scale) / 2;
  var oy = area.top + (area.height - scale * sqrt3h) / 2;

  var nodeR = total <= 27 ? 5 : total <= 81 ? 3 : total <= 243 ? 2 : 1.2;

  ctx.globalAlpha = 0.08;
  ctx.strokeStyle = 'rgba(0,245,212,0.5)';
  ctx.lineWidth = 0.5;
  for (var i = 0; i < edgeList.length; i++) {
    var a = graphNodes[edgeList[i][0]];
    var b = graphNodes[edgeList[i][1]];
    if (!a || !b) continue;
    ctx.beginPath();
    ctx.moveTo(ox + a.x * scale, oy + a.y * scale);
    ctx.lineTo(ox + b.x * scale, oy + b.y * scale);
    ctx.stroke();
  }
  ctx.globalAlpha = 1;

  var curHash = stateHash();
  var keys = Object.keys(graphNodes);
  for (var i = 0; i < keys.length; i++) {
    var n = graphNodes[keys[i]];
    var sx = ox + n.x * scale;
    var sy = oy + n.y * scale;
    ctx.beginPath();
    ctx.arc(sx, sy, nodeR, 0, Math.PI * 2);
    if (keys[i] === curHash) {
      ctx.fillStyle = '#00f5d4';
      ctx.fill();
      ctx.beginPath();
      ctx.arc(sx, sy, nodeR + 3, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,0.4)';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    } else if (visitedSet[keys[i]]) {
      ctx.fillStyle = 'rgba(0,245,212,0.5)';
      ctx.fill();
    } else {
      ctx.fillStyle = 'rgba(255,255,255,0.1)';
      ctx.fill();
    }
  }

  if (pathList.length > 1) {
    ctx.strokeStyle = 'rgba(181,140,255,0.7)';
    ctx.lineWidth = total <= 27 ? 2 : 1.2;
    ctx.beginPath();
    var first = graphNodes[pathList[0]];
    ctx.moveTo(ox + first.x * scale, oy + first.y * scale);
    for (var i = 1; i < pathList.length; i++) {
      var n = graphNodes[pathList[i]];
      if (n) ctx.lineTo(ox + n.x * scale, oy + n.y * scale);
    }
    ctx.stroke();
  }

  var goalHash = '';
  for (var d = 0; d < numDiscs; d++) goalHash += '2';
  var goalNode = graphNodes[goalHash];
  if (goalNode) {
    ctx.beginPath();
    ctx.arc(ox + goalNode.x * scale, oy + goalNode.y * scale, nodeR + 5, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(181,140,255,0.5)';
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 3]);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.font = '10px ui-monospace,SFMono-Regular,Menlo,monospace';
    ctx.fillStyle = 'rgba(181,140,255,0.6)';
    ctx.textAlign = 'center';
    ctx.fillText('GOAL', ox + goalNode.x * scale, oy + goalNode.y * scale - nodeR - 8);
  }

  var startHash = '';
  for (var d = 0; d < numDiscs; d++) startHash += '0';
  var startNode = graphNodes[startHash];
  if (startNode) {
    ctx.font = '10px ui-monospace,SFMono-Regular,Menlo,monospace';
    ctx.fillStyle = 'rgba(0,245,212,0.5)';
    ctx.textAlign = 'center';
    ctx.fillText('START', ox + startNode.x * scale, oy + startNode.y * scale - nodeR - 8);
  }
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r);
  ctx.lineTo(x + w, y + h - r);
  ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  ctx.lineTo(x + r, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - r);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.closePath();
}

function draw() {
  ctx.clearRect(0, 0, W, H);

  if (W > H * 1.2) {
    ctx.strokeStyle = 'rgba(0,245,212,0.06)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(W * 0.46, H * 0.05);
    ctx.lineTo(W * 0.46, H * 0.95);
    ctx.stroke();
  } else {
    ctx.strokeStyle = 'rgba(0,245,212,0.06)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(W * 0.05, H * 0.47);
    ctx.lineTo(W * 0.95, H * 0.47);
    ctx.stroke();
  }

  drawPuzzle();
  drawGraph();
}

function onClick(e) {
  if (animating || autoMode || solved) return;
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
  px *= dpr;
  py *= dpr;

  var p = hitPeg(px, py);
  if (p < 0) { selectedPeg = -1; return; }

  if (selectedPeg < 0) {
    if (pegs[p].length > 0) {
      selectedPeg = p;
      SFX.play('spawn');
    }
  } else {
    if (p === selectedPeg) {
      selectedPeg = -1;
      return;
    }
    if (canMove(selectedPeg, p)) {
      doMove(selectedPeg, p);
      selectedPeg = -1;
    } else {
      SFX.play('fail');
      selectedPeg = -1;
    }
  }
}

c.addEventListener('mousedown', onClick);
c.addEventListener('touchstart', onClick, {passive: false});
c.addEventListener('contextmenu', function(e) { e.preventDefault(); });

document.getElementById('sl-discs').addEventListener('input', function() {
  numDiscs = parseInt(this.value);
  document.getElementById('sv-discs').textContent = numDiscs;
  SFX.play('tick', 50);
  resetPuzzle();
});

document.getElementById('btn-reset').addEventListener('click', function() {
  SFX.play('click');
  resetPuzzle();
});

document.getElementById('btn-auto').addEventListener('click', function() {
  SFX.play('toggle');
  startAuto();
});

document.getElementById('btn-play').addEventListener('click', function() {
  SFX.play('toggle');
  autoMode = false;
  document.getElementById('btn-play').classList.add('active');
  document.getElementById('btn-auto').classList.remove('active');
  resetPuzzle();
});

function resize() {
  dpr = window.devicePixelRatio || 1;
  W = c.width = window.innerWidth * dpr;
  H = c.height = window.innerHeight * dpr;
  c.style.width = window.innerWidth + 'px';
  c.style.height = window.innerHeight + 'px';
}

window.addEventListener('resize', resize);
resize();
resetPuzzle();

function loop() {
  if (animating) {
    animT += 0.06;
    if (animT >= 1) {
      animT = 1;
      animating = false;
    }
  }

  if (autoMode && !animating && !solved && autoIdx < autoSolution.length) {
    autoTimer++;
    if (autoTimer > (autoDelay / 16)) {
      autoTimer = 0;
      var step = autoSolution[autoIdx++];
      doMove(step[0], step[1]);
    }
  }

  draw();
  requestAnimationFrame(loop);
}
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
