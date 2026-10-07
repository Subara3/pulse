<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('grain');

$pageName = 'grain';
$pageTitle = 'GRAIN - PULSE';
$pageDesc = '種を置くと結晶が四方に育ち、ぶつかった所に光る境界が走る。粒の大きさを揃えられるか。';
$pageCanonical = 'https://pulse.subara3.com/grain.php';
$extraStyle = '
#canvas{position:fixed;top:0;left:0;touch-action:none;image-rendering:pixelated;image-rendering:crisp-edges}
.hud{position:fixed;top:1rem;left:1rem;z-index:20;font:12px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;color:rgba(255,255,255,.55);letter-spacing:.08em;background:rgba(10,10,15,.48);border:1px solid rgba(0,245,212,.16);padding:.75rem .85rem;border-radius:8px;backdrop-filter:blur(12px);pointer-events:none}
.hud b{color:#00f5d4;font-weight:700}
.hud .sub{color:rgba(181,140,255,.9)}
@media(max-width:760px){.hud{top:.75rem;left:.75rem;font-size:10px;padding:.55rem .65rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  GRAINS <b id="h-count">0</b><br>
  UNIFORMITY <b id="h-uni">&mdash;</b><br>
  <span class="sub" id="h-goal">GOAL 90%+</span>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag',  'label' => 'seeds'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">NUCLEATION</span>
    <input type="range" id="sl-nuc" min="0" max="100" value="15">
    <span class="slider-value" id="v-nuc">15</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">ANISOTROPY</span>
    <input type="range" id="sl-aniso" min="0" max="100" value="30">
    <span class="slider-value" id="v-aniso">30</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sl-speed" min="1" max="20" value="2">
    <span class="slider-value" id="v-speed">2</span>
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
var W, H, scale;
var grid, imgData;
var grains, nextId, frontier, boundaryList;
var crystallized, challengeCleared, nucTimer, hudTimer;

var slNuc   = document.getElementById('sl-nuc');
var slAniso = document.getElementById('sl-aniso');
var slSpeed = document.getElementById('sl-speed');
var elCount = document.getElementById('h-count');
var elUni   = document.getElementById('h-uni');
var elGoal  = document.getElementById('h-goal');

function brandHue(t) {
  t = t < 0 ? 0 : t > 1 ? 1 : t;
  var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                  : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
  return 172 + e * 118;
}

function hsl2rgb(h, s, l) {
  h = ((h % 360) + 360) % 360 / 360;
  var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
  var p = 2 * l - q;
  function f(tt) {
    if (tt < 0) tt += 1; if (tt > 1) tt -= 1;
    if (tt < 1/6) return p + (q - p) * 6 * tt;
    if (tt < 1/2) return q;
    if (tt < 2/3) return p + (q - p) * (2/3 - tt) * 6;
    return p;
  }
  return [Math.round(f(h + 1/3) * 255),
          Math.round(f(h) * 255),
          Math.round(f(h - 1/3) * 255)];
}

function makeColor(orient) {
  var t = ((orient % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2) / (Math.PI * 2);
  return hsl2rgb(brandHue(t), 0.6, 0.4);
}

function canvasXY(cx, cy) {
  var r = C.getBoundingClientRect();
  return [Math.floor(cx / r.width * W), Math.floor(cy / r.height * H)];
}

function addSeed(gx, gy) {
  if (gx < 0 || gx >= W || gy < 0 || gy >= H) return false;
  var idx = gy * W + gx;
  if (grid[idx] !== 0) return false;
  var id = nextId++;
  var orient = Math.random() * Math.PI * 2;
  var col = makeColor(orient);
  grains[id] = {orient: orient, color: col, area: 1,
                co: Math.cos(orient), so: Math.sin(orient)};
  grid[idx] = id;
  writePixel(idx, gx, gy, id);
  frontier.push([gx, gy, id]);
  crystallized = false;
  return true;
}

function writePixel(idx, x, y, id) {
  var g = grains[id];
  var stripe = Math.sin((x * g.co + y * g.so) * 0.4);
  var br = 0.85 + 0.15 * stripe;
  var p = idx * 4, d = imgData.data;
  d[p]   = Math.floor(g.color[0] * br);
  d[p+1] = Math.floor(g.color[1] * br);
  d[p+2] = Math.floor(g.color[2] * br);
  d[p+3] = 255;
}

function writeBoundary(idx) {
  var p = idx * 4, d = imgData.data;
  if (d[p] === 0 && d[p+1] === 245) return;
  d[p] = 0; d[p+1] = 245; d[p+2] = 212; d[p+3] = 255;
  boundaryList.push(idx);
}

var dx8 = [1, -1, 0, 0, 1, -1, 1, -1];
var dy8 = [0, 0, 1, -1, 1, 1, -1, -1];

function growStep() {
  if (frontier.length === 0) {
    if (!crystallized) { crystallized = true; updateHUD(); }
    return;
  }
  var aniso = slAniso.value / 100;
  var next = [];
  for (var i = 0; i < frontier.length; i++) {
    var fi = frontier[i], fx = fi[0], fy = fi[1], fg = fi[2];
    var g = grains[fg];
    if (!g) continue;
    var hasEmpty = false;
    for (var d = 0; d < 8; d++) {
      var nx = fx + dx8[d], ny = fy + dy8[d];
      if (nx < 0 || nx >= W || ny < 0 || ny >= H) continue;
      var nIdx = ny * W + nx;
      var gv = grid[nIdx];
      if (gv === 0) {
        var angle = Math.atan2(dy8[d], dx8[d]);
        var alignment = Math.cos(4 * (angle - g.orient));
        var prob = 1 - aniso * 0.6 * Math.max(0, -alignment);
        if (Math.random() < prob) {
          grid[nIdx] = fg;
          g.area++;
          writePixel(nIdx, nx, ny, fg);
          next.push([nx, ny, fg]);
        } else {
          hasEmpty = true;
        }
      } else if (gv > 0 && gv !== fg) {
        writeBoundary(nIdx);
        writeBoundary(fy * W + fx);
      }
    }
    if (hasEmpty) next.push(fi);
  }
  frontier = next;
}

function reset() {
  grid = new Int32Array(W * H);
  imgData = ctx.createImageData(W, H);
  var d = imgData.data;
  for (var i = 0; i < W * H * 4; i += 4) {
    d[i] = 10; d[i+1] = 10; d[i+2] = 15; d[i+3] = 255;
  }
  grains = [null];
  nextId = 1;
  frontier = [];
  boundaryList = [];
  crystallized = false;
  challengeCleared = false;
  nucTimer = 0;
  if (elGoal) elGoal.textContent = 'GOAL 90%+';
  var n = 5 + Math.floor(Math.random() * 3);
  for (var i = 0; i < n; i++) {
    addSeed(
      Math.floor(W * 0.12 + Math.random() * W * 0.76),
      Math.floor(H * 0.12 + Math.random() * H * 0.76)
    );
  }
  updateHUD();
}

function updateHUD() {
  var count = 0, areas = [];
  for (var i = 1; i < grains.length; i++) {
    if (grains[i] && grains[i].area > 0) { count++; areas.push(grains[i].area); }
  }
  elCount.textContent = count;
  if (areas.length < 2) { elUni.textContent = '—'; return; }
  var mean = 0;
  for (var i = 0; i < areas.length; i++) mean += areas[i];
  mean /= areas.length;
  var v = 0;
  for (var i = 0; i < areas.length; i++) v += (areas[i] - mean) * (areas[i] - mean);
  v /= areas.length;
  var cv = Math.sqrt(v) / mean;
  var uni = Math.max(0, Math.floor((1 - cv) * 100));
  elUni.textContent = uni + '%';
  if (uni >= 90 && count >= 5 && crystallized && !challengeCleared) {
    challengeCleared = true;
    SFX.play('complete');
    if (elGoal) elGoal.textContent = 'CLEAR';
    if (window.PULSE) PULSE.clear();
  }
}

function maybeNucleate() {
  if (crystallized) return;
  var rate = slNuc.value / 100;
  if (rate < 0.01) return;
  nucTimer++;
  var interval = Math.max(1, Math.floor(120 / (rate * 10 + 0.5)));
  if (nucTimer % interval !== 0) return;
  var x = Math.floor(Math.random() * W);
  var y = Math.floor(Math.random() * H);
  if (grid[y * W + x] === 0) addSeed(x, y);
}

function resize() {
  var maxDim = 1200;
  scale = Math.max(1, Math.ceil(Math.max(innerWidth, innerHeight) / maxDim));
  W = Math.ceil(innerWidth / scale);
  H = Math.ceil(innerHeight / scale);
  C.width = W;
  C.height = H;
  C.style.width = innerWidth + 'px';
  C.style.height = innerHeight + 'px';
  reset();
}

function render() {
  if (crystallized && boundaryList.length > 0) {
    var t = performance.now() * 0.002;
    var pulse = 0.55 + 0.45 * Math.sin(t);
    var d = imgData.data;
    for (var i = 0; i < boundaryList.length; i++) {
      var p = boundaryList[i] * 4;
      d[p+1] = Math.floor(180 + 65 * pulse);
      d[p+2] = Math.floor(155 + 57 * pulse);
    }
  }
  ctx.putImageData(imgData, 0, 0);
}

function loop() {
  var spd = parseInt(slSpeed.value) || 2;
  maybeNucleate();
  for (var s = 0; s < spd; s++) growStep();
  render();
  hudTimer = (hudTimer || 0) + 1;
  if (hudTimer % 20 === 0) updateHUD();
  requestAnimationFrame(loop);
}

/* ---- Events ---- */
var dragging = false, lastDX = -999, lastDY = -999;
var DRAG_DIST = 50;

C.addEventListener('mousedown', function(e) {
  if (e.button === 2) return;
  dragging = true;
  var xy = canvasXY(e.clientX, e.clientY);
  if (addSeed(xy[0], xy[1])) SFX.play('spawn');
  lastDX = xy[0]; lastDY = xy[1];
});
C.addEventListener('mousemove', function(e) {
  if (!dragging) return;
  var xy = canvasXY(e.clientX, e.clientY);
  var ddx = xy[0] - lastDX, ddy = xy[1] - lastDY;
  if (ddx * ddx + ddy * ddy >= DRAG_DIST * DRAG_DIST) {
    if (addSeed(xy[0], xy[1])) SFX.play('spawn', 80);
    lastDX = xy[0]; lastDY = xy[1];
  }
});
window.addEventListener('mouseup', function() { dragging = false; });
C.addEventListener('contextmenu', function(e) {
  e.preventDefault();
  SFX.play('click');
  reset();
});

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  var t = e.touches[0];
  dragging = true;
  var xy = canvasXY(t.clientX, t.clientY);
  if (addSeed(xy[0], xy[1])) SFX.play('spawn');
  lastDX = xy[0]; lastDY = xy[1];
}, {passive: false});
C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (!dragging || !e.touches.length) return;
  var t = e.touches[0];
  var xy = canvasXY(t.clientX, t.clientY);
  var ddx = xy[0] - lastDX, ddy = xy[1] - lastDY;
  if (ddx * ddx + ddy * ddy >= DRAG_DIST * DRAG_DIST) {
    if (addSeed(xy[0], xy[1])) SFX.play('spawn', 80);
    lastDX = xy[0]; lastDY = xy[1];
  }
}, {passive: false});
C.addEventListener('touchend', function() { dragging = false; });

slNuc.addEventListener('input', function() {
  document.getElementById('v-nuc').textContent = slNuc.value;
  SFX.play('tick', 100);
});
slAniso.addEventListener('input', function() {
  document.getElementById('v-aniso').textContent = slAniso.value;
  SFX.play('tick', 100);
});
slSpeed.addEventListener('input', function() {
  document.getElementById('v-speed').textContent = slSpeed.value;
});
document.getElementById('btn-reset').addEventListener('click', function() {
  SFX.play('click');
  reset();
});

resize();
window.addEventListener('resize', resize);
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
