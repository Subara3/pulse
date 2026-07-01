<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('schelling');

$pageName = 'schelling';
$pageTitle = 'SCHELLING - PULSE';
$pageDesc = "シェリング隔離モデル。緩やかな同類選好だけで強い隔離が自然発生する。社会物理学のシミュレーション。";
$pageCanonical = 'https://pulse.subara3.com/schelling.php';

$extraStyle = '
body{background:#0a0a0f;margin:0;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;image-rendering:pixelated;image-rendering:crisp-edges;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
.hud .v2{color:#a855f7;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  STEP: <span id="hudStep" class="v">0</span><br>
  HAPPY: <span id="hudHappy" class="v">0%</span><br>
  SEG: <span id="hudSeg" class="v2">0.00</span>
</div>

<div id="info">
  schelling segregation<br>
  <span class="v">local preference</span><br>
  global pattern emerges
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">TOLERANCE</label>
    <div class="slider-row">
      <input type="range" id="sldTol" min="10" max="80" value="35">
      <span class="slider-value" id="valTol">0.35</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">DENSITY</label>
    <div class="slider-row">
      <input type="range" id="sldDen" min="50" max="95" value="85">
      <span class="slider-value" id="valDen">0.85</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="8" value="3">
      <span class="slider-value" id="valSpeed">3</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnRun">RUN</button>
      <button class="control-btn" id="btnStep">STEP</button>
      <button class="control-btn" id="btnReseed">RESEED</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var GRID = 96;
  var cells, empties;
  var tolerance = 0.35;
  var density = 0.85;
  var speed = 3;
  var step = 0;
  var running = true;
  function playSfx(name, value) {
    if (typeof SFX !== 'undefined') SFX.play(name, value);
  }
  var pendingReseed = false;
  var fitScale, drawX, drawY, dispW, dispH;
  var offC = document.createElement('canvas'), offCtx = offC.getContext('2d');
  var imgData, imgD;

  // 0 = empty, 1 = teal, 2 = purple
  var COLORS = [
    [10, 10, 15],       // empty
    [0, 245, 212],      // teal
    [168, 85, 247]      // purple
  ];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    calcLayout();
  }

  function calcLayout() {
    fitScale = Math.min(W * 0.92 / GRID, H * 0.86 / GRID);
    fitScale = Math.max(2, Math.floor(fitScale));
    dispW = GRID * fitScale;
    dispH = GRID * fitScale;
    drawX = Math.floor((W - dispW) / 2);
    drawY = Math.floor((H - dispH) / 2);
  }

  function initImg() {
    offC.width = GRID;
    offC.height = GRID;
    imgData = offCtx.createImageData(GRID, GRID);
    imgD = imgData.data;
  }

  function reseed() {
    cells = new Uint8Array(GRID * GRID);
    empties = [];
    for (var i = 0; i < cells.length; i++) {
      if (Math.random() < density) {
        cells[i] = Math.random() < 0.5 ? 1 : 2;
      } else {
        cells[i] = 0;
        empties.push(i);
      }
    }
    step = 0;
    paintAll();
  }

  function paintAll() {
    for (var i = 0; i < cells.length; i++) {
      var c = COLORS[cells[i]];
      var b = i * 4;
      imgD[b] = c[0]; imgD[b + 1] = c[1]; imgD[b + 2] = c[2]; imgD[b + 3] = 255;
    }
    offCtx.putImageData(imgData, 0, 0);
  }

  function paintCell(idx) {
    var c = COLORS[cells[idx]];
    var b = idx * 4;
    imgD[b] = c[0]; imgD[b + 1] = c[1]; imgD[b + 2] = c[2]; imgD[b + 3] = 255;
  }

  // Moore neighborhood, return ratio of same-type / total occupied neighbors
  function isHappy(idx) {
    var t = cells[idx];
    if (t === 0) return true;
    var x = idx % GRID, y = (idx / GRID) | 0;
    var same = 0, total = 0;
    for (var dy = -1; dy <= 1; dy++) {
      var ny = y + dy;
      if (ny < 0 || ny >= GRID) continue;
      for (var dx = -1; dx <= 1; dx++) {
        if (dx === 0 && dy === 0) continue;
        var nx = x + dx;
        if (nx < 0 || nx >= GRID) continue;
        var v = cells[ny * GRID + nx];
        if (v === 0) continue;
        total++;
        if (v === t) same++;
      }
    }
    if (total === 0) return true;
    return (same / total) >= tolerance;
  }

  function doStep() {
    if (empties.length === 0) return 0;
    var unhappy = [];
    for (var i = 0; i < cells.length; i++) {
      if (cells[i] !== 0 && !isHappy(i)) unhappy.push(i);
    }
    // Fisher-Yates shuffle of unhappy
    for (var k = unhappy.length - 1; k > 0; k--) {
      var j = (Math.random() * (k + 1)) | 0;
      var tmp = unhappy[k]; unhappy[k] = unhappy[j]; unhappy[j] = tmp;
    }
    var moved = 0;
    for (var u = 0; u < unhappy.length; u++) {
      if (empties.length === 0) break;
      var src = unhappy[u];
      // pick random empty
      var ei = (Math.random() * empties.length) | 0;
      var dst = empties[ei];
      // swap
      cells[dst] = cells[src];
      cells[src] = 0;
      empties[ei] = src;
      paintCell(src);
      paintCell(dst);
      moved++;
    }
    step++;
    return moved;
  }

  function happyRatio() {
    var occ = 0, h = 0;
    for (var i = 0; i < cells.length; i++) {
      if (cells[i] === 0) continue;
      occ++;
      if (isHappy(i)) h++;
    }
    return occ === 0 ? 1 : h / occ;
  }

  // Segregation index: average fraction of same-type neighbors
  function segregation() {
    var occ = 0, sum = 0;
    for (var i = 0; i < cells.length; i++) {
      if (cells[i] === 0) continue;
      var t = cells[i];
      var x = i % GRID, y = (i / GRID) | 0;
      var same = 0, total = 0;
      for (var dy = -1; dy <= 1; dy++) {
        var ny = y + dy;
        if (ny < 0 || ny >= GRID) continue;
        for (var dx = -1; dx <= 1; dx++) {
          if (dx === 0 && dy === 0) continue;
          var nx = x + dx;
          if (nx < 0 || nx >= GRID) continue;
          var v = cells[ny * GRID + nx];
          if (v === 0) continue;
          total++;
          if (v === t) same++;
        }
      }
      if (total > 0) { sum += same / total; occ++; }
    }
    return occ === 0 ? 0 : sum / occ;
  }

  function updateHud() {
    document.getElementById('hudStep').textContent = step;
    document.getElementById('hudHappy').textContent = Math.round(happyRatio() * 100) + '%';
    document.getElementById('hudSeg').textContent = segregation().toFixed(2);
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(offC, 0, 0, GRID, GRID, drawX, drawY, dispW, dispH);
  }

  var hudTimer = 0;
  function tick() {
    if (pendingReseed) {
      reseed();
      pendingReseed = false;
      updateHud();
    }
    if (running) {
      var totalMoved = 0;
      for (var s = 0; s < speed; s++) {
        var m = doStep();
        totalMoved += m;
        if (m === 0) break;
      }
      if (totalMoved > 0) {
        offCtx.putImageData(imgData, 0, 0);
        if (totalMoved > 30) playSfx('tick', 80);
      } else if (running) {
        // equilibrium reached
        running = false;
        document.getElementById('btnRun').classList.remove('active');
        document.getElementById('btnRun').textContent = 'RUN';
        playSfx('complete');
      }
    }
    hudTimer++;
    if (hudTimer % 8 === 0) updateHud();
    draw();
    requestAnimationFrame(tick);
  }

  // pointer paint
  var painting = 0; // 0 none, 1 paint type, 2 erase
  var paintType = 1;

  function screenToCell(sx, sy) {
    var gx = Math.floor((sx - drawX) / fitScale);
    var gy = Math.floor((sy - drawY) / fitScale);
    if (gx < 0 || gy < 0 || gx >= GRID || gy >= GRID) return -1;
    return gy * GRID + gx;
  }

  function applyBrush(idx, mode) {
    if (idx < 0) return;
    var x = idx % GRID, y = (idx / GRID) | 0;
    for (var dy = -1; dy <= 1; dy++) {
      var ny = y + dy;
      if (ny < 0 || ny >= GRID) continue;
      for (var dx = -1; dx <= 1; dx++) {
        var nx = x + dx;
        if (nx < 0 || nx >= GRID) continue;
        var i = ny * GRID + nx;
        var was = cells[i];
        if (mode === 2) {
          if (was !== 0) {
            cells[i] = 0;
            empties.push(i);
            paintCell(i);
          }
        } else {
          // paint type
          if (was === 0) {
            // remove from empties
            var ei = empties.indexOf(i);
            if (ei >= 0) empties.splice(ei, 1);
          }
          cells[i] = paintType;
          paintCell(i);
        }
      }
    }
    offCtx.putImageData(imgData, 0, 0);
  }

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  C.addEventListener('pointerdown', function(e) {
    e.preventDefault();
    if (e.button === 2) {
      painting = 2;
    } else {
      painting = 1;
      paintType = (paintType === 1) ? 2 : 1; // alternate on each new stroke
    }
    var idx = screenToCell(e.clientX, e.clientY);
    applyBrush(idx, painting);
    playSfx('tick', 60);
  });

  C.addEventListener('pointermove', function(e) {
    if (!painting) return;
    var idx = screenToCell(e.clientX, e.clientY);
    applyBrush(idx, painting);
  });

  C.addEventListener('pointerup', function() { painting = 0; });
  C.addEventListener('pointerleave', function() { painting = 0; });

  document.getElementById('sldTol').addEventListener('input', function() {
    tolerance = parseInt(this.value) / 100;
    document.getElementById('valTol').textContent = tolerance.toFixed(2);
  });
  document.getElementById('sldTol').addEventListener('change', function() {
    if (!running) {
      running = true;
      document.getElementById('btnRun').classList.add('active');
      document.getElementById('btnRun').textContent = 'PAUSE';
    }
    playSfx('toggle');
  });

  document.getElementById('sldDen').addEventListener('input', function() {
    density = parseInt(this.value) / 100;
    document.getElementById('valDen').textContent = density.toFixed(2);
  });
  document.getElementById('sldDen').addEventListener('change', function() {
    pendingReseed = true;
    playSfx('toggle');
  });

  document.getElementById('sldSpeed').addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('valSpeed').textContent = this.value;
  });

  document.getElementById('btnRun').addEventListener('click', function() {
    running = !running;
    this.classList.toggle('active', running);
    this.textContent = running ? 'PAUSE' : 'RUN';
    playSfx('toggle');
  });

  document.getElementById('btnStep').addEventListener('click', function() {
    running = false;
    document.getElementById('btnRun').classList.remove('active');
    document.getElementById('btnRun').textContent = 'RUN';
    var m = doStep();
    if (m > 0) offCtx.putImageData(imgData, 0, 0);
    updateHud();
    playSfx('click');
  });

  document.getElementById('btnReseed').addEventListener('click', function() {
    pendingReseed = true;
    running = true;
    document.getElementById('btnRun').classList.add('active');
    document.getElementById('btnRun').textContent = 'PAUSE';
    playSfx('click');
  });

  window.addEventListener('resize', resize);

  initImg();
  reseed();
  resize();
  document.getElementById('btnRun').textContent = 'PAUSE';
  updateHud();
  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
