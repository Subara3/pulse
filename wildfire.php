<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('wildfire');

$pageName = 'wildfire';
$pageTitle = 'WILDFIRE - PULSE';
$pageDesc = '森林火災モデル。樹木の成長と落雷が自己組織化臨界を作り出す。';
$pageCanonical = 'https://pulse.subara3.com/wildfire.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  DENSITY: <span id="hudDen" class="v">0%</span><br>
  FIRES: <span id="hudFire" class="v">0</span><br>
  STEP: <span id="hudStep" class="v">0</span>
</div>

<?php
$pageInfo = [
  'Drossel-Schwabl model',
  ['accent' => 'self-organized criticality'],
  'power-law fire sizes',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'ignite'],
  ['type' => 'drag', 'label' => 'plant'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">GROWTH</label>
    <div class="slider-row">
      <input type="range" id="sldGrow" min="1" max="30" value="8">
      <span class="slider-value" id="valGrow">8</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">LIGHTNING</label>
    <div class="slider-row">
      <input type="range" id="sldLight" min="1" max="20" value="5">
      <span class="slider-value" id="valLight">5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="10" value="3">
      <span class="slider-value" id="valSpeed">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">CELL</label>
    <div class="slider-row">
      <input type="range" id="sldCell" min="2" max="8" value="3">
      <span class="slider-value" id="valCell">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
      <button class="control-btn" id="btnPause">PAUSE</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var cols, rows, cellSz = 3;
  var grid, buf, oc, ox, imgD;
  var pGrow = 0.008, pLight = 0.00005;
  var spd = 3, paused = false, stp = 0;
  var tc = 0, bc = 0;

  var $den = document.getElementById('hudDen');
  var $fire = document.getElementById('hudFire');
  var $step = document.getElementById('hudStep');

  var PR = [12,  0,  0,  0, 200, 120,  55];
  var PG = [12,128,168,209, 100,  45,  20];
  var PB = [18,108,142,177, 255, 170,  85];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    build();
  }

  function build() {
    cols = Math.max(1, Math.floor(W / cellSz));
    rows = Math.max(1, Math.floor(H / cellSz));
    var n = cols * rows;
    grid = new Uint8Array(n);
    buf = new Uint8Array(n);
    oc = document.createElement('canvas');
    oc.width = cols; oc.height = rows;
    ox = oc.getContext('2d');
    imgD = ox.createImageData(cols, rows);

    for (var i = 0; i < n; i++)
      grid[i] = Math.random() < 0.6 ? (1 + (Math.random() * 3 | 0)) : 0;

    var cx = (cols * 0.3 + Math.random() * cols * 0.4) | 0;
    var cy = (rows * 0.3 + Math.random() * rows * 0.4) | 0;
    var r = Math.max(3, (Math.min(cols, rows) * 0.02) | 0);
    for (var dy = -r; dy <= r; dy++)
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy > r * r) continue;
        var nx = cx + dx, ny = cy + dy;
        if (nx >= 0 && nx < cols && ny >= 0 && ny < rows) {
          var ni = ny * cols + nx;
          if (grid[ni] >= 1 && grid[ni] <= 3) grid[ni] = 4;
        }
      }
    stp = 0;
  }

  function sim() {
    tc = 0; bc = 0;
    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        var i = y * cols + x;
        var s = grid[i];
        if (s === 4) {
          buf[i] = 5; bc++;
        } else if (s === 5) {
          buf[i] = 6;
        } else if (s === 6) {
          buf[i] = 0;
        } else if (s >= 1 && s <= 3) {
          var fb = false;
          if (y > 0) {
            if (grid[i - cols] === 4) fb = true;
            if (!fb && x > 0 && grid[i - cols - 1] === 4) fb = true;
            if (!fb && x < cols - 1 && grid[i - cols + 1] === 4) fb = true;
          }
          if (!fb && y < rows - 1) {
            if (grid[i + cols] === 4) fb = true;
            if (!fb && x > 0 && grid[i + cols - 1] === 4) fb = true;
            if (!fb && x < cols - 1 && grid[i + cols + 1] === 4) fb = true;
          }
          if (!fb && x > 0 && grid[i - 1] === 4) fb = true;
          if (!fb && x < cols - 1 && grid[i + 1] === 4) fb = true;

          if (fb || Math.random() < pLight) {
            buf[i] = 4; bc++;
          } else {
            if (s < 3 && Math.random() < 0.002) buf[i] = s + 1;
            else buf[i] = s;
            tc++;
          }
        } else {
          buf[i] = Math.random() < pGrow ? (1 + (Math.random() * 3 | 0)) : 0;
          if (buf[i] > 0) tc++;
        }
      }
    }
    var t = grid; grid = buf; buf = t;
    stp++;
  }

  function render() {
    var d = imgD.data;
    for (var i = 0, len = grid.length; i < len; i++) {
      var s = grid[i], p = i << 2;
      d[p] = PR[s]; d[p | 1] = PG[s]; d[p | 2] = PB[s]; d[p | 3] = 255;
    }
    ox.putImageData(imgD, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(oc, 0, 0, W, H);

    var tm = stp * 0.004;
    var wx = W * 0.5 + Math.sin(tm) * W * 0.6;
    var sp = W * 0.22;
    var g = ctx.createLinearGradient(wx - sp, 0, wx + sp, 0);
    g.addColorStop(0, 'rgba(0,245,212,0)');
    g.addColorStop(0.5, 'rgba(0,245,212,0.03)');
    g.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    var wy = H * 0.5 + Math.sin(tm * 0.6 + 1.3) * H * 0.6;
    var sp2 = H * 0.2;
    var g2 = ctx.createLinearGradient(0, wy - sp2, 0, wy + sp2);
    g2.addColorStop(0, 'rgba(123,44,191,0)');
    g2.addColorStop(0.5, 'rgba(123,44,191,0.018)');
    g2.addColorStop(1, 'rgba(123,44,191,0)');
    ctx.fillStyle = g2;
    ctx.fillRect(0, 0, W, H);

    $den.textContent = (tc * 100 / (cols * rows) | 0) + '%';
    $fire.textContent = bc;
    $step.textContent = stp;
  }

  function loop() {
    if (!paused) for (var i = 0; i < spd; i++) sim();
    render();
    requestAnimationFrame(loop);
  }

  var down = false, rdown = false, lastK = '';

  function s2g(sx, sy) {
    return { x: (sx * cols / W) | 0, y: (sy * rows / H) | 0 };
  }

  function brush(sx, sy, mode) {
    var gp = s2g(sx, sy);
    var r = Math.max(2, (12 / cellSz) | 0);
    for (var dy = -r; dy <= r; dy++)
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy > r * r) continue;
        var nx = gp.x + dx, ny = gp.y + dy;
        if (nx >= 0 && nx < cols && ny >= 0 && ny < rows) {
          var ni = ny * cols + nx;
          if (mode === 0 && grid[ni] >= 1 && grid[ni] <= 3) grid[ni] = 4;
          else if (mode === 1 && grid[ni] === 0) grid[ni] = 1 + (Math.random() * 3 | 0);
          else if (mode === 2) grid[ni] = 0;
        }
      }
  }

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('pointerdown', function(e) {
    if (e.button === 2) {
      rdown = true;
      brush(e.clientX, e.clientY, 2);
      SFX.play('pop');
    } else {
      down = true;
      brush(e.clientX, e.clientY, 0);
      SFX.play('spawn');
    }
    lastK = '';
  });

  C.addEventListener('pointermove', function(e) {
    if (!down && !rdown) return;
    var gp = s2g(e.clientX, e.clientY);
    var k = gp.x + ',' + gp.y;
    if (k === lastK) return;
    lastK = k;
    if (rdown) {
      brush(e.clientX, e.clientY, 2);
      SFX.play('pop', 80);
    } else {
      brush(e.clientX, e.clientY, 1);
      SFX.play('tick', 60);
    }
  });

  C.addEventListener('pointerup', function() { down = rdown = false; });
  C.addEventListener('pointerleave', function() { down = rdown = false; });

  document.getElementById('sldGrow').addEventListener('input', function() {
    pGrow = this.value / 1000;
    document.getElementById('valGrow').textContent = this.value;
  });
  document.getElementById('sldLight').addEventListener('input', function() {
    pLight = this.value / 100000;
    document.getElementById('valLight').textContent = this.value;
  });
  document.getElementById('sldSpeed').addEventListener('input', function() {
    spd = parseInt(this.value);
    document.getElementById('valSpeed').textContent = this.value;
  });
  document.getElementById('sldCell').addEventListener('input', function() {
    document.getElementById('valCell').textContent = this.value;
  });
  document.getElementById('sldCell').addEventListener('change', function() {
    cellSz = parseInt(this.value);
    build();
    SFX.play('toggle');
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    build();
    SFX.play('click');
  });
  document.getElementById('btnPause').addEventListener('click', function() {
    paused = !paused;
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    this.classList.toggle('active', paused);
    SFX.play('toggle');
  });

  window.addEventListener('resize', resize);
  resize();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
