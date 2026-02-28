<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';

recordPageView('pathfind');

$pageName = 'pathfind';
$pageTitle = 'PATHFIND - PULSE';
$pageDesc = '探索アルゴリズムの可視化。A*、ダイクストラ、BFSがゴールを探す過程を眺める。';
$pageCanonical = 'https://pulse.subara3.com/pathfind.php';

$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&display=swap" rel="stylesheet">';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.65rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .highlight { color: #00f5d4; font-weight: 700; }
.hud .purple { color: #7b2cbf; font-weight: 700; }
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

<div class="ui info">
  CLICK TO PLACE WALLS<br>
  DRAG START / GOAL<br>
  WATCH THE ALGORITHM SEARCH
</div>

<div class="ui hud">
  ALGORITHM: <span id="hudAlgo" class="highlight">A*</span><br>
  VISITED: <span id="hudVisited" class="highlight">0</span><br>
  PATH: <span id="hudPath" class="purple">-</span><br>
  STATE: <span id="hudState" class="highlight">READY</span>
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="1" max="10" value="4">
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="astarBtn">A*</button>
    <button class="control-btn" id="dijkstraBtn">DIJKSTRA</button>
    <button class="control-btn" id="bfsBtn">BFS</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="mazeBtn">MAZE</button>
    <button class="control-btn" id="startBtn">START</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var hudAlgo = document.getElementById('hudAlgo');
  var hudVisited = document.getElementById('hudVisited');
  var hudPath = document.getElementById('hudPath');
  var hudState = document.getElementById('hudState');
  var speedSlider = document.getElementById('speedSlider');
  var astarBtn = document.getElementById('astarBtn');
  var dijkstraBtn = document.getElementById('dijkstraBtn');
  var bfsBtn = document.getElementById('bfsBtn');
  var mazeBtn = document.getElementById('mazeBtn');
  var startBtn = document.getElementById('startBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W, H, CELL_SIZE, COLS, ROWS;
  var grid = [];
  var startCell = { x: 2, y: 2 };
  var goalCell = { x: 10, y: 10 };
  var algorithm = 'astar';
  var state = 'ready'; // ready, searching, found, nopath
  var openSet = [];
  var closedSet = [];
  var path = [];
  var dragging = null;
  var drawing = false;
  var drawWall = true;
  var searchInterval = null;
  var stepsPerFrame = 1;

  var WALL = 1;
  var EMPTY = 0;

  function initGrid() {
    grid = [];
    for (var y = 0; y < ROWS; y++) {
      grid[y] = [];
      for (var x = 0; x < COLS; x++) {
        grid[y][x] = EMPTY;
      }
    }
    startCell = { x: 2, y: Math.floor(ROWS / 2) };
    goalCell = { x: COLS - 3, y: Math.floor(ROWS / 2) };
    resetSearch();
  }

  function resetSearch() {
    state = 'ready';
    openSet = [];
    closedSet = [];
    path = [];
    hudState.textContent = 'READY';
    hudVisited.textContent = '0';
    hudPath.textContent = '-';
    if (searchInterval) {
      clearInterval(searchInterval);
      searchInterval = null;
    }
  }

  function heuristic(a, b) {
    return Math.abs(a.x - b.x) + Math.abs(a.y - b.y);
  }

  function getNeighbors(node) {
    var dirs = [[0,-1],[1,0],[0,1],[-1,0]];
    var neighbors = [];
    for (var i = 0; i < dirs.length; i++) {
      var nx = node.x + dirs[i][0];
      var ny = node.y + dirs[i][1];
      if (nx >= 0 && nx < COLS && ny >= 0 && ny < ROWS && grid[ny][nx] !== WALL) {
        neighbors.push({ x: nx, y: ny });
      }
    }
    return neighbors;
  }

  function nodeKey(n) { return n.x + ',' + n.y; }

  function startSearch() {
    if (state === 'searching') return;
    resetSearch();
    state = 'searching';
    hudState.textContent = 'SEARCHING';

    var start = { x: startCell.x, y: startCell.y, g: 0, h: 0, f: 0, parent: null };
    start.h = heuristic(start, goalCell);
    start.f = start.g + start.h;
    openSet = [start];
    closedSet = [];
    var closedMap = {};
    var openMap = {};
    openMap[nodeKey(start)] = start;

    var stepsPerTick = Math.pow(2, parseInt(speedSlider.value) - 1);

    searchInterval = setInterval(function() {
      for (var step = 0; step < stepsPerTick; step++) {
        if (openSet.length === 0) {
          state = 'nopath';
          hudState.textContent = 'NO PATH';
          if (typeof SFX !== 'undefined') SFX.play('fail');
          clearInterval(searchInterval);
          return;
        }

        var currentIdx = 0;
        if (algorithm === 'bfs') {
          currentIdx = 0;
        } else {
          for (var i = 1; i < openSet.length; i++) {
            if (openSet[i].f < openSet[currentIdx].f ||
               (openSet[i].f === openSet[currentIdx].f && openSet[i].h < openSet[currentIdx].h)) {
              currentIdx = i;
            }
          }
        }

        var current = openSet.splice(currentIdx, 1)[0];
        delete openMap[nodeKey(current)];
        closedSet.push(current);
        closedMap[nodeKey(current)] = true;

        if (typeof SFX !== 'undefined') SFX.play('tick', 30);

        if (current.x === goalCell.x && current.y === goalCell.y) {
          path = [];
          var node = current;
          while (node) {
            path.unshift({ x: node.x, y: node.y });
            node = node.parent;
          }
          state = 'found';
          hudState.textContent = 'FOUND';
          hudPath.textContent = path.length;
          if (typeof SFX !== 'undefined') SFX.play('success');
          clearInterval(searchInterval);
          return;
        }

        var neighbors = getNeighbors(current);
        for (var j = 0; j < neighbors.length; j++) {
          var n = neighbors[j];
          var key = nodeKey(n);
          if (closedMap[key]) continue;

          var tentG = current.g + 1;
          var existing = openMap[key];

          if (!existing) {
            var newNode = {
              x: n.x, y: n.y,
              g: tentG,
              h: algorithm === 'dijkstra' ? 0 : heuristic(n, goalCell),
              parent: current
            };
            newNode.f = newNode.g + newNode.h;
            openSet.push(newNode);
            openMap[key] = newNode;
          } else if (tentG < existing.g) {
            existing.g = tentG;
            existing.f = existing.g + existing.h;
            existing.parent = current;
          }
        }
      }
      hudVisited.textContent = closedSet.length;
    }, 30);
  }

  function generateMaze() {
    resetSearch();
    for (var y = 0; y < ROWS; y++) {
      for (var x = 0; x < COLS; x++) {
        grid[y][x] = WALL;
      }
    }

    var stack = [];
    var sx = 1 + Math.floor(Math.random() * ((COLS - 2) / 2)) * 2;
    var sy = 1 + Math.floor(Math.random() * ((ROWS - 2) / 2)) * 2;
    if (sx >= COLS - 1) sx = 1;
    if (sy >= ROWS - 1) sy = 1;
    grid[sy][sx] = EMPTY;
    stack.push({ x: sx, y: sy });

    var dirs = [[0,-2],[2,0],[0,2],[-2,0]];

    while (stack.length > 0) {
      var cur = stack[stack.length - 1];
      var shuffled = dirs.slice().sort(function() { return Math.random() - 0.5; });
      var found = false;
      for (var i = 0; i < shuffled.length; i++) {
        var nx = cur.x + shuffled[i][0];
        var ny = cur.y + shuffled[i][1];
        if (nx > 0 && nx < COLS - 1 && ny > 0 && ny < ROWS - 1 && grid[ny][nx] === WALL) {
          grid[ny][nx] = EMPTY;
          grid[cur.y + shuffled[i][1]/2][cur.x + shuffled[i][0]/2] = EMPTY;
          stack.push({ x: nx, y: ny });
          found = true;
          break;
        }
      }
      if (!found) stack.pop();
    }

    startCell = { x: 1, y: 1 };
    goalCell = { x: COLS - 2, y: ROWS - 2 };
    if (grid[startCell.y]) grid[startCell.y][startCell.x] = EMPTY;
    if (grid[goalCell.y]) grid[goalCell.y][goalCell.x] = EMPTY;

    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function render() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var offsetX = (W - COLS * CELL_SIZE) / 2;
    var offsetY = (H - ROWS * CELL_SIZE) / 2;

    for (var y = 0; y < ROWS; y++) {
      for (var x = 0; x < COLS; x++) {
        var px = offsetX + x * CELL_SIZE;
        var py = offsetY + y * CELL_SIZE;

        if (grid[y][x] === WALL) {
          ctx.fillStyle = 'rgba(255,255,255,0.15)';
          ctx.fillRect(px + 1, py + 1, CELL_SIZE - 2, CELL_SIZE - 2);
        } else {
          ctx.fillStyle = 'rgba(255,255,255,0.02)';
          ctx.fillRect(px + 1, py + 1, CELL_SIZE - 2, CELL_SIZE - 2);
        }
      }
    }

    for (var i = 0; i < closedSet.length; i++) {
      var c = closedSet[i];
      var px = offsetX + c.x * CELL_SIZE;
      var py = offsetY + c.y * CELL_SIZE;
      var t = i / Math.max(closedSet.length, 1);
      ctx.fillStyle = 'hsla(' + (180 + t * 40) + ',60%,30%,0.6)';
      ctx.fillRect(px + 1, py + 1, CELL_SIZE - 2, CELL_SIZE - 2);
    }

    for (var i = 0; i < openSet.length; i++) {
      var o = openSet[i];
      var px = offsetX + o.x * CELL_SIZE;
      var py = offsetY + o.y * CELL_SIZE;
      ctx.fillStyle = 'rgba(0,245,212,0.3)';
      ctx.fillRect(px + 1, py + 1, CELL_SIZE - 2, CELL_SIZE - 2);
    }

    if (path.length > 0) {
      ctx.strokeStyle = '#7b2cbf';
      ctx.lineWidth = CELL_SIZE * 0.3;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      for (var i = 0; i < path.length; i++) {
        var p = path[i];
        var px = offsetX + p.x * CELL_SIZE + CELL_SIZE / 2;
        var py = offsetY + p.y * CELL_SIZE + CELL_SIZE / 2;
        if (i === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
      }
      ctx.stroke();
    }

    var spx = offsetX + startCell.x * CELL_SIZE;
    var spy = offsetY + startCell.y * CELL_SIZE;
    ctx.fillStyle = '#00f5d4';
    ctx.beginPath();
    ctx.arc(spx + CELL_SIZE/2, spy + CELL_SIZE/2, CELL_SIZE * 0.35, 0, Math.PI * 2);
    ctx.fill();

    var gpx = offsetX + goalCell.x * CELL_SIZE;
    var gpy = offsetY + goalCell.y * CELL_SIZE;
    ctx.fillStyle = '#7b2cbf';
    ctx.beginPath();
    ctx.arc(gpx + CELL_SIZE/2, gpy + CELL_SIZE/2, CELL_SIZE * 0.35, 0, Math.PI * 2);
    ctx.fill();
  }

  function loop() {
    render();
    requestAnimationFrame(loop);
  }

  function getGridPos(clientX, clientY) {
    var rect = canvas.getBoundingClientRect();
    var mx = (clientX - rect.left) * (W / rect.width);
    var my = (clientY - rect.top) * (H / rect.height);
    var offsetX = (W - COLS * CELL_SIZE) / 2;
    var offsetY = (H - ROWS * CELL_SIZE) / 2;
    var gx = Math.floor((mx - offsetX) / CELL_SIZE);
    var gy = Math.floor((my - offsetY) / CELL_SIZE);
    return { x: gx, y: gy };
  }

  function isStart(gx, gy) { return gx === startCell.x && gy === startCell.y; }
  function isGoal(gx, gy) { return gx === goalCell.x && gy === goalCell.y; }

  function onPointerDown(x, y) {
    var g = getGridPos(x, y);
    if (g.x < 0 || g.x >= COLS || g.y < 0 || g.y >= ROWS) return;

    if (isStart(g.x, g.y)) {
      dragging = 'start';
    } else if (isGoal(g.x, g.y)) {
      dragging = 'goal';
    } else {
      drawing = true;
      drawWall = grid[g.y][g.x] !== WALL;
      grid[g.y][g.x] = drawWall ? WALL : EMPTY;
      if (typeof SFX !== 'undefined') SFX.play('spawn', 50);
      resetSearch();
    }
  }

  function onPointerMove(x, y) {
    var g = getGridPos(x, y);
    if (g.x < 0 || g.x >= COLS || g.y < 0 || g.y >= ROWS) return;

    if (dragging === 'start') {
      if (!isGoal(g.x, g.y) && grid[g.y][g.x] !== WALL) {
        startCell = { x: g.x, y: g.y };
        resetSearch();
      }
    } else if (dragging === 'goal') {
      if (!isStart(g.x, g.y) && grid[g.y][g.x] !== WALL) {
        goalCell = { x: g.x, y: g.y };
        resetSearch();
      }
    } else if (drawing) {
      if (!isStart(g.x, g.y) && !isGoal(g.x, g.y)) {
        grid[g.y][g.x] = drawWall ? WALL : EMPTY;
        resetSearch();
      }
    }
  }

  function onPointerUp() {
    dragging = null;
    drawing = false;
  }

  canvas.addEventListener('mousedown', function(e) {
    onPointerDown(e.clientX, e.clientY);
  });
  canvas.addEventListener('mousemove', function(e) {
    onPointerMove(e.clientX, e.clientY);
  });
  canvas.addEventListener('mouseup', onPointerUp);
  canvas.addEventListener('mouseleave', onPointerUp);

  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.changedTouches[0];
    onPointerDown(t.clientX, t.clientY);
  }, { passive: false });
  canvas.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.changedTouches[0];
    onPointerMove(t.clientX, t.clientY);
  }, { passive: false });
  canvas.addEventListener('touchend', onPointerUp);

  function setAlgo(name) {
    algorithm = name;
    hudAlgo.textContent = name === 'astar' ? 'A*' : name.toUpperCase();
    astarBtn.classList.toggle('active', name === 'astar');
    dijkstraBtn.classList.toggle('active', name === 'dijkstra');
    bfsBtn.classList.toggle('active', name === 'bfs');
    resetSearch();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  }

  astarBtn.addEventListener('click', function() { setAlgo('astar'); });
  dijkstraBtn.addEventListener('click', function() { setAlgo('dijkstra'); });
  bfsBtn.addEventListener('click', function() { setAlgo('bfs'); });

  mazeBtn.addEventListener('click', function() {
    generateMaze();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  startBtn.addEventListener('click', function() {
    startSearch();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resetBtn.addEventListener('click', function() {
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;

    var minDim = Math.min(W, H);
    CELL_SIZE = Math.max(12, Math.floor(minDim / 40));
    COLS = Math.floor((W * 0.85) / CELL_SIZE);
    ROWS = Math.floor((H * 0.85) / CELL_SIZE);

    if (COLS % 2 === 0) COLS--;
    if (ROWS % 2 === 0) ROWS--;
    COLS = Math.max(11, COLS);
    ROWS = Math.max(11, ROWS);

    initGrid();
    // 初期表示は迷路ベースにして、何をすればいいか分かりやすくする
    generateMaze();
  }

  window.addEventListener('resize', resize);

  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
