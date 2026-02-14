<?php
require_once __DIR__ . '/includes/config.php';

recordPageView('collatz');

$pageName = 'collatz';
$pageTitle = 'COLLATZ - PULSE';
$pageDesc = 'コラッツ予想。すべての正の整数は1に辿り着く——まだ誰も証明していない。';
$pageCanonical = 'https://pulse.subara3.com/collatz.php';

$extraStyle = '
body {
  background: #0a0a0f;
  min-height: 100vh;
  overflow: hidden;
  margin: 0;
}

#canvas {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.ui {
  position: fixed;
  z-index: 1000;
  font-family: "Orbitron", sans-serif;
}

.hud {
  top: 1.5rem;
  right: 1.5rem;
  color: rgba(255,255,255,0.35);
  font-size: 0.65rem;
  text-align: right;
  letter-spacing: 0.05em;
  line-height: 2;
}

.hud .val {
  color: #00f5d4;
  font-weight: 700;
}

.hud .mode-name {
  color: #7b2cbf;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.2em;
  margin-bottom: 0.3rem;
}

.info {
  bottom: 1.5rem;
  left: 1.5rem;
  color: rgba(255,255,255,0.25);
  font-size: 0.6rem;
  line-height: 1.8;
}

.formula {
  color: rgba(0,245,212,0.5);
  font-family: serif;
  font-style: italic;
}

.angle-wrap {
  bottom: 6rem;
  left: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.angle-wrap label {
  color: rgba(255,255,255,0.4);
  font-size: 0.6rem;
  letter-spacing: 0.05em;
  min-width: 5rem;
}

.angle-wrap input[type="range"] {
  -webkit-appearance: none;
  appearance: none;
  width: 120px;
  height: 3px;
  background: rgba(255,255,255,0.15);
  border-radius: 2px;
  outline: none;
}

.angle-wrap input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #00f5d4;
  cursor: pointer;
  border: 2px solid #0a0a0f;
  box-shadow: 0 0 6px rgba(0,245,212,0.4);
}

.angle-wrap input[type="range"]::-moz-range-thumb {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #00f5d4;
  cursor: pointer;
  border: 2px solid #0a0a0f;
  box-shadow: 0 0 6px rgba(0,245,212,0.4);
}

.hide-ui .hud,
.hide-ui .info,
.hide-ui .angle-wrap,
.hide-ui .content-controls,

.hide-ui .floating-nav {
  opacity: 0 !important;
  pointer-events: none;
}

@media (max-width: 600px) {
  .hud {
    font-size: 0.55rem;
    top: 1rem;
    right: 1rem;
  }
  .info {
    bottom: 1rem;
    left: 1rem;
    font-size: 0.5rem;
  }
  .angle-wrap {
    bottom: 5rem;
    left: 1rem;
  }
  .angle-wrap input[type="range"] {
    width: 80px;
  }
}
';

$controls = [
  ['id' => 'modeBtn', 'icon' => 'fa-shuffle', 'label' => 'MODE'],
  ['id' => 'resetBtn', 'icon' => 'fa-rotate-right', 'label' => 'RESET'],
  ['id' => 'colorBtn', 'icon' => 'fa-palette', 'label' => 'COLOR'],
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
require_once __DIR__ . '/includes/content-controls.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud" id="hud">
  <div class="mode-name" id="modeName">TREE</div>
  <div id="hudStats"></div>
</div>

<div class="ui info">
  Press H to hide UI<br>
  <span class="formula">n &rarr; n / 2 (even)</span><br>
  <span class="formula">n &rarr; 3n + 1 (odd)</span><br>
  <span style="color:rgba(123,44,191,0.5);font-size:0.55rem;">Does it always reach 1?</span>
</div>

<div class="ui angle-wrap" id="angleWrap">
  <label id="angleLabel">ANGLE: 8&deg;</label>
  <input type="range" id="angleSlider" min="2" max="25" value="8">
</div>

<script>
(function() {
  const canvas = document.getElementById('canvas');
  const ctx = canvas.getContext('2d');
  const modeName = document.getElementById('modeName');
  const hudStats = document.getElementById('hudStats');
  const angleWrap = document.getElementById('angleWrap');
  const angleSlider = document.getElementById('angleSlider');
  const angleLabel = document.getElementById('angleLabel');

  let width, height;
  const MODES = ['TREE', 'TRAJECTORY', 'STOPPING TIME'];
  let mode = 0;
  let paused = false;

  // Color schemes
  const COLORS = [
    { a: [0, 245, 212], b: [123, 44, 191] },
    { a: [255, 107, 107], b: [254, 202, 87] },
    { a: [72, 219, 251], b: [200, 200, 255] },
    { a: [57, 255, 20], b: [255, 0, 255] },
  ];
  let colorIdx = 0;

  function lerpColor(c, t, alpha) {
    const r = Math.round(c.a[0] + (c.b[0] - c.a[0]) * t);
    const g = Math.round(c.a[1] + (c.b[1] - c.a[1]) * t);
    const b = Math.round(c.a[2] + (c.b[2] - c.a[2]) * t);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  // Collatz math
  function collatzSeq(n) {
    const seq = [n];
    while (n !== 1 && seq.length < 10000) {
      n = n % 2 === 0 ? n / 2 : 3 * n + 1;
      seq.push(n);
    }
    return seq;
  }

  function stoppingTime(n) {
    var s = 0;
    while (n !== 1 && s < 10000) {
      n = n % 2 === 0 ? n / 2 : 3 * n + 1;
      s++;
    }
    return s;
  }

  // ============================================
  //  TREE MODE
  //  Reversed Collatz sequences as angular paths
  // ============================================
  let treeN = 2;
  const TREE_MAX = 8000;
  const TREE_PER_FRAME = 5;
  let treeAngle = 8;

  function resetTree() {
    treeN = 2;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
  }

  function drawTree() {
    // Once done, show subtle base glow
    if (treeN > TREE_MAX) {
      var ga = 0.08 + Math.sin(Date.now() * 0.002) * 0.03;
      var bx = width / 2, by = height * 0.85;
      var g = ctx.createRadialGradient(bx, by, 0, bx, by, 25);
      g.addColorStop(0, 'rgba(' + COLORS[colorIdx].a.join(',') + ',' + ga + ')');
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(bx, by, 25, 0, Math.PI * 2);
      ctx.fill();
      return;
    }

    var c = COLORS[colorIdx];
    var angleRad = treeAngle * Math.PI / 180;
    var len = Math.min(width, height) / 350;

    for (var i = 0; i < TREE_PER_FRAME && treeN <= TREE_MAX; i++) {
      var seq = collatzSeq(treeN);
      seq.reverse();

      var x = width / 2;
      var y = height * 0.85;
      var dir = -Math.PI / 2;
      var progress = treeN / TREE_MAX;

      ctx.beginPath();
      ctx.moveTo(x, y);

      for (var j = 1; j < seq.length; j++) {
        if (seq[j] % 2 === 0) {
          dir += angleRad;
        } else {
          dir -= angleRad;
        }
        x += Math.cos(dir) * len;
        y += Math.sin(dir) * len;
        ctx.lineTo(x, y);
      }

      ctx.strokeStyle = lerpColor(c, progress, 0.03);
      ctx.lineWidth = 0.8;
      ctx.stroke();
      treeN++;
    }
  }

  // ============================================
  //  TRAJECTORY MODE
  //  Hailstone sequence for individual numbers
  // ============================================
  let trajN = 27;
  let trajSeq = [];
  let trajMax = 0;
  let trajStep = 0;
  let trajDone = false;
  let trajWait = 0;
  let flashAlpha = 0;

  function initTraj(n) {
    if (n < 2) n = 2;
    trajN = n;
    trajSeq = collatzSeq(n);
    trajMax = 0;
    for (var i = 0; i < trajSeq.length; i++) {
      if (trajSeq[i] > trajMax) trajMax = trajSeq[i];
    }
    trajStep = 0;
    trajDone = false;
    trajWait = 0;
    flashAlpha = 1.0;
  }

  function drawTrajectory() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);

    var c = COLORS[colorIdx];
    var total = trajSeq.length;

    // Large number watermark
    if (flashAlpha > 0) {
      ctx.save();
      ctx.fillStyle = 'rgba(' + c.a.join(',') + ',' + (flashAlpha * 0.08) + ')';
      ctx.font = 'bold ' + (Math.min(width, height) * 0.25) + 'px "Orbitron"';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(trajN.toString(), width / 2, height / 2);
      ctx.restore();
      flashAlpha -= 0.015;
    }

    // Auto-advance when done
    if (trajDone) {
      trajWait++;
      if (trajWait > 90) initTraj(trajN + 1);
    }

    // Graph area
    var m = { t: 80, r: 80, b: 60, l: 80 };
    var gw = width - m.l - m.r;
    var gh = height - m.t - m.b;

    // Grid
    ctx.strokeStyle = 'rgba(255,255,255,0.04)';
    ctx.lineWidth = 0.5;
    for (var i = 0; i <= 4; i++) {
      var gy = m.t + (i / 4) * gh;
      ctx.beginPath();
      ctx.moveTo(m.l, gy);
      ctx.lineTo(width - m.r, gy);
      ctx.stroke();
    }

    // Advance animation
    var shown = Math.min(trajStep + 2, total - 1);
    if (!trajDone) trajStep = shown;
    if (shown >= total - 1 && !trajDone) {
      trajDone = true;
      trajWait = 0;
    }

    // Draw trajectory path
    var drawTo = trajDone ? total - 1 : shown;

    ctx.beginPath();
    for (var i = 0; i <= drawTo; i++) {
      var px = m.l + (i / Math.max(total - 1, 1)) * gw;
      var py = m.t + gh - (trajSeq[i] / trajMax) * gh;
      if (i === 0) ctx.moveTo(px, py);
      else ctx.lineTo(px, py);
    }
    ctx.strokeStyle = 'rgba(' + c.a.join(',') + ',0.8)';
    ctx.lineWidth = 2;
    ctx.stroke();

    // Glowing trail on recent segments
    if (!trajDone && drawTo > 1) {
      var from = Math.max(0, drawTo - 20);
      ctx.beginPath();
      for (var i = from; i <= drawTo; i++) {
        var px = m.l + (i / Math.max(total - 1, 1)) * gw;
        var py = m.t + gh - (trajSeq[i] / trajMax) * gh;
        if (i === from) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
      }
      ctx.strokeStyle = 'rgba(255,255,255,0.3)';
      ctx.lineWidth = 3;
      ctx.stroke();
    }

    // Current point with glow
    if (!trajDone) {
      var cx = m.l + (drawTo / Math.max(total - 1, 1)) * gw;
      var cy = m.t + gh - (trajSeq[drawTo] / trajMax) * gh;

      var grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, 18);
      grad.addColorStop(0, 'rgba(' + c.a.join(',') + ',0.4)');
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = grad;
      ctx.beginPath();
      ctx.arc(cx, cy, 18, 0, Math.PI * 2);
      ctx.fill();

      ctx.beginPath();
      ctx.arc(cx, cy, 3.5, 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();

      // Value label
      ctx.save();
      ctx.fillStyle = 'rgba(' + c.a.join(',') + ',0.6)';
      ctx.font = '10px "Orbitron"';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'bottom';
      ctx.fillText(trajSeq[drawTo].toLocaleString(), cx + 8, cy - 8);
      ctx.restore();
    }

    // Arrival marker
    if (trajDone) {
      ctx.save();
      ctx.fillStyle = 'rgba(' + c.b.join(',') + ',0.6)';
      ctx.font = '12px "Orbitron"';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'top';
      ctx.fillText('\u2192 1', m.l + gw, m.t + gh + 8);
      ctx.restore();
    }
  }

  // ============================================
  //  STOPPING TIME MODE
  //  Scatter plot of total stopping times
  // ============================================
  let stopN = 1;
  const STOP_MAX = 10000;
  const STOP_PER_FRAME = 8;
  let maxST = 0;

  function resetStop() {
    stopN = 1;
    maxST = 0;
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
  }

  function drawStopTime() {
    if (stopN > STOP_MAX) return;

    var mm = { t: 60, r: 60, b: 60, l: 60 };
    var gw = width - mm.l - mm.r;
    var gh = height - mm.t - mm.b;
    var stCeil = 600;
    var c = COLORS[colorIdx];

    for (var i = 0; i < STOP_PER_FRAME && stopN <= STOP_MAX; i++) {
      var st = stoppingTime(stopN);
      if (st > maxST) maxST = st;

      var x = mm.l + (stopN / STOP_MAX) * gw;
      var y = mm.t + gh - (st / stCeil) * gh;
      var t = Math.min(st / stCeil, 1);

      ctx.beginPath();
      ctx.arc(x, y, 1.3, 0, Math.PI * 2);
      ctx.fillStyle = lerpColor(c, t, 0.7);
      ctx.fill();

      stopN++;
    }
  }

  // ============================================
  //  HUD
  // ============================================
  function updateHud() {
    modeName.textContent = MODES[mode];
    var s = '';
    switch (mode) {
      case 0:
        s = 'DRAWN: <span class="val">' + Math.min(treeN - 1, TREE_MAX).toLocaleString() + '</span> / ' + TREE_MAX.toLocaleString() + '<br>';
        s += 'ANGLE: <span class="val">' + treeAngle + '&deg;</span>';
        angleWrap.style.display = 'flex';
        break;
      case 1:
        s = 'N: <span class="val">' + trajN.toLocaleString() + '</span><br>';
        s += 'STEPS: <span class="val">' + (trajSeq.length - 1) + '</span><br>';
        s += 'PEAK: <span class="val">' + trajMax.toLocaleString() + '</span>';
        angleWrap.style.display = 'none';
        break;
      case 2:
        s = 'CHECKED: <span class="val">' + Math.min(stopN - 1, STOP_MAX).toLocaleString() + '</span><br>';
        s += 'MAX STEPS: <span class="val">' + maxST + '</span>';
        angleWrap.style.display = 'none';
        break;
    }
    hudStats.innerHTML = s;
  }

  // ============================================
  //  MAIN LOOP
  // ============================================
  function loop() {
    if (!paused) {
      switch (mode) {
        case 0: drawTree(); break;
        case 1: drawTrajectory(); break;
        case 2: drawStopTime(); break;
      }
    }
    updateHud();
    requestAnimationFrame(loop);
  }

  function switchMode() {
    mode = (mode + 1) % MODES.length;
    resetAll();
  }

  function resetAll() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, width, height);
    switch (mode) {
      case 0: resetTree(); break;
      case 1: initTraj(27); break;
      case 2: resetStop(); break;
    }
  }

  // ============================================
  //  EVENTS
  // ============================================
  document.getElementById('modeBtn').addEventListener('click', switchMode);
  document.getElementById('resetBtn').addEventListener('click', resetAll);
  document.getElementById('colorBtn').addEventListener('click', function() {
    colorIdx = (colorIdx + 1) % COLORS.length;
    if (mode !== 1) resetAll();
  });

  angleSlider.addEventListener('input', function() {
    treeAngle = parseInt(this.value);
    angleLabel.textContent = 'ANGLE: ' + treeAngle + '\u00b0';
    if (mode === 0) resetTree();
  });

  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT') return;
    switch (e.key) {
      case 'h': case 'H':
        document.body.classList.toggle('hide-ui');
        break;
      case ' ':
        e.preventDefault();
        paused = !paused;
        break;
      case 'ArrowRight':
        if (mode === 1) { e.preventDefault(); initTraj(trajN + 1); }
        break;
      case 'ArrowLeft':
        if (mode === 1 && trajN > 2) { e.preventDefault(); initTraj(trajN - 1); }
        break;
      case 'ArrowUp':
        if (mode === 1) { e.preventDefault(); initTraj(trajN + 10); }
        break;
      case 'ArrowDown':
        if (mode === 1 && trajN > 11) { e.preventDefault(); initTraj(trajN - 10); }
        break;
    }
  });

  function resize() {
    width = canvas.width = window.innerWidth;
    height = canvas.height = window.innerHeight;
    resetAll();
  }

  window.addEventListener('resize', resize);

  // Init
  resize();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/share-buttons.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
