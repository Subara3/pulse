<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('catenary');

$pageName = 'catenary';
$pageTitle = 'CATENARY - PULSE';
$pageDesc = '懸垂線。重力に吊るされた鎖が描く双曲線余弦の曲線。放物線との違いを体感する。';
$pageCanonical = 'https://pulse.subara3.com/catenary.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:4.5rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  HOOKS <span class="v" id="h-hooks">0</span><br>
  SAG <span class="v" id="h-sag">120</span><br>
  MODE <span class="v" id="h-mode">CHAIN</span>
</div>

<div id="info">
  <span class="v">Catenary</span><br>
  y = a &middot; cosh(x/a)
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'hook'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="chain">CHAIN</button>
      <button class="control-btn" data-mode="arch">ARCH</button>
      <button class="control-btn" data-mode="web">WEB</button>
      <button class="control-btn" data-mode="bridge">BRIDGE</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SAG</span>
      <input type="range" id="sl-sag" min="10" max="300" value="120" step="1">
      <span class="slider-value" id="sv-sag">120</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">BEADS</span>
      <input type="range" id="sl-beads" min="3" max="60" value="20" step="1">
      <span class="slider-value" id="sv-beads">20</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-compare">COMPARE</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, PI = Math.PI;
var hooks = [];
var mode = 'chain';
var sag = 120;
var beadCount = 20;
var showCompare = false;
var dragIdx = -1;
var dragOffX = 0, dragOffY = 0;
var time = 0;
var HOOK_RADIUS = 22;

function catenaryShape(t, s) {
  if (s < 0.01) return 4 * t * (1 - t);
  var cs = Math.cosh(s);
  if (cs > 1e8) return t < 0.5 ? 2 * t : 2 * (1 - t);
  return (Math.cosh(s * (2 * t - 1)) - cs) / (1 - cs);
}

function parabolaShape(t) {
  return 4 * t * (1 - t);
}

function chainPoints(h1, h2, sagPx, n, invert, useParabola) {
  var pts = [];
  var s = 3.0;
  for (var i = 0; i <= n; i++) {
    var t = i / n;
    var x = h1.x + t * (h2.x - h1.x);
    var y = h1.y + t * (h2.y - h1.y);
    var shape = useParabola ? parabolaShape(t) : catenaryShape(t, s);
    var offset = sagPx * shape;
    y += invert ? -offset : offset;
    pts.push({x: x, y: y});
  }
  return pts;
}

function chainPairs() {
  var pairs = [];
  if (hooks.length < 2) return pairs;
  if (mode === 'web') {
    for (var i = 0; i < hooks.length; i++)
      for (var j = i + 1; j < hooks.length; j++)
        pairs.push([i, j]);
  } else {
    for (var i = 0; i < hooks.length - 1; i++)
      pairs.push([i, i + 1]);
  }
  return pairs;
}

function drawCurve(pts, r, g, b, baseAlpha, lw) {
  if (pts.length < 2) return;
  ctx.beginPath();
  ctx.moveTo(pts[0].x, pts[0].y);
  for (var i = 1; i < pts.length; i++) ctx.lineTo(pts[i].x, pts[i].y);
  ctx.strokeStyle = 'rgba(' + r + ',' + g + ',' + b + ',' + baseAlpha + ')';
  ctx.lineWidth = lw;
  ctx.stroke();
}

function drawGlowChain(pts) {
  drawCurve(pts, 0, 245, 212, 0.04, 14);
  drawCurve(pts, 0, 245, 212, 0.08, 7);
  drawCurve(pts, 0, 245, 212, 0.25, 3);
  drawCurve(pts, 0, 245, 212, 0.7, 1.2);
}

function drawPurpleChain(pts) {
  drawCurve(pts, 123, 44, 191, 0.06, 10);
  drawCurve(pts, 123, 44, 191, 0.2, 4);
  drawCurve(pts, 123, 44, 191, 0.6, 1.5);
}

function totalLength(pts) {
  var len = 0;
  for (var i = 1; i < pts.length; i++) {
    var dx = pts[i].x - pts[i-1].x;
    var dy = pts[i].y - pts[i-1].y;
    len += Math.sqrt(dx * dx + dy * dy);
  }
  return len;
}

function pointOnCurve(pts, lengths, dist) {
  for (var i = 1; i < lengths.length; i++) {
    if (lengths[i] >= dist) {
      var segLen = lengths[i] - lengths[i-1];
      var t = segLen > 0 ? (dist - lengths[i-1]) / segLen : 0;
      return {
        x: pts[i-1].x + t * (pts[i].x - pts[i-1].x),
        y: pts[i-1].y + t * (pts[i].y - pts[i-1].y)
      };
    }
  }
  return pts[pts.length - 1];
}

function drawBeads(pts, offset) {
  var lengths = [0];
  for (var i = 1; i < pts.length; i++) {
    var dx = pts[i].x - pts[i-1].x;
    var dy = pts[i].y - pts[i-1].y;
    lengths.push(lengths[i-1] + Math.sqrt(dx*dx + dy*dy));
  }
  var tLen = lengths[lengths.length - 1];
  if (tLen < 1) return;

  for (var b = 0; b < beadCount; b++) {
    var t = ((b / beadCount) + offset) % 1;
    var pt = pointOnCurve(pts, lengths, t * tLen);
    var pulse = 0.4 + 0.6 * (0.5 + 0.5 * Math.sin(time * 4 + b * 0.7));
    var r = 2 + pulse;

    var gr = ctx.createRadialGradient(pt.x, pt.y, 0, pt.x, pt.y, r * 3.5);
    gr.addColorStop(0, 'rgba(0,245,212,' + (pulse * 0.85).toFixed(2) + ')');
    gr.addColorStop(0.3, 'rgba(0,245,212,' + (pulse * 0.3).toFixed(2) + ')');
    gr.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.beginPath();
    ctx.arc(pt.x, pt.y, r * 3.5, 0, PI * 2);
    ctx.fillStyle = gr;
    ctx.fill();

    ctx.beginPath();
    ctx.arc(pt.x, pt.y, r * 0.45, 0, PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,' + (pulse * 0.9).toFixed(2) + ')';
    ctx.fill();
  }
}

function drawHook(h) {
  var gr = ctx.createRadialGradient(h.x, h.y, 0, h.x, h.y, HOOK_RADIUS);
  gr.addColorStop(0, 'rgba(123,44,191,0.5)');
  gr.addColorStop(0.5, 'rgba(123,44,191,0.15)');
  gr.addColorStop(1, 'rgba(123,44,191,0)');
  ctx.beginPath();
  ctx.arc(h.x, h.y, HOOK_RADIUS, 0, PI * 2);
  ctx.fillStyle = gr;
  ctx.fill();

  ctx.beginPath();
  ctx.arc(h.x, h.y, 5, 0, PI * 2);
  ctx.fillStyle = '#7b2cbf';
  ctx.fill();

  ctx.beginPath();
  ctx.arc(h.x, h.y, 2, 0, PI * 2);
  ctx.fillStyle = 'rgba(255,255,255,0.9)';
  ctx.fill();
}

function drawBridge(h1, h2, pts) {
  var lowestY = -Infinity;
  for (var i = 0; i < pts.length; i++) {
    if (pts[i].y > lowestY) lowestY = pts[i].y;
  }
  lowestY += 20;

  var nHangers = Math.max(3, Math.floor(pts.length / 8));
  for (var i = 1; i < nHangers; i++) {
    var idx = Math.floor((i / nHangers) * (pts.length - 1));
    var pt = pts[idx];
    ctx.beginPath();
    ctx.moveTo(pt.x, pt.y);
    ctx.lineTo(pt.x, lowestY);
    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 1;
    ctx.stroke();
  }

  ctx.beginPath();
  ctx.moveTo(pts[0].x, lowestY);
  ctx.lineTo(pts[pts.length-1].x, lowestY);
  ctx.strokeStyle = 'rgba(123,44,191,0.35)';
  ctx.lineWidth = 3;
  ctx.stroke();

  ctx.beginPath();
  ctx.moveTo(pts[0].x, lowestY);
  ctx.lineTo(pts[pts.length-1].x, lowestY);
  ctx.strokeStyle = 'rgba(123,44,191,0.8)';
  ctx.lineWidth = 1;
  ctx.stroke();
}

function initHooks() {
  hooks = [];
  var n = 5;
  var mx = W * 0.1;
  var span = W - 2 * mx;
  for (var i = 0; i < n; i++) {
    hooks.push({
      x: mx + (i / (n - 1)) * span,
      y: H * 0.18 + (i % 2) * H * 0.12 + (Math.sin(i * 1.7) * 0.5 + 0.5) * H * 0.08
    });
  }
}

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
  if (hooks.length === 0) initHooks();
}

function render() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  time += 0.004;
  var pairs = chainPairs();
  var invert = mode === 'arch';

  for (var p = 0; p < pairs.length; p++) {
    var h1 = hooks[pairs[p][0]];
    var h2 = hooks[pairs[p][1]];
    var pts = chainPoints(h1, h2, sag, 120, invert, false);

    if (mode === 'bridge') drawBridge(h1, h2, pts);

    drawGlowChain(pts);
    drawBeads(pts, time * 0.5 + p * 0.13);

    if (showCompare) {
      var parPts = chainPoints(h1, h2, sag, 120, invert, true);
      drawPurpleChain(parPts);
    }
  }

  for (var i = 0; i < hooks.length; i++) drawHook(hooks[i]);

  document.getElementById('h-hooks').textContent = hooks.length;
  document.getElementById('h-sag').textContent = sag;
  document.getElementById('h-mode').textContent = mode.toUpperCase();

  requestAnimationFrame(render);
}

function hitHook(x, y) {
  for (var i = 0; i < hooks.length; i++) {
    var dx = hooks[i].x - x;
    var dy = hooks[i].y - y;
    if (dx * dx + dy * dy < HOOK_RADIUS * HOOK_RADIUS) return i;
  }
  return -1;
}

function pos(e) {
  if (e.touches && e.touches.length) return {x: e.touches[0].clientX, y: e.touches[0].clientY};
  return {x: e.clientX, y: e.clientY};
}

var longPress = null;

function onDown(e) {
  if (e.button === 2) return;
  e.preventDefault();
  var p = pos(e);
  var hit = hitHook(p.x, p.y);
  if (hit >= 0) {
    dragIdx = hit;
    dragOffX = hooks[hit].x - p.x;
    dragOffY = hooks[hit].y - p.y;
    if (typeof SFX !== 'undefined') SFX.play('tick');

    if (e.touches) {
      longPress = setTimeout(function() {
        if (dragIdx >= 0 && hooks.length > 2) {
          hooks.splice(dragIdx, 1);
          dragIdx = -1;
          if (typeof SFX !== 'undefined') SFX.play('pop');
        }
        longPress = null;
      }, 500);
    }
  } else {
    hooks.push({x: p.x, y: p.y});
    dragIdx = hooks.length - 1;
    dragOffX = 0;
    dragOffY = 0;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }
}

function onMove(e) {
  if (dragIdx < 0) return;
  if (longPress) { clearTimeout(longPress); longPress = null; }
  var p;
  if (e.touches && e.touches.length) p = {x: e.touches[0].clientX, y: e.touches[0].clientY};
  else p = {x: e.clientX, y: e.clientY};
  hooks[dragIdx].x = p.x + dragOffX;
  hooks[dragIdx].y = p.y + dragOffY;
}

function onUp() {
  if (longPress) { clearTimeout(longPress); longPress = null; }
  dragIdx = -1;
}

function onContext(e) {
  e.preventDefault();
  var p = pos(e);
  var hit = hitHook(p.x, p.y);
  if (hit >= 0 && hooks.length > 2) {
    hooks.splice(hit, 1);
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }
}

C.addEventListener('mousedown', onDown);
window.addEventListener('mousemove', onMove);
window.addEventListener('mouseup', onUp);
C.addEventListener('contextmenu', onContext);
C.addEventListener('touchstart', onDown, {passive: false});
C.addEventListener('touchmove', function(e){ e.preventDefault(); onMove(e); }, {passive: false});
C.addEventListener('touchend', onUp);
window.addEventListener('resize', resize);

document.querySelectorAll('[data-mode]').forEach(function(btn){
  btn.addEventListener('click', function(){
    mode = this.dataset.mode;
    document.querySelectorAll('[data-mode]').forEach(function(b){ b.classList.remove('active'); });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.getElementById('sl-sag').addEventListener('input', function(){
  sag = parseInt(this.value);
  document.getElementById('sv-sag').textContent = sag;
});

document.getElementById('sl-beads').addEventListener('input', function(){
  beadCount = parseInt(this.value);
  document.getElementById('sv-beads').textContent = beadCount;
});

document.getElementById('btn-compare').addEventListener('click', function(){
  showCompare = !showCompare;
  this.classList.toggle('active', showCompare);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

document.getElementById('btn-reset').addEventListener('click', function(){
  initHooks();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

resize();
render();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
