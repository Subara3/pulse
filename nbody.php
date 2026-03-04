<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('nbody');

$pageName = 'nbody';
$pageTitle = 'N-BODY - PULSE';
$pageDesc = 'N体重力シミュレーション。粒子が互いに引き合い、軌道を描く。';
$pageCanonical = 'https://pulse.subara3.com/nbody.php';

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
<canvas id="canvas"></canvas>

<div class="ui hud">
  BODIES: <span id="hudBodies" class="v">0</span><br>
  ENERGY: <span id="hudEnergy" class="v">0</span><br>
  TRAILS: <span id="hudTrails" class="v">ON</span>
</div>

<div class="ui info">
  TAP: ADD BODY<br>
  DRAG: LAUNCH WITH VELOCITY<br>
  RIGHT-CLICK: REMOVE NEAREST<br>
  GRAVITY SHAPES THE DANCE
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>GRAVITY</label>
    <input type="range" id="gravSlider" min="1" max="30" value="12">
  </div>
  <div class="slider-wrap">
    <label>TRAIL LENGTH</label>
    <input type="range" id="trailSlider" min="10" max="200" value="80">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="trailBtn">TRAILS: ON</button>
    <button class="control-btn" id="mergeBtn">MERGE: ON</button>
    <button class="control-btn" id="presetBtn">PRESET</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudBodies = document.getElementById('hudBodies');
  var hudEnergy = document.getElementById('hudEnergy');
  var hudTrails = document.getElementById('hudTrails');

  var gravSlider = document.getElementById('gravSlider');
  var trailSlider = document.getElementById('trailSlider');
  var trailBtn = document.getElementById('trailBtn');
  var mergeBtn = document.getElementById('mergeBtn');
  var presetBtn = document.getElementById('presetBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0;
  var bodies = [];
  var showTrails = true;
  var mergeEnabled = true;
  var presetIdx = 0;
  var dragStart = null;
  var dragCurrent = null;
  var SOFTENING = 4;
  var DT = 0.3;

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

  function createBody(x, y, vx, vy, mass) {
    var m = mass || (2 + Math.random() * 6);
    var t = Math.min(1, m / 15);
    bodies.push({
      x: x, y: y,
      vx: vx || 0, vy: vy || 0,
      mass: m,
      rad: Math.sqrt(m) * 2.2,
      col: mix(ACCENT, SUB, t),
      trail: []
    });
    hudBodies.textContent = bodies.length;
  }

  function seed() {
    bodies = [];
    var cx = W / 2, cy = H / 2;
    createBody(cx, cy, 0, 0, 20);
    for (var i = 0; i < 6; i++) {
      var angle = Math.random() * Math.PI * 2;
      var dist = 80 + Math.random() * 150;
      var speed = Math.sqrt(20 * parseFloat(gravSlider.value) / dist) * 0.6;
      var px = cx + Math.cos(angle) * dist;
      var py = cy + Math.sin(angle) * dist;
      var vx = -Math.sin(angle) * speed;
      var vy = Math.cos(angle) * speed;
      createBody(px, py, vx, vy, 1.5 + Math.random() * 3);
    }
  }

  function presetBinary() {
    bodies = [];
    var cx = W / 2, cy = H / 2;
    var sep = 80;
    var spd = 1.2;
    createBody(cx - sep, cy, 0, spd, 12);
    createBody(cx + sep, cy, 0, -spd, 12);
    for (var i = 0; i < 8; i++) {
      var a = Math.random() * Math.PI * 2;
      var d = 180 + Math.random() * 120;
      createBody(cx + Math.cos(a) * d, cy + Math.sin(a) * d,
        -Math.sin(a) * 0.5, Math.cos(a) * 0.5, 1 + Math.random() * 2);
    }
  }

  function presetRing() {
    bodies = [];
    var cx = W / 2, cy = H / 2;
    createBody(cx, cy, 0, 0, 30);
    var count = 20;
    var dist = 140;
    for (var i = 0; i < count; i++) {
      var a = (i / count) * Math.PI * 2;
      var speed = Math.sqrt(30 * parseFloat(gravSlider.value) / dist) * 0.55;
      createBody(cx + Math.cos(a) * dist, cy + Math.sin(a) * dist,
        -Math.sin(a) * speed, Math.cos(a) * speed, 0.8);
    }
  }

  var PRESETS = [seed, presetBinary, presetRing];
  var PRESET_NAMES = ['ORBIT', 'BINARY', 'RING'];

  function updatePhysics() {
    var G = parseFloat(gravSlider.value);
    var n = bodies.length;
    var trailLen = parseInt(trailSlider.value, 10);

    // compute forces
    for (var i = 0; i < n; i++) {
      var a = bodies[i];
      var ax = 0, ay = 0;
      for (var j = 0; j < n; j++) {
        if (i === j) continue;
        var b = bodies[j];
        var dx = b.x - a.x;
        var dy = b.y - a.y;
        var d2 = dx * dx + dy * dy + SOFTENING * SOFTENING;
        var d = Math.sqrt(d2);
        var f = G * b.mass / d2;
        ax += f * dx / d;
        ay += f * dy / d;
      }
      a.vx += ax * DT;
      a.vy += ay * DT;
    }

    // integrate + trails
    for (var i = 0; i < n; i++) {
      var a = bodies[i];
      a.x += a.vx * DT;
      a.y += a.vy * DT;
      if (showTrails) {
        a.trail.push(a.x, a.y);
        if (a.trail.length > trailLen * 2) {
          a.trail.splice(0, a.trail.length - trailLen * 2);
        }
      }
    }

    // merge
    if (mergeEnabled) {
      for (var i = 0; i < bodies.length; i++) {
        for (var j = i + 1; j < bodies.length; j++) {
          var a = bodies[i], b = bodies[j];
          var dx = a.x - b.x, dy = a.y - b.y;
          var dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < a.rad + b.rad) {
            var totalMass = a.mass + b.mass;
            a.x = (a.x * a.mass + b.x * b.mass) / totalMass;
            a.y = (a.y * a.mass + b.y * b.mass) / totalMass;
            a.vx = (a.vx * a.mass + b.vx * b.mass) / totalMass;
            a.vy = (a.vy * a.mass + b.vy * b.mass) / totalMass;
            a.mass = totalMass;
            a.rad = Math.sqrt(totalMass) * 2.2;
            a.col = mix(ACCENT, SUB, Math.min(1, totalMass / 15));
            bodies.splice(j, 1);
            j--;
            if (typeof SFX !== 'undefined') SFX.play('collide', 80);
          }
        }
      }
    }

    hudBodies.textContent = bodies.length;

    // kinetic energy
    var ke = 0;
    for (var i = 0; i < bodies.length; i++) {
      var a = bodies[i];
      ke += 0.5 * a.mass * (a.vx * a.vx + a.vy * a.vy);
    }
    hudEnergy.textContent = ke.toFixed(0);
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.15)';
    ctx.fillRect(0, 0, W, H);

    // trails
    if (showTrails) {
      for (var i = 0; i < bodies.length; i++) {
        var b = bodies[i];
        var t = b.trail;
        if (t.length < 4) continue;
        var len = t.length / 2;
        for (var k = 2; k < t.length; k += 2) {
          var progress = (k / 2) / len;
          ctx.strokeStyle = rgba(b.col, progress * 0.4);
          ctx.lineWidth = 0.5 + progress * 1.2;
          ctx.beginPath();
          ctx.moveTo(t[k - 2], t[k - 1]);
          ctx.lineTo(t[k], t[k + 1]);
          ctx.stroke();
        }
      }
    }

    // bodies
    for (var i = 0; i < bodies.length; i++) {
      var b = bodies[i];

      // glow
      var g = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.rad * 4);
      g.addColorStop(0, rgba(b.col, 0.18));
      g.addColorStop(1, rgba(b.col, 0));
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.rad * 4, 0, Math.PI * 2);
      ctx.fill();

      // core
      ctx.fillStyle = rgba(b.col, 0.9);
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.rad, 0, Math.PI * 2);
      ctx.fill();

      // bright center
      ctx.fillStyle = 'rgba(200,245,240,0.18)';
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.rad * 0.3, 0, Math.PI * 2);
      ctx.fill();
    }

    // drag indicator
    if (dragStart && dragCurrent) {
      ctx.strokeStyle = rgba(ACCENT, 0.4);
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.beginPath();
      ctx.moveTo(dragStart.x, dragStart.y);
      ctx.lineTo(dragCurrent.x, dragCurrent.y);
      ctx.stroke();
      ctx.setLineDash([]);

      // arrow tip
      var dx = dragCurrent.x - dragStart.x;
      var dy = dragCurrent.y - dragStart.y;
      var len = Math.sqrt(dx * dx + dy * dy);
      if (len > 10) {
        ctx.fillStyle = rgba(ACCENT, 0.5);
        ctx.beginPath();
        ctx.arc(dragStart.x, dragStart.y, 4, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function nearestBody(x, y) {
    var best = -1, bestD = Infinity;
    for (var i = 0; i < bodies.length; i++) {
      var dx = x - bodies[i].x, dy = y - bodies[i].y;
      var d = dx * dx + dy * dy;
      if (d < bestD) { bestD = d; best = i; }
    }
    return best;
  }

  canvas.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    dragStart = { x: e.clientX, y: e.clientY };
    dragCurrent = { x: e.clientX, y: e.clientY };
  });

  canvas.addEventListener('pointermove', function(e) {
    if (!dragStart) return;
    dragCurrent = { x: e.clientX, y: e.clientY };
  });

  canvas.addEventListener('pointerup', function(e) {
    if (!dragStart) return;
    var dx = dragStart.x - e.clientX;
    var dy = dragStart.y - e.clientY;
    var len = Math.sqrt(dx * dx + dy * dy);
    var vx = dx * 0.02;
    var vy = dy * 0.02;
    if (len < 5) { vx = 0; vy = 0; }
    createBody(dragStart.x, dragStart.y, vx, vy);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
    dragStart = null;
    dragCurrent = null;
  });

  canvas.addEventListener('pointercancel', function() {
    dragStart = null;
    dragCurrent = null;
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    if (bodies.length <= 1) return;
    var i = nearestBody(e.clientX, e.clientY);
    if (i >= 0) {
      bodies.splice(i, 1);
      hudBodies.textContent = bodies.length;
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
  });

  gravSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  trailSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  trailBtn.addEventListener('click', function() {
    showTrails = !showTrails;
    trailBtn.textContent = 'TRAILS: ' + (showTrails ? 'ON' : 'OFF');
    hudTrails.textContent = showTrails ? 'ON' : 'OFF';
    if (!showTrails) {
      for (var i = 0; i < bodies.length; i++) bodies[i].trail = [];
    }
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  mergeBtn.addEventListener('click', function() {
    mergeEnabled = !mergeEnabled;
    mergeBtn.textContent = 'MERGE: ' + (mergeEnabled ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  presetBtn.addEventListener('click', function() {
    presetIdx = (presetIdx + 1) % PRESETS.length;
    PRESETS[presetIdx]();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resetBtn.addEventListener('click', function() {
    seed();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);

  function loop() {
    updatePhysics();
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
