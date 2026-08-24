<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('collapse');

$pageName = 'collapse';
$pageTitle = 'COLLAPSE - PULSE';
$pageDesc = '波動関数崩壊（WFC）。制約伝播によりタイルが一枚ずつ確定していく。エントロピー最小のセルから秩序が広がる。';
$pageCanonical = 'https://pulse.subara3.com/collapse.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  RESOLVED: <span id="hudResolved" class="v">0</span> / <span id="hudTotal" class="v">0</span><br>
  ENTROPY: <span id="hudEntropy" class="v">0.00</span><br>
  STATE: <span id="hudState" class="v">IDLE</span>
</div>

<div id="info">
  wave function collapse<br>
  <span class="v">constraint propagation</span><br>
  minimum entropy heuristic
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'collapse cell'],
  ['type' => 'drag', 'label' => 'pan view'],
  ['type' => 'right', 'label' => 'reset cell'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">GRID SIZE</label>
    <div class="slider-row">
      <input type="range" id="sldGrid" min="8" max="40" value="20">
      <span class="slider-value" id="valGrid">20</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="20" value="5">
      <span class="slider-value" id="valSpeed">5</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">TILESET</label>
    <div class="preset-group" id="tilesetGrp">
      <button class="preset-btn active" data-t="circuit">CIRCUIT</button>
      <button class="preset-btn" data-t="pipe">PIPE</button>
      <button class="preset-btn" data-t="knot">KNOT</button>
      <button class="preset-btn" data-t="maze">MAZE</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnRun">RUN</button>
      <button class="control-btn" id="btnStep">STEP</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var gridSize = 20, speed = 5, tileset = 'circuit';
  var grid = [], numTiles = 0, totalCells = 0, resolved = 0;
  var running = false, done = false, contradiction = false;
  var frameCount = 0;
  var panX = 0, panY = 0, dragging = false, dragStartX = 0, dragStartY = 0;
  var panStartX = 0, panStartY = 0;

  // Tile definitions: each tile has edges [top, right, bottom, left]
  // Edge types: 0=empty, 1=line, 2=double
  var TILESETS = {
    circuit: {
      tiles: [
        {edges:[0,0,0,0], weight:2},  // empty
        {edges:[1,1,0,0], weight:1},   // corner TR
        {edges:[0,1,1,0], weight:1},   // corner BR
        {edges:[0,0,1,1], weight:1},   // corner BL
        {edges:[1,0,0,1], weight:1},   // corner TL
        {edges:[1,0,1,0], weight:1},   // vertical
        {edges:[0,1,0,1], weight:1},   // horizontal
        {edges:[1,1,1,0], weight:0.5}, // T right
        {edges:[0,1,1,1], weight:0.5}, // T down
        {edges:[1,0,1,1], weight:0.5}, // T left
        {edges:[1,1,0,1], weight:0.5}, // T up
        {edges:[1,1,1,1], weight:0.3}, // cross
      ]
    },
    pipe: {
      tiles: [
        {edges:[0,0,0,0], weight:1},
        {edges:[2,2,0,0], weight:1},
        {edges:[0,2,2,0], weight:1},
        {edges:[0,0,2,2], weight:1},
        {edges:[2,0,0,2], weight:1},
        {edges:[2,0,2,0], weight:1.5},
        {edges:[0,2,0,2], weight:1.5},
        {edges:[2,2,2,2], weight:0.2},
        {edges:[1,0,1,0], weight:1},
        {edges:[0,1,0,1], weight:1},
        {edges:[1,1,0,0], weight:0.8},
        {edges:[0,1,1,0], weight:0.8},
        {edges:[0,0,1,1], weight:0.8},
        {edges:[1,0,0,1], weight:0.8},
      ]
    },
    knot: {
      tiles: [
        {edges:[0,0,0,0], weight:3},
        {edges:[1,1,1,1], weight:0.5},
        {edges:[1,0,1,0], weight:1},
        {edges:[0,1,0,1], weight:1},
        {edges:[1,1,0,0], weight:1},
        {edges:[0,1,1,0], weight:1},
        {edges:[0,0,1,1], weight:1},
        {edges:[1,0,0,1], weight:1},
        {edges:[2,2,2,2], weight:0.2},
        {edges:[2,0,2,0], weight:0.8},
        {edges:[0,2,0,2], weight:0.8},
        {edges:[1,2,1,2], weight:0.3},
        {edges:[2,1,2,1], weight:0.3},
      ]
    },
    maze: {
      tiles: [
        {edges:[0,0,0,0], weight:0.1},
        {edges:[1,0,0,0], weight:1},
        {edges:[0,1,0,0], weight:1},
        {edges:[0,0,1,0], weight:1},
        {edges:[0,0,0,1], weight:1},
        {edges:[1,1,0,0], weight:1.5},
        {edges:[0,1,1,0], weight:1.5},
        {edges:[0,0,1,1], weight:1.5},
        {edges:[1,0,0,1], weight:1.5},
        {edges:[1,0,1,0], weight:2},
        {edges:[0,1,0,1], weight:2},
        {edges:[1,1,1,0], weight:1},
        {edges:[0,1,1,1], weight:1},
        {edges:[1,0,1,1], weight:1},
        {edges:[1,1,0,1], weight:1},
        {edges:[1,1,1,1], weight:0.5},
      ]
    }
  };

  function getTiles() { return TILESETS[tileset].tiles; }

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function initGrid() {
    var tiles = getTiles();
    numTiles = tiles.length;
    totalCells = gridSize * gridSize;
    resolved = 0;
    done = false;
    contradiction = false;
    grid = [];
    for (var i = 0; i < totalCells; i++) {
      grid.push({
        possible: new Array(numTiles).fill(true),
        collapsed: false,
        tileIdx: -1,
        count: numTiles
      });
    }
    updateHud();
  }

  function entropy(cell) {
    if (cell.collapsed) return Infinity;
    if (cell.count <= 0) return -1;
    if (cell.count === 1) return 0;
    var tiles = getTiles();
    var sumW = 0, sumWLogW = 0;
    for (var i = 0; i < numTiles; i++) {
      if (cell.possible[i]) {
        var w = tiles[i].weight;
        sumW += w;
        sumWLogW += w * Math.log(w);
      }
    }
    if (sumW === 0) return -1;
    return Math.log(sumW) - sumWLogW / sumW;
  }

  function findMinEntropy() {
    var minE = Infinity, candidates = [];
    for (var i = 0; i < totalCells; i++) {
      if (grid[i].collapsed) continue;
      var e = entropy(grid[i]);
      if (e < 0) { contradiction = true; return -1; }
      if (e < minE) { minE = e; candidates = [i]; }
      else if (Math.abs(e - minE) < 0.0001) candidates.push(i);
    }
    if (candidates.length === 0) { done = true; return -1; }
    return candidates[Math.floor(Math.random() * candidates.length)];
  }

  function collapseCell(idx) {
    var cell = grid[idx];
    if (cell.collapsed) return;
    var tiles = getTiles();
    var choices = [], weights = [];
    for (var i = 0; i < numTiles; i++) {
      if (cell.possible[i]) {
        choices.push(i);
        weights.push(tiles[i].weight);
      }
    }
    if (choices.length === 0) { contradiction = true; return; }
    var totalW = 0;
    for (var i = 0; i < weights.length; i++) totalW += weights[i];
    var r = Math.random() * totalW, acc = 0;
    var chosen = choices[0];
    for (var i = 0; i < choices.length; i++) {
      acc += weights[i];
      if (r <= acc) { chosen = choices[i]; break; }
    }
    cell.collapsed = true;
    cell.tileIdx = chosen;
    cell.count = 1;
    for (var i = 0; i < numTiles; i++) cell.possible[i] = (i === chosen);
    resolved++;
    propagate(idx);
  }

  function getNeighbors(idx) {
    var x = idx % gridSize, y = Math.floor(idx / gridSize);
    var n = [];
    if (y > 0) n.push({idx: idx - gridSize, dir: 0}); // top
    if (x < gridSize - 1) n.push({idx: idx + 1, dir: 1}); // right
    if (y < gridSize - 1) n.push({idx: idx + gridSize, dir: 2}); // bottom
    if (x > 0) n.push({idx: idx - 1, dir: 3}); // left
    return n;
  }

  function opposite(dir) { return (dir + 2) % 4; }

  function propagate(startIdx) {
    var tiles = getTiles();
    var stack = [startIdx];
    var visited = new Set();
    while (stack.length > 0) {
      var curr = stack.pop();
      var neighbors = getNeighbors(curr);
      for (var n = 0; n < neighbors.length; n++) {
        var nb = neighbors[n];
        var nbCell = grid[nb.idx];
        if (nbCell.collapsed) continue;
        var dir = nb.dir;
        var opp = opposite(dir);
        var allowedEdges = new Set();
        for (var t = 0; t < numTiles; t++) {
          if (grid[curr].possible[t]) {
            allowedEdges.add(tiles[t].edges[dir]);
          }
        }
        var changed = false;
        for (var t = 0; t < numTiles; t++) {
          if (nbCell.possible[t]) {
            if (!allowedEdges.has(tiles[t].edges[opp])) {
              nbCell.possible[t] = false;
              nbCell.count--;
              changed = true;
            }
          }
        }
        if (nbCell.count <= 0) { contradiction = true; return; }
        if (nbCell.count === 1 && !nbCell.collapsed) {
          for (var t = 0; t < numTiles; t++) {
            if (nbCell.possible[t]) {
              nbCell.collapsed = true;
              nbCell.tileIdx = t;
              resolved++;
              break;
            }
          }
        }
        if (changed) {
          var key = nb.idx;
          if (!visited.has(key)) {
            visited.add(key);
            stack.push(nb.idx);
          }
        }
      }
    }
  }

  function step() {
    if (done || contradiction) return false;
    var idx = findMinEntropy();
    if (idx < 0) return false;
    collapseCell(idx);
    return true;
  }

  function cellSize() {
    var maxDim = Math.min(W, H) * 0.85;
    return maxDim / gridSize;
  }

  function gridOrigin() {
    var cs = cellSize();
    var gw = gridSize * cs;
    return {x: (W - gw) / 2 + panX, y: (H - gw) / 2 + panY};
  }

  function drawTile(x, y, cs, tileIdx, alpha) {
    var tiles = getTiles();
    var tile = tiles[tileIdx];
    var cx = x + cs / 2, cy = y + cs / 2;
    var r = cs * 0.35;
    ctx.lineWidth = Math.max(1.5, cs * 0.06);
    ctx.lineCap = 'round';

    var edges = tile.edges;
    // Draw connections
    for (var d = 0; d < 4; d++) {
      if (edges[d] === 0) continue;
      var hue = edges[d] === 1 ? 175 : 270;
      var sat = edges[d] === 1 ? '70%' : '60%';
      var lit = edges[d] === 1 ? '50%' : '45%';
      ctx.strokeStyle = 'hsla(' + hue + ',' + sat + ',' + lit + ',' + alpha + ')';
      ctx.beginPath();
      if (d === 0) { ctx.moveTo(cx, cy); ctx.lineTo(cx, y); }
      else if (d === 1) { ctx.moveTo(cx, cy); ctx.lineTo(x + cs, cy); }
      else if (d === 2) { ctx.moveTo(cx, cy); ctx.lineTo(cx, y + cs); }
      else { ctx.moveTo(cx, cy); ctx.lineTo(x, cy); }
      ctx.stroke();
    }
    // Node dot
    var anyEdge = edges[0] || edges[1] || edges[2] || edges[3];
    if (anyEdge) {
      ctx.fillStyle = 'hsla(175,80%,60%,' + alpha + ')';
      ctx.beginPath();
      ctx.arc(cx, cy, cs * 0.08, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function drawSuperposition(x, y, cs, cell) {
    var count = cell.count;
    if (count <= 0) {
      ctx.fillStyle = 'rgba(123,44,191,0.3)';
      ctx.fillRect(x + 1, y + 1, cs - 2, cs - 2);
      return;
    }
    var intensity = 1 - (count / numTiles);
    var alpha = 0.05 + intensity * 0.25;
    ctx.fillStyle = 'rgba(0,245,212,' + (alpha * 0.3) + ')';
    ctx.fillRect(x + 1, y + 1, cs - 2, cs - 2);

    // Show superposition as faint overlapping possible tiles
    if (count <= 4 && count > 1) {
      var tiles = getTiles();
      for (var t = 0; t < numTiles; t++) {
        if (cell.possible[t]) {
          drawTile(x, y, cs, t, 0.15);
        }
      }
    }
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    var cs = cellSize();
    var origin = gridOrigin();

    // Grid background
    ctx.fillStyle = 'rgba(0,245,212,0.02)';
    ctx.fillRect(origin.x, origin.y, gridSize * cs, gridSize * cs);

    // Grid lines
    ctx.strokeStyle = 'rgba(0,245,212,0.08)';
    ctx.lineWidth = 0.5;
    for (var i = 0; i <= gridSize; i++) {
      ctx.beginPath();
      ctx.moveTo(origin.x + i * cs, origin.y);
      ctx.lineTo(origin.x + i * cs, origin.y + gridSize * cs);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(origin.x, origin.y + i * cs);
      ctx.lineTo(origin.x + gridSize * cs, origin.y + i * cs);
      ctx.stroke();
    }

    // Cells
    for (var i = 0; i < totalCells; i++) {
      var gx = i % gridSize, gy = Math.floor(i / gridSize);
      var x = origin.x + gx * cs, y = origin.y + gy * cs;
      var cell = grid[i];
      if (cell.collapsed) {
        drawTile(x, y, cs, cell.tileIdx, 0.9);
      } else {
        drawSuperposition(x, y, cs, cell);
      }
    }

    // Highlight min-entropy cell
    if (!done && !contradiction) {
      var minE = Infinity, minIdx = -1;
      for (var i = 0; i < totalCells; i++) {
        if (grid[i].collapsed) continue;
        var e = entropy(grid[i]);
        if (e >= 0 && e < minE) { minE = e; minIdx = i; }
      }
      if (minIdx >= 0) {
        var gx = minIdx % gridSize, gy = Math.floor(minIdx / gridSize);
        var x = origin.x + gx * cs, y = origin.y + gy * cs;
        ctx.strokeStyle = 'rgba(0,245,212,' + (0.4 + 0.3 * Math.sin(frameCount * 0.08)) + ')';
        ctx.lineWidth = 2;
        ctx.strokeRect(x + 1, y + 1, cs - 2, cs - 2);
      }
    }

    // Contradiction indicator
    if (contradiction) {
      ctx.fillStyle = 'rgba(123,44,191,0.6)';
      ctx.font = '14px Space Grotesk, monospace';
      ctx.textAlign = 'center';
      ctx.fillText('CONTRADICTION — click RESET', W / 2, origin.y - 20);
    }
  }

  function updateHud() {
    document.getElementById('hudResolved').textContent = resolved;
    document.getElementById('hudTotal').textContent = totalCells;
    var avgEntropy = 0, uncollapsed = 0;
    for (var i = 0; i < totalCells; i++) {
      if (!grid[i].collapsed) {
        var e = entropy(grid[i]);
        if (e >= 0) { avgEntropy += e; uncollapsed++; }
      }
    }
    document.getElementById('hudEntropy').textContent = uncollapsed > 0 ? (avgEntropy / uncollapsed).toFixed(2) : '0.00';
    var state = done ? 'DONE' : contradiction ? 'CONTRADICTION' : running ? 'RUNNING' : 'IDLE';
    document.getElementById('hudState').textContent = state;
  }

  function tick() {
    frameCount++;
    if (running && !done && !contradiction) {
      for (var i = 0; i < speed; i++) {
        if (!step()) { running = false; break; }
      }
      updateHud();
      if (done) SFX.play('complete');
      if (contradiction) SFX.play('fail');
    }
    draw();
    requestAnimationFrame(tick);
  }

  // Input handling
  function getCellAt(px, py) {
    var cs = cellSize();
    var origin = gridOrigin();
    var gx = Math.floor((px - origin.x) / cs);
    var gy = Math.floor((py - origin.y) / cs);
    if (gx < 0 || gx >= gridSize || gy < 0 || gy >= gridSize) return -1;
    return gy * gridSize + gx;
  }

  var pointerDown = false, didDrag = false, startX = 0, startY = 0;

  C.addEventListener('pointerdown', function(e) {
    pointerDown = true;
    didDrag = false;
    startX = e.clientX;
    startY = e.clientY;
    panStartX = panX;
    panStartY = panY;
    if (e.button === 2) return;
  });

  C.addEventListener('pointermove', function(e) {
    if (!pointerDown) return;
    var dx = e.clientX - startX, dy = e.clientY - startY;
    if (Math.abs(dx) > 5 || Math.abs(dy) > 5) {
      didDrag = true;
      panX = panStartX + dx;
      panY = panStartY + dy;
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (!didDrag && e.button === 0) {
      var idx = getCellAt(e.clientX, e.clientY);
      if (idx >= 0 && !grid[idx].collapsed && !contradiction) {
        collapseCell(idx);
        updateHud();
        SFX.play('tick');
        if (done) SFX.play('complete');
      }
    }
    pointerDown = false;
  });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var idx = getCellAt(e.clientX, e.clientY);
    if (idx >= 0) {
      resetCell(idx);
      SFX.play('pop');
      updateHud();
    }
  });

  function resetCell(idx) {
    if (!grid[idx].collapsed) return;
    grid[idx].collapsed = false;
    grid[idx].tileIdx = -1;
    grid[idx].possible = new Array(numTiles).fill(true);
    grid[idx].count = numTiles;
    resolved--;
    contradiction = false;
    done = false;
    // Re-propagate from neighbors
    var neighbors = getNeighbors(idx);
    for (var n = 0; n < neighbors.length; n++) {
      if (grid[neighbors[n].idx].collapsed) {
        propagate(neighbors[n].idx);
      }
    }
  }

  // Controls
  var sldGrid = document.getElementById('sldGrid');
  var sldSpeed = document.getElementById('sldSpeed');
  var valGrid = document.getElementById('valGrid');
  var valSpeed = document.getElementById('valSpeed');
  var btnRun = document.getElementById('btnRun');
  var btnStep = document.getElementById('btnStep');
  var btnReset = document.getElementById('btnReset');

  sldGrid.addEventListener('input', function() {
    gridSize = parseInt(this.value);
    valGrid.textContent = gridSize;
  });
  sldGrid.addEventListener('change', function() {
    running = false;
    panX = 0; panY = 0;
    initGrid();
    SFX.play('click');
  });

  sldSpeed.addEventListener('input', function() {
    speed = parseInt(this.value);
    valSpeed.textContent = speed;
  });

  btnRun.addEventListener('click', function() {
    if (done || contradiction) return;
    running = !running;
    this.textContent = running ? 'PAUSE' : 'RUN';
    SFX.play('toggle');
    updateHud();
  });

  btnStep.addEventListener('click', function() {
    if (done || contradiction) return;
    running = false;
    btnRun.textContent = 'RUN';
    step();
    updateHud();
    SFX.play('tick');
    if (done) SFX.play('complete');
    if (contradiction) SFX.play('fail');
  });

  btnReset.addEventListener('click', function() {
    running = false;
    btnRun.textContent = 'RUN';
    panX = 0; panY = 0;
    initGrid();
    SFX.play('click');
  });

  // Tileset buttons
  var tilesetBtns = document.querySelectorAll('#tilesetGrp .preset-btn');
  tilesetBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      tilesetBtns.forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      tileset = this.dataset.t;
      running = false;
      btnRun.textContent = 'RUN';
      panX = 0; panY = 0;
      initGrid();
      SFX.play('toggle');
    });
  });

  // Init
  window.addEventListener('resize', resize);
  resize();
  initGrid();

  // Auto-run a few steps on load so something is visible
  for (var i = 0; i < 15; i++) step();
  updateHud();

  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
