<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('feedback');

$pageName = 'feedback';
$pageTitle = 'FEEDBACK - PULSE';
$pageDesc = '映像帰還ループ。画面が自身を縮小・回転して再描画し続け、無限後退のパターンが創発する。';
$pageCanonical = 'https://pulse.subara3.com/feedback.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
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
  SCALE <span class="v" id="h-scale">0.96</span><br>
  ROTATE <span class="v" id="h-rotate">1.2&deg;</span><br>
  MODE <span class="v" id="h-mode">AUTO</span>
</div>

<div id="info">
  <span class="v">Feedback</span><br>
  Iterated image transform
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop'],
  ['type' => 'drag', 'label' => 'draw'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-mode="auto">AUTO</button>
      <button class="control-btn" data-mode="draw">DRAW</button>
      <button class="control-btn" data-mode="drops">DROPS</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SCALE</span>
      <input type="range" id="sl-scale" min="85" max="99" value="96" step="1">
      <span class="slider-value" id="sv-scale">0.96</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">ROTATE</span>
      <input type="range" id="sl-rotate" min="-50" max="50" value="12" step="1">
      <span class="slider-value" id="sv-rotate">1.2&deg;</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">FADE</span>
      <input type="range" id="sl-fade" min="1" max="30" value="5" step="1">
      <span class="slider-value" id="sv-fade">0.05</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SHIFT</span>
      <input type="range" id="sl-shift" min="-30" max="30" value="0" step="1">
      <span class="slider-value" id="sv-shift">0</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btn-clear">CLEAR</button>
      <button class="control-btn" id="btn-mirror">MIRROR</button>
    </div>
  </div>
  <div>
    <div class="btn-row" style="flex-wrap:wrap">
      <button class="preset-btn" data-preset="spiral">SPIRAL</button>
      <button class="preset-btn" data-preset="tunnel">TUNNEL</button>
      <button class="preset-btn" data-preset="vortex">VORTEX</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H, PI = Math.PI;
var off = document.createElement('canvas'), offCtx = off.getContext('2d');

var scale = 0.96;
var rotateDeg = 1.2;
var rotateRad = rotateDeg * PI / 180;
var fadeAlpha = 0.05;
var shiftX = 0;
var mirrorOn = false;
var mode = 'auto';
var drawing = false;
var lastX = 0, lastY = 0;
var time = 0;
var orbiters = [];

function initOrbiters() {
  orbiters = [];
  var baseR = Math.min(W, H) * 0.18;
  for (var i = 0; i < 3; i++) {
    orbiters.push({
      angle: i * PI * 2 / 3,
      baseR: baseR * (0.7 + i * 0.3),
      speed: 0.015 + i * 0.005,
      wobbleFreq: 0.4 + i * 0.15,
      hue: 174 + i * 45,
      size: 3 + i * 0.5
    });
  }
}

function seed() {
  var cx = W / 2, cy = H / 2;
  var r = Math.min(W, H) * 0.08;
  for (var i = 0; i < 12; i++) {
    var a = i * PI * 2 / 12;
    var x = cx + Math.cos(a) * r;
    var y = cy + Math.sin(a) * r;
    var g = ctx.createRadialGradient(x, y, 0, x, y, 8);
    g.addColorStop(0, 'rgba(0,245,212,0.5)');
    g.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.beginPath();
    ctx.arc(x, y, 8, 0, PI * 2);
    ctx.fillStyle = g;
    ctx.fill();
  }
}

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
  off.width = W;
  off.height = H;
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  initOrbiters();
  seed();
}

function feedback() {
  offCtx.drawImage(C, 0, 0);
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  ctx.save();
  ctx.globalAlpha = 1 - fadeAlpha;
  ctx.translate(W / 2 + shiftX, H / 2);
  ctx.rotate(rotateRad);
  if (mirrorOn) ctx.scale(-scale, scale);
  else ctx.scale(scale, scale);
  ctx.translate(-W / 2, -H / 2);
  ctx.drawImage(off, 0, 0);
  ctx.restore();
  ctx.globalAlpha = 1;
}

function drawAuto() {
  var cx = W / 2, cy = H / 2;
  for (var i = 0; i < orbiters.length; i++) {
    var o = orbiters[i];
    o.angle += o.speed;
    var r = o.baseR + Math.sin(time * o.wobbleFreq) * o.baseR * 0.3;
    var x = cx + Math.cos(o.angle) * r;
    var y = cy + Math.sin(o.angle) * r;
    var h = o.hue + Math.sin(time * 0.3 + i * 2) * 15;
    if (h < 165) h = 165;
    if (h > 295) h = 295;
    var gs = o.size * 5;
    var g = ctx.createRadialGradient(x, y, 0, x, y, gs);
    g.addColorStop(0, 'hsla(' + h + ',90%,75%,0.9)');
    g.addColorStop(0.25, 'hsla(' + h + ',80%,60%,0.5)');
    g.addColorStop(0.6, 'hsla(' + h + ',70%,50%,0.15)');
    g.addColorStop(1, 'hsla(' + h + ',60%,40%,0)');
    ctx.beginPath();
    ctx.arc(x, y, gs, 0, PI * 2);
    ctx.fillStyle = g;
    ctx.fill();
    ctx.beginPath();
    ctx.arc(x, y, o.size * 0.7, 0, PI * 2);
    ctx.fillStyle = 'hsla(' + h + ',95%,85%,0.95)';
    ctx.fill();
  }
}

function dropAt(x, y) {
  var g = ctx.createRadialGradient(x, y, 0, x, y, 28);
  g.addColorStop(0, 'rgba(255,255,255,0.9)');
  g.addColorStop(0.08, 'rgba(0,245,212,0.85)');
  g.addColorStop(0.35, 'rgba(0,245,212,0.35)');
  g.addColorStop(0.65, 'rgba(123,44,191,0.12)');
  g.addColorStop(1, 'transparent');
  ctx.beginPath();
  ctx.arc(x, y, 28, 0, PI * 2);
  ctx.fillStyle = g;
  ctx.fill();
  ctx.beginPath();
  ctx.arc(x, y, 2.5, 0, PI * 2);
  ctx.fillStyle = '#fff';
  ctx.fill();
}

function drawStroke(x1, y1, x2, y2) {
  ctx.lineCap = 'round';
  ctx.beginPath();
  ctx.moveTo(x1, y1);
  ctx.lineTo(x2, y2);
  ctx.strokeStyle = 'rgba(0,245,212,0.15)';
  ctx.lineWidth = 12;
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(x1, y1);
  ctx.lineTo(x2, y2);
  ctx.strokeStyle = 'rgba(0,245,212,0.85)';
  ctx.lineWidth = 3;
  ctx.stroke();
}

function loop() {
  feedback();
  if (mode === 'auto') drawAuto();
  time += 0.016;
  document.getElementById('h-scale').textContent = scale.toFixed(2);
  document.getElementById('h-rotate').textContent = rotateDeg.toFixed(1) + '°';
  document.getElementById('h-mode').textContent = mode.toUpperCase();
  requestAnimationFrame(loop);
}

function getPos(e) {
  if (e.touches && e.touches.length > 0) return [e.touches[0].clientX, e.touches[0].clientY];
  return [e.clientX, e.clientY];
}

function onDown(e) {
  if (e.button === 2) return;
  e.preventDefault();
  var p = getPos(e);
  if (mode === 'draw') {
    drawing = true;
    lastX = p[0];
    lastY = p[1];
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  } else {
    dropAt(p[0], p[1]);
    if (typeof SFX !== 'undefined') SFX.play('ripple');
  }
}

function onMove(e) {
  if (!drawing) return;
  var p;
  if (e.touches && e.touches.length > 0) p = [e.touches[0].clientX, e.touches[0].clientY];
  else p = [e.clientX, e.clientY];
  drawStroke(lastX, lastY, p[0], p[1]);
  lastX = p[0];
  lastY = p[1];
}

function onUp() {
  drawing = false;
}

function onContext(e) {
  e.preventDefault();
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  seed();
  if (typeof SFX !== 'undefined') SFX.play('click');
}

C.addEventListener('mousedown', onDown);
window.addEventListener('mousemove', onMove);
window.addEventListener('mouseup', onUp);
C.addEventListener('contextmenu', onContext);
C.addEventListener('touchstart', onDown, { passive: false });
C.addEventListener('touchmove', function(e) { onMove(e); }, { passive: true });
C.addEventListener('touchend', onUp);
window.addEventListener('resize', resize);

document.querySelectorAll('[data-mode]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    mode = this.dataset.mode;
    document.querySelectorAll('[data-mode]').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.getElementById('sl-scale').addEventListener('input', function() {
  scale = parseInt(this.value) / 100;
  document.getElementById('sv-scale').textContent = scale.toFixed(2);
});

document.getElementById('sl-rotate').addEventListener('input', function() {
  rotateDeg = parseInt(this.value) / 10;
  rotateRad = rotateDeg * PI / 180;
  document.getElementById('sv-rotate').textContent = rotateDeg.toFixed(1) + '°';
});

document.getElementById('sl-fade').addEventListener('input', function() {
  fadeAlpha = parseInt(this.value) / 100;
  document.getElementById('sv-fade').textContent = fadeAlpha.toFixed(2);
});

document.getElementById('sl-shift').addEventListener('input', function() {
  shiftX = parseInt(this.value);
  document.getElementById('sv-shift').textContent = shiftX;
});

document.getElementById('btn-clear').addEventListener('click', function() {
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  seed();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

document.getElementById('btn-mirror').addEventListener('click', function() {
  mirrorOn = !mirrorOn;
  this.classList.toggle('active', mirrorOn);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

var presets = {
  spiral: { s: 96, r: 12, f: 5, sh: 0 },
  tunnel: { s: 98, r: 3, f: 2, sh: 0 },
  vortex: { s: 92, r: 30, f: 8, sh: 0 }
};

document.querySelectorAll('[data-preset]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var p = presets[this.dataset.preset];
    if (!p) return;
    document.getElementById('sl-scale').value = p.s;
    document.getElementById('sl-rotate').value = p.r;
    document.getElementById('sl-fade').value = p.f;
    document.getElementById('sl-shift').value = p.sh;
    scale = p.s / 100;
    rotateDeg = p.r / 10;
    rotateRad = rotateDeg * PI / 180;
    fadeAlpha = p.f / 100;
    shiftX = p.sh;
    document.getElementById('sv-scale').textContent = scale.toFixed(2);
    document.getElementById('sv-rotate').textContent = rotateDeg.toFixed(1) + '°';
    document.getElementById('sv-fade').textContent = fadeAlpha.toFixed(2);
    document.getElementById('sv-shift').textContent = shiftX;
    document.querySelectorAll('[data-preset]').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
});

resize();
loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
