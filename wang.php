<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('wang');

$pageName = 'wang';
$pageTitle = 'WANG TILES - PULSE';
$pageDesc = 'ワンタイル。辺の色が一致するように正方形を並べると、非周期的パターンが創発する。決定不能性を秘めた敷き詰め問題。';
$pageCanonical = 'https://pulse.subara3.com/wang.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;background:rgba(10,10,15,.8);padding:6px 10px;border-radius:6px;color:rgba(0,245,212,.85);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>TILES <span id="hud-tiles">0</span></div>
  <div>SET <span id="hud-set">0</span> / <span id="hud-total">0</span></div>
  <div>SPEED <span id="hud-speed">1</span></div>
  <div>STUCK <span id="hud-stuck">0</span></div>
  <div>BACKTRACKS <span id="hud-bt">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place'],
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row"><label>TILE SET</label></div>
    <div class="btn-row">
      <button id="btn-set1" class="mode-btn active">SET A</button>
      <button id="btn-set2" class="mode-btn">SET B</button>
      <button id="btn-set3" class="mode-btn">SET C</button>
    </div>
  </div>
  <div>
    <div class="slider-row"><label>SPEED</label><input type="range" id="sl-speed" min="1" max="20" value="5"></div>
  </div>
  <div>
    <div class="btn-row">
      <button id="btn-auto" class="mode-btn active">AUTO</button>
      <button id="btn-bt" class="mode-btn">BACKTRACK</button>
      <button id="btn-step">STEP</button>
      <button id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;
  var hudTiles = document.getElementById('hud-tiles');
  var hudSet = document.getElementById('hud-set');
  var hudTotal = document.getElementById('hud-total');
  var hudSpeed = document.getElementById('hud-speed');

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  var EDGE_COLORS_COUNT = 4;
  var edgeColors = [];
  function buildEdgeColors() {
    edgeColors = [];
    for (var i = 0; i < EDGE_COLORS_COUNT; i++) {
      var t = i / (EDGE_COLORS_COUNT - 1);
      var h = brandHue(t);
      edgeColors.push('hsl(' + h + ',70%,55%)');
    }
  }
  buildEdgeColors();

  function edgeColorRGBA(idx, a) {
    var t = idx / (EDGE_COLORS_COUNT - 1);
    var h = brandHue(t);
    var s = 0.7, l = 0.55;
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2;
    var r, g, b;
    if (h < 60) { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return 'rgba(' + Math.round((r + m) * 255) + ',' + Math.round((g + m) * 255) + ',' + Math.round((b + m) * 255) + ',' + a + ')';
  }

  var tileSets = {
    A: [
      [0,0,0,0],[0,0,1,1],[0,1,0,1],[0,1,1,0],
      [1,0,0,1],[1,0,1,0],[1,1,0,0],[1,1,1,1],
      [0,0,0,1],[0,1,1,1],[1,0,1,1],[1,1,0,1]
    ],
    B: [
      [0,0,0,0],[0,0,1,1],[0,1,0,1],[1,0,1,0],
      [1,1,0,0],[1,1,1,1],[0,2,1,0],[2,0,0,1],
      [1,2,0,2],[2,1,2,0],[0,1,2,2],[2,2,1,1],
      [1,0,2,1],[0,2,2,0]
    ],
    C: [
      [0,1,2,3],[1,0,3,2],[2,3,0,1],[3,2,1,0],
      [0,0,1,1],[1,1,0,0],[2,2,3,3],[3,3,2,2],
      [0,2,0,2],[2,0,2,0],[1,3,1,3],[3,1,3,1],
      [0,3,1,2],[1,2,0,3],[2,1,3,0],[3,0,2,1]
    ]
  };

  var currentSetKey = 'A';
  var currentSet = tileSets.A;
  var TILE_SIZE = 28;
  var GRID_COLS, GRID_ROWS;
  var grid;
  var placedCount = 0;
  var autoMode = true;
  // 貪欲に 1 枚ずつ置いていくと、どのタイルも合わない穴（行き詰まり）ができる。
  // BACKTRACK を入れると、穴の周りを剥がして置き直す
  var backtrack = false;
  var stuck = {};
  var stuckCount = 0, btCount = 0;
  var speed = 5;
  var panX = 0, panY = 0;
  var isPanning = false, panStartX, panStartY, panStartPX, panStartPY;
  var fillQueue = [];

  function setTileSet(key) {
    currentSetKey = key;
    currentSet = tileSets[key];
    EDGE_COLORS_COUNT = 2;
    if (key === 'B') EDGE_COLORS_COUNT = 3;
    if (key === 'C') EDGE_COLORS_COUNT = 4;
    buildEdgeColors();
    hudSet.textContent = currentSet.length;
    hudTotal.textContent = currentSet.length;
    resetGrid();
    document.querySelectorAll('#btn-set1, #btn-set2, #btn-set3').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('btn-set' + ({'A':'1','B':'2','C':'3'}[key])).classList.add('active');
    SFX.play('toggle');
  }

  function initGrid() {
    GRID_COLS = Math.ceil(W / TILE_SIZE) + 4;
    GRID_ROWS = Math.ceil(H / TILE_SIZE) + 4;
    grid = [];
    for (var r = 0; r < GRID_ROWS; r++) {
      grid[r] = [];
      for (var c = 0; c < GRID_COLS; c++) {
        grid[r][c] = null;
      }
    }
    placedCount = 0;
    fillQueue = [];
    stuck = {}; stuckCount = 0; btCount = 0;
    updateStuckHud();
  }

  function updateStuckHud() {
    var a = document.getElementById('hud-stuck'), b = document.getElementById('hud-bt');
    if (a) a.textContent = stuckCount;
    if (b) b.textContent = btCount;
  }

  function markStuck(row, col) {
    var k = row + ',' + col;
    if (stuck[k]) return;
    if (backtrack) {
      // 穴の上下左右を剥がしてキューに戻す（周りの選び方を変えれば埋まることが多い）
      var nb = [[row - 1, col], [row + 1, col], [row, col - 1], [row, col + 1]];
      var filled = [];
      for (var i = 0; i < nb.length; i++) {
        var r = nb[i][0], c = nb[i][1];
        if (r < 0 || r >= GRID_ROWS || c < 0 || c >= GRID_COLS) continue;
        if (grid[r][c] !== null) filled.push([r, c]);
      }
      // 周りのうち 1 枚だけを剥がす（剥がしすぎると穴が穴を呼んで収束しない）
      if (filled.length) {
        var pick = filled[Math.floor(Math.random() * filled.length)];
        grid[pick[0]][pick[1]] = null;
        placedCount--;
        addToQueue(pick[0], pick[1]);
      }
      addToQueue(row, col);
      btCount++;
      updateStuckHud();
      SFX.play('pop', 60);
      return;
    }
    stuck[k] = true;
    stuckCount++;
    updateStuckHud();
    SFX.play('fail', 200);
  }

  function resetGrid() {
    initGrid();
    fillQueue = [];
    hudTiles.textContent = '0';
    if (autoMode) seedCenter();
  }

  function seedCenter() {
    placeTile(Math.floor(GRID_ROWS / 2), Math.floor(GRID_COLS / 2));
  }

  function findMatchingTile(row, col) {
    var top = row > 0 && grid[row - 1][col] !== null ? grid[row - 1][col][2] : -1;
    var left = col > 0 && grid[row][col - 1] !== null ? grid[row][col - 1][1] : -1;
    var bottom = row < GRID_ROWS - 1 && grid[row + 1][col] !== null ? grid[row + 1][col][0] : -1;
    var right = col < GRID_COLS - 1 && grid[row][col + 1] !== null ? grid[row][col + 1][3] : -1;

    var candidates = [];
    for (var i = 0; i < currentSet.length; i++) {
      var t = currentSet[i];
      if (top !== -1 && t[0] !== top) continue;
      if (right !== -1 && t[1] !== right) continue;
      if (bottom !== -1 && t[2] !== bottom) continue;
      if (left !== -1 && t[3] !== left) continue;
      candidates.push(t);
    }
    if (candidates.length === 0) return null;
    return candidates[Math.floor(Math.random() * candidates.length)];
  }

  function placeTile(row, col) {
    if (row < 0 || row >= GRID_ROWS || col < 0 || col >= GRID_COLS) return false;
    if (grid[row][col] !== null) return false;
    var tile = findMatchingTile(row, col);
    if (!tile) return false;
    grid[row][col] = tile.slice();
    placedCount++;
    hudTiles.textContent = placedCount;
    if (row > 0 && grid[row - 1][col] === null) addToQueue(row - 1, col);
    if (row < GRID_ROWS - 1 && grid[row + 1][col] === null) addToQueue(row + 1, col);
    if (col > 0 && grid[row][col - 1] === null) addToQueue(row, col - 1);
    if (col < GRID_COLS - 1 && grid[row][col + 1] === null) addToQueue(row, col + 1);
    return true;
  }

  function addToQueue(row, col) {
    for (var i = 0; i < fillQueue.length; i++) {
      if (fillQueue[i][0] === row && fillQueue[i][1] === col) return;
    }
    fillQueue.push([row, col]);
  }

  function stepFill() {
    if (fillQueue.length === 0) return false;
    var attempts = 0;
    while (fillQueue.length > 0 && attempts < 50) {
      var idx = Math.floor(Math.random() * fillQueue.length);
      var pos = fillQueue.splice(idx, 1)[0];
      if (grid[pos[0]][pos[1]] === null && !stuck[pos[0] + ',' + pos[1]]) {
        var ok = placeTile(pos[0], pos[1]);
        if (ok) {
          SFX.play('tick', 40);
          return true;
        }
        markStuck(pos[0], pos[1]);
      }
      attempts++;
    }
    return false;
  }

  function drawTile(x, y, tile, size) {
    var hs = size / 2;
    ctx.fillStyle = 'rgba(10,10,15,0.9)';
    ctx.fillRect(x, y, size, size);

    ctx.beginPath();
    ctx.moveTo(x + hs, y);
    ctx.lineTo(x + size, y);
    ctx.lineTo(x + hs, y + hs);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[0], 0.5);
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + hs, y);
    ctx.lineTo(x + hs, y + hs);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[0], 0.35);
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x + size, y);
    ctx.lineTo(x + size, y + size);
    ctx.lineTo(x + hs, y + hs);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[1], 0.5);
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x + size, y + size);
    ctx.lineTo(x, y + size);
    ctx.lineTo(x + hs, y + hs);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[2], 0.5);
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x + size, y + size);
    ctx.lineTo(x + hs, y + hs);
    ctx.lineTo(x, y + size);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[2], 0.35);
    ctx.fill();

    ctx.beginPath();
    ctx.moveTo(x, y + size);
    ctx.lineTo(x, y);
    ctx.lineTo(x + hs, y + hs);
    ctx.closePath();
    ctx.fillStyle = edgeColorRGBA(tile[3], 0.5);
    ctx.fill();

    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 0.5;
    ctx.strokeRect(x, y, size, size);
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var ox = panX % TILE_SIZE;
    var oy = panY % TILE_SIZE;
    var startCol = Math.floor(-panX / TILE_SIZE);
    var startRow = Math.floor(-panY / TILE_SIZE);

    for (var r = 0; r < GRID_ROWS; r++) {
      for (var c = 0; c < GRID_COLS; c++) {
        if (grid[r] && grid[r][c] !== null) {
          var sx = c * TILE_SIZE + panX;
          var sy = r * TILE_SIZE + panY;
          if (sx > -TILE_SIZE && sx < W && sy > -TILE_SIZE && sy < H) {
            drawTile(sx, sy, grid[r][c], TILE_SIZE);
          }
        }
      }
    }

    // 行き詰まった穴: どのタイルの辺も周りと合わない場所
    for (var key in stuck) {
      var rc = key.split(',');
      var kx = (+rc[1]) * TILE_SIZE + panX, ky = (+rc[0]) * TILE_SIZE + panY;
      ctx.strokeStyle = 'rgba(181,140,255,0.6)';
      ctx.lineWidth = 1;
      ctx.strokeRect(kx + 3, ky + 3, TILE_SIZE - 6, TILE_SIZE - 6);
      ctx.beginPath();
      ctx.moveTo(kx + 7, ky + 7); ctx.lineTo(kx + TILE_SIZE - 7, ky + TILE_SIZE - 7);
      ctx.moveTo(kx + TILE_SIZE - 7, ky + 7); ctx.lineTo(kx + 7, ky + TILE_SIZE - 7);
      ctx.stroke();
    }

    if (placedCount === 0) {
      ctx.globalAlpha = 0.12;
      for (var gr = 0; gr < GRID_ROWS && gr * TILE_SIZE < H; gr++) {
        for (var gc = 0; gc < GRID_COLS && gc * TILE_SIZE < W; gc++) {
          var gx = gc * TILE_SIZE + panX;
          var gy = gr * TILE_SIZE + panY;
          ctx.strokeStyle = 'rgba(0,245,212,0.08)';
          ctx.lineWidth = 0.5;
          ctx.strokeRect(gx, gy, TILE_SIZE, TILE_SIZE);
        }
      }
      ctx.globalAlpha = 1;
    }
  }

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    if (!grid || GRID_COLS < Math.ceil(W / TILE_SIZE) + 4) {
      var oldGrid = grid;
      var oldCols = GRID_COLS;
      var oldRows = GRID_ROWS;
      GRID_COLS = Math.ceil(W / TILE_SIZE) + 4;
      GRID_ROWS = Math.ceil(H / TILE_SIZE) + 4;
      grid = [];
      for (var r = 0; r < GRID_ROWS; r++) {
        grid[r] = [];
        for (var c = 0; c < GRID_COLS; c++) {
          grid[r][c] = (oldGrid && r < oldRows && c < oldCols) ? oldGrid[r][c] : null;
        }
      }
    }
    draw();
  }
  window.addEventListener('resize', resize);

  function getGridPos(mx, my) {
    var col = Math.floor((mx - panX) / TILE_SIZE);
    var row = Math.floor((my - panY) / TILE_SIZE);
    return [row, col];
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) {
      e.preventDefault();
      resetGrid();
      SFX.play('click');
      draw();
      return;
    }
    if (e.button === 1 || e.shiftKey) {
      isPanning = true;
      panStartX = e.clientX; panStartY = e.clientY;
      panStartPX = panX; panStartPY = panY;
      return;
    }
    var pos = getGridPos(e.clientX, e.clientY);
    if (pos[0] >= 0 && pos[0] < GRID_ROWS && pos[1] >= 0 && pos[1] < GRID_COLS) {
      if (grid[pos[0]][pos[1]] === null) {
        placeTile(pos[0], pos[1]);
        SFX.play('spawn');
        draw();
      }
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (isPanning) {
      panX = panStartPX + (e.clientX - panStartX);
      panY = panStartPY + (e.clientY - panStartY);
      draw();
    }
  });

  C.addEventListener('mouseup', function() { isPanning = false; });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchStartX, touchStartY, touchPanX, touchPanY, touchMoved;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    touchStartX = t.clientX; touchStartY = t.clientY;
    touchPanX = panX; touchPanY = panY;
    touchMoved = false;
  }, {passive: false});

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    var dx = t.clientX - touchStartX;
    var dy = t.clientY - touchStartY;
    if (Math.abs(dx) > 8 || Math.abs(dy) > 8) touchMoved = true;
    if (touchMoved) {
      panX = touchPanX + dx;
      panY = touchPanY + dy;
      draw();
    }
  }, {passive: false});

  C.addEventListener('touchend', function(e) {
    if (!touchMoved) {
      var t = e.changedTouches[0];
      var pos = getGridPos(t.clientX, t.clientY);
      if (pos[0] >= 0 && pos[0] < GRID_ROWS && pos[1] >= 0 && pos[1] < GRID_COLS) {
        if (grid[pos[0]][pos[1]] === null) {
          placeTile(pos[0], pos[1]);
          SFX.play('spawn');
          draw();
        }
      }
    }
  });

  document.getElementById('btn-set1').addEventListener('click', function() { setTileSet('A'); });
  document.getElementById('btn-set2').addEventListener('click', function() { setTileSet('B'); });
  document.getElementById('btn-set3').addEventListener('click', function() { setTileSet('C'); });

  document.getElementById('btn-auto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
    if (autoMode && placedCount === 0) {
      var cr = Math.floor(GRID_ROWS / 2);
      var cc = Math.floor(GRID_COLS / 2);
      placeTile(cr, cc);
      SFX.play('spawn');
    }
  });

  document.getElementById('btn-step').addEventListener('click', function() {
    if (placedCount === 0) {
      var cr = Math.floor(GRID_ROWS / 2);
      var cc = Math.floor(GRID_COLS / 2);
      placeTile(cr, cc);
      SFX.play('spawn');
    } else {
      stepFill();
    }
    draw();
  });

  document.getElementById('btn-bt').addEventListener('click', function() {
    backtrack = !backtrack;
    this.classList.toggle('active', backtrack);
    // 既存の穴も剥がし直しの対象に戻す
    if (backtrack) {
      var keys = Object.keys(stuck);
      stuck = {}; stuckCount = 0;
      for (var i = 0; i < keys.length; i++) {
        var rc = keys[i].split(',');
        markStuck(+rc[0], +rc[1]);
      }
      updateStuckHud();
    }
    SFX.play('toggle');
  });

  document.getElementById('btn-reset').addEventListener('click', function() {
    resetGrid();
    SFX.play('click');
    draw();
  });

  var slSpeed = document.getElementById('sl-speed');
  slSpeed.addEventListener('input', function() {
    speed = parseInt(this.value);
    hudSpeed.textContent = speed;
  });
  speed = parseInt(slSpeed.value);
  hudSpeed.textContent = speed;

  var frameCount = 0;
  function loop() {
    requestAnimationFrame(loop);
    frameCount++;
    if (autoMode && frameCount % Math.max(1, Math.floor(6 / speed)) === 0) {
      var steps = Math.min(speed, 10);
      for (var i = 0; i < steps; i++) {
        stepFill();
      }
      draw();
    }
  }

  resize();
  hudSet.textContent = currentSet.length;
  hudTotal.textContent = currentSet.length;

  setTileSet('C');
  for (var i = 0; i < 30; i++) stepFill();
  draw();

  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
