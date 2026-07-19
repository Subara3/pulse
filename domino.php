<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('domino');

$pageName = 'domino';
$pageTitle = 'DOMINO - PULSE';
$pageDesc = 'アステカダイヤモンドのランダムドミノタイリング。シャッフリングアルゴリズムが北極円定理の境界を描く。';
$pageCanonical = 'https://pulse.subara3.com/domino.php';
$extraStyle = '
body{background:#0a0a0f;margin:0;overflow:hidden}
canvas{position:fixed;top:0;left:0;width:100%;height:100%}
.hud{position:fixed;top:3.2rem;left:1rem;z-index:999;
  font:.7rem "JetBrains Mono","Fira Code",monospace;color:rgba(255,255,255,.56);
  display:flex;flex-direction:column;gap:.18rem;pointer-events:none}
.hud b{color:#00f5d4;min-width:2.5em;display:inline-block;text-align:right}
@media(max-width:768px){.hud{top:2.8rem;font-size:.6rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div class="hud">
  <div>N <b id="hN">1</b></div>
  <div>TILES <b id="hTiles">0</b></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'generate'],
  ['type' => 'wheel', 'label' => 'order'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <span class="slider-label">ORDER</span>
    <input type="range" id="sOrder" min="5" max="80" step="1" value="40">
    <span class="slider-value" id="vOrder">40</span>
  </div>
  <div class="slider-row">
    <span class="slider-label">SPEED</span>
    <input type="range" id="sSpeed" min="1" max="20" step="1" value="3">
    <span class="slider-value" id="vSpeed">3</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btnStep">STEP</button>
    <button class="control-btn active" id="btnAuto">AUTO</button>
    <button class="control-btn" id="btnCircle">CIRCLE</button>
    <button class="control-btn" id="btnReset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var X = C.getContext('2d');
  var W, H, dpr;

  var maxN = 40;
  var speed = 3;
  var curN = 0;
  var autoRun = true;
  var showCircle = false;
  var frame = 0;
  var gridSize;
  var grid;

  var COL_N = 'hsl(175,70%,45%)';
  var COL_S = 'hsl(190,65%,38%)';
  var COL_E = 'hsl(270,60%,42%)';
  var COL_W = 'hsl(285,55%,35%)';

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function initGrid() {
    gridSize = 2 * maxN;
    grid = new Uint8Array(gridSize * gridSize);
    curN = 0;
  }

  function inDiamond(r, c, n) {
    return Math.abs(r - maxN + 0.5) + Math.abs(c - maxN + 0.5) <= n;
  }

  function getCell(r, c) {
    if (r < 0 || r >= gridSize || c < 0 || c >= gridSize) return 0;
    return grid[r * gridSize + c];
  }

  function setCell(r, c, v) {
    grid[r * gridSize + c] = v;
  }

  function shuffleStep() {
    if (curN >= maxN) return false;
    curN++;
    var n = curN;

    // 1) Delete bad pairs
    for (var r = 0; r < gridSize - 1; r++) {
      for (var c = 0; c < gridSize - 1; c++) {
        var a = getCell(r, c);
        var b;
        if (a === 1) { // N moves up: check if cell above is S
          b = getCell(r - 1, c);
          if (b === 2) { setCell(r, c, 0); setCell(r - 1, c, 0); }
        } else if (a === 2) { // S moves down: check if cell below is N
          b = getCell(r + 1, c);
          if (b === 1) { setCell(r, c, 0); setCell(r + 1, c, 0); }
        }
        if (a === 3) { // E moves right: check if cell right is W
          b = getCell(r, c + 1);
          if (b === 4) { setCell(r, c, 0); setCell(r, c + 1, 0); }
        } else if (a === 4) { // W moves left: check if cell left is E
          b = getCell(r, c - 1);
          if (b === 3) { setCell(r, c, 0); setCell(r, c - 1, 0); }
        }
      }
    }

    // 2) Move dominoes outward (process in correct order to avoid overwrites)
    var moved = new Uint8Array(gridSize * gridSize);
    // N: move up (scan top to bottom)
    for (var r = 1; r < gridSize; r++) {
      for (var c = 0; c < gridSize; c++) {
        if (grid[r * gridSize + c] === 1 && moved[r * gridSize + c] === 0) {
          grid[r * gridSize + c] = 0;
          grid[(r - 1) * gridSize + c] = 2; // N becomes S after move
          moved[(r - 1) * gridSize + c] = 1;
        }
      }
    }
    // S: move down (scan bottom to top)
    for (var r = gridSize - 2; r >= 0; r--) {
      for (var c = 0; c < gridSize; c++) {
        if (grid[r * gridSize + c] === 2 && moved[r * gridSize + c] === 0) {
          grid[r * gridSize + c] = 0;
          grid[(r + 1) * gridSize + c] = 1; // S becomes N after move
          moved[(r + 1) * gridSize + c] = 1;
        }
      }
    }
    // E: move right (scan right to left)
    for (var r = 0; r < gridSize; r++) {
      for (var c = gridSize - 2; c >= 0; c--) {
        if (grid[r * gridSize + c] === 3 && moved[r * gridSize + c] === 0) {
          grid[r * gridSize + c] = 0;
          grid[r * gridSize + c + 1] = 4; // E becomes W after move
          moved[r * gridSize + c + 1] = 1;
        }
      }
    }
    // W: move left (scan left to right)
    for (var r = 0; r < gridSize; r++) {
      for (var c = 1; c < gridSize; c++) {
        if (grid[r * gridSize + c] === 4 && moved[r * gridSize + c] === 0) {
          grid[r * gridSize + c] = 0;
          grid[r * gridSize + c - 1] = 3; // W becomes E after move
          moved[r * gridSize + c - 1] = 1;
        }
      }
    }

    // 3) Fill empty 2x2 blocks within diamond
    for (var r = 0; r < gridSize - 1; r += 2) {
      for (var c = 0; c < gridSize - 1; c += 2) {
        var br = r - (maxN - n);
        var bc = c - (maxN - n);
        if (br < 0 || bc < 0 || br >= 2 * n || bc >= 2 * n) continue;
        if (br % 2 !== 0 || bc % 2 !== 0) continue;

        if (getCell(r, c) === 0 && getCell(r, c + 1) === 0 &&
            getCell(r + 1, c) === 0 && getCell(r + 1, c + 1) === 0 &&
            inDiamond(r, c, n) && inDiamond(r, c + 1, n) &&
            inDiamond(r + 1, c, n) && inDiamond(r + 1, c + 1, n)) {
          if (Math.random() < 0.5) {
            // Horizontal pair
            setCell(r, c, 3); setCell(r, c + 1, 4);
            setCell(r + 1, c, 3); setCell(r + 1, c + 1, 4);
          } else {
            // Vertical pair
            setCell(r, c, 1); setCell(r + 1, c, 2);
            setCell(r, c + 1, 1); setCell(r + 1, c + 1, 2);
          }
        }
      }
    }

    SFX.play('tick', 30);
    return true;
  }

  function countTiles() {
    var count = 0;
    for (var i = 0; i < grid.length; i++) {
      if (grid[i] > 0) count++;
    }
    return count / 2;
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    var n = curN || 1;
    var margin = 40;
    var maxDim = Math.min(W - margin * 2, H - margin * 2);
    var cellSize = maxDim / (2 * maxN);
    var ox = (W - gridSize * cellSize) / 2;
    var oy = (H - gridSize * cellSize) / 2;

    // Batch by color
    var batches = [[], [], [], []];
    var colors = [COL_N, COL_S, COL_E, COL_W];
    for (var r = 0; r < gridSize; r++) {
      for (var c = 0; c < gridSize; c++) {
        var v = grid[r * gridSize + c];
        if (v > 0) batches[v - 1].push(r * gridSize + c);
      }
    }

    for (var b = 0; b < 4; b++) {
      if (batches[b].length === 0) continue;
      X.fillStyle = colors[b];
      X.beginPath();
      for (var i = 0; i < batches[b].length; i++) {
        var idx = batches[b][i];
        var r = (idx / gridSize) | 0;
        var c = idx % gridSize;
        var px = ox + c * cellSize;
        var py = oy + r * cellSize;
        X.rect(px + 0.5, py + 0.5, cellSize - 1, cellSize - 1);
      }
      X.fill();
    }

    // Diamond outline
    X.strokeStyle = 'rgba(255,255,255,0.08)';
    X.lineWidth = 1;
    if (n > 0) {
      var dcx = ox + maxN * cellSize;
      var dcy = oy + maxN * cellSize;
      var half = n * cellSize;
      X.beginPath();
      X.moveTo(dcx, dcy - half);
      X.lineTo(dcx + half, dcy);
      X.lineTo(dcx, dcy + half);
      X.lineTo(dcx - half, dcy);
      X.closePath();
      X.stroke();
    }

    // Arctic circle
    if (showCircle && n > 2) {
      var dcx = ox + maxN * cellSize;
      var dcy = oy + maxN * cellSize;
      var radius = n * cellSize / Math.SQRT2;
      X.strokeStyle = 'rgba(123,44,191,0.5)';
      X.lineWidth = 1.5;
      X.setLineDash([6, 4]);
      X.beginPath();
      X.arc(dcx, dcy, radius, 0, Math.PI * 2);
      X.stroke();
      X.setLineDash([]);
    }

    document.getElementById('hN').textContent = curN;
    document.getElementById('hTiles').textContent = countTiles();
  }

  function loop() {
    frame++;
    if (autoRun && curN < maxN) {
      var steps = speed;
      for (var i = 0; i < steps; i++) {
        if (!shuffleStep()) break;
      }
      if (curN >= maxN) {
        SFX.play('complete');
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  function reset() {
    initGrid();
    SFX.play('click');
  }

  // Events
  C.addEventListener('click', function(e) {
    if (curN >= maxN) {
      reset();
    } else if (!autoRun) {
      shuffleStep();
    } else {
      reset();
    }
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var d = e.deltaY > 0 ? -1 : 1;
    var s = document.getElementById('sOrder');
    var v = parseInt(s.value) + d;
    v = Math.max(5, Math.min(80, v));
    s.value = v;
    document.getElementById('vOrder').textContent = v;
    maxN = v;
    reset();
  }, { passive: false });

  C.addEventListener('touchstart', function(e) {
    if (e.touches.length === 1) {
      if (curN >= maxN) {
        reset();
      } else if (!autoRun) {
        shuffleStep();
      } else {
        reset();
      }
    }
  });

  // Controls
  document.getElementById('sOrder').addEventListener('input', function() {
    maxN = parseInt(this.value);
    document.getElementById('vOrder').textContent = maxN;
    reset();
  });
  document.getElementById('sSpeed').addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('vSpeed').textContent = speed;
  });
  document.getElementById('btnStep').addEventListener('click', function() {
    autoRun = false;
    document.getElementById('btnAuto').classList.remove('active');
    shuffleStep();
    SFX.play('toggle');
  });
  document.getElementById('btnAuto').addEventListener('click', function() {
    autoRun = !autoRun;
    this.classList.toggle('active', autoRun);
    SFX.play('toggle');
  });
  document.getElementById('btnCircle').addEventListener('click', function() {
    showCircle = !showCircle;
    this.classList.toggle('active', showCircle);
    SFX.play('toggle');
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    reset();
  });

  resize();
  window.addEventListener('resize', resize);
  initGrid();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
