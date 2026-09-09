<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('polyomino');

$pageName = 'polyomino';
$pageTitle = 'POLYOMINO - PULSE';
$pageDesc = 'ポリオミノ完全被覆。バックトラッキングが矩形領域をピースで隙間なく埋める過程を目撃する。探索木の枝刈りと分岐が見える組合せパズル。';
$pageCanonical = 'https://pulse.subara3.com/polyomino.php';
$extraStyle = '
  #hud{position:fixed;top:80px;left:20px;color:rgba(0,245,212,.6);font:11px "Space Grotesk",monospace;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Space Grotesk",monospace;letter-spacing:.12em}
  canvas{cursor:crosshair}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>STEPS <span id="hud-steps">0</span></div>
  <div>SOLUTIONS <span id="hud-sol">0</span></div>
  <div>PIECES <span id="hud-pieces">0/0</span></div>
</div>
<div id="info-label"><span>POLYOMINO</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'solve / next'],
  ['type' => 'right', 'label' => 'reset'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row"><label>MODE</label></div>
    <div class="btn-row">
      <button id="btn-tetro" class="mode-btn">TETRO</button>
      <button id="btn-pento" class="mode-btn active">PENTO</button>
      <button id="btn-hexo" class="mode-btn">HEXO</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <label>SPEED</label>
      <input type="range" id="sl-speed" min="0" max="4" step="1" value="2">
      <span class="slider-value" id="val-speed">MED</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
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
  var hudSteps = document.getElementById('hud-steps');
  var hudSol = document.getElementById('hud-sol');
  var hudPieces = document.getElementById('hud-pieces');

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2) : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function pieceColor(idx, total, alpha) {
    var t = total > 1 ? idx / (total - 1) : 0.5;
    var h = brandHue(t);
    return 'hsla(' + h + ',70%,55%,' + (alpha || 1) + ')';
  }

  var TETRO_CELLS = [
    [[0,0],[0,1],[0,2],[0,3]],
    [[0,0],[0,1],[1,0],[1,1]],
    [[0,0],[0,1],[0,2],[1,1]],
    [[0,1],[0,2],[1,0],[1,1]],
    [[0,0],[1,0],[2,0],[2,1]]
  ];
  var TETRO_NAMES = ['I','O','T','S','L'];

  var PENTO_CELLS = [
    [[0,1],[0,2],[1,0],[1,1],[2,1]],
    [[0,0],[1,0],[2,0],[3,0],[4,0]],
    [[0,0],[1,0],[2,0],[3,0],[3,1]],
    [[0,0],[1,0],[1,1],[2,1],[3,1]],
    [[0,0],[0,1],[1,0],[1,1],[2,0]],
    [[0,0],[0,1],[0,2],[1,1],[2,1]],
    [[0,0],[0,2],[1,0],[1,1],[1,2]],
    [[0,0],[1,0],[2,0],[2,1],[2,2]],
    [[0,0],[1,0],[1,1],[2,1],[2,2]],
    [[0,1],[1,0],[1,1],[1,2],[2,1]],
    [[0,1],[1,0],[1,1],[2,1],[3,1]],
    [[0,0],[0,1],[1,1],[2,1],[2,2]]
  ];
  var PENTO_NAMES = ['F','I','L','N','P','T','U','V','W','X','Y','Z'];

  var HEXO_CELLS = [
    [[0,0],[1,0],[2,0],[3,0],[3,1],[3,2]],
    [[0,0],[0,1],[1,1],[2,1],[2,2],[3,2]],
    [[0,0],[1,0],[1,1],[2,1],[2,2],[3,2]],
    [[0,0],[0,1],[0,2],[1,0],[2,0],[2,1]],
    [[0,0],[1,0],[1,1],[1,2],[2,2],[3,2]],
    [[0,0],[0,1],[1,1],[1,2],[2,2],[2,3]],
    [[0,0],[1,0],[2,0],[2,1],[3,1],[3,2]],
    [[0,1],[0,2],[1,0],[1,1],[2,0],[3,0]],
    [[0,0],[0,1],[1,0],[1,1],[2,0],[2,1]],
    [[0,0],[0,1],[0,2],[1,2],[1,3],[1,4]]
  ];
  var HEXO_NAMES = ['A','B','C','D','E','F','G','H','I','J'];

  var MODES = {
    TETRO: { pieces: TETRO_CELLS, names: TETRO_NAMES, rows: 4, cols: 5 },
    PENTO: { pieces: PENTO_CELLS, names: PENTO_NAMES, rows: 6, cols: 10 },
    HEXO:  { pieces: HEXO_CELLS, names: HEXO_NAMES, rows: 6, cols: 10 }
  };

  var mode = 'PENTO';
  var board, rows, cols, pieces, numPieces;
  var used, placedStack;
  var solving, solved, paused;
  var solutions, stepCount;
  var solverState;
  var currentSolIdx;
  var stepsPerFrame;
  var speedLabels = ['SLOW','LOW','MED','FAST','MAX'];
  var speedValues = [1, 5, 30, 200, 2000];
  var speedIdx = 2;
  var flashCells = [];
  var showingSolution = false;
  var animT = 0;

  function rotate90(cells) {
    var out = [];
    for (var i = 0; i < cells.length; i++)
      out.push([cells[i][1], -cells[i][0]]);
    return normalize(out);
  }
  function reflect(cells) {
    var out = [];
    for (var i = 0; i < cells.length; i++)
      out.push([cells[i][0], -cells[i][1]]);
    return normalize(out);
  }
  function normalize(cells) {
    var minR = 999, minC = 999;
    for (var i = 0; i < cells.length; i++) {
      if (cells[i][0] < minR) minR = cells[i][0];
      if (cells[i][1] < minC) minC = cells[i][1];
    }
    var out = [];
    for (var i = 0; i < cells.length; i++)
      out.push([cells[i][0] - minR, cells[i][1] - minC]);
    out.sort(function(a, b) { return a[0] !== b[0] ? a[0] - b[0] : a[1] - b[1]; });
    return out;
  }
  function cellsKey(cells) {
    var s = '';
    for (var i = 0; i < cells.length; i++) s += cells[i][0] + ',' + cells[i][1] + ';';
    return s;
  }
  function allOrientations(cells) {
    var seen = {};
    var result = [];
    var c = normalize(cells);
    for (var flip = 0; flip < 2; flip++) {
      for (var r = 0; r < 4; r++) {
        var k = cellsKey(c);
        if (!seen[k]) { seen[k] = true; result.push(c); }
        c = rotate90(c);
      }
      c = reflect(c);
    }
    return result;
  }

  var pieceOrients;

  function initMode(m) {
    mode = m;
    var cfg = MODES[m];
    rows = cfg.rows; cols = cfg.cols;
    numPieces = cfg.pieces.length;
    pieces = [];
    for (var i = 0; i < numPieces; i++) {
      pieces.push({ name: cfg.names[i], orients: allOrientations(cfg.pieces[i]) });
    }
    resetBoard();
    updateModeButtons();
  }

  function resetBoard() {
    board = [];
    for (var r = 0; r < rows; r++) {
      board[r] = [];
      for (var c = 0; c < cols; c++) board[r][c] = -1;
    }
    used = new Array(numPieces).fill(false);
    placedStack = [];
    solving = false; solved = false; paused = false;
    solutions = []; stepCount = 0; currentSolIdx = 0;
    solverState = null;
    flashCells = [];
    showingSolution = false;
    updateHud();
  }

  function updateHud() {
    hudSteps.textContent = stepCount;
    hudSol.textContent = solutions.length;
    var placed = 0;
    for (var i = 0; i < numPieces; i++) if (used[i]) placed++;
    hudPieces.textContent = placed + '/' + numPieces;
  }

  function updateModeButtons() {
    var btns = ['btn-tetro', 'btn-pento', 'btn-hexo'];
    var modes = ['TETRO', 'PENTO', 'HEXO'];
    for (var i = 0; i < 3; i++) {
      var b = document.getElementById(btns[i]);
      if (b) b.classList.toggle('active', modes[i] === mode);
    }
  }

  function findEmpty() {
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        if (board[r][c] === -1) return [r, c];
    return null;
  }

  function canPlace(orient, startR, startC) {
    for (var i = 0; i < orient.length; i++) {
      var r = startR + orient[i][0], c = startC + orient[i][1];
      if (r < 0 || r >= rows || c < 0 || c >= cols || board[r][c] !== -1) return false;
    }
    return true;
  }

  function doPlace(orient, startR, startC, pieceIdx) {
    for (var i = 0; i < orient.length; i++) {
      board[startR + orient[i][0]][startC + orient[i][1]] = pieceIdx;
    }
  }

  function doRemove(orient, startR, startC) {
    for (var i = 0; i < orient.length; i++) {
      board[startR + orient[i][0]][startC + orient[i][1]] = -1;
    }
  }

  function hasIsland() {
    var visited = [];
    for (var r = 0; r < rows; r++) {
      visited[r] = [];
      for (var c = 0; c < cols; c++) visited[r][c] = false;
    }
    var cellSize = pieces[0].orients[0].length;
    var dirs = [[0,1],[0,-1],[1,0],[-1,0]];
    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        if (board[r][c] === -1 && !visited[r][c]) {
          var count = 0;
          var queue = [[r, c]];
          visited[r][c] = true;
          while (queue.length > 0) {
            var cur = queue.shift();
            count++;
            for (var d = 0; d < 4; d++) {
              var nr = cur[0] + dirs[d][0], nc = cur[1] + dirs[d][1];
              if (nr >= 0 && nr < rows && nc >= 0 && nc < cols && !visited[nr][nc] && board[nr][nc] === -1) {
                visited[nr][nc] = true;
                queue.push([nr, nc]);
              }
            }
          }
          if (count % cellSize !== 0) return true;
        }
      }
    }
    return false;
  }

  function initSolver() {
    solverState = {
      stack: [{ cell: findEmpty(), pi: 0, oi: 0 }],
      maxSolutions: 100
    };
    solving = true;
    solved = false;
    stepCount = 0;
    solutions = [];
    currentSolIdx = 0;
    showingSolution = false;
  }

  function solverStep() {
    var st = solverState;
    if (!st || st.stack.length === 0) { solving = false; solved = true; return false; }
    if (solutions.length >= st.maxSolutions) { solving = false; solved = true; return false; }

    var frame = st.stack[st.stack.length - 1];
    var cell = frame.cell;

    if (cell === null) {
      var snapshot = [];
      for (var r = 0; r < rows; r++) {
        snapshot[r] = [];
        for (var c = 0; c < cols; c++) snapshot[r][c] = board[r][c];
      }
      solutions.push(snapshot);
      SFX.play('complete');
      addFlash(null, 'solution');
      st.stack.pop();
      return true;
    }

    while (frame.pi < numPieces) {
      if (used[frame.pi]) { frame.pi++; frame.oi = 0; continue; }
      while (frame.oi < pieces[frame.pi].orients.length) {
        var orient = pieces[frame.pi].orients[frame.oi];
        frame.oi++;
        stepCount++;
        if (canPlace(orient, cell[0], cell[1])) {
          doPlace(orient, cell[0], cell[1], frame.pi);
          if (!hasIsland()) {
            used[frame.pi] = true;
            placedStack.push({ pi: frame.pi, orient: orient, r: cell[0], c: cell[1] });
            SFX.play('tick', 40);
            addFlash(orient, 'place', cell[0], cell[1]);
            st.stack.push({ cell: findEmpty(), pi: 0, oi: 0 });
            return true;
          }
          doRemove(orient, cell[0], cell[1]);
        }
      }
      frame.oi = 0;
      frame.pi++;
    }

    st.stack.pop();
    if (placedStack.length > 0) {
      var last = placedStack.pop();
      doRemove(last.orient, last.r, last.c);
      used[last.pi] = false;
      addFlash(last.orient, 'remove', last.r, last.c);
    }
    return true;
  }

  function addFlash(orient, type, startR, startC) {
    if (orient === null) {
      for (var r = 0; r < rows; r++)
        for (var c = 0; c < cols; c++)
          flashCells.push({ r: r, c: c, type: type, t: 1.0 });
      return;
    }
    for (var i = 0; i < orient.length; i++)
      flashCells.push({ r: startR + orient[i][0], c: startC + orient[i][1], type: type, t: 1.0 });
  }

  function showSolution(idx) {
    if (solutions.length === 0) return;
    idx = ((idx % solutions.length) + solutions.length) % solutions.length;
    currentSolIdx = idx;
    var snap = solutions[idx];
    for (var r = 0; r < rows; r++)
      for (var c = 0; c < cols; c++)
        board[r][c] = snap[r][c];
    used = new Array(numPieces).fill(true);
    showingSolution = true;
    updateHud();
  }

  function cellSize() {
    var pad = 60;
    var maxW = (W - pad * 2) / cols;
    var maxH = (H - pad * 2) / rows;
    return Math.min(maxW, maxH, 60);
  }

  function boardOrigin() {
    var cs = cellSize();
    var bw = cols * cs, bh = rows * cs;
    return { x: (W - bw) / 2, y: (H - bh) / 2 };
  }

  function draw() {
    animT += 1 / 60;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cs = cellSize();
    var orig = boardOrigin();
    var ox = orig.x, oy = orig.y;

    ctx.strokeStyle = 'rgba(0,245,212,0.08)';
    ctx.lineWidth = 1;
    for (var r = 0; r <= rows; r++) {
      ctx.beginPath();
      ctx.moveTo(ox, oy + r * cs);
      ctx.lineTo(ox + cols * cs, oy + r * cs);
      ctx.stroke();
    }
    for (var c = 0; c <= cols; c++) {
      ctx.beginPath();
      ctx.moveTo(ox + c * cs, oy);
      ctx.lineTo(ox + c * cs, oy + rows * cs);
      ctx.stroke();
    }

    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        var v = board[r][c];
        if (v >= 0) {
          ctx.fillStyle = pieceColor(v, numPieces, 0.7);
          ctx.fillRect(ox + c * cs + 1, oy + r * cs + 1, cs - 2, cs - 2);
        }
      }
    }

    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        var v = board[r][c];
        if (v < 0) continue;
        ctx.strokeStyle = pieceColor(v, numPieces, 0.9);
        ctx.lineWidth = 2;
        if (r === 0 || board[r - 1][c] !== v) {
          ctx.beginPath(); ctx.moveTo(ox + c * cs, oy + r * cs); ctx.lineTo(ox + (c + 1) * cs, oy + r * cs); ctx.stroke();
        }
        if (r === rows - 1 || board[r + 1][c] !== v) {
          ctx.beginPath(); ctx.moveTo(ox + c * cs, oy + (r + 1) * cs); ctx.lineTo(ox + (c + 1) * cs, oy + (r + 1) * cs); ctx.stroke();
        }
        if (c === 0 || board[r][c - 1] !== v) {
          ctx.beginPath(); ctx.moveTo(ox + c * cs, oy + r * cs); ctx.lineTo(ox + c * cs, oy + (r + 1) * cs); ctx.stroke();
        }
        if (c === cols - 1 || board[r][c + 1] !== v) {
          ctx.beginPath(); ctx.moveTo(ox + (c + 1) * cs, oy + r * cs); ctx.lineTo(ox + (c + 1) * cs, oy + (r + 1) * cs); ctx.stroke();
        }
      }
    }

    for (var i = flashCells.length - 1; i >= 0; i--) {
      var f = flashCells[i];
      f.t -= 0.04;
      if (f.t <= 0) { flashCells.splice(i, 1); continue; }
      var a = f.t * 0.5;
      if (f.type === 'place') ctx.fillStyle = 'rgba(0,245,212,' + a + ')';
      else if (f.type === 'remove') ctx.fillStyle = 'rgba(123,44,191,' + a + ')';
      else ctx.fillStyle = 'rgba(0,245,212,' + a + ')';
      ctx.fillRect(ox + f.c * cs, oy + f.r * cs, cs, cs);
    }

    if (showingSolution && solutions.length > 1) {
      ctx.fillStyle = 'rgba(0,245,212,0.5)';
      ctx.font = '13px "Space Grotesk", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('SOLUTION ' + (currentSolIdx + 1) + ' / ' + solutions.length, W / 2, oy + rows * cs + 30);
    }

    if (solving) {
      var pulse = 0.4 + 0.3 * Math.sin(animT * 4);
      ctx.fillStyle = 'rgba(0,245,212,' + pulse + ')';
      ctx.font = '11px "Space Grotesk", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('SOLVING...', W / 2, oy - 15);
    } else if (solved && solutions.length === 0) {
      ctx.fillStyle = 'rgba(123,44,191,0.6)';
      ctx.font = '13px "Space Grotesk", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('NO SOLUTION FOUND', W / 2, oy - 15);
    }

    drawPieceInventory(cs, ox, oy);

    updateHud();
  }

  function drawPieceInventory(cs, ox, oy) {
    var invCS = Math.min(cs * 0.45, 12);
    var startX = ox;
    var startY = oy + rows * cs + 50;
    if (startY + 80 > H) startY = oy - 60;
    var curX = startX;

    for (var i = 0; i < numPieces; i++) {
      var orient = pieces[i].orients[0];
      var maxR = 0, maxC = 0;
      for (var j = 0; j < orient.length; j++) {
        if (orient[j][0] > maxR) maxR = orient[j][0];
        if (orient[j][1] > maxC) maxC = orient[j][1];
      }
      var pw = (maxC + 1) * invCS;

      if (curX + pw > ox + cols * cs) {
        curX = startX;
        startY += (maxR + 2) * invCS + 4;
      }

      var alpha = used[i] ? 0.15 : 0.6;
      ctx.fillStyle = pieceColor(i, numPieces, alpha);
      for (var j = 0; j < orient.length; j++) {
        ctx.fillRect(curX + orient[j][1] * invCS, startY + orient[j][0] * invCS, invCS - 1, invCS - 1);
      }
      curX += pw + invCS + 4;
    }
  }

  function loop() {
    if (solving && !paused) {
      var n = speedValues[speedIdx];
      for (var i = 0; i < n; i++) {
        if (!solverStep()) break;
      }
    }
    draw();
    requestAnimationFrame(loop);
  }

  function handleClick(mx, my) {
    if (showingSolution && solutions.length > 1) {
      currentSolIdx++;
      if (currentSolIdx >= solutions.length) currentSolIdx = 0;
      showSolution(currentSolIdx);
      SFX.play('toggle');
      return;
    }
    if (solved && solutions.length > 0) {
      showSolution(0);
      SFX.play('toggle');
      return;
    }
    if (solving) {
      paused = !paused;
      SFX.play('toggle');
      return;
    }
    initSolver();
    SFX.play('spawn');
  }

  function handleReset() {
    resetBoard();
    SFX.play('click');
  }

  C.addEventListener('click', function(e) {
    handleClick(e.clientX, e.clientY);
  });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    handleReset();
  });

  var touchStartT = 0;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    touchStartT = Date.now();
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    if (Date.now() - touchStartT > 400) {
      handleReset();
    } else {
      var t = e.changedTouches[0];
      handleClick(t.clientX, t.clientY);
    }
  }, { passive: false });

  document.getElementById('btn-tetro').addEventListener('click', function() { initMode('TETRO'); SFX.play('toggle'); });
  document.getElementById('btn-pento').addEventListener('click', function() { initMode('PENTO'); SFX.play('toggle'); });
  document.getElementById('btn-hexo').addEventListener('click', function() { initMode('HEXO'); SFX.play('toggle'); });

  var slSpeed = document.getElementById('sl-speed');
  var valSpeed = document.getElementById('val-speed');
  slSpeed.addEventListener('input', function() {
    speedIdx = parseInt(this.value);
    valSpeed.textContent = speedLabels[speedIdx];
  });

  document.getElementById('btn-reset').addEventListener('click', function() { handleReset(); });

  initMode('PENTO');
  initSolver();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
