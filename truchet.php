<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('truchet');

$pageName = 'truchet';
$pageTitle = 'TRUCHET - PULSE';
$pageDesc = 'トリュシェタイル。四分円弧のランダム配置が、迷路のように流れるパターンを生む。';
$pageCanonical = 'https://pulse.subara3.com/truchet.php';

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
  TILES: <span id="hudTiles" class="v">0</span><br>
  MODE: <span id="hudMode" class="v">ARC</span><br>
  GRID: <span id="hudGrid" class="v">20</span>
</div>

<?php
$pageInfo = [
  'truchet tiling',
  ['accent' => 'quarter-circle arcs'],
  'random orientation',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'flip'],
  ['type' => 'drag', 'label' => 'paint'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnArc">ARC</button>
      <button class="control-btn" id="btnFlow">FLOW</button>
      <button class="control-btn" id="btnDual">DUAL</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">GRID</label>
    <div class="slider-row">
      <input type="range" id="sldGrid" min="8" max="60" value="20">
      <span class="slider-value" id="valGrid">20</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">LINE</label>
    <div class="slider-row">
      <input type="range" id="sldLine" min="1" max="6" value="2">
      <span class="slider-value" id="valLine">2</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnRandom">RANDOM</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var gridN = 20, lineW = 2, mode = 0;
  var tiles = [], cols = 0, rows = 0;
  var cellSz, offX, offY, half;
  var revealR = 0, maxR = 0, building = true;
  var time = 0;
  var PI = Math.PI;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    calcGrid();
    extendTiles();
  }

  function calcGrid() {
    cellSz = Math.floor(Math.min(W, H) / gridN);
    if (cellSz < 6) cellSz = 6;
    half = cellSz / 2;
    cols = Math.ceil(W / cellSz) + 1;
    rows = Math.ceil(H / cellSz) + 1;
    offX = (W - cols * cellSz) / 2;
    offY = (H - rows * cellSz) / 2;
    maxR = Math.sqrt(cols * cols + rows * rows) / 2 + 2;
  }

  function extendTiles() {
    while (tiles.length < rows) tiles.push([]);
    for (var r = 0; r < rows; r++)
      while (tiles[r].length < cols)
        tiles[r].push(Math.random() < 0.5 ? 0 : 1);
  }

  function randomizeAll() {
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        tiles[r][c] = Math.random() < 0.5 ? 0 : 1;
    revealR = 2; building = true;
  }

  function fullReset() {
    tiles = [];
    calcGrid();
    for (var r = 0; r < rows; r++) {
      tiles[r] = [];
      for (var c = 0; c < cols; c++)
        tiles[r][c] = Math.random() < 0.5 ? 0 : 1;
    }
    revealR = 2; building = true;
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cx = cols / 2, cy = rows / 2;
    var count = 0;
    var s = cellSz, h = half;

    if (mode === 0) {
      ctx.lineWidth = lineW;
      ctx.lineCap = 'round';
      ctx.strokeStyle = 'rgba(0,245,212,0.85)';
      ctx.beginPath();
      for (var r = 0; r < rows; r++) {
        for (var c = 0; c < cols; c++) {
          if (building) {
            var dx = c - cx, dy = r - cy;
            if (Math.sqrt(dx * dx + dy * dy) > revealR) continue;
          }
          var x = offX + c * s, y = offY + r * s;
          if (tiles[r][c] === 0) {
            ctx.moveTo(x + h, y);
            ctx.arc(x, y, h, 0, PI / 2);
            ctx.moveTo(x + h, y + s);
            ctx.arc(x + s, y + s, h, PI, PI * 1.5);
          } else {
            ctx.moveTo(x + s, y + h);
            ctx.arc(x + s, y, h, PI / 2, PI);
            ctx.moveTo(x, y + h);
            ctx.arc(x, y + s, h, PI * 1.5, PI * 2);
          }
          count++;
        }
      }
      ctx.stroke();
    } else if (mode === 1) {
      ctx.lineWidth = h;
      ctx.lineCap = 'butt';
      ctx.strokeStyle = 'rgba(0,245,212,0.82)';
      ctx.beginPath();
      for (var r = 0; r < rows; r++) {
        for (var c = 0; c < cols; c++) {
          if (building) {
            var dx = c - cx, dy = r - cy;
            if (Math.sqrt(dx * dx + dy * dy) > revealR) continue;
          }
          var x = offX + c * s, y = offY + r * s;
          if (tiles[r][c] === 0) {
            ctx.moveTo(x + h, y);
            ctx.arc(x, y, h, 0, PI / 2);
            ctx.moveTo(x + h, y + s);
            ctx.arc(x + s, y + s, h, PI, PI * 1.5);
          } else {
            ctx.moveTo(x + s, y + h);
            ctx.arc(x + s, y, h, PI / 2, PI);
            ctx.moveTo(x, y + h);
            ctx.arc(x, y + s, h, PI * 1.5, PI * 2);
          }
          count++;
        }
      }
      ctx.stroke();
    } else {
      ctx.lineWidth = h;
      ctx.lineCap = 'butt';

      ctx.strokeStyle = 'rgba(0,245,212,0.82)';
      ctx.beginPath();
      for (var r = 0; r < rows; r++) {
        for (var c = 0; c < cols; c++) {
          if (building) {
            var dx = c - cx, dy = r - cy;
            if (Math.sqrt(dx * dx + dy * dy) > revealR) continue;
          }
          var x = offX + c * s, y = offY + r * s;
          if (tiles[r][c] === 0) {
            ctx.moveTo(x + h, y);
            ctx.arc(x, y, h, 0, PI / 2);
          } else {
            ctx.moveTo(x + s, y + h);
            ctx.arc(x + s, y, h, PI / 2, PI);
          }
        }
      }
      ctx.stroke();

      ctx.strokeStyle = 'rgba(123,44,191,0.65)';
      ctx.beginPath();
      for (var r = 0; r < rows; r++) {
        for (var c = 0; c < cols; c++) {
          if (building) {
            var dx = c - cx, dy = r - cy;
            if (Math.sqrt(dx * dx + dy * dy) > revealR) continue;
          }
          var x = offX + c * s, y = offY + r * s;
          if (tiles[r][c] === 0) {
            ctx.moveTo(x + h, y + s);
            ctx.arc(x + s, y + s, h, PI, PI * 1.5);
          } else {
            ctx.moveTo(x, y + h);
            ctx.arc(x, y + s, h, PI * 1.5, PI * 2);
          }
          count++;
        }
      }
      ctx.stroke();
      if (count === 0) {
        for (var r2 = 0; r2 < rows; r2++)
          for (var c2 = 0; c2 < cols; c2++) {
            if (building) {
              var dx2 = c2 - cx, dy2 = r2 - cy;
              if (Math.sqrt(dx2*dx2+dy2*dy2) > revealR) continue;
            }
            count++;
          }
      }
    }

    document.getElementById('hudTiles').textContent = count;

    var wx = W * 0.5 + Math.sin(time * 0.3) * W * 0.6;
    var sp = W * 0.22;
    var g1 = ctx.createLinearGradient(wx - sp, 0, wx + sp, 0);
    g1.addColorStop(0, 'rgba(0,245,212,0)');
    g1.addColorStop(0.5, 'rgba(0,245,212,0.035)');
    g1.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.fillStyle = g1;
    ctx.fillRect(0, 0, W, H);

    var wy = H * 0.5 + Math.sin(time * 0.17 + 1.3) * H * 0.6;
    var sp2 = H * 0.2;
    var g2 = ctx.createLinearGradient(0, wy - sp2, 0, wy + sp2);
    g2.addColorStop(0, 'rgba(123,44,191,0)');
    g2.addColorStop(0.5, 'rgba(123,44,191,0.02)');
    g2.addColorStop(1, 'rgba(123,44,191,0)');
    ctx.fillStyle = g2;
    ctx.fillRect(0, 0, W, H);
  }

  var lastTickSound = 0;
  function tick() {
    time += 0.01;
    if (building) {
      revealR += Math.max(0.5, maxR / 70);
      if (revealR >= maxR) {
        building = false;
        revealR = maxR;
        SFX.play('complete');
      } else {
        var now = Date.now();
        if (now - lastTickSound > 100) {
          SFX.play('tick', 100);
          lastTickSound = now;
        }
      }
    }
    draw();
    requestAnimationFrame(tick);
  }

  function screenToCell(sx, sy) {
    var c = Math.floor((sx - offX) / cellSz);
    var r = Math.floor((sy - offY) / cellSz);
    return (r >= 0 && r < rows && c >= 0 && c < cols) ? {r: r, c: c} : null;
  }

  var dragging = false, lastKey = '', dragTarget = 0;

  C.addEventListener('pointerdown', function(e) {
    dragging = true; lastKey = '';
    var cell = screenToCell(e.clientX, e.clientY);
    if (cell && !building) {
      dragTarget = 1 - tiles[cell.r][cell.c];
      tiles[cell.r][cell.c] = dragTarget;
      lastKey = cell.r + ',' + cell.c;
      SFX.play('tick');
    }
  });

  C.addEventListener('pointermove', function(e) {
    if (!dragging || building) return;
    var cell = screenToCell(e.clientX, e.clientY);
    if (cell) {
      var key = cell.r + ',' + cell.c;
      if (key !== lastKey) {
        tiles[cell.r][cell.c] = dragTarget;
        lastKey = key;
        SFX.play('tick', 50);
      }
    }
  });

  C.addEventListener('pointerup', function() { dragging = false; });
  C.addEventListener('pointerleave', function() { dragging = false; });

  ['btnArc','btnFlow','btnDual'].forEach(function(id, i) {
    document.getElementById(id).addEventListener('click', function() {
      mode = i;
      ['btnArc','btnFlow','btnDual'].forEach(function(mid, j) {
        document.getElementById(mid).classList.toggle('active', j === i);
      });
      document.getElementById('hudMode').textContent = ['ARC','FLOW','DUAL'][i];
      SFX.play('toggle');
    });
  });

  document.getElementById('sldGrid').addEventListener('input', function() {
    document.getElementById('valGrid').textContent = this.value;
  });
  document.getElementById('sldGrid').addEventListener('change', function() {
    gridN = parseInt(this.value);
    document.getElementById('hudGrid').textContent = gridN;
    fullReset();
    SFX.play('toggle');
  });

  document.getElementById('sldLine').addEventListener('input', function() {
    lineW = parseInt(this.value);
    document.getElementById('valLine').textContent = this.value;
  });

  document.getElementById('btnRandom').addEventListener('click', function() {
    randomizeAll();
    SFX.play('click');
  });

  document.getElementById('btnClear').addEventListener('click', function() {
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        tiles[r][c] = 0;
    building = false; revealR = maxR;
    SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  fullReset();
  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
