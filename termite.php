<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('termite');

$pageName = 'termite';
$pageTitle = 'TERMITE - PULSE';
$pageDesc = 'シロアリの木片整理。単純なルールで動くエージェントが、散乱した木片を自発的にクラスタへまとめる。中央制御なしの自己組織化。';
$pageCanonical = 'https://pulse.subara3.com/termite.php';
$extraStyle = '
  #hud{position:fixed;top:80px;right:20px;color:rgba(0,245,212,.6);font:11px "Orbitron",monospace;text-align:right;pointer-events:none;z-index:10;line-height:1.9}
  #info-label{position:fixed;bottom:80px;left:20px;pointer-events:none;z-index:10}
  #info-label span{color:rgba(0,245,212,.4);font:13px "Orbitron",monospace;letter-spacing:.12em}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>TERMITES <span id="hud-termites">0</span></div>
  <div>CHIPS <span id="hud-chips">0</span></div>
  <div>CLUSTERS <span id="hud-clusters">0</span></div>
  <div>STEPS <span id="hud-steps">0</span></div>
</div>
<div id="info-label"><span>STIGMERGY</span></div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'scatter chips'],
  ['type' => 'drag', 'label' => 'draw chips'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">TERMITES</label>
    <input type="range" id="sl-termites" min="20" max="500" value="200" step="10">
    <span class="slider-value" id="sv-termites">200</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">CHIP DENSITY</label>
    <input type="range" id="sl-density" min="5" max="40" value="20" step="1">
    <span class="slider-value" id="sv-density">20%</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SPEED</label>
    <input type="range" id="sl-speed" min="1" max="30" value="10" step="1">
    <span class="slider-value" id="sv-speed">10</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-trails">TRAILS</button>
    <button class="ctrl-btn" id="btn-pause">PAUSE</button>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn" id="btn-scatter">SCATTER</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, dpr;
  var cellSize = 4;
  var cols, rows;
  var grid;
  var termites = [];
  var numTermites = 200;
  var chipDensity = 0.20;
  var stepsPerFrame = 10;
  var totalSteps = 0;
  var paused = false;
  var showTrails = true;
  var trailCanvas, trailCtx;

  var hTermites = document.getElementById('hud-termites');
  var hChips = document.getElementById('hud-chips');
  var hClusters = document.getElementById('hud-clusters');
  var hSteps = document.getElementById('hud-steps');

  var TEAL = [0, 245, 212];
  var PURPLE = [123, 44, 191];
  var BG = [10, 10, 15];

  var dx4 = [0, 1, 0, -1];
  var dy4 = [0, -1, 0, 1];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(dpr, 0, 0, dpr, 0, 0);
    cols = Math.floor(W / cellSize);
    rows = Math.floor(H / cellSize);
    if (trailCanvas) {
      trailCanvas.width = W * dpr;
      trailCanvas.height = H * dpr;
      trailCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
  }

  function initTrailCanvas() {
    trailCanvas = document.createElement('canvas');
    trailCtx = trailCanvas.getContext('2d');
  }

  function initGrid() {
    grid = new Uint8Array(cols * rows);
    var chipCount = Math.floor(cols * rows * chipDensity);
    for (var i = 0; i < chipCount; i++) {
      var idx = Math.floor(Math.random() * cols * rows);
      grid[idx] = 1;
    }
  }

  function initTermites() {
    termites = [];
    for (var i = 0; i < numTermites; i++) {
      termites.push({
        x: Math.floor(Math.random() * cols),
        y: Math.floor(Math.random() * rows),
        dir: Math.floor(Math.random() * 4),
        carrying: false
      });
    }
  }

  function countNeighborChips(gx, gy) {
    var count = 0;
    for (var dy = -2; dy <= 2; dy++) {
      for (var dx = -2; dx <= 2; dx++) {
        if (dx === 0 && dy === 0) continue;
        var nx = (gx + dx + cols) % cols;
        var ny = (gy + dy + rows) % rows;
        if (grid[ny * cols + nx]) count++;
      }
    }
    return count;
  }

  function stepTermite(t) {
    if (Math.random() < 0.2) {
      t.dir = Math.floor(Math.random() * 4);
    }
    t.x = (t.x + dx4[t.dir] + cols) % cols;
    t.y = (t.y + dy4[t.dir] + rows) % rows;
    var idx = t.y * cols + t.x;

    if (!t.carrying) {
      if (grid[idx]) {
        var neighbors = countNeighborChips(t.x, t.y);
        var pickProb = neighbors < 3 ? 0.9 : (neighbors < 5 ? 0.3 : 0.02);
        if (Math.random() < pickProb) {
          grid[idx] = 0;
          t.carrying = true;
        }
      }
    } else {
      if (!grid[idx]) {
        var neighbors = countNeighborChips(t.x, t.y);
        var dropProb = neighbors > 4 ? 0.95 : (neighbors > 2 ? 0.5 : 0.01);
        if (Math.random() < dropProb) {
          grid[idx] = 1;
          t.carrying = false;
        }
      }
    }
  }

  function countClusters() {
    var visited = new Uint8Array(cols * rows);
    var count = 0;
    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        var idx = y * cols + x;
        if (grid[idx] && !visited[idx]) {
          count++;
          var stack = [idx];
          while (stack.length) {
            var ci = stack.pop();
            if (visited[ci]) continue;
            visited[ci] = 1;
            var cx = ci % cols, cy = (ci - cx) / cols;
            for (var d = 0; d < 4; d++) {
              var nx = (cx + dx4[d] + cols) % cols;
              var ny = (cy + dy4[d] + rows) % rows;
              var ni = ny * cols + nx;
              if (grid[ni] && !visited[ni]) stack.push(ni);
            }
          }
        }
      }
    }
    return count;
  }

  function countChips() {
    var c = 0;
    for (var i = 0; i < grid.length; i++) if (grid[i]) c++;
    return c;
  }

  function draw() {
    X.fillStyle = 'rgba(10,10,15,0.15)';
    X.fillRect(0, 0, W, H);

    if (showTrails) {
      trailCtx.fillStyle = 'rgba(10,10,15,0.02)';
      trailCtx.fillRect(0, 0, W, H);
    }

    for (var y = 0; y < rows; y++) {
      for (var x = 0; x < cols; x++) {
        if (grid[y * cols + x]) {
          var neighbors = countNeighborChips(x, y);
          var t = Math.min(neighbors / 12, 1);
          var r = Math.round(TEAL[0] * (1 - t) + PURPLE[0] * t);
          var g = Math.round(TEAL[1] * (1 - t) + PURPLE[1] * t);
          var b = Math.round(TEAL[2] * (1 - t) + PURPLE[2] * t);
          X.fillStyle = 'rgba(' + r + ',' + g + ',' + b + ',0.85)';
          X.fillRect(x * cellSize, y * cellSize, cellSize, cellSize);
        }
      }
    }

    for (var i = 0; i < termites.length; i++) {
      var tm = termites[i];
      var px = tm.x * cellSize + cellSize * 0.5;
      var py = tm.y * cellSize + cellSize * 0.5;

      if (showTrails) {
        trailCtx.fillStyle = tm.carrying
          ? 'rgba(123,44,191,0.15)'
          : 'rgba(0,245,212,0.08)';
        trailCtx.fillRect(px - 0.5, py - 0.5, 1, 1);
      }

      X.fillStyle = tm.carrying
        ? 'rgba(200,120,255,0.9)'
        : 'rgba(0,245,212,0.5)';
      X.fillRect(px - 1.5, py - 1.5, 3, 3);
    }

    if (showTrails) {
      X.globalAlpha = 0.6;
      X.drawImage(trailCanvas, 0, 0, W, H);
      X.globalAlpha = 1;
    }
  }

  var hudCounter = 0;
  function loop() {
    if (!paused) {
      for (var s = 0; s < stepsPerFrame; s++) {
        for (var i = 0; i < termites.length; i++) {
          stepTermite(termites[i]);
        }
        totalSteps++;
      }
    }
    draw();

    hudCounter++;
    if (hudCounter % 15 === 0) {
      hTermites.textContent = termites.length;
      hChips.textContent = countChips();
      hClusters.textContent = countClusters();
      hSteps.textContent = totalSteps > 1000 ? (totalSteps / 1000).toFixed(1) + 'k' : totalSteps;
    }

    requestAnimationFrame(loop);
  }

  function scatterChips() {
    initGrid();
    clearTrails();
    totalSteps = 0;
    SFX.play('click');
  }

  function resetAll() {
    initGrid();
    initTermites();
    clearTrails();
    totalSteps = 0;
    SFX.play('click');
  }

  function clearTrails() {
    if (trailCtx) {
      trailCtx.clearRect(0, 0, trailCanvas.width, trailCanvas.height);
    }
  }

  function paintChips(mx, my, erase) {
    var gx = Math.floor(mx / cellSize);
    var gy = Math.floor(my / cellSize);
    var r = 4;
    for (var dy = -r; dy <= r; dy++) {
      for (var dx = -r; dx <= r; dx++) {
        if (dx * dx + dy * dy > r * r) continue;
        var nx = (gx + dx + cols) % cols;
        var ny = (gy + dy + rows) % rows;
        grid[ny * cols + nx] = erase ? 0 : 1;
      }
    }
  }

  var isDown = false, isRight = false;
  function getPos(e) {
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX, y: t.clientY };
  }

  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    isDown = true;
    isRight = e.button === 2;
    var p = getPos(e);
    if (!isRight) {
      paintChips(p.x, p.y, false);
      SFX.play('spawn');
    } else {
      paintChips(p.x, p.y, true);
      SFX.play('pop');
    }
  });

  C.addEventListener('mousemove', function(e) {
    if (!isDown) return;
    var p = getPos(e);
    paintChips(p.x, p.y, isRight);
    SFX.play(isRight ? 'pop' : 'spawn', 80);
  });

  C.addEventListener('mouseup', function() { isDown = false; });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    isDown = true;
    var p = getPos(e);
    paintChips(p.x, p.y, false);
    SFX.play('spawn');
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDown) return;
    var p = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    paintChips(p.x, p.y, false);
    SFX.play('spawn', 80);
  }, { passive: false });

  C.addEventListener('touchend', function() { isDown = false; });

  var slTermites = document.getElementById('sl-termites');
  var svTermites = document.getElementById('sv-termites');
  slTermites.addEventListener('input', function() {
    numTermites = +this.value;
    svTermites.textContent = numTermites;
    while (termites.length < numTermites) {
      termites.push({
        x: Math.floor(Math.random() * cols),
        y: Math.floor(Math.random() * rows),
        dir: Math.floor(Math.random() * 4),
        carrying: false
      });
    }
    while (termites.length > numTermites) termites.pop();
    SFX.play('tick');
  });

  var slDensity = document.getElementById('sl-density');
  var svDensity = document.getElementById('sv-density');
  slDensity.addEventListener('input', function() {
    chipDensity = +this.value / 100;
    svDensity.textContent = this.value + '%';
    SFX.play('tick');
  });

  var slSpeed = document.getElementById('sl-speed');
  var svSpeed = document.getElementById('sv-speed');
  slSpeed.addEventListener('input', function() {
    stepsPerFrame = +this.value;
    svSpeed.textContent = this.value;
    SFX.play('tick');
  });

  document.getElementById('btn-trails').addEventListener('click', function() {
    showTrails = !showTrails;
    this.classList.toggle('active');
    if (!showTrails) clearTrails();
    SFX.play('toggle');
  });

  document.getElementById('btn-pause').addEventListener('click', function() {
    paused = !paused;
    this.classList.toggle('active');
    this.textContent = paused ? 'PLAY' : 'PAUSE';
    SFX.play('toggle');
  });

  document.getElementById('btn-scatter').addEventListener('click', scatterChips);
  document.getElementById('btn-reset').addEventListener('click', resetAll);

  window.addEventListener('resize', function() {
    var oldGrid = grid;
    var oldCols = cols, oldRows = rows;
    resize();
    grid = new Uint8Array(cols * rows);
    var mc = Math.min(cols, oldCols), mr = Math.min(rows, oldRows);
    for (var y = 0; y < mr; y++) {
      for (var x = 0; x < mc; x++) {
        grid[y * cols + x] = oldGrid[y * oldCols + x];
      }
    }
  });

  initTrailCanvas();
  resize();
  initGrid();
  initTermites();

  X.fillStyle = '#0a0a0f';
  X.fillRect(0, 0, W, H);

  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
