<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ballistic');

$pageName = 'ballistic';
$pageTitle = 'BALLISTIC - PULSE';
$pageDesc = '弾道堆積モデル。粒子が垂直に降り積もり、表面粗さがスケーリング則に従って成長する。KPZ普遍性クラスの可視化。';
$pageCanonical = 'https://pulse.subara3.com/ballistic.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;left:1rem;z-index:60;color:rgba(255,255,255,.4);font-family:"JetBrains Mono",monospace;font-size:.62rem;line-height:1.8;letter-spacing:.04em;pointer-events:none}
.hud b{color:#00f5d4}
.hud .sub{color:rgba(123,44,191,.9)}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;left:.75rem;font-size:.52rem}#info{bottom:1rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  DEPOSITED <b id="h-count">0</b><br>
  ROUGHNESS <b id="h-rough">0.00</b><br>
  <span class="sub">MODE</span> <b id="h-mode">BALLISTIC</b>
</div>

<div id="info">
  ballistic deposition<br>
  <span class="v">KPZ universality &middot; surface roughness</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop column'],
  ['type' => 'drag', 'label' => 'rain'],
  ['type' => 'wheel', 'label' => 'rate'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="slider-row">
      <span class="slider-label">RATE</span>
      <input type="range" id="sldRate" min="1" max="20" value="5">
      <span class="slider-value" id="valRate">5</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnBallistic">BALLISTIC</button>
      <button class="control-btn" id="btnRandom">RANDOM</button>
      <button class="control-btn" id="btnRelax">RELAXED</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" id="btnGraph">GRAPH</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<?php require_once __DIR__ . '/includes/colors.php'; ?>

<script>
(function(){
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H;

  var COLS = 200;
  var heights = new Int32Array(COLS);
  var grid = null;
  var ROWS = 0;
  var deposited = 0;
  var mode = 0; // 0=ballistic, 1=random, 2=relaxed
  var rate = 5;
  var showGraph = false;
  var roughnessHistory = [];
  var countHistory = [];

  var falling = [];
  var MAX_FALLING = 60;

  function brandHue(t) {
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    var e = t < 0.5 ? 0.5 * Math.pow(2 * t, 3.2)
                    : 1 - 0.5 * Math.pow(2 * (1 - t), 3.2);
    return 172 + e * 118;
  }

  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    var k = function(n) { return (n + h / 30) % 12; };
    var a = s * Math.min(l, 1 - l);
    var f = function(n) { return l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)); };
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    ROWS = Math.ceil(H / cellH()) + 10;
    rebuildGrid();
  }

  function cellW() { return W / COLS; }
  function cellH() { return Math.max(2, Math.floor(W / COLS)); }

  function rebuildGrid() {
    var newGrid = new Uint8Array(COLS * ROWS);
    if (grid) {
      var copyRows = Math.min(grid.length / COLS, ROWS);
      for (var r = 0; r < copyRows; r++) {
        for (var c = 0; c < COLS; c++) {
          newGrid[r * COLS + c] = grid[r * COLS + c];
        }
      }
    }
    grid = newGrid;
  }

  function reset() {
    heights.fill(0);
    grid = new Uint8Array(COLS * ROWS);
    deposited = 0;
    falling = [];
    roughnessHistory = [];
    countHistory = [];
    SFX.play('click');
  }

  function meanHeight() {
    var sum = 0;
    for (var i = 0; i < COLS; i++) sum += heights[i];
    return sum / COLS;
  }

  function roughness() {
    var m = meanHeight();
    var sum2 = 0;
    for (var i = 0; i < COLS; i++) {
      var d = heights[i] - m;
      sum2 += d * d;
    }
    return Math.sqrt(sum2 / COLS);
  }

  function deposit(col) {
    col = ((col % COLS) + COLS) % COLS;
    var h;
    if (mode === 0) {
      // ballistic: stick to highest neighbor
      var left = (col - 1 + COLS) % COLS;
      var right = (col + 1) % COLS;
      h = Math.max(heights[col], heights[left], heights[right]);
    } else if (mode === 1) {
      // random: just stack
      h = heights[col];
    } else {
      // relaxed: pick lowest of col and neighbors
      var left2 = (col - 1 + COLS) % COLS;
      var right2 = (col + 1) % COLS;
      var minH = heights[col], minC = col;
      if (heights[left2] < minH) { minH = heights[left2]; minC = left2; }
      if (heights[right2] < minH) { minH = heights[right2]; minC = right2; }
      col = minC;
      h = heights[col];
    }

    heights[col] = h + 1;
    if (h < ROWS) {
      grid[h * COLS + col] = 1;
    }
    deposited++;

    if (deposited % 50 === 0) {
      roughnessHistory.push(roughness());
      countHistory.push(deposited);
    }

    return col;
  }

  function spawnFalling(col) {
    if (falling.length >= MAX_FALLING) return;
    var cw = cellW();
    falling.push({
      x: col * cw + cw * 0.5,
      y: -Math.random() * H * 0.3,
      col: col,
      speed: 3 + Math.random() * 4,
      targetY: 0,
      done: false,
      alpha: 0.7 + Math.random() * 0.3
    });
  }

  function updateFalling() {
    var ch = cellH();
    for (var i = falling.length - 1; i >= 0; i--) {
      var p = falling[i];
      var targetRow = heights[p.col];
      p.targetY = H - targetRow * ch;
      p.y += p.speed;
      if (p.y >= p.targetY - ch) {
        var landCol = deposit(p.col);
        SFX.play('tick', 40);
        falling.splice(i, 1);
      }
    }
  }

  function dropMultiple(count, center, spread) {
    for (var i = 0; i < count; i++) {
      var col;
      if (center !== undefined) {
        col = center + Math.round((Math.random() - 0.5) * spread);
      } else {
        col = Math.floor(Math.random() * COLS);
      }
      col = ((col % COLS) + COLS) % COLS;
      spawnFalling(col);
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cw = cellW();
    var ch = cellH();
    var maxH = 0;
    for (var i = 0; i < COLS; i++) if (heights[i] > maxH) maxH = heights[i];
    if (maxH < 1) maxH = 1;

    // draw deposited cells
    var startRow = 0;
    var endRow = Math.min(ROWS, maxH + 1);
    for (var r = startRow; r < endRow; r++) {
      for (var c = 0; c < COLS; c++) {
        if (grid[r * COLS + c]) {
          var t = r / maxH;
          var hue = brandHue(t);
          var lightness = 35 + t * 20;
          var sat = 70 + t * 15;
          var col = hslToRgb(hue, sat, lightness);
          ctx.fillStyle = 'rgb(' + col[0] + ',' + col[1] + ',' + col[2] + ')';
          var x = c * cw;
          var y = H - (r + 1) * ch;
          ctx.fillRect(x, y, Math.ceil(cw), Math.ceil(ch));
        }
      }
    }

    // draw surface highlight
    ctx.strokeStyle = 'rgba(0,245,212,0.6)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    for (var c2 = 0; c2 < COLS; c2++) {
      var sx = c2 * cw + cw * 0.5;
      var sy = H - heights[c2] * ch;
      if (c2 === 0) ctx.moveTo(sx, sy);
      else ctx.lineTo(sx, sy);
    }
    ctx.stroke();

    // draw falling particles
    for (var fi = 0; fi < falling.length; fi++) {
      var fp = falling[fi];
      ctx.fillStyle = 'rgba(0,245,212,' + fp.alpha + ')';
      ctx.fillRect(fp.x - cw * 0.4, fp.y - ch * 0.4, cw * 0.8, ch * 0.8);
      // trail
      ctx.fillStyle = 'rgba(0,245,212,0.15)';
      ctx.fillRect(fp.x - cw * 0.2, fp.y - ch * 3, cw * 0.4, ch * 3);
    }

    // draw roughness graph overlay
    if (showGraph && roughnessHistory.length > 2) {
      drawGraph();
    }

    // HUD
    document.getElementById('h-count').textContent = deposited;
    document.getElementById('h-rough').textContent = roughness().toFixed(2);
    var modeNames = ['BALLISTIC', 'RANDOM', 'RELAXED'];
    document.getElementById('h-mode').textContent = modeNames[mode];
  }

  function drawGraph() {
    var gx = W * 0.55, gy = H * 0.05;
    var gw = W * 0.4, gh = H * 0.3;

    ctx.fillStyle = 'rgba(10,10,15,0.85)';
    ctx.strokeStyle = 'rgba(0,245,212,0.3)';
    ctx.lineWidth = 1;
    ctx.fillRect(gx, gy, gw, gh);
    ctx.strokeRect(gx, gy, gw, gh);

    // label
    ctx.fillStyle = 'rgba(0,245,212,0.6)';
    ctx.font = '10px "JetBrains Mono", monospace';
    ctx.fillText('log W(t) vs log t', gx + 6, gy + 14);

    if (roughnessHistory.length < 3) return;

    // log-log plot
    var logR = [], logT = [];
    for (var i = 0; i < roughnessHistory.length; i++) {
      if (roughnessHistory[i] > 0 && countHistory[i] > 0) {
        logR.push(Math.log10(roughnessHistory[i]));
        logT.push(Math.log10(countHistory[i]));
      }
    }
    if (logR.length < 2) return;

    var minLR = logR[0], maxLR = logR[0];
    var minLT = logT[0], maxLT = logT[0];
    for (var j = 1; j < logR.length; j++) {
      if (logR[j] < minLR) minLR = logR[j];
      if (logR[j] > maxLR) maxLR = logR[j];
      if (logT[j] < minLT) minLT = logT[j];
      if (logT[j] > maxLT) maxLT = logT[j];
    }
    var rngR = maxLR - minLR || 1;
    var rngT = maxLT - minLT || 1;
    var pad = 20;

    ctx.strokeStyle = 'rgba(0,245,212,0.8)';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    for (var k = 0; k < logR.length; k++) {
      var px = gx + pad + (logT[k] - minLT) / rngT * (gw - 2 * pad);
      var py = gy + gh - pad - (logR[k] - minLR) / rngR * (gh - 2 * pad);
      if (k === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    ctx.stroke();

    // beta line (slope reference)
    var betaRef = mode === 0 ? 0.33 : mode === 1 ? 0.5 : 0.25;
    ctx.strokeStyle = 'rgba(123,44,191,0.6)';
    ctx.setLineDash([4, 3]);
    ctx.beginPath();
    var refX0 = gx + pad;
    var refY0 = gy + gh - pad;
    var refX1 = gx + gw - pad;
    var refY1 = refY0 - betaRef * (gw - 2 * pad) * (rngR / rngT);
    if (refY1 < gy + pad) refY1 = gy + pad;
    ctx.moveTo(refX0, refY0);
    ctx.lineTo(refX1, refY1);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = 'rgba(183,140,255,0.7)';
    ctx.font = '9px "JetBrains Mono", monospace';
    ctx.fillText('β=' + betaRef.toFixed(2), refX1 - 36, refY1 - 4);
  }

  // Auto-deposit loop
  var autoTimer = 0;
  function tick() {
    autoTimer++;
    if (autoTimer >= Math.max(1, 6 - Math.floor(rate / 4))) {
      autoTimer = 0;
      var batchSize = Math.ceil(rate * 0.6);
      for (var i = 0; i < batchSize; i++) {
        var col = Math.floor(Math.random() * COLS);
        if (falling.length < MAX_FALLING) {
          spawnFalling(col);
        } else {
          deposit(col);
        }
      }
    }
    updateFalling();

    // auto-scroll: if surface gets too high, shift everything down
    var maxH = 0;
    for (var c = 0; c < COLS; c++) if (heights[c] > maxH) maxH = heights[c];
    if (maxH * cellH() > H * 0.85) {
      var shift = Math.floor(ROWS * 0.2);
      var newGrid = new Uint8Array(COLS * ROWS);
      for (var r = shift; r < ROWS; r++) {
        for (var cc = 0; cc < COLS; cc++) {
          newGrid[(r - shift) * COLS + cc] = grid[r * COLS + cc];
        }
      }
      grid = newGrid;
      for (var ci = 0; ci < COLS; ci++) {
        heights[ci] = Math.max(0, heights[ci] - shift);
      }
    }

    draw();
    requestAnimationFrame(tick);
  }

  // Events
  function getCol(e) {
    var rect = C.getBoundingClientRect();
    var x = (e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0)) - rect.left;
    return Math.floor(x / cellW());
  }

  var dragging = false;
  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    dragging = true;
    var col = getCol(e);
    dropMultiple(3, col, 5);
    SFX.play('spawn');
  });
  C.addEventListener('mousemove', function(e) {
    if (!dragging) return;
    var col = getCol(e);
    dropMultiple(2, col, 8);
    SFX.play('ripple', 80);
  });
  C.addEventListener('mouseup', function() { dragging = false; });
  C.addEventListener('mouseleave', function() { dragging = false; });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var col = getCol(e);
    dragging = true;
    dropMultiple(3, col, 5);
    SFX.play('spawn');
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!dragging) return;
    var col = getCol(e);
    dropMultiple(2, col, 8);
    SFX.play('ripple', 80);
  }, { passive: false });
  C.addEventListener('touchend', function() { dragging = false; });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    rate = Math.max(1, Math.min(20, rate + (e.deltaY > 0 ? -1 : 1)));
    document.getElementById('sldRate').value = rate;
    document.getElementById('valRate').textContent = rate;
    SFX.play('tick');
  }, { passive: false });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // Controls
  var sldRate = document.getElementById('sldRate');
  sldRate.addEventListener('input', function() {
    rate = parseInt(this.value);
    document.getElementById('valRate').textContent = rate;
  });

  function setMode(m) {
    mode = m;
    document.getElementById('btnBallistic').classList.toggle('active', m === 0);
    document.getElementById('btnRandom').classList.toggle('active', m === 1);
    document.getElementById('btnRelax').classList.toggle('active', m === 2);
    SFX.play('toggle');
  }

  document.getElementById('btnBallistic').addEventListener('click', function() { setMode(0); });
  document.getElementById('btnRandom').addEventListener('click', function() { setMode(1); });
  document.getElementById('btnRelax').addEventListener('click', function() { setMode(2); });
  document.getElementById('btnGraph').addEventListener('click', function() {
    showGraph = !showGraph;
    this.classList.toggle('active', showGraph);
    SFX.play('toggle');
  });
  document.getElementById('btnReset').addEventListener('click', reset);

  setMode(0);

  window.addEventListener('resize', resize);
  resize();

  // seed initial surface
  for (var si = 0; si < COLS * 3; si++) {
    deposit(Math.floor(Math.random() * COLS));
  }
  deposited = 0;
  roughnessHistory = [];
  countHistory = [];

  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
