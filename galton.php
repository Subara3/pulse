<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('galton');

$pageName = 'galton';
$pageTitle = 'GALTON BOARD - PULSE';
$pageDesc = 'ガルトンボード。ペグを通り抜けるボールが正規分布を描き出す。';
$pageCanonical = 'https://pulse.subara3.com/galton.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1.2rem; bottom: 1.2rem; color: rgba(255,255,255,0.24); font-size: 0.56rem; line-height: 1.75; letter-spacing: 0.05em; max-width: min(74vw, 420px); }
@media (max-width: 768px) {
  .hud { top: 0.65rem; right: 0.65rem; font-size: 0.54rem; }
  .info { left: 0.85rem; bottom: 0.9rem; font-size: 0.49rem; max-width: 72vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'tap'],
  ['type' => 'drag', 'label' => 'drag'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  BALLS: <span id="hudBalls" class="v">0</span><br>
  ROWS: <span id="hudRows" class="v">12</span><br>
  &sigma;: <span id="hudSigma" class="v">0.00</span>
</div>

<div class="ui info">
  TAP: DROP BALLS<br>
  DRAG: TILT BOARD<br>
  RIGHT-CLICK: CLEAR<br>
  PROBABILITY IN MOTION
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>ROWS</label>
    <input type="range" id="rowsSlider" min="6" max="18" value="12">
  </div>
  <div class="slider-wrap">
    <label>SPEED</label>
    <input type="range" id="speedSlider" min="3" max="15" value="8">
  </div>
  <div class="slider-wrap">
    <label>BATCH</label>
    <input type="range" id="batchSlider" min="1" max="20" value="5">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO: ON</button>
    <button class="control-btn" id="curveBtn">CURVE: ON</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudBalls = document.getElementById('hudBalls');
  var hudRows = document.getElementById('hudRows');
  var hudSigma = document.getElementById('hudSigma');

  var rowsSlider = document.getElementById('rowsSlider');
  var speedSlider = document.getElementById('speedSlider');
  var batchSlider = document.getElementById('batchSlider');
  var autoBtn = document.getElementById('autoBtn');
  var curveBtn = document.getElementById('curveBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0;
  var balls = [];
  var bins = [];
  var pegs = [];
  var numRows = 12;
  var totalDropped = 0;
  var autoMode = true;
  var showCurve = true;
  var lastAuto = 0;
  var tilt = 0;
  var dragging = false;
  var dragStartX = 0;

  var GRAVITY = 0.15;
  var BOUNCE = 0.4;
  var FRICTION = 0.98;
  var PEG_RAD = 3;
  var BALL_RAD = 2.5;

  var ACCENT = [0, 245, 212];
  var SUB = [123, 44, 191];

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) {
    return [
      (a[0] + (b[0] - a[0]) * t) | 0,
      (a[1] + (b[1] - a[1]) * t) | 0,
      (a[2] + (b[2] - a[2]) * t) | 0
    ];
  }

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = W;
    canvas.height = H;
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    buildBoard();
  }

  function buildBoard() {
    numRows = parseInt(rowsSlider.value, 10);
    hudRows.textContent = numRows;

    var numBins = numRows + 1;
    var boardW = Math.min(W * 0.85, H * 0.7);
    var pegSpacing = boardW / numRows;
    var boardTop = H * 0.08;
    var boardBottom = boardTop + numRows * pegSpacing;
    var boardCX = W / 2;

    pegs = [];
    for (var r = 0; r < numRows; r++) {
      var count = r + 1;
      var rowW = count * pegSpacing;
      var startX = boardCX - rowW / 2 + pegSpacing / 2;
      var y = boardTop + (r + 1) * pegSpacing;
      for (var c = 0; c < count; c++) {
        pegs.push({ x: startX + c * pegSpacing, y: y });
      }
    }

    if (bins.length !== numBins) {
      bins = new Array(numBins);
      for (var i = 0; i < numBins; i++) bins[i] = 0;
      totalDropped = 0;
    }

    PEG_RAD = Math.max(2, pegSpacing * 0.08);
    BALL_RAD = Math.max(1.8, pegSpacing * 0.065);
  }

  function spawnBall() {
    var boardW = Math.min(W * 0.85, H * 0.7);
    var pegSpacing = boardW / numRows;
    var jitter = (Math.random() - 0.5) * pegSpacing * 0.15;
    balls.push({
      x: W / 2 + jitter,
      y: H * 0.03,
      vx: 0,
      vy: 0,
      settled: false,
      alpha: 1,
      col: mix(ACCENT, SUB, Math.random() * 0.35)
    });
    totalDropped++;
    hudBalls.textContent = totalDropped;
  }

  function spawnBatch() {
    var count = parseInt(batchSlider.value, 10);
    for (var i = 0; i < count; i++) {
      setTimeout(function() { spawnBall(); }, i * 40);
    }
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function updateBalls() {
    var speed = parseInt(speedSlider.value, 10) / 8;
    var boardW = Math.min(W * 0.85, H * 0.7);
    var pegSpacing = boardW / numRows;
    var boardTop = H * 0.08;
    var boardBottom = boardTop + numRows * pegSpacing;
    var boardCX = W / 2;
    var numBins = numRows + 1;
    var binW = boardW / numBins;
    var binAreaTop = boardBottom + pegSpacing * 0.6;

    for (var i = balls.length - 1; i >= 0; i--) {
      var b = balls[i];
      if (b.settled) {
        b.alpha -= 0.008;
        if (b.alpha <= 0) balls.splice(i, 1);
        continue;
      }

      b.vy += GRAVITY * speed;
      b.vx += tilt * 0.05 * speed;
      b.vx *= FRICTION;
      b.vy *= FRICTION;
      b.x += b.vx * speed;
      b.y += b.vy * speed;

      for (var p = 0; p < pegs.length; p++) {
        var peg = pegs[p];
        var dx = b.x - peg.x;
        var dy = b.y - peg.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        var minDist = PEG_RAD + BALL_RAD;
        if (dist < minDist && dist > 0.001) {
          var nx = dx / dist;
          var ny = dy / dist;
          b.x = peg.x + nx * minDist;
          b.y = peg.y + ny * minDist;
          var dot = b.vx * nx + b.vy * ny;
          b.vx -= (1 + BOUNCE) * dot * nx;
          b.vy -= (1 + BOUNCE) * dot * ny;
          b.vx += (Math.random() - 0.5) * 0.6;
          if (typeof SFX !== 'undefined') SFX.play('collide', 50);
        }
      }

      if (b.y > binAreaTop) {
        var binStartX = boardCX - boardW / 2;
        var binIdx = Math.floor((b.x - binStartX) / binW);
        binIdx = Math.max(0, Math.min(numBins - 1, binIdx));
        bins[binIdx]++;
        b.settled = true;
        if (typeof SFX !== 'undefined') SFX.play('tick', 30);
      }

      if (b.x < 0) { b.x = 0; b.vx *= -0.5; }
      if (b.x > W) { b.x = W; b.vx *= -0.5; }
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var boardW = Math.min(W * 0.85, H * 0.7);
    var pegSpacing = boardW / numRows;
    var boardTop = H * 0.08;
    var boardBottom = boardTop + numRows * pegSpacing;
    var boardCX = W / 2;
    var numBins = numRows + 1;
    var binW = boardW / numBins;
    var binAreaTop = boardBottom + pegSpacing * 0.6;
    var binAreaH = H - binAreaTop - H * 0.04;

    // funnel lines
    ctx.strokeStyle = rgba(ACCENT, 0.08);
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(boardCX - pegSpacing * 0.4, boardTop * 0.5);
    ctx.lineTo(boardCX - boardW * 0.5 - pegSpacing * 0.3, boardBottom + pegSpacing * 0.3);
    ctx.moveTo(boardCX + pegSpacing * 0.4, boardTop * 0.5);
    ctx.lineTo(boardCX + boardW * 0.5 + pegSpacing * 0.3, boardBottom + pegSpacing * 0.3);
    ctx.stroke();

    // pegs
    for (var p = 0; p < pegs.length; p++) {
      var peg = pegs[p];
      var g = ctx.createRadialGradient(peg.x, peg.y, 0, peg.x, peg.y, PEG_RAD * 2.5);
      g.addColorStop(0, rgba(ACCENT, 0.35));
      g.addColorStop(1, rgba(ACCENT, 0));
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(peg.x, peg.y, PEG_RAD * 2.5, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = rgba(ACCENT, 0.7);
      ctx.beginPath();
      ctx.arc(peg.x, peg.y, PEG_RAD, 0, Math.PI * 2);
      ctx.fill();
    }

    // bin dividers
    ctx.strokeStyle = rgba(ACCENT, 0.06);
    ctx.lineWidth = 0.5;
    var binStartX = boardCX - boardW / 2;
    for (var d = 0; d <= numBins; d++) {
      var dx = binStartX + d * binW;
      ctx.beginPath();
      ctx.moveTo(dx, binAreaTop);
      ctx.lineTo(dx, H);
      ctx.stroke();
    }

    // histogram
    var maxBin = 0;
    for (var i = 0; i < numBins; i++) {
      if (bins[i] > maxBin) maxBin = bins[i];
    }

    for (var i = 0; i < numBins; i++) {
      if (bins[i] === 0) continue;
      var ratio = bins[i] / Math.max(1, maxBin);
      var barH = ratio * binAreaH * 0.9;
      var bx = binStartX + i * binW + 1;
      var bw = binW - 2;
      var by = H - H * 0.04 - barH;

      var t = Math.abs(i - numBins / 2) / (numBins / 2);
      var col = mix(ACCENT, SUB, t * 0.6);

      var grad = ctx.createLinearGradient(bx, by, bx, H);
      grad.addColorStop(0, rgba(col, 0.6));
      grad.addColorStop(1, rgba(col, 0.15));
      ctx.fillStyle = grad;
      ctx.fillRect(bx, by, bw, barH);

      ctx.fillStyle = rgba(col, 0.12);
      ctx.fillRect(bx, by, bw, barH);
    }

    // gaussian curve overlay
    if (showCurve && totalDropped > 10) {
      var mean = 0, total = 0;
      for (var i = 0; i < numBins; i++) {
        mean += i * bins[i];
        total += bins[i];
      }
      mean /= total;
      var variance = 0;
      for (var i = 0; i < numBins; i++) {
        variance += bins[i] * (i - mean) * (i - mean);
      }
      variance /= total;
      var sigma = Math.sqrt(variance);
      hudSigma.textContent = sigma.toFixed(2);

      if (sigma > 0.01) {
        ctx.strokeStyle = rgba([255, 255, 255], 0.25);
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        for (var px = 0; px < boardW; px++) {
          var binPos = px / binW;
          var z = (binPos - mean) / sigma;
          var gauss = Math.exp(-0.5 * z * z) / (sigma * Math.sqrt(2 * Math.PI));
          var normalizedH = gauss * total * binAreaH * 0.9 / maxBin;
          var sx = binStartX + px;
          var sy = H - H * 0.04 - normalizedH;
          if (px === 0) ctx.moveTo(sx, sy);
          else ctx.lineTo(sx, sy);
        }
        ctx.stroke();
      }
    }

    // balls
    for (var i = 0; i < balls.length; i++) {
      var b = balls[i];
      var a = b.settled ? b.alpha * 0.5 : 0.9;
      ctx.fillStyle = rgba(b.col, a);
      ctx.beginPath();
      ctx.arc(b.x, b.y, BALL_RAD, 0, Math.PI * 2);
      ctx.fill();

      if (!b.settled) {
        var g = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, BALL_RAD * 3);
        g.addColorStop(0, rgba(b.col, 0.2));
        g.addColorStop(1, rgba(b.col, 0));
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(b.x, b.y, BALL_RAD * 3, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    // spawn point glow
    var g2 = ctx.createRadialGradient(W / 2, H * 0.03, 0, W / 2, H * 0.03, 20);
    g2.addColorStop(0, rgba(ACCENT, 0.15));
    g2.addColorStop(1, rgba(ACCENT, 0));
    ctx.fillStyle = g2;
    ctx.beginPath();
    ctx.arc(W / 2, H * 0.03, 20, 0, Math.PI * 2);
    ctx.fill();
  }

  function clearBins() {
    for (var i = 0; i < bins.length; i++) bins[i] = 0;
    totalDropped = 0;
    balls = [];
    hudBalls.textContent = '0';
    hudSigma.textContent = '0.00';
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  // events
  canvas.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    dragging = true;
    dragStartX = e.clientX;
    spawnBatch();
  });

  canvas.addEventListener('pointermove', function(e) {
    if (!dragging) return;
    tilt = (e.clientX - dragStartX) / W * 3;
    tilt = Math.max(-1.5, Math.min(1.5, tilt));
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  });

  canvas.addEventListener('pointerup', function() {
    dragging = false;
    tilt = 0;
  });
  canvas.addEventListener('pointercancel', function() {
    dragging = false;
    tilt = 0;
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    clearBins();
  });

  rowsSlider.addEventListener('input', function() {
    clearBins();
    buildBoard();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  speedSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });
  batchSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    autoBtn.textContent = 'AUTO: ' + (autoMode ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  curveBtn.addEventListener('click', function() {
    showCurve = !showCurve;
    curveBtn.textContent = 'CURVE: ' + (showCurve ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    clearBins();
  });

  window.addEventListener('resize', resize);

  function loop(t) {
    if (autoMode && t - lastAuto > 600) {
      lastAuto = t;
      spawnBall();
    }
    updateBalls();
    draw();
    requestAnimationFrame(loop);
  }

  resize();
  // initial burst
  for (var i = 0; i < 8; i++) {
    setTimeout(function() { spawnBall(); }, i * 80);
  }
  loop(0);
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
