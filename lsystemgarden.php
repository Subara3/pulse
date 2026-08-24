<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('lsystemgarden');

$pageName = 'lsystemgarden';
$pageTitle = 'L-SYSTEM GARDEN - PULSE';
$pageDesc = 'Lシステムの枝がゆっくり成長し、風でしなる発光ガーデン。クリックで新しい種を植えられる。';
$pageCanonical = 'https://pulse.subara3.com/lsystemgarden.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 10; pointer-events: none; font-family: "Space Grotesk", "Segoe UI", sans-serif; letter-spacing: 0.05em; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.36); font-size: 0.62rem; line-height: 1.8; text-align: right; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1rem; bottom: 1rem; color: rgba(255,255,255,0.32); font-size: 0.56rem; line-height: 1.7; max-width: min(72vw, 520px); }
@media (max-width: 768px) {
  .hud { top: 0.75rem; right: 0.75rem; font-size: 0.54rem; }
  .info { left: 0.75rem; bottom: 0.75rem; font-size: 0.5rem; max-width: 74vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  SEEDS: <span class="v" id="hudSeeds">0</span><br>
  SEGMENTS: <span class="v" id="hudSegs">0</span><br>
  WIND: <span class="v" id="hudWind">0.20</span>
</div>

<div class="ui info">
  TAP: PLANT SEED<br>
  DRAG: PUSH WIND<br>
  RIGHT-CLICK: CLEAR GARDEN<br>
  L-SYSTEM BRANCHES GROW SLOWLY INTO A LUMINOUS CANOPY
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>DEPTH</label>
    <input type="range" id="depthSlider" min="3" max="7" value="5">
  </div>
  <div class="slider-wrap">
    <label>ANGLE</label>
    <input type="range" id="angleSlider" min="12" max="42" value="24">
  </div>
  <div class="slider-wrap">
    <label>GROWTH SPEED</label>
    <input type="range" id="speedSlider" min="20" max="140" value="70">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO: ON</button>
    <button class="control-btn" id="plantBtn">PLANT BURST</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'plant'],
  ['type' => 'drag', 'label' => 'wind'],
  ['type' => 'right', 'label' => 'clear']
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/drawer-controls.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudSeeds = document.getElementById('hudSeeds');
  var hudSegs = document.getElementById('hudSegs');
  var hudWind = document.getElementById('hudWind');

  var depthSlider = document.getElementById('depthSlider');
  var angleSlider = document.getElementById('angleSlider');
  var speedSlider = document.getElementById('speedSlider');
  var autoBtn = document.getElementById('autoBtn');
  var plantBtn = document.getElementById('plantBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0, DPR = 1;
  var time = 0;
  var seeds = [];
  var autoPlant = true;
  var windBias = 0.2;
  var dragging = false;
  var pointerId = null;
  var lastAuto = 0;

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) {
    return [
      Math.round(a[0] + (b[0] - a[0]) * t),
      Math.round(a[1] + (b[1] - a[1]) * t),
      Math.round(a[2] + (b[2] - a[2]) * t)
    ];
  }

  function resize() {
    DPR = Math.min(2, window.devicePixelRatio || 1);
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  function lsys(depth) {
    var str = 'X';
    for (var i = 0; i < depth; i++) {
      var next = '';
      for (var j = 0; j < str.length; j++) {
        var ch = str[j];
        if (ch === 'X') next += 'F[+X][-X]FX';
        else if (ch === 'F') next += 'FF';
        else next += ch;
      }
      str = next;
    }
    return str;
  }

  function compileSegments(x, y, depth, angleDeg, scale, hueJitter) {
    var sentence = lsys(depth);
    var stack = [];
    var px = x, py = y;
    var ang = -Math.PI / 2;
    var segs = [];
    var baseLen = Math.min(W, H) * (0.018 + scale * 0.012);

    for (var i = 0; i < sentence.length; i++) {
      var ch = sentence[i];
      if (ch === 'F') {
        var len = baseLen * (0.88 + 0.24 * Math.sin(i * 0.17 + hueJitter));
        var nx = px + Math.cos(ang) * len;
        var ny = py + Math.sin(ang) * len;
        var t = i / sentence.length;
        segs.push({ x1: px, y1: py, x2: nx, y2: ny, t: t });
        px = nx; py = ny;
      } else if (ch === '+') {
        ang += angleDeg * Math.PI / 180;
      } else if (ch === '-') {
        ang -= angleDeg * Math.PI / 180;
      } else if (ch === '[') {
        stack.push({ x: px, y: py, a: ang });
      } else if (ch === ']') {
        var s = stack.pop();
        if (s) { px = s.x; py = s.y; ang = s.a; }
      }
    }
    return segs;
  }

  function plant(x, y, burst) {
    var depth = parseInt(depthSlider.value, 10);
    var angle = parseFloat(angleSlider.value);
    var count = burst ? 3 : 1;
    for (var i = 0; i < count; i++) {
      var sx = x + (Math.random() - 0.5) * (burst ? 180 : 20);
      var sy = y + (Math.random() - 0.5) * (burst ? 40 : 12);
      var hueJitter = Math.random() * Math.PI * 2;
      var segs = compileSegments(sx, sy, depth, angle * (0.9 + Math.random() * 0.25), 0.7 + Math.random() * 0.7, hueJitter);
      seeds.push({
        x: sx, y: sy,
        segs: segs,
        growth: 0,
        glow: 0,
        phase: Math.random() * Math.PI * 2,
        sway: (Math.random() * 2 - 1) * 0.4,
        colorA: mix([0, 245, 212], [123, 44, 191], 0.15 + Math.random() * 0.3),
        colorB: mix([0, 245, 212], [123, 44, 191], 0.7 + Math.random() * 0.25)
      });
    }
    while (seeds.length > 22) seeds.shift();
    if (typeof SFX !== 'undefined') SFX.play('spawn', 40);
  }

  function clearGarden() {
    seeds = [];
    if (typeof SFX !== 'undefined') SFX.play('ripple', 60);
  }

  function update(dt) {
    var growthSpeed = parseFloat(speedSlider.value) / 10000;

    if (autoPlant && time - lastAuto > 3600 && seeds.length < 16) {
      lastAuto = time;
      plant(Math.random() * W, H * (0.72 + Math.random() * 0.24), false);
    }

    var totalSeg = 0;
    for (var i = 0; i < seeds.length; i++) {
      var s = seeds[i];
      s.growth += dt * growthSpeed;
      if (s.growth > 1) s.growth = 1;
      s.glow += dt * 0.0012;
      totalSeg += Math.floor(s.segs.length * s.growth);
    }

    windBias *= 0.996;
    if (Math.abs(windBias) < 0.03) windBias = 0;

    hudSeeds.textContent = seeds.length;
    hudSegs.textContent = totalSeg;
    hudWind.textContent = windBias.toFixed(2);
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.16)';
    ctx.fillRect(0, 0, W, H);

    var g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, 'rgba(22, 18, 44, 0.24)');
    g.addColorStop(1, 'rgba(8, 16, 24, 0.02)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    ctx.globalCompositeOperation = 'lighter';
    for (var i = 0; i < seeds.length; i++) {
      var s = seeds[i];
      var n = Math.floor(s.segs.length * s.growth);
      for (var k = 0; k < n; k++) {
        var seg = s.segs[k];
        var depthF = 1 - seg.t;
        var wobble = Math.sin(time * 0.00035 + s.phase + seg.t * 8) * (2 + 28 * depthF * windBias) + s.sway * 14 * depthF;
        var col = mix(s.colorA, s.colorB, seg.t);

        ctx.strokeStyle = rgba(col, 0.08 + 0.26 * depthF);
        ctx.lineWidth = 0.6 + 2.4 * depthF;
        ctx.beginPath();
        ctx.moveTo(seg.x1 + wobble, seg.y1);
        ctx.lineTo(seg.x2 + wobble * 1.2, seg.y2);
        ctx.stroke();
      }

      var pulse = 0.45 + 0.55 * Math.sin(s.glow + s.phase);
      ctx.fillStyle = rgba(s.colorA, 0.35 * pulse);
      ctx.beginPath();
      ctx.arc(s.x, s.y, 2 + pulse * 3, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalCompositeOperation = 'source-over';
  }

  function pointerDown(e) {
    dragging = true;
    pointerId = e.pointerId;
    canvas.setPointerCapture(e.pointerId);
    plant(e.clientX, e.clientY, false);
  }

  function pointerMove(e) {
    if (!dragging || e.pointerId !== pointerId) return;
    var nx = (e.clientX / Math.max(1, W) - 0.5) * 2;
    windBias += nx * 0.06;
    windBias = Math.max(-1.6, Math.min(1.6, windBias));
    if (typeof SFX !== 'undefined') SFX.play('move', 90);
  }

  function pointerUp(e) {
    if (e.pointerId !== pointerId) return;
    dragging = false;
    pointerId = null;
  }

  canvas.addEventListener('click', function(e) {
    plant(e.clientX, e.clientY, false);
  });

  canvas.addEventListener('pointerdown', pointerDown);
  canvas.addEventListener('pointermove', pointerMove);
  canvas.addEventListener('pointerup', pointerUp);
  canvas.addEventListener('pointercancel', pointerUp);

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    clearGarden();
  });

  autoBtn.addEventListener('click', function() {
    autoPlant = !autoPlant;
    autoBtn.textContent = 'AUTO: ' + (autoPlant ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  plantBtn.addEventListener('click', function() {
    plant(W * (0.2 + Math.random() * 0.6), H * (0.78 + Math.random() * 0.18), true);
    if (typeof SFX !== 'undefined') SFX.play('grow', 40);
  });

  resetBtn.addEventListener('click', function() {
    seeds = [];
    depthSlider.value = 5;
    angleSlider.value = 24;
    speedSlider.value = 70;
    autoPlant = true;
    autoBtn.textContent = 'AUTO: ON';
    initSeeds();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  depthSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  angleSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  speedSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });

  function initSeeds() {
    var baseY = H * 0.9;
    plant(W * 0.28, baseY, false);
    plant(W * 0.5, baseY, false);
    plant(W * 0.72, baseY, false);
    for (var i = 0; i < seeds.length; i++) seeds[i].growth = 0.25 + Math.random() * 0.45;
  }

  window.addEventListener('resize', resize);
  resize();
  initSeeds();

  var prev = performance.now();
  function frame(now) {
    var dt = Math.min(40, now - prev);
    prev = now;
    time += dt;
    update(dt);
    draw();
    if (Math.random() < 0.008 && typeof SFX !== 'undefined') SFX.play('ripple', 1200);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
