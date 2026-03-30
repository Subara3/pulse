<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'ORBIT - PULSE';
$pageDesc = 'N体重力シミュレーション。質点を配置して軌道を眺める。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.hud .sub { color: #c455ff; }
.info { bottom: 1.5rem; left: 1.5rem; color: rgba(255,255,255,0.2); font-size: 0.6rem; letter-spacing: 0.05em; line-height: 1.8; }

@media (max-width: 768px) {
  .hud { font-size: 0.55rem; top: 0.5rem; right: 0.5rem; }
  .info { bottom: 1rem; left: 1rem; font-size: 0.5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  CLICK TO ADD BODY<br>
  DRAG TO SET VELOCITY<br>
  RIGHT-CLICK TO CLEAR
</div>

<div class="ui hud" id="hud">
  BODIES: <span id="hudBodies" class="highlight">0</span><br>
  ENERGY: <span id="hudEnergy" class="highlight">0</span><br>
  PRESET: <span id="hudPreset" class="sub">TRINARY</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="8" value="3">
  </div>
  <div class="slider-wrap">
    <label>TRAIL</label>
    <input type="range" id="trailSlider" min="20" max="400" step="20" value="200">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="presetBtn">PRESET</button>
    <button class="control-btn" id="massBtn">MASS: M</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="pauseBtn">PAUSE</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add body'],
  ['type' => 'drag',  'label' => 'velocity'],
  ['type' => 'right', 'label' => 'clear all'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W, H;

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize);
  resize();

  // ── Bodies ───────────────────────────────────────────────────
  var bodies = [];
  var G = 800;
  var dt = 0.016;
  var stepsPerFrame = 3;
  var trailLen = 200;
  var paused = false;
  var massLevel = 1;  // 0=S, 1=M, 2=L
  var MASS_LABELS = ['S', 'M', 'L'];
  var MASS_VALUES = [4, 12, 40];
  var MASS_RADII  = [3, 5, 9];

  // ── Colors per body ──────────────────────────────────────────
  var COLORS = [
    [0, 245, 212],
    [123, 44, 191],
    [80, 180, 255],
    [160, 80, 220],
    [0, 200, 180],
    [100, 60, 200],
    [40, 220, 200],
    [180, 100, 255],
    [0, 160, 140],
    [140, 50, 180]
  ];

  function addBody(x, y, vx, vy, mass) {
    var r = mass < 8 ? 3 : mass < 20 ? 5 : 9;
    var col = COLORS[bodies.length % COLORS.length];
    bodies.push({
      x: x, y: y, vx: vx, vy: vy,
      mass: mass, r: r, col: col,
      trail: []
    });
  }

  // ── Presets ──────────────────────────────────────────────────
  var PRESETS = ['TRINARY', 'FIGURE-8', 'SOLAR', 'BINARY', 'RANDOM'];
  var presetIdx = 0;

  function loadPreset(name) {
    bodies = [];
    var cx = W / 2, cy = H / 2;

    if (name === 'TRINARY') {
      var d = Math.min(W, H) * 0.18;
      for (var i = 0; i < 3; i++) {
        var a = i * Math.PI * 2 / 3 - Math.PI / 2;
        var speed = 90;
        addBody(
          cx + Math.cos(a) * d, cy + Math.sin(a) * d,
          -Math.sin(a) * speed, Math.cos(a) * speed,
          15
        );
      }
    } else if (name === 'FIGURE-8') {
      var s = Math.min(W, H) * 0.15;
      addBody(cx - s, cy, 0, -65, 12);
      addBody(cx + s, cy, 0, 65, 12);
      addBody(cx, cy, 0, 0, 12);
    } else if (name === 'SOLAR') {
      addBody(cx, cy, 0, 0, 60);
      var orbits = [
        { d: 80,  v: 190, m: 3 },
        { d: 140, v: 140, m: 5 },
        { d: 210, v: 115, m: 4 },
        { d: 300, v: 95,  m: 8 }
      ];
      for (var i = 0; i < orbits.length; i++) {
        var o = orbits[i];
        var a = Math.random() * Math.PI * 2;
        addBody(
          cx + Math.cos(a) * o.d, cy + Math.sin(a) * o.d,
          -Math.sin(a) * o.v, Math.cos(a) * o.v,
          o.m
        );
      }
    } else if (name === 'BINARY') {
      var sep = Math.min(W, H) * 0.12;
      addBody(cx - sep, cy, 0, -80, 20);
      addBody(cx + sep, cy, 0, 80, 20);
      for (var i = 0; i < 4; i++) {
        var a = Math.random() * Math.PI * 2;
        var d = 150 + Math.random() * 100;
        var v = 70 + Math.random() * 30;
        addBody(
          cx + Math.cos(a) * d, cy + Math.sin(a) * d,
          -Math.sin(a) * v, Math.cos(a) * v,
          2 + Math.random() * 3
        );
      }
    } else if (name === 'RANDOM') {
      var n = 5 + Math.floor(Math.random() * 4);
      for (var i = 0; i < n; i++) {
        var a = Math.random() * Math.PI * 2;
        var d = 50 + Math.random() * Math.min(W, H) * 0.25;
        var v = 40 + Math.random() * 80;
        addBody(
          cx + Math.cos(a) * d, cy + Math.sin(a) * d,
          -Math.sin(a) * v + (Math.random() - 0.5) * 30,
          Math.cos(a) * v + (Math.random() - 0.5) * 30,
          3 + Math.random() * 25
        );
      }
    }
  }

  // ── Physics (Velocity Verlet) ────────────────────────────────
  function computeAccel(b, allBodies) {
    var ax = 0, ay = 0;
    for (var j = 0; j < allBodies.length; j++) {
      var o = allBodies[j];
      if (o === b) continue;
      var dx = o.x - b.x, dy = o.y - b.y;
      var dist2 = dx * dx + dy * dy;
      var soft = 400;  // softening
      var f = G * o.mass / (dist2 + soft);
      var dist = Math.sqrt(dist2 + soft);
      ax += f * dx / dist;
      ay += f * dy / dist;
    }
    return [ax, ay];
  }

  function step() {
    var n = bodies.length;
    if (n < 1) return;

    // Compute accelerations
    var accels = [];
    for (var i = 0; i < n; i++) {
      accels.push(computeAccel(bodies[i], bodies));
    }

    // Update positions and velocities (leapfrog)
    for (var i = 0; i < n; i++) {
      var b = bodies[i];
      b.vx += accels[i][0] * dt;
      b.vy += accels[i][1] * dt;
      b.x += b.vx * dt;
      b.y += b.vy * dt;

      // Store trail
      b.trail.push(b.x, b.y);
      if (b.trail.length > trailLen * 2) {
        b.trail.splice(0, 2);
      }
    }

    // Merge close massive bodies
    for (var i = 0; i < bodies.length; i++) {
      for (var j = i + 1; j < bodies.length; j++) {
        var a = bodies[i], b = bodies[j];
        var dx = a.x - b.x, dy = a.y - b.y;
        var minD = a.r + b.r;
        if (dx * dx + dy * dy < minD * minD) {
          var totalM = a.mass + b.mass;
          a.vx = (a.vx * a.mass + b.vx * b.mass) / totalM;
          a.vy = (a.vy * a.mass + b.vy * b.mass) / totalM;
          a.x = (a.x * a.mass + b.x * b.mass) / totalM;
          a.y = (a.y * a.mass + b.y * b.mass) / totalM;
          a.mass = totalM;
          a.r = totalM < 8 ? 3 : totalM < 20 ? 5 : totalM < 50 ? 9 : 13;
          bodies.splice(j, 1);
          j--;
          SFX.play('collide', 100);
        }
      }
    }
  }

  // ── Render ───────────────────────────────────────────────────
  function render() {
    // Fade background (creates trail effect)
    ctx.fillStyle = 'rgba(10, 10, 15, 0.15)';
    ctx.fillRect(0, 0, W, H);

    for (var i = 0; i < bodies.length; i++) {
      var b = bodies[i];
      var col = b.col;

      // Draw trail
      if (b.trail.length >= 4) {
        ctx.beginPath();
        ctx.moveTo(b.trail[0], b.trail[1]);
        for (var t = 2; t < b.trail.length; t += 2) {
          ctx.lineTo(b.trail[t], b.trail[t + 1]);
        }
        ctx.strokeStyle = 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0.35)';
        ctx.lineWidth = 1;
        ctx.stroke();
      }

      // Draw body
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
      ctx.fill();

      // Glow
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r * 3, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + col[0] + ',' + col[1] + ',' + col[2] + ',0.08)';
      ctx.fill();
    }

    // Drag indicator
    if (dragging) {
      ctx.beginPath();
      ctx.moveTo(dragStart[0], dragStart[1]);
      ctx.lineTo(dragCurrent[0], dragCurrent[1]);
      ctx.strokeStyle = 'rgba(0,245,212,0.4)';
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.stroke();
      ctx.setLineDash([]);

      // Ghost body
      ctx.beginPath();
      ctx.arc(dragStart[0], dragStart[1], MASS_RADII[massLevel], 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(0,245,212,0.3)';
      ctx.fill();
    }
  }

  // ── HUD ──────────────────────────────────────────────────────
  var elBodies = document.getElementById('hudBodies');
  var elEnergy = document.getElementById('hudEnergy');
  var elPreset = document.getElementById('hudPreset');
  var tick = 0;

  function updateHUD() {
    if (++tick % 6 !== 0) return;
    elBodies.textContent = bodies.length;

    // Total kinetic energy
    var ke = 0;
    for (var i = 0; i < bodies.length; i++) {
      var b = bodies[i];
      ke += 0.5 * b.mass * (b.vx * b.vx + b.vy * b.vy);
    }
    elEnergy.textContent = (ke / 1000).toFixed(1) + 'k';
    elPreset.textContent = PRESETS[presetIdx];
  }

  // ── Main loop ────────────────────────────────────────────────
  function loop() {
    if (!paused) {
      for (var i = 0; i < stepsPerFrame; i++) step();
    }
    render();
    updateHUD();
    requestAnimationFrame(loop);
  }

  // ── Mouse events ─────────────────────────────────────────────
  var dragging = false;
  var dragStart = [0, 0];
  var dragCurrent = [0, 0];

  canvas.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    e.preventDefault();
    dragging = true;
    dragStart = [e.clientX, e.clientY];
    dragCurrent = [e.clientX, e.clientY];
  });

  canvas.addEventListener('mousemove', function(e) {
    if (dragging) {
      dragCurrent = [e.clientX, e.clientY];
    }
  });

  canvas.addEventListener('mouseup', function(e) {
    if (e.button === 2) return;
    if (dragging) {
      var vx = (dragStart[0] - dragCurrent[0]) * 2;
      var vy = (dragStart[1] - dragCurrent[1]) * 2;
      addBody(dragStart[0], dragStart[1], vx, vy, MASS_VALUES[massLevel]);
      SFX.play('spawn');
      dragging = false;
    }
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    bodies = [];
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    SFX.play('click');
  });

  // ── Touch events ─────────────────────────────────────────────
  var touchStart = null;

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
      dragging = true;
      dragStart = [touchStart.x, touchStart.y];
      dragCurrent = [touchStart.x, touchStart.y];
    }
  }, { passive: false });

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragging && e.touches.length === 1) {
      dragCurrent = [e.touches[0].clientX, e.touches[0].clientY];
    }
  }, { passive: false });

  canvas.addEventListener('touchend', function(e) {
    if (dragging && touchStart) {
      var t = e.changedTouches[0];
      var vx = (dragStart[0] - t.clientX) * 2;
      var vy = (dragStart[1] - t.clientY) * 2;
      addBody(dragStart[0], dragStart[1], vx, vy, MASS_VALUES[massLevel]);
      SFX.play('spawn');
    }
    dragging = false;
    touchStart = null;
  });

  // ── Controls ─────────────────────────────────────────────────
  document.getElementById('presetBtn').addEventListener('click', function() {
    presetIdx = (presetIdx + 1) % PRESETS.length;
    loadPreset(PRESETS[presetIdx]);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    SFX.play('toggle');
  });

  document.getElementById('massBtn').addEventListener('click', function() {
    massLevel = (massLevel + 1) % 3;
    this.textContent = 'MASS: ' + MASS_LABELS[massLevel];
    SFX.play('click');
  });

  document.getElementById('pauseBtn').addEventListener('click', function() {
    paused = !paused;
    this.classList.toggle('active', paused);
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    SFX.play('toggle');
  });

  document.getElementById('resetBtn').addEventListener('click', function() {
    loadPreset(PRESETS[presetIdx]);
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    SFX.play('click');
  });

  document.getElementById('speedSlider').addEventListener('input', function(e) {
    stepsPerFrame = parseInt(e.target.value);
  });

  document.getElementById('trailSlider').addEventListener('input', function(e) {
    trailLen = parseInt(e.target.value);
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === ' ') {
      e.preventDefault();
      paused = !paused;
      document.getElementById('pauseBtn').classList.toggle('active', paused);
      document.getElementById('pauseBtn').textContent = paused ? 'PLAY' : 'PAUSE';
    }
  });

  // ── Start ────────────────────────────────────────────────────
  loadPreset('TRINARY');
  // Initial full clear
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);
  requestAnimationFrame(loop);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
