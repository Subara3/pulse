<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('inversion');

$pageName = 'inversion';
$pageTitle = 'INVERSION - PULSE';
$pageDesc = '円に関する反転変換。直線は円になり、円は円に写る。反転円を配置して幾何学の魔法を体験する。';
$pageCanonical = 'https://pulse.subara3.com/inversion.php';

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
  CIRCLES <span class="v" id="hud-circles">0</span><br>
  DEPTH <span class="v" id="hud-depth">1</span><br>
  MODE <span class="v" id="hud-mode">GRID</span>
</div>

<div id="info">
  <span class="v">Circle Inversion</span><br>
  OP &middot; OP' = r&sup2;
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add circle'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">RADIUS</span>
      <input type="range" id="sl-radius" min="30" max="300" value="120" step="5">
      <span class="slider-value" id="sv-radius">120</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">DEPTH</span>
      <input type="range" id="sl-depth" min="1" max="4" value="1" step="1">
      <span class="slider-value" id="sv-depth">1</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">GRID</span>
      <input type="range" id="sl-grid" min="20" max="80" value="40" step="5">
      <span class="slider-value" id="sv-grid">40</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-grid">GRID</button>
      <button class="control-btn" id="btn-circles">CIRCLES</button>
      <button class="control-btn" id="btn-radial">RADIAL</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-clear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, dpr;
var invCircles = [];
var newRadius = 120;
var depth = 1;
var gridSpacing = 40;
var sourceMode = 'grid';
var dragIdx = -1;
var isDragging = false;
var pointerDown = false;
var mx = 0, my = 0;
var animPhase = 0;

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

function addDefaultCircle() {
  invCircles.push({ x: W / 2, y: H / 2, r: 120 });
}
addDefaultCircle();

function invertPoint(px, py, cx, cy, r) {
  var dx = px - cx, dy = py - cy;
  var d2 = dx * dx + dy * dy;
  if (d2 < 0.01) return null;
  var factor = (r * r) / d2;
  return { x: cx + dx * factor, y: cy + dy * factor };
}

function invertSegment(x0, y0, x1, y1, cx, cy, r, segments) {
  var steps = 20;
  var pts = [];
  for (var i = 0; i <= steps; i++) {
    var t = i / steps;
    var px = x0 + (x1 - x0) * t;
    var py = y0 + (y1 - y0) * t;
    var inv = invertPoint(px, py, cx, cy, r);
    if (inv) pts.push(inv);
  }
  if (pts.length > 1) segments.push(pts);
}

function generateGridLines() {
  var lines = [];
  var sp = gridSpacing;
  for (var x = sp; x < W; x += sp) {
    lines.push({ x0: x, y0: 0, x1: x, y1: H });
  }
  for (var y = sp; y < H; y += sp) {
    lines.push({ x0: 0, y0: y, x1: W, y1: y });
  }
  return lines;
}

function generateConcentricCircles() {
  var circles = [];
  var cx = W / 2, cy = H / 2;
  var maxR = Math.max(W, H) * 0.6;
  for (var r = 40; r < maxR; r += 40) {
    circles.push({ cx: cx, cy: cy, r: r });
  }
  for (var r = 40; r < maxR; r += 40) {
    circles.push({ cx: cx + 200, cy: cy - 100, r: r });
  }
  return circles;
}

function generateRadialLines() {
  var lines = [];
  var cx = W / 2, cy = H / 2;
  var maxR = Math.max(W, H);
  var numRays = 36;
  for (var i = 0; i < numRays; i++) {
    var a = (i / numRays) * Math.PI * 2;
    lines.push({
      x0: cx, y0: cy,
      x1: cx + Math.cos(a) * maxR,
      y1: cy + Math.sin(a) * maxR
    });
  }
  var sp = gridSpacing;
  for (var r = sp; r < maxR; r += sp) {
    for (var j = 0; j < 72; j++) {
      var a1 = (j / 72) * Math.PI * 2;
      var a2 = ((j + 1) / 72) * Math.PI * 2;
      lines.push({
        x0: cx + Math.cos(a1) * r, y0: cy + Math.sin(a1) * r,
        x1: cx + Math.cos(a2) * r, y1: cy + Math.sin(a2) * r
      });
    }
  }
  return lines;
}

function applyInversions(lines, invList, currentDepth, maxDepth) {
  var result = [];
  for (var li = 0; li < lines.length; li++) {
    var line = lines[li];
    for (var ci = 0; ci < invList.length; ci++) {
      var ic = invList[ci];
      var segments = [];
      invertSegment(line.x0, line.y0, line.x1, line.y1, ic.x, ic.y, ic.r, segments);
      for (var si = 0; si < segments.length; si++) {
        result.push({ pts: segments[si], depth: currentDepth });
      }
    }
  }
  if (currentDepth < maxDepth && result.length > 0) {
    var nextLines = [];
    for (var ri = 0; ri < result.length; ri++) {
      var pts = result[ri].pts;
      for (var pi = 0; pi < pts.length - 1; pi++) {
        nextLines.push({
          x0: pts[pi].x, y0: pts[pi].y,
          x1: pts[pi + 1].x, y1: pts[pi + 1].y
        });
      }
    }
    var deeper = applyInversions(nextLines, invList, currentDepth + 1, maxDepth);
    for (var di = 0; di < deeper.length; di++) {
      result.push(deeper[di]);
    }
  }
  return result;
}

function drawSourceGeometry() {
  ctx.strokeStyle = 'rgba(0,245,212,0.08)';
  ctx.lineWidth = 0.5;
  if (sourceMode === 'grid') {
    var lines = generateGridLines();
    for (var i = 0; i < lines.length; i++) {
      ctx.beginPath();
      ctx.moveTo(lines[i].x0, lines[i].y0);
      ctx.lineTo(lines[i].x1, lines[i].y1);
      ctx.stroke();
    }
  } else if (sourceMode === 'circles') {
    var circles = generateConcentricCircles();
    for (var i = 0; i < circles.length; i++) {
      ctx.beginPath();
      ctx.arc(circles[i].cx, circles[i].cy, circles[i].r, 0, Math.PI * 2);
      ctx.stroke();
    }
  } else if (sourceMode === 'radial') {
    var lines = generateRadialLines();
    for (var i = 0; i < lines.length; i++) {
      ctx.beginPath();
      ctx.moveTo(lines[i].x0, lines[i].y0);
      ctx.lineTo(lines[i].x1, lines[i].y1);
      ctx.stroke();
    }
  }
}

function getSourceLines() {
  if (sourceMode === 'grid') {
    return generateGridLines();
  } else if (sourceMode === 'circles') {
    var circles = generateConcentricCircles();
    var lines = [];
    for (var i = 0; i < circles.length; i++) {
      var c = circles[i];
      var segs = 72;
      for (var j = 0; j < segs; j++) {
        var a1 = (j / segs) * Math.PI * 2;
        var a2 = ((j + 1) / segs) * Math.PI * 2;
        lines.push({
          x0: c.cx + Math.cos(a1) * c.r, y0: c.cy + Math.sin(a1) * c.r,
          x1: c.cx + Math.cos(a2) * c.r, y1: c.cy + Math.sin(a2) * c.r
        });
      }
    }
    return lines;
  } else {
    return generateRadialLines();
  }
}

function drawInvertedGeometry() {
  if (invCircles.length === 0) return;
  var srcLines = getSourceLines();
  var inverted = applyInversions(srcLines, invCircles, 1, depth);

  for (var i = 0; i < inverted.length; i++) {
    var pts = inverted[i].pts;
    var d = inverted[i].depth;
    var alpha = 0.6 / d;
    var hue = 170 + d * 30;
    if (hue > 290) hue = 290;
    ctx.strokeStyle = 'hsla(' + hue + ',80%,55%,' + alpha + ')';
    ctx.lineWidth = Math.max(0.5, 1.5 / d);
    ctx.beginPath();
    if (pts.length > 0) {
      ctx.moveTo(pts[0].x, pts[0].y);
      for (var j = 1; j < pts.length; j++) {
        ctx.lineTo(pts[j].x, pts[j].y);
      }
    }
    ctx.stroke();
  }
}

function drawInversionCircles() {
  var time = animPhase;
  for (var i = 0; i < invCircles.length; i++) {
    var ic = invCircles[i];

    ctx.strokeStyle = 'rgba(123,44,191,0.5)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([6, 4]);
    ctx.beginPath();
    ctx.arc(ic.x, ic.y, ic.r, 0, Math.PI * 2);
    ctx.stroke();
    ctx.setLineDash([]);

    var pulseR = ic.r + Math.sin(time * 2 + i) * 3;
    ctx.strokeStyle = 'rgba(123,44,191,0.15)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(ic.x, ic.y, pulseR, 0, Math.PI * 2);
    ctx.stroke();

    ctx.fillStyle = '#7b2cbf';
    ctx.beginPath();
    ctx.arc(ic.x, ic.y, 3, 0, Math.PI * 2);
    ctx.fill();

    var numDots = 12;
    for (var j = 0; j < numDots; j++) {
      var a = (j / numDots) * Math.PI * 2 + time * 0.5;
      var dotX = ic.x + Math.cos(a) * ic.r;
      var dotY = ic.y + Math.sin(a) * ic.r;
      ctx.fillStyle = 'rgba(123,44,191,0.3)';
      ctx.beginPath();
      ctx.arc(dotX, dotY, 1.5, 0, Math.PI * 2);
      ctx.fill();
    }
  }
}

function findCircleAt(x, y) {
  for (var i = invCircles.length - 1; i >= 0; i--) {
    var ic = invCircles[i];
    var dx = x - ic.x, dy = y - ic.y;
    var dist = Math.sqrt(dx * dx + dy * dy);
    if (dist < ic.r + 20) return i;
  }
  return -1;
}

function draw() {
  animPhase += 0.016;
  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  drawSourceGeometry();
  drawInvertedGeometry();
  drawInversionCircles();

  document.getElementById('hud-circles').textContent = invCircles.length;
  document.getElementById('hud-depth').textContent = depth;
  document.getElementById('hud-mode').textContent = sourceMode.toUpperCase();

  requestAnimationFrame(draw);
}

function getPointerPos(e) {
  var rect = C.getBoundingClientRect();
  if (e.touches) {
    return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
  }
  return { x: e.clientX - rect.left, y: e.clientY - rect.top };
}

C.addEventListener('mousedown', function(e) {
  e.preventDefault();
  var pos = getPointerPos(e);
  mx = pos.x; my = pos.y;
  pointerDown = true;

  if (e.button === 2) {
    var idx = findCircleAt(pos.x, pos.y);
    if (idx >= 0) {
      invCircles.splice(idx, 1);
      if(typeof SFX!=='undefined') SFX.play('pop');
    }
    return;
  }

  dragIdx = findCircleAt(pos.x, pos.y);
  if (dragIdx >= 0) {
    isDragging = true;
  }
});

C.addEventListener('mousemove', function(e) {
  var pos = getPointerPos(e);
  mx = pos.x; my = pos.y;
  if (isDragging && dragIdx >= 0) {
    invCircles[dragIdx].x = pos.x;
    invCircles[dragIdx].y = pos.y;
    if(typeof SFX!=='undefined') SFX.play('move', 100);
  }
});

C.addEventListener('mouseup', function(e) {
  var pos = getPointerPos(e);
  if (pointerDown && !isDragging && e.button === 0) {
    invCircles.push({ x: pos.x, y: pos.y, r: newRadius });
    if(typeof SFX!=='undefined') SFX.play('spawn');
  }
  pointerDown = false;
  isDragging = false;
  dragIdx = -1;
});

C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

C.addEventListener('wheel', function(e) {
  e.preventDefault();
  var pos = getPointerPos(e);
  var idx = findCircleAt(pos.x, pos.y);
  if (idx >= 0) {
    var delta = e.deltaY > 0 ? -5 : 5;
    invCircles[idx].r = Math.max(20, Math.min(500, invCircles[idx].r + delta));
    if(typeof SFX!=='undefined') SFX.play('tick', 50);
  }
}, { passive: false });

var touchStartDist = 0;
var touchStartR = 0;
var pinchIdx = -1;

C.addEventListener('touchstart', function(e) {
  e.preventDefault();
  if (e.touches.length === 1) {
    var pos = getPointerPos(e);
    mx = pos.x; my = pos.y;
    pointerDown = true;
    dragIdx = findCircleAt(pos.x, pos.y);
    if (dragIdx >= 0) {
      isDragging = true;
    }
  } else if (e.touches.length === 2) {
    var dx = e.touches[0].clientX - e.touches[1].clientX;
    var dy = e.touches[0].clientY - e.touches[1].clientY;
    touchStartDist = Math.sqrt(dx * dx + dy * dy);
    var midX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
    var midY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
    var rect = C.getBoundingClientRect();
    pinchIdx = findCircleAt(midX - rect.left, midY - rect.top);
    if (pinchIdx >= 0) touchStartR = invCircles[pinchIdx].r;
  }
}, { passive: false });

C.addEventListener('touchmove', function(e) {
  e.preventDefault();
  if (e.touches.length === 1 && isDragging && dragIdx >= 0) {
    var pos = getPointerPos(e);
    invCircles[dragIdx].x = pos.x;
    invCircles[dragIdx].y = pos.y;
    if(typeof SFX!=='undefined') SFX.play('move', 100);
  } else if (e.touches.length === 2 && pinchIdx >= 0) {
    var dx = e.touches[0].clientX - e.touches[1].clientX;
    var dy = e.touches[0].clientY - e.touches[1].clientY;
    var dist = Math.sqrt(dx * dx + dy * dy);
    var scale = dist / touchStartDist;
    invCircles[pinchIdx].r = Math.max(20, Math.min(500, touchStartR * scale));
  }
}, { passive: false });

C.addEventListener('touchend', function(e) {
  if (e.touches.length === 0) {
    if (pointerDown && !isDragging) {
      invCircles.push({ x: mx, y: my, r: newRadius });
      if(typeof SFX!=='undefined') SFX.play('spawn');
    }
    pointerDown = false;
    isDragging = false;
    dragIdx = -1;
    pinchIdx = -1;
  }
});

var slRadius = document.getElementById('sl-radius');
var svRadius = document.getElementById('sv-radius');
slRadius.addEventListener('input', function() {
  newRadius = parseInt(this.value);
  svRadius.textContent = newRadius;
});

var slDepth = document.getElementById('sl-depth');
var svDepth = document.getElementById('sv-depth');
slDepth.addEventListener('input', function() {
  depth = parseInt(this.value);
  svDepth.textContent = depth;
});

var slGrid = document.getElementById('sl-grid');
var svGrid = document.getElementById('sv-grid');
slGrid.addEventListener('input', function() {
  gridSpacing = parseInt(this.value);
  svGrid.textContent = gridSpacing;
});

var modeButtons = [
  { id: 'btn-grid', mode: 'grid' },
  { id: 'btn-circles', mode: 'circles' },
  { id: 'btn-radial', mode: 'radial' }
];
modeButtons.forEach(function(mb) {
  document.getElementById(mb.id).addEventListener('click', function() {
    sourceMode = mb.mode;
    modeButtons.forEach(function(b) {
      document.getElementById(b.id).classList.remove('active');
    });
    this.classList.add('active');
    if(typeof SFX!=='undefined') SFX.play('toggle');
  });
});

document.getElementById('btn-clear').addEventListener('click', function() {
  invCircles = [];
  addDefaultCircle();
  if(typeof SFX!=='undefined') SFX.play('click');
});

draw();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
