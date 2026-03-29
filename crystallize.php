<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'CRYSTALLIZE - PULSE';
$pageDesc = '結晶成長シミュレーション。対称性を持つ拡散律速凝集で、雪の結晶のようなデンドライトが育つ。';
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
require_once __DIR__ . '/includes/share.php';
?>

<canvas id="canvas"></canvas>

<div class="ui info" id="infoPanel">
  CLICK TO PLACE SEED<br>
  DRAG TO DRAW BARRIER<br>
  RIGHT-CLICK TO ERASE
</div>

<div class="ui hud" id="hud">
  CRYSTAL: <span id="hudCrystal" class="highlight">0</span><br>
  RADIUS: <span id="hudRadius" class="highlight">0</span><br>
  SYMM: <span id="hudSymm" class="sub">6</span><br>
  MORPH: <span id="hudMode">BRANCH</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="5" max="60" value="30">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="morphBtn">MORPH</button>
    <button class="control-btn" id="symmBtn">SYMM</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="colorBtn">COLOR</button>
    <button class="control-btn" id="pauseBtn">PAUSE</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'drag',  'label' => 'barrier'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var hudCrystal = document.getElementById('hudCrystal');
  var hudRadius  = document.getElementById('hudRadius');
  var hudSymm    = document.getElementById('hudSymm');
  var hudMode    = document.getElementById('hudMode');
  var speedSlider = document.getElementById('speedSlider');

  var W, H, cx, cy;
  var crystal = [];       // {x, y, depth, dist}
  var crystalGrid = {};
  var barriers = [];      // {x, y}
  var barrierGrid = {};
  var paused = false;
  var hideUI = false;
  var maxRadius = 0;
  var stepsPerFrame = 30;

  // ── Modes ────────────────────────────────────────────────────────
  var MORPHS = ['BRANCH', 'STELLAR', 'PLATE', 'NEEDLE'];
  var MORPH_STICK = [0.9, 0.4, 0.12, 0.85];
  var MORPH_MIN_N = [1, 2, 3, 1];
  var morphIdx = 0;

  var SYMMETRIES = [6, 4, 8, 5, 3];
  var symmIdx = 0;
  var symmetry = 6;

  // ── Palettes ─────────────────────────────────────────────────────
  var PALETTES = [
    {
      name: 'AURORA',
      crystal: function(depth, dist, maxD) {
        var t = maxD > 0 ? Math.min(1, dist / maxD) : 0;
        return [
          Math.floor(123 - t * 123),
          Math.floor(44 + t * 201),
          Math.floor(191 + t * 21)
        ];
      }
    },
    {
      name: 'DEEP',
      crystal: function(depth, dist, maxD) {
        var t = maxD > 0 ? Math.min(1, dist / maxD) : 0;
        return [
          Math.floor(20 + t * (0 - 20)),
          Math.floor(60 + t * (245 - 60)),
          Math.floor(180 + t * (212 - 180))
        ];
      }
    },
    {
      name: 'CRYSTAL',
      crystal: function(depth, dist, maxD) {
        var t = maxD > 0 ? Math.min(1, dist / maxD) : 0;
        return [
          Math.floor(80 + t * 100),
          Math.floor(30 + t * 190),
          Math.floor(160 + t * 95)
        ];
      }
    },
    {
      name: 'VOID',
      crystal: function(depth, dist, maxD) {
        var t = maxD > 0 ? Math.min(1, dist / maxD) : 0;
        var l = 20 + t * 40;
        return [Math.floor(l * 1.5), Math.floor(l * 2.0), Math.floor(l * 2.55)];
      }
    }
  ];
  var paletteIdx = 0;
  var palette = PALETTES[0];

  // ── Grid helpers (10px cells) ────────────────────────────────────
  function gridKey(x, y) { return (x / 10 | 0) + ',' + (y / 10 | 0); }

  function addToCrystal(px, py, depth) {
    var dist = Math.sqrt((px - cx) * (px - cx) + (py - cy) * (py - cy));
    if (dist > maxRadius) maxRadius = dist;
    var c = { x: px, y: py, depth: depth, dist: dist };
    crystal.push(c);
    var key = gridKey(px, py);
    if (!crystalGrid[key]) crystalGrid[key] = [];
    crystalGrid[key].push(c);
  }

  function countNearby(px, py, r2) {
    var count = 0;
    var gx = px / 10 | 0, gy = py / 10 | 0;
    for (var dx = -1; dx <= 1; dx++) {
      for (var dy = -1; dy <= 1; dy++) {
        var cell = crystalGrid[(gx + dx) + ',' + (gy + dy)];
        if (!cell) continue;
        for (var i = 0; i < cell.length; i++) {
          var ddx = px - cell[i].x, ddy = py - cell[i].y;
          if (ddx * ddx + ddy * ddy < r2) count++;
        }
      }
    }
    return count;
  }

  function hitBarrier(px, py) {
    var gx = px / 10 | 0, gy = py / 10 | 0;
    for (var dx = -1; dx <= 1; dx++) {
      for (var dy = -1; dy <= 1; dy++) {
        var cell = barrierGrid[(gx + dx) + ',' + (gy + dy)];
        if (!cell) continue;
        for (var i = 0; i < cell.length; i++) {
          var ddx = px - cell[i].x, ddy = py - cell[i].y;
          if (ddx * ddx + ddy * ddy < 100) return true;
        }
      }
    }
    return false;
  }

  // ── Freeze with symmetry ─────────────────────────────────────────
  function freezeSymmetric(px, py) {
    var dx = px - cx, dy = py - cy;
    var depth = crystal.length;
    for (var k = 0; k < symmetry; k++) {
      var a = k * Math.PI * 2 / symmetry;
      var nx = cx + dx * Math.cos(a) - dy * Math.sin(a);
      var ny = cy + dx * Math.sin(a) + dy * Math.cos(a);
      // Also add mirror
      addToCrystal(nx, ny, depth);
      if (symmetry >= 4) {
        addToCrystal(cx + dx * Math.cos(a) + dy * Math.sin(a),
                     cy + dx * Math.sin(a) - dy * Math.cos(a), depth);
      }
    }
  }

  // ── DLA walker step ──────────────────────────────────────────────
  function walkOnce() {
    var spawnR = maxRadius + 20 + Math.random() * 30;
    var killR2 = (spawnR + 80) * (spawnR + 80);
    var angle = Math.random() * Math.PI * 2;
    var wx = cx + Math.cos(angle) * spawnR;
    var wy = cy + Math.sin(angle) * spawnR;

    var stickP = MORPH_STICK[morphIdx];
    var minN   = MORPH_MIN_N[morphIdx];
    var r2     = 36; // 6px collision radius squared

    for (var s = 0; s < 4000; s++) {
      var ddx = wx - cx, ddy = wy - cy;
      if (ddx * ddx + ddy * ddy > killR2) return;

      // Adaptive step
      var dist = Math.sqrt(ddx * ddx + ddy * ddy);
      var gap  = dist - maxRadius;
      if (gap > 15) {
        var step = Math.min(gap * 0.4, 12);
        var a2 = Math.random() * Math.PI * 2;
        wx += Math.cos(a2) * step;
        wy += Math.sin(a2) * step;
        // slight inward drift
        wx += (cx - wx) * 0.003;
        wy += (cy - wy) * 0.003;
        continue;
      }

      // Small step near crystal
      wx += (Math.random() - 0.5) * 5;
      wy += (Math.random() - 0.5) * 5;

      if (hitBarrier(wx, wy)) return;

      var nearby = countNearby(wx, wy, r2);
      if (nearby >= minN && Math.random() < stickP) {
        freezeSymmetric(wx, wy);
        SFX.play('tick', 40);
        return;
      }
    }
  }

  // ── Init ─────────────────────────────────────────────────────────
  function init() {
    crystal = [];
    crystalGrid = {};
    maxRadius = 0;
    // Center seed
    addToCrystal(cx, cy, 0);
    // Pre-warm: grow a visible initial shape
    for (var i = 0; i < 400; i++) walkOnce();
  }

  // ── Render ───────────────────────────────────────────────────────
  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var maxD = maxRadius || 1;

    // Draw crystal
    for (var i = 0; i < crystal.length; i++) {
      var c = crystal[i];
      var col = palette.crystal(c.depth, c.dist, maxD);
      ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
      ctx.fillRect(c.x - 1.5, c.y - 1.5, 3, 3);
    }

    // Glow on recent additions
    var recentN = Math.min(80, crystal.length);
    var start = crystal.length - recentN;
    for (var i = start; i < crystal.length; i++) {
      if (i < 0) continue;
      var c = crystal[i];
      var t = (i - start) / recentN;
      ctx.fillStyle = 'rgba(0,245,212,' + (t * 0.25).toFixed(2) + ')';
      ctx.beginPath();
      ctx.arc(c.x, c.y, 5, 0, Math.PI * 2);
      ctx.fill();
    }

    // Draw barriers
    ctx.fillStyle = 'rgba(123,44,191,0.35)';
    for (var i = 0; i < barriers.length; i++) {
      ctx.beginPath();
      ctx.arc(barriers[i].x, barriers[i].y, 5, 0, Math.PI * 2);
      ctx.fill();
    }

    // HUD
    hudCrystal.textContent = crystal.length;
    hudRadius.textContent  = Math.floor(maxRadius);
    hudSymm.textContent    = symmetry;
    hudMode.textContent    = MORPHS[morphIdx];
  }

  // ── Loop ─────────────────────────────────────────────────────────
  function loop() {
    if (!paused) {
      stepsPerFrame = parseInt(speedSlider.value);
      for (var i = 0; i < stepsPerFrame; i++) walkOnce();
    }
    render();
    requestAnimationFrame(loop);
  }

  // ── Mouse: click=seed, drag=barrier, right=erase ─────────────────
  var mouseDown = false, hasMoved = false, erasing = false;
  var startMX = 0, startMY = 0;

  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    return [(e.clientX - r.left) * (W / r.width), (e.clientY - r.top) * (H / r.height)];
  }

  canvas.addEventListener('mousedown', function(e) {
    e.preventDefault();
    mouseDown = true; hasMoved = false;
    startMX = e.clientX; startMY = e.clientY;
    erasing = (e.button === 2);
    if (erasing) {
      var p = getPos(e);
      eraseBarrier(p[0], p[1]);
      SFX.play('pop', 60);
    }
  });

  canvas.addEventListener('mousemove', function(e) {
    if (!mouseDown) return;
    if (!hasMoved && Math.abs(e.clientX - startMX) < 4 && Math.abs(e.clientY - startMY) < 4) return;
    hasMoved = true;
    var p = getPos(e);
    if (erasing) eraseBarrier(p[0], p[1]);
    else addBarrier(p[0], p[1]);
  });

  canvas.addEventListener('mouseup', function(e) {
    if (!hasMoved && !erasing) {
      var p = getPos(e);
      freezeSymmetric(p[0], p[1]);
      SFX.play('spawn');
    }
    mouseDown = false; hasMoved = false; erasing = false;
  });

  canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // ── Touch ────────────────────────────────────────────────────────
  var touchStart = null, touchMoved = false;

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      touchStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
      touchMoved = false;
    }
  }, { passive: false });

  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!touchStart || e.touches.length !== 1) return;
    if (!touchMoved && Math.abs(e.touches[0].clientX - touchStart.x) < 4
        && Math.abs(e.touches[0].clientY - touchStart.y) < 4) return;
    touchMoved = true;
    var r = canvas.getBoundingClientRect();
    var px = (e.touches[0].clientX - r.left) * (W / r.width);
    var py = (e.touches[0].clientY - r.top) * (H / r.height);
    addBarrier(px, py);
  }, { passive: false });

  canvas.addEventListener('touchend', function(e) {
    if (touchStart && !touchMoved) {
      var r = canvas.getBoundingClientRect();
      var px = (touchStart.x - r.left) * (W / r.width);
      var py = (touchStart.y - r.top) * (H / r.height);
      freezeSymmetric(px, py);
      SFX.play('spawn');
    }
    touchStart = null; touchMoved = false;
  });

  // ── Barrier paint/erase ──────────────────────────────────────────
  function addBarrier(px, py) {
    var b = { x: px, y: py };
    barriers.push(b);
    var key = gridKey(px, py);
    if (!barrierGrid[key]) barrierGrid[key] = [];
    barrierGrid[key].push(b);
    SFX.play('tick', 90);
  }

  function eraseBarrier(px, py) {
    var r2 = 400; // 20px radius
    barriers = barriers.filter(function(b) {
      var dx = b.x - px, dy = b.y - py;
      return dx * dx + dy * dy > r2;
    });
    // Rebuild grid
    barrierGrid = {};
    for (var i = 0; i < barriers.length; i++) {
      var key = gridKey(barriers[i].x, barriers[i].y);
      if (!barrierGrid[key]) barrierGrid[key] = [];
      barrierGrid[key].push(barriers[i]);
    }
    SFX.play('pop', 60);
  }

  // ── Controls ─────────────────────────────────────────────────────
  document.getElementById('morphBtn').addEventListener('click', function() {
    morphIdx = (morphIdx + 1) % MORPHS.length;
    SFX.play('toggle');
  });

  document.getElementById('symmBtn').addEventListener('click', function() {
    symmIdx = (symmIdx + 1) % SYMMETRIES.length;
    symmetry = SYMMETRIES[symmIdx];
    crystal = []; crystalGrid = {}; maxRadius = 0;
    addToCrystal(cx, cy, 0);
    for (var i = 0; i < 400; i++) walkOnce();
    SFX.play('toggle');
  });

  document.getElementById('colorBtn').addEventListener('click', function() {
    paletteIdx = (paletteIdx + 1) % PALETTES.length;
    palette = PALETTES[paletteIdx];
    SFX.play('click');
  });

  document.getElementById('pauseBtn').addEventListener('click', function() {
    paused = !paused;
    this.classList.toggle('active', paused);
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    SFX.play('toggle');
  });

  document.getElementById('resetBtn').addEventListener('click', function() {
    barriers = []; barrierGrid = {};
    init();
    SFX.play('click');
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'h' || e.key === 'H') {
      hideUI = !hideUI;
      document.body.classList.toggle('hide-ui', hideUI);
    }
    if (e.key === ' ') {
      e.preventDefault();
      paused = !paused;
      document.getElementById('pauseBtn').classList.toggle('active', paused);
      document.getElementById('pauseBtn').textContent = paused ? 'PLAY' : 'PAUSE';
    }
  });

  // ── Resize ───────────────────────────────────────────────────────
  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    barriers = []; barrierGrid = {};
    init();
  }
  window.addEventListener('resize', resize);

  var style = document.createElement('style');
  style.textContent = '.hide-ui .ui, .hide-ui .floating-nav, .hide-ui .content-controls { opacity: 0 !important; pointer-events: none; }';
  document.head.appendChild(style);

  // ── Start ────────────────────────────────────────────────────────
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
