<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'CRYSTALLIZE - PULSE';
$pageDesc = '結晶成長シミュレーション。拡散律速凝集に六方対称を課したデンドライト構造の生成。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; image-rendering: pixelated; image-rendering: crisp-edges; }
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
  CLICK TO SEED<br>
  RIGHT-CLICK TO RESET
</div>

<div class="ui hud" id="hud">
  CELLS: <span id="hudCells" class="highlight">0</span><br>
  RADIUS: <span id="hudRadius" class="highlight">0</span><br>
  SYMM: <span id="hudSymm" class="sub">6</span><br>
  MORPH: <span id="hudMorph">BRANCH</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="5" max="60" value="20">
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
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  /* ================================================================
     CRYSTALLIZE — DLA + rotational symmetry
     Original rendering & physics kept intact from Day 060 commit.
     UI upgraded to drawer-controls style.
     ================================================================ */

  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');

  // ── Grid ──────────────────────────────────────────────────────
  var GSIZE = 340;
  var GCX = GSIZE >> 1, GCY = GSIZE >> 1;

  var frozen   = new Uint8Array(GSIZE * GSIZE);
  var frozenT  = new Uint32Array(GSIZE * GSIZE);
  var stepCount = 0;
  var iceCount  = 0;
  var maxRadius = 0;
  var growing   = true;
  var fadeAlpha = 0;
  var paused    = false;
  var stepsPerFrame = 20;

  // ── Morphology ───────────────────────────────────────────────
  var MORPHS = ['BRANCH', 'STELLAR', 'PLATE', 'NEEDLE'];
  var MORPH_STICK = [1.0, 0.50, 0.12, 0.85];
  var morphIdx = 0;

  // ── Symmetry ─────────────────────────────────────────────────
  var SYMMETRIES = [6, 4, 8, 5, 3];
  var symmIdx = 0;
  var symmetry = 6;

  // ── Color palettes ───────────────────────────────────────────
  var PALETTES = [
    { name: 'AURORA',  a: [123,44,191],  b: [0,245,212] },
    { name: 'DEEP',    a: [20,60,180],   b: [0,245,212] },
    { name: 'CRYSTAL', a: [80,30,160],   b: [180,220,255] },
    { name: 'VOID',    a: [40,50,70],    b: [120,160,200] }
  ];
  var palIdx = 0;

  // ── Helpers ──────────────────────────────────────────────────
  function idx(x, y) { return y * GSIZE + x; }
  function valid(x, y) { return x >= 0 && x < GSIZE && y >= 0 && y < GSIZE; }

  function isAdj(x, y) {
    for (var dy = -1; dy <= 1; dy++)
      for (var dx = -1; dx <= 1; dx++) {
        if (!dx && !dy) continue;
        var nx = x + dx, ny = y + dy;
        if (valid(nx, ny) && frozen[idx(nx, ny)]) return true;
      }
    return false;
  }

  // Freeze with n-fold rotational symmetry
  function freeze(x, y) {
    stepCount++;
    var dx = x - GCX, dy = y - GCY;
    var r = Math.sqrt(dx * dx + dy * dy);
    if (r > maxRadius) maxRadius = r;

    for (var k = 0; k < symmetry; k++) {
      var a = k * Math.PI * 2 / symmetry;
      var ca = Math.cos(a), sa = Math.sin(a);
      var nx = Math.round(GCX + dx * ca - dy * sa);
      var ny = Math.round(GCY + dx * sa + dy * ca);
      if (valid(nx, ny)) {
        var i = idx(nx, ny);
        if (!frozen[i]) { frozen[i] = 1; frozenT[i] = stepCount; iceCount++; }
      }
    }
  }

  function reset() {
    frozen.fill(0); frozenT.fill(0);
    stepCount = 0; iceCount = 0; maxRadius = 0;
    growing = true; fadeAlpha = 0;
    freeze(GCX, GCY);
    for (var i = 0; i < 220; i++) stepWalker();
  }

  // ── DLA walker (unchanged from original) ─────────────────────
  function stepWalker() {
    if (maxRadius >= GCX - 10) { growing = false; return; }

    var p   = MORPH_STICK[morphIdx];
    var spR = maxRadius + 3 + Math.random() * 6;
    var a0  = Math.random() * Math.PI * 2;
    var wx  = GCX + spR * Math.cos(a0);
    var wy  = GCY + spR * Math.sin(a0);
    var kR2 = (spR + 40) * (spR + 40);

    for (var s = 0; s < 5000; s++) {
      var ddx = wx - GCX, ddy = wy - GCY;
      if (ddx * ddx + ddy * ddy > kR2) return;

      var dist = Math.sqrt(ddx * ddx + ddy * ddy);
      var step = Math.min(8, Math.max(1, (dist - maxRadius - 1.5) | 0));
      var a    = Math.random() * Math.PI * 2;
      wx += step * Math.cos(a);
      wy += step * Math.sin(a);

      var ix = Math.round(wx), iy = Math.round(wy);
      if (!valid(ix, iy)) return;
      if (!frozen[idx(ix, iy)] && isAdj(ix, iy) && Math.random() < p) {
        freeze(ix, iy);
        SFX.play('tick', 80);
        return;
      }
    }
  }

  // ── Offscreen buffer (unchanged from original) ───────────────
  var off  = document.createElement('canvas');
  off.width  = GSIZE;
  off.height = GSIZE;
  var octx = off.getContext('2d');

  // ── Render (original + palette support) ──────────────────────
  function render() {
    var W  = canvas.width, H = canvas.height;
    var sc = Math.min(W, H) / GSIZE;
    var ox = (W - GSIZE * sc) / 2;
    var oy = (H - GSIZE * sc) / 2;

    var img = octx.createImageData(GSIZE, GSIZE);
    var d   = img.data;
    var mx  = stepCount || 1;
    var pal = PALETTES[palIdx];

    for (var y = 0; y < GSIZE; y++)
      for (var x = 0; x < GSIZE; x++) {
        var i  = idx(x, y);
        var p4 = i * 4;
        if (frozen[i]) {
          var t = frozenT[i] / mx;
          d[p4]   = Math.round(pal.a[0] + (pal.b[0] - pal.a[0]) * t);
          d[p4+1] = Math.round(pal.a[1] + (pal.b[1] - pal.a[1]) * t);
          d[p4+2] = Math.round(pal.a[2] + (pal.b[2] - pal.a[2]) * t);
          d[p4+3] = 255;
        } else {
          d[p4] = 10; d[p4+1] = 10; d[p4+2] = 15; d[p4+3] = 255;
        }
      }
    octx.putImageData(img, 0, 0);

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var blurPx = Math.min(6, Math.round(sc * 2.5));
    ctx.filter      = 'blur(' + blurPx + 'px)';
    ctx.globalAlpha = 0.55;
    ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);
    ctx.filter      = 'none';
    ctx.globalAlpha = 1.0;
    ctx.drawImage(off, ox, oy, GSIZE * sc, GSIZE * sc);

    if (fadeAlpha > 0) {
      ctx.fillStyle = 'rgba(10,10,15,' + fadeAlpha + ')';
      ctx.fillRect(0, 0, W, H);
    }
  }

  // ── Resize ───────────────────────────────────────────────────
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  window.addEventListener('resize', resize);
  resize();

  // ── HUD ──────────────────────────────────────────────────────
  var elCells = document.getElementById('hudCells');
  var elRadius = document.getElementById('hudRadius');
  var elSymm = document.getElementById('hudSymm');
  var elMorph = document.getElementById('hudMorph');
  var tick = 0;

  function updateHUD() {
    if (++tick % 6 !== 0) return;
    elCells.textContent = iceCount;
    elRadius.textContent = maxRadius | 0;
    elSymm.textContent = symmetry;
    elMorph.textContent = MORPHS[morphIdx];
  }

  // ── Animation loop ───────────────────────────────────────────
  function animate() {
    if (!paused) {
      if (!growing) {
        fadeAlpha = Math.min(1, fadeAlpha + 0.012);
        if (fadeAlpha >= 1) reset();
      } else {
        for (var i = 0; i < stepsPerFrame; i++) stepWalker();
      }
    }
    render();
    updateHUD();
    requestAnimationFrame(animate);
  }

  // ── Coordinate helper ────────────────────────────────────────
  function toGrid(clientX, clientY) {
    var sc = Math.min(canvas.width, canvas.height) / GSIZE;
    var ox = (canvas.width  - GSIZE * sc) / 2;
    var oy = (canvas.height - GSIZE * sc) / 2;
    return [Math.round((clientX - ox) / sc), Math.round((clientY - oy) / sc)];
  }

  // ── Events (unchanged from original) ─────────────────────────
  canvas.addEventListener('click', function(e) {
    var g = toGrid(e.clientX, e.clientY);
    if (valid(g[0], g[1]) && !frozen[idx(g[0], g[1])]) {
      freeze(g[0], g[1]);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    reset();
    SFX.play('click');
  });

  canvas.addEventListener('touchend', function(e) {
    e.preventDefault();
    var t = e.changedTouches[0];
    var g = toGrid(t.clientX, t.clientY);
    if (valid(g[0], g[1]) && !frozen[idx(g[0], g[1])]) {
      freeze(g[0], g[1]);
      growing = true; fadeAlpha = 0;
      SFX.play('spawn');
    }
  }, { passive: false });

  // ── Control buttons ──────────────────────────────────────────
  document.getElementById('morphBtn').addEventListener('click', function() {
    morphIdx = (morphIdx + 1) % MORPHS.length;
    reset();
    SFX.play('toggle');
  });

  document.getElementById('symmBtn').addEventListener('click', function() {
    symmIdx = (symmIdx + 1) % SYMMETRIES.length;
    symmetry = SYMMETRIES[symmIdx];
    reset();
    SFX.play('toggle');
  });

  document.getElementById('colorBtn').addEventListener('click', function() {
    palIdx = (palIdx + 1) % PALETTES.length;
    SFX.play('click');
  });

  document.getElementById('pauseBtn').addEventListener('click', function() {
    paused = !paused;
    this.classList.toggle('active', paused);
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    SFX.play('toggle');
  });

  document.getElementById('resetBtn').addEventListener('click', function() {
    reset();
    SFX.play('click');
  });

  document.getElementById('speedSlider').addEventListener('input', function(e) {
    stepsPerFrame = parseInt(e.target.value);
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
  reset();
  requestAnimationFrame(animate);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/related.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
