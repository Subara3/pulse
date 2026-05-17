<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lattice');

$pageName = 'lattice';
$pageTitle = 'LATTICE - PULSE';
$pageDesc = '2D格子振動。離散格子上の波動方程式。タップで波を起こし、ピン留めで壁を作り、フォノンの伝搬を観察する。';
$pageCanonical = 'https://pulse.subara3.com/lattice.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,0.22);font-family:"JetBrains Mono",monospace;font-size:0.6rem;line-height:1.7;letter-spacing:0.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,0.5)}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}#info{bottom:1rem;left:1rem;font-size:0.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  GRID: <span class="v" id="hud-grid">0</span><br>
  ENERGY: <span class="v" id="hud-energy">0.00</span><br>
  PINNED: <span class="v" id="hud-pinned">0</span>
</div>

<div id="info">
  <span class="v">lattice vibration</span><br>
  ∂²u/∂t² = c²∇²u − γ∂u/∂t
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'pulse'],
  ['type' => 'drag', 'label' => 'excite'],
  ['type' => 'right', 'label' => 'pin'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="speed-slider" min="5" max="35" value="15">
      <span class="slider-value" id="speed-val">0.15</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">DAMPING</span>
      <input type="range" id="damp-slider" min="990" max="1000" value="999">
      <span class="slider-value" id="damp-val">0.999</span>
    </div>
  </div>
  <div>
    <span class="slider-label">DISPLAY</span>
    <div class="btn-row" id="mode-btns">
      <button class="control-btn active" data-mode="0">DOTS</button>
      <button class="control-btn" data-mode="1">MESH</button>
      <button class="control-btn" data-mode="2">HEAT</button>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="reset-btn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  var sz = Math.min(window.innerWidth, window.innerHeight);
  var GRID = Math.max(25, Math.min(50, Math.floor(sz / 15)));
  var N = GRID * GRID;

  var spacing, ox, oy;
  var bufs = [new Float64Array(N), new Float64Array(N), new Float64Array(N)];
  var cur = 0, prev = 1;
  var pin = new Uint8Array(N);

  var c2 = 0.15;
  var dampFactor = 0.999;
  var STEPS = 4;
  var displayMode = 0;
  var pinnedCount = 0;
  var frameCount = 0;

  var isDragging = false;
  var pointerMoved = false;
  var exciteR = -1, exciteC = -1;
  var longPressTimer = null;
  var longPressTriggered = false;
  var touchStartX = 0, touchStartY = 0;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    spacing = Math.min(W, H) * 0.82 / (GRID - 1);
    ox = (W - spacing * (GRID - 1)) / 2;
    oy = (H - spacing * (GRID - 1)) / 2;
  }

  function resetGrid() {
    bufs[0] = new Float64Array(N);
    bufs[1] = new Float64Array(N);
    bufs[2] = new Float64Array(N);
    cur = 0; prev = 1;
    pin = new Uint8Array(N);
    pinnedCount = 0;
  }

  function screenToGrid(sx, sy) {
    var gc = Math.round((sx - ox) / spacing);
    var gr = Math.round((sy - oy) / spacing);
    return [gr, gc];
  }

  function addPulse(gr, gc, amp) {
    var sigma2 = 3.0;
    var range = 6;
    var uc = bufs[cur];
    for (var r = Math.max(0, gr - range); r <= Math.min(GRID - 1, gr + range); r++) {
      for (var c = Math.max(0, gc - range); c <= Math.min(GRID - 1, gc + range); c++) {
        var dr = r - gr, dc = c - gc;
        var d2 = dr * dr + dc * dc;
        var i = r * GRID + c;
        if (!pin[i]) uc[i] += amp * Math.exp(-d2 / (2 * sigma2));
      }
    }
  }

  function exciteAt(gr, gc) {
    var sigma2 = 1.5;
    var range = 3;
    var uc = bufs[cur];
    for (var r = Math.max(0, gr - range); r <= Math.min(GRID - 1, gr + range); r++) {
      for (var c = Math.max(0, gc - range); c <= Math.min(GRID - 1, gc + range); c++) {
        var dr = r - gr, dc = c - gc;
        var d2 = dr * dr + dc * dc;
        var i = r * GRID + c;
        if (!pin[i]) uc[i] += 0.12 * Math.exp(-d2 / (2 * sigma2));
      }
    }
  }

  function togglePin(r, c) {
    if (r < 0 || r >= GRID || c < 0 || c >= GRID) return;
    var i = r * GRID + c;
    if (pin[i]) {
      pin[i] = 0;
      pinnedCount--;
    } else {
      pin[i] = 1;
      bufs[cur][i] = 0;
      bufs[prev][i] = 0;
      var nxt = 3 - cur - prev;
      bufs[nxt][i] = 0;
      pinnedCount++;
    }
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }

  function step() {
    var nxt = 3 - cur - prev;
    var uc = bufs[cur];
    var up = bufs[prev];
    var un = bufs[nxt];
    for (var r = 0; r < GRID; r++) {
      for (var c = 0; c < GRID; c++) {
        var i = r * GRID + c;
        if (pin[i]) { un[i] = 0; continue; }
        var top = r > 0 ? uc[i - GRID] : 0;
        var bot = r < GRID - 1 ? uc[i + GRID] : 0;
        var lft = c > 0 ? uc[i - 1] : 0;
        var rgt = c < GRID - 1 ? uc[i + 1] : 0;
        var lap = top + bot + lft + rgt - 4 * uc[i];
        un[i] = dampFactor * (2 * uc[i] - up[i] + c2 * lap);
      }
    }
    prev = cur;
    cur = nxt;
  }

  function simulate() {
    if (isDragging && pointerMoved && exciteR >= 0 && exciteR < GRID && exciteC >= 0 && exciteC < GRID) {
      exciteAt(exciteR, exciteC);
      if (typeof SFX !== 'undefined') SFX.play('ripple', 150);
    }
    for (var s = 0; s < STEPS; s++) step();
  }

  function energy() {
    var e = 0;
    var uc = bufs[cur];
    var up = bufs[prev];
    for (var i = 0; i < N; i++) {
      var vel = uc[i] - up[i];
      e += uc[i] * uc[i] + vel * vel;
    }
    return e;
  }

  function drawDots() {
    var uc = bufs[cur];
    for (var r = 0; r < GRID; r++) {
      for (var c = 0; c < GRID; c++) {
        var i = r * GRID + c;
        var x = ox + c * spacing;
        var y = oy + r * spacing;
        var val = uc[i];

        if (pin[i]) {
          ctx.fillStyle = 'rgba(0,245,212,0.9)';
          var ps = spacing * 0.18;
          ctx.fillRect(x - ps, y - ps, ps * 2, ps * 2);
          continue;
        }

        var absVal = Math.min(Math.abs(val) * 3, 1);
        var nodeSz = spacing * (0.07 + absVal * 0.25);
        var alpha = 0.15 + absVal * 0.85;
        var rr, gg, bb;

        if (val >= 0) {
          rr = 0;
          gg = (60 + 185 * absVal) | 0;
          bb = (52 + 160 * absVal) | 0;
        } else {
          rr = (40 + 83 * absVal) | 0;
          gg = (12 + 32 * absVal) | 0;
          bb = (50 + 141 * absVal) | 0;
        }

        ctx.fillStyle = 'rgba(' + rr + ',' + gg + ',' + bb + ',' + alpha.toFixed(2) + ')';
        ctx.beginPath();
        ctx.arc(x, y, nodeSz, 0, Math.PI * 2);
        ctx.fill();
      }
    }
  }

  function drawMesh() {
    ctx.strokeStyle = 'rgba(0,245,212,0.06)';
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    for (var r = 0; r < GRID; r++) {
      ctx.moveTo(ox, oy + r * spacing);
      ctx.lineTo(ox + (GRID - 1) * spacing, oy + r * spacing);
    }
    for (var c = 0; c < GRID; c++) {
      ctx.moveTo(ox + c * spacing, oy);
      ctx.lineTo(ox + c * spacing, oy + (GRID - 1) * spacing);
    }
    ctx.stroke();
    drawDots();
  }

  function drawHeat() {
    var uc = bufs[cur];
    var half = spacing * 0.5;
    for (var r = 0; r < GRID; r++) {
      for (var c = 0; c < GRID; c++) {
        var i = r * GRID + c;
        var val = uc[i];
        var x = ox + c * spacing - half;
        var y = oy + r * spacing - half;

        if (pin[i]) {
          ctx.fillStyle = 'rgba(0,245,212,0.5)';
          ctx.fillRect(x, y, spacing + 0.5, spacing + 0.5);
          continue;
        }

        var absVal = Math.min(Math.abs(val) * 3, 1);
        if (absVal < 0.01) continue;

        var alpha = 0.15 + absVal * 0.85;
        if (val >= 0) {
          ctx.fillStyle = 'rgba(0,' + ((245 * absVal) | 0) + ',' + ((212 * absVal) | 0) + ',' + alpha.toFixed(2) + ')';
        } else {
          ctx.fillStyle = 'rgba(' + ((123 * absVal) | 0) + ',' + ((44 * absVal) | 0) + ',' + ((191 * absVal) | 0) + ',' + alpha.toFixed(2) + ')';
        }
        ctx.fillRect(x, y, spacing + 0.5, spacing + 0.5);
      }
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (displayMode === 0) drawDots();
    else if (displayMode === 1) drawMesh();
    else drawHeat();

    frameCount++;
    if (frameCount % 10 === 0) {
      document.getElementById('hud-grid').textContent = GRID + '×' + GRID;
      var e = energy();
      document.getElementById('hud-energy').textContent = e < 100 ? e.toFixed(2) : e < 1000 ? e.toFixed(1) : (e | 0);
      document.getElementById('hud-pinned').textContent = pinnedCount;
    }
  }

  var lastTs = 0;
  function loop(ts) {
    if (lastTs && ts - lastTs < 200) {
      simulate();
      draw();
    }
    lastTs = ts;
    requestAnimationFrame(loop);
  }

  // --- Mouse events ---
  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    if (e.button === 2) {
      var gc = screenToGrid(e.clientX, e.clientY);
      togglePin(gc[0], gc[1]);
      return;
    }
    pointerMoved = false;
    isDragging = true;
    var gc = screenToGrid(e.clientX, e.clientY);
    exciteR = gc[0];
    exciteC = gc[1];
  });

  C.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    pointerMoved = true;
    var gc = screenToGrid(e.clientX, e.clientY);
    exciteR = gc[0];
    exciteC = gc[1];
  });

  C.addEventListener('mouseup', function(e) {
    if (!pointerMoved && isDragging) {
      var gc = screenToGrid(e.clientX, e.clientY);
      addPulse(gc[0], gc[1], 1.0);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
    isDragging = false;
    exciteR = -1;
    exciteC = -1;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  // --- Touch events ---
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    longPressTriggered = false;
    var t = e.touches[0];
    touchStartX = t.clientX;
    touchStartY = t.clientY;
    longPressTimer = setTimeout(function() {
      longPressTriggered = true;
      isDragging = false;
      exciteR = -1;
      exciteC = -1;
      var gc = screenToGrid(touchStartX, touchStartY);
      togglePin(gc[0], gc[1]);
    }, 500);
    pointerMoved = false;
    isDragging = true;
    var gc = screenToGrid(t.clientX, t.clientY);
    exciteR = gc[0];
    exciteC = gc[1];
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (longPressTriggered) return;
    pointerMoved = true;
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    var t = e.touches[0];
    var gc = screenToGrid(t.clientX, t.clientY);
    exciteR = gc[0];
    exciteC = gc[1];
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    if (longPressTriggered) {
      isDragging = false;
      exciteR = -1;
      exciteC = -1;
      return;
    }
    if (!pointerMoved && isDragging) {
      var gc = screenToGrid(touchStartX, touchStartY);
      addPulse(gc[0], gc[1], 1.0);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
    isDragging = false;
    exciteR = -1;
    exciteC = -1;
  });

  // --- Controls ---
  document.getElementById('speed-slider').addEventListener('input', function() {
    c2 = parseInt(this.value) / 100;
    document.getElementById('speed-val').textContent = c2.toFixed(2);
  });

  document.getElementById('damp-slider').addEventListener('input', function() {
    dampFactor = parseInt(this.value) / 1000;
    document.getElementById('damp-val').textContent = dampFactor.toFixed(3);
  });

  var modeBtns = document.querySelectorAll('#mode-btns .control-btn');
  modeBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      modeBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      displayMode = parseInt(btn.dataset.mode);
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('reset-btn').addEventListener('click', function() {
    resetGrid();
    addPulse(Math.floor(GRID / 2), Math.floor(GRID / 2), 1.0);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // --- Init ---
  resize();
  window.addEventListener('resize', resize);
  document.getElementById('hud-grid').textContent = GRID + '×' + GRID;
  addPulse(Math.floor(GRID / 2), Math.floor(GRID / 2), 1.0);
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
