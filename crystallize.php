<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('crystallize');

$pageName = 'crystallize';
$pageTitle = 'CRYSTALLIZE - PULSE';
$pageDesc = '結晶成長シミュレーション。拡散律速凝集に六方対称を課したデンドライト構造の生成。';
$pageCanonical = 'https://pulse.subara3.com/crystallize.php';
$extraStyle = '
body { overflow:hidden; cursor:crosshair; }
canvas {
  display:block; position:fixed; top:0; left:0;
  width:100%; height:100%; z-index:0;
  image-rendering:pixelated;
  image-rendering:crisp-edges;
}
.controls {
  position:fixed; bottom:1.5rem; right:1.5rem;
  display:flex; gap:0.5rem; flex-direction:column; align-items:flex-end; z-index:10;
}
.btn {
  background:rgba(0,245,212,0.08); border:1px solid rgba(0,245,212,0.25);
  color:rgba(0,245,212,0.7); padding:0.35rem 0.8rem;
  font-size:0.65rem; letter-spacing:0.15em; cursor:pointer;
  font-family:inherit; transition:all 0.2s;
}
.btn:hover { background:rgba(0,245,212,0.18); color:#00f5d4; }
.btn.active { background:rgba(0,245,212,0.22); color:#00f5d4; border-color:#00f5d4; }
.hud {
  position:fixed; top:1rem; left:1.5rem;
  font-family:"JetBrains Mono",monospace; font-size:0.65rem;
  color:rgba(255,255,255,0.3); line-height:1.8; z-index:10; pointer-events:none;
}
.hud .va { color:#00f5d4; }
.hud .vb { color:#c455ff; }
.slider-wrap {
  display:flex; align-items:center; gap:0.4rem;
  font-family:"JetBrains Mono",monospace; font-size:0.6rem;
  color:rgba(255,255,255,0.35);
}
.slider-wrap input[type=range] { width:80px; accent-color:#00f5d4; }
@media (max-width:768px) {
  .controls { bottom:1rem; right:1rem; }
  .btn { font-size:0.55rem; padding:0.25rem 0.5rem; }
  .hud { font-size:0.55rem; top:0.7rem; left:1rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="hud">
  <div>CELLS <span class="va" id="hudCells">0</span></div>
  <div>RADIUS <span class="va" id="hudRadius">0</span></div>
  <div>SYMM <span class="vb" id="hudSymm">6</span></div>
  <div>MORPH <span id="hudMorph" style="color:rgba(255,255,255,0.5)">BRANCH</span></div>
</div>

<div class="controls">
  <button class="btn" id="btnMorph">BRANCH</button>
  <button class="btn" id="btnSymm">6-FOLD</button>
  <div class="slider-wrap">SPEED <input type="range" id="slSpeed" min="5" max="60" value="20"></div>
  <button class="btn" id="btnColor">COLOR</button>
  <button class="btn" id="btnPause">PAUSE</button>
  <button class="btn" id="btnReset">RESET</button>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');

  // ── Grid (ORIGINAL unchanged) ────────────────────────────────
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
  var SYMM_LABELS = ['6-FOLD','4-FOLD','8-FOLD','5-FOLD','3-FOLD'];
  var symmIdx = 0;
  var symmetry = 6;

  // ── Palettes ─────────────────────────────────────────────────
  var PALETTES = [
    { a:[123,44,191], b:[0,245,212] },
    { a:[20,60,180],  b:[0,245,212] },
    { a:[80,30,160],  b:[180,220,255] },
    { a:[40,50,70],   b:[120,160,200] }
  ];
  var palIdx = 0;

  // ── Helpers (ORIGINAL unchanged) ─────────────────────────────
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

  // ── Freeze with n-fold symmetry ──────────────────────────────
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

  // ── DLA walker (ORIGINAL unchanged) ──────────────────────────
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

  // ── Offscreen buffer (ORIGINAL unchanged) ────────────────────
  var off  = document.createElement('canvas');
  off.width  = GSIZE;
  off.height = GSIZE;
  var octx = off.getContext('2d');

  // ── Render (ORIGINAL + palette) ──────────────────────────────
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

  // ── Resize (ORIGINAL unchanged) ──────────────────────────────
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  window.addEventListener('resize', resize);
  resize();

  // ── HUD ──────────────────────────────────────────────────────
  var elCells  = document.getElementById('hudCells');
  var elRadius = document.getElementById('hudRadius');
  var elSymm   = document.getElementById('hudSymm');
  var elMorph  = document.getElementById('hudMorph');
  var tick = 0;

  function updateHUD() {
    if (++tick % 6 !== 0) return;
    elCells.textContent  = iceCount;
    elRadius.textContent = maxRadius | 0;
    elSymm.textContent   = symmetry;
    elMorph.textContent  = MORPHS[morphIdx];
  }

  // ── Animation loop (ORIGINAL logic) ──────────────────────────
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

  // ── Coordinate helper (ORIGINAL unchanged) ───────────────────
  function toGrid(clientX, clientY) {
    var sc = Math.min(canvas.width, canvas.height) / GSIZE;
    var ox = (canvas.width  - GSIZE * sc) / 2;
    var oy = (canvas.height - GSIZE * sc) / 2;
    return [Math.round((clientX - ox) / sc), Math.round((clientY - oy) / sc)];
  }

  // ── Events (ORIGINAL unchanged) ──────────────────────────────
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

  // ── New controls ─────────────────────────────────────────────
  document.getElementById('btnMorph').addEventListener('click', function() {
    morphIdx = (morphIdx + 1) % MORPHS.length;
    this.textContent = MORPHS[morphIdx];
    reset();
    SFX.play('toggle');
  });

  document.getElementById('btnSymm').addEventListener('click', function() {
    symmIdx = (symmIdx + 1) % SYMMETRIES.length;
    symmetry = SYMMETRIES[symmIdx];
    this.textContent = SYMM_LABELS[symmIdx];
    reset();
    SFX.play('toggle');
  });

  document.getElementById('btnColor').addEventListener('click', function() {
    palIdx = (palIdx + 1) % PALETTES.length;
    SFX.play('click');
  });

  document.getElementById('btnPause').addEventListener('click', function() {
    paused = !paused;
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', paused);
    SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    reset();
    SFX.play('click');
  });

  document.getElementById('slSpeed').addEventListener('input', function(e) {
    stepsPerFrame = parseInt(e.target.value);
  });

  // ── Start (ORIGINAL unchanged) ───────────────────────────────
  reset();
  requestAnimationFrame(animate);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
