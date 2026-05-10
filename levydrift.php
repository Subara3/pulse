<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('levydrift');

$pageName = 'levydrift';
$pageTitle = 'LEVY DRIFT - PULSE';
$pageDesc = 'レヴィフライト。短い彷徨いと突然の大跳躍が描く、光の航跡図。';
$pageCanonical = 'https://pulse.subara3.com/levydrift.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1.2rem; bottom: 1.2rem; color: rgba(255,255,255,0.24); font-size: 0.56rem; line-height: 1.75; letter-spacing: 0.05em; max-width: min(74vw, 420px); }
@media (max-width: 768px) {
  .hud { top: 0.65rem; right: 0.65rem; font-size: 0.54rem; }
  .info { left: 0.85rem; bottom: 0.9rem; font-size: 0.49rem; max-width: 72vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
  ['type' => 'drag', 'label' => 'drag'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  WALKERS: <span id="hudWalkers" class="v">0</span><br>
  JUMPS: <span id="hudJumps" class="v">0</span><br>
  MAX LEAP: <span id="hudMaxLeap" class="v">0</span>
</div>

<div class="ui info">
  TAP: SPAWN WALKERS<br>
  DRAG: ATTRACT<br>
  RIGHT-CLICK: CLEAR TRAILS<br>
  HEAVY TAILS SHAPE THE PATH
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>ALPHA (tail)</label>
    <input type="range" id="alphaSlider" min="110" max="200" value="150">
  </div>
  <div class="slider-wrap">
    <label>WALKERS</label>
    <input type="range" id="countSlider" min="20" max="200" value="80">
  </div>
  <div class="slider-wrap">
    <label>TRAIL FADE</label>
    <input type="range" id="fadeSlider" min="1" max="20" value="4">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="streakBtn">STREAKS: ON</button>
    <button class="control-btn" id="burstBtn">BURST</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudWalkers = document.getElementById('hudWalkers');
  var hudJumps = document.getElementById('hudJumps');
  var hudMaxLeap = document.getElementById('hudMaxLeap');

  var alphaSlider = document.getElementById('alphaSlider');
  var countSlider = document.getElementById('countSlider');
  var fadeSlider = document.getElementById('fadeSlider');
  var streakBtn = document.getElementById('streakBtn');
  var burstBtn = document.getElementById('burstBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0;
  var walkers = [];
  var showStreaks = true;
  var totalJumps = 0;
  var maxLeap = 0;
  var pointer = { x: 0, y: 0, down: false };

  var ACCENT = [0, 245, 212];
  var SUB = [123, 44, 191];

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) {
    return [
      (a[0] + (b[0] - a[0]) * t) | 0,
      (a[1] + (b[1] - a[1]) * t) | 0,
      (a[2] + (b[2] - a[2]) * t) | 0
    ];
  }

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = W;
    canvas.height = H;
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
  }

  // Levy flight step: Pareto-distributed with heavy tail
  function levyStep(alpha) {
    var u = Math.max(1e-8, 1 - Math.random());
    var step = Math.pow(u, -1.0 / alpha);
    return Math.min(step * 3, Math.min(W, H) * 0.4);
  }

  function createWalker(x, y) {
    var hue = 175 + Math.random() * 110; // teal to purple range
    return {
      x: x, y: y,
      angle: Math.random() * Math.PI * 2,
      hue: hue,
      col: mix(ACCENT, SUB, (hue - 175) / 110),
      trail: [],
      age: 0,
      lastStep: 0
    };
  }

  function seed() {
    walkers = [];
    totalJumps = 0;
    maxLeap = 0;
    var count = parseInt(countSlider.value, 10);
    var cx = W / 2, cy = H / 2;
    var spread = Math.min(W, H) * 0.15;
    for (var i = 0; i < count; i++) {
      var a = Math.random() * Math.PI * 2;
      var r = Math.random() * spread;
      walkers.push(createWalker(cx + Math.cos(a) * r, cy + Math.sin(a) * r));
    }
    hudWalkers.textContent = walkers.length;
  }

  function addBurst(x, y, count) {
    var n = count || 16;
    for (var i = 0; i < n; i++) {
      var a = Math.random() * Math.PI * 2;
      var r = Math.random() * 30;
      walkers.push(createWalker(x + Math.cos(a) * r, y + Math.sin(a) * r));
    }
    // cap total walkers
    var max = parseInt(countSlider.value, 10) + 100;
    if (walkers.length > max) walkers.splice(0, walkers.length - max);
    hudWalkers.textContent = walkers.length;
  }

  function update() {
    var alpha = parseInt(alphaSlider.value, 10) / 100;
    var maxTrail = 300;
    var frameMaxLeap = 0;

    for (var i = 0; i < walkers.length; i++) {
      var w = walkers[i];
      w.age++;

      // direction jitter
      w.angle += (Math.random() - 0.5) * 0.6;

      // attract to pointer if dragging
      if (pointer.down) {
        var dx = pointer.x - w.x;
        var dy = pointer.y - w.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > 1) {
          var targetAngle = Math.atan2(dy, dx);
          var diff = targetAngle - w.angle;
          while (diff > Math.PI) diff -= Math.PI * 2;
          while (diff < -Math.PI) diff += Math.PI * 2;
          w.angle += diff * 0.15;
        }
      }

      var step = levyStep(alpha);
      var oldX = w.x;
      var oldY = w.y;

      w.x += Math.cos(w.angle) * step;
      w.y += Math.sin(w.angle) * step;
      w.lastStep = step;

      // wrap around
      if (w.x < 0) w.x += W;
      if (w.x >= W) w.x -= W;
      if (w.y < 0) w.y += H;
      if (w.y >= H) w.y -= H;

      // record trail
      w.trail.push(oldX, oldY, w.x, w.y, step);
      if (w.trail.length > maxTrail * 5) {
        w.trail.splice(0, w.trail.length - maxTrail * 5);
      }

      if (step > 15) {
        totalJumps++;
        if (step > frameMaxLeap) frameMaxLeap = step;
      }
    }

    if (frameMaxLeap > maxLeap) maxLeap = frameMaxLeap;

    // maintain walker count
    var target = parseInt(countSlider.value, 10);
    while (walkers.length < target) {
      walkers.push(createWalker(Math.random() * W, Math.random() * H));
    }
  }

  function draw() {
    var fadeAmount = parseInt(fadeSlider.value, 10) / 100;
    ctx.fillStyle = 'rgba(10,10,15,' + fadeAmount + ')';
    ctx.fillRect(0, 0, W, H);

    for (var i = 0; i < walkers.length; i++) {
      var w = walkers[i];
      var trail = w.trail;
      var segments = trail.length / 5;

      // draw trail segments
      for (var k = 0; k < trail.length; k += 5) {
        var x0 = trail[k];
        var y0 = trail[k + 1];
        var x1 = trail[k + 2];
        var y1 = trail[k + 3];
        var step = trail[k + 4];
        var progress = (k / 5) / segments;

        // skip wrap-around segments
        var dx = Math.abs(x1 - x0);
        var dy = Math.abs(y1 - y0);
        if (dx > W * 0.5 || dy > H * 0.5) continue;

        var isLeap = step > 15;

        if (isLeap && showStreaks) {
          // bright streak for long jumps
          var intensity = Math.min(1, step / 60);
          var streakCol = mix(w.col, [255, 255, 255], intensity * 0.4);
          ctx.strokeStyle = rgba(streakCol, progress * (0.3 + intensity * 0.4));
          ctx.lineWidth = 1 + intensity * 2.5;
          ctx.beginPath();
          ctx.moveTo(x0, y0);
          ctx.lineTo(x1, y1);
          ctx.stroke();
        } else {
          // normal trail
          ctx.strokeStyle = rgba(w.col, progress * 0.25);
          ctx.lineWidth = 0.6;
          ctx.beginPath();
          ctx.moveTo(x0, y0);
          ctx.lineTo(x1, y1);
          ctx.stroke();
        }
      }

      // current position: glow
      var glowR = 6 + (w.lastStep > 15 ? Math.min(w.lastStep * 0.15, 8) : 0);
      var g = ctx.createRadialGradient(w.x, w.y, 0, w.x, w.y, glowR);
      g.addColorStop(0, rgba(w.col, 0.35));
      g.addColorStop(1, rgba(w.col, 0));
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(w.x, w.y, glowR, 0, Math.PI * 2);
      ctx.fill();

      // core dot
      ctx.fillStyle = rgba(w.col, 0.85);
      ctx.beginPath();
      ctx.arc(w.x, w.y, 1.5, 0, Math.PI * 2);
      ctx.fill();
    }

    hudWalkers.textContent = walkers.length;
    hudJumps.textContent = totalJumps;
    hudMaxLeap.textContent = maxLeap.toFixed(0);
  }

  // --- events ---
  canvas.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    pointer.x = e.clientX;
    pointer.y = e.clientY;
    pointer.down = true;
    addBurst(e.clientX, e.clientY, 20);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  canvas.addEventListener('pointermove', function(e) {
    pointer.x = e.clientX;
    pointer.y = e.clientY;
  });

  window.addEventListener('pointerup', function() {
    pointer.down = false;
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    // clear trails
    for (var i = 0; i < walkers.length; i++) {
      walkers[i].trail = [];
    }
    totalJumps = 0;
    maxLeap = 0;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  alphaSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  countSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  fadeSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  streakBtn.addEventListener('click', function() {
    showStreaks = !showStreaks;
    streakBtn.textContent = 'STREAKS: ' + (showStreaks ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  burstBtn.addEventListener('click', function() {
    addBurst(W * 0.3 + Math.random() * W * 0.4, H * 0.3 + Math.random() * H * 0.4, 30);
    if (typeof SFX !== 'undefined') SFX.play('grow');
  });

  resetBtn.addEventListener('click', function() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    seed();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);

  function loop() {
    update();
    draw();
    requestAnimationFrame(loop);
  }

  resize();
  seed();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
