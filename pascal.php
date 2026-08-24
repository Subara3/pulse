<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pascal');

$pageName = 'pascal';
$pageTitle = 'PASCAL - PULSE';
$pageDesc = 'パスカルの三角形。二項係数を mod N で色分けすると、自己相似フラクタルが浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/pascal.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Space Grotesk", "Segoe UI", sans-serif; }
.hud { top: 3.2rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.63rem; line-height: 1.8; text-align: right; letter-spacing: 0.05em; }
.hud .v { color: #00f5d4; font-weight: 700; }
@media (max-width: 768px) {
  .hud { top: 2.8rem; right: 0.65rem; font-size: 0.54rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  ROWS: <span id="hudRows" class="v">0</span><br>
  MOD: <span id="hudMod" class="v">2</span><br>
  CELLS: <span id="hudCells" class="v">0</span>
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'pan'],
  ['type' => 'wheel', 'label' => 'zoom'],
  ['type' => 'click', 'label' => 'reset view'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <label>MOD</label>
      <input type="range" id="modSlider" min="2" max="13" step="1" value="2">
      <span class="slider-value" id="modVal">2</span>
    </div>
    <div class="slider-row">
      <label>ROWS</label>
      <input type="range" id="rowSlider" min="32" max="512" step="16" value="256">
      <span class="slider-value" id="rowVal">256</span>
    </div>
    <div class="slider-row">
      <label>SPEED</label>
      <input type="range" id="speedSlider" min="1" max="20" step="1" value="8">
      <span class="slider-value" id="speedVal">8</span>
    </div>
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="animBtn">ANIMATE</button>
    <button class="control-btn" id="fillBtn">FILL</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d', { alpha: false });
  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0;

  var modN = 2, maxRows = 256, speed = 8;
  var grid = [];
  var builtRows = 0;
  var animating = true;
  var accum = 0;

  var camX = 0, camY = 0, scl = 1;
  var targetScl = 1;

  var pDown = false, pMoved = false;
  var dsx = 0, dsy = 0, dcx = 0, dcy = 0;
  var pinchD = 0;

  var palette = [];
  var imgBuf = null;
  var bufW = 0, bufH = 0;
  var dirty = true;
  var lastBuiltRows = -1;
  var offCanvas = document.createElement('canvas');
  var offCtx = offCanvas.getContext('2d');

  function buildPalette() {
    palette = [];
    for (var i = 0; i < modN; i++) {
      if (i === 0) {
        palette.push([10, 10, 15, 0]);
      } else {
        var t = (i - 1) / Math.max(1, modN - 2);
        var h = 175 + t * 110;
        var s = 70 + t * 10;
        var l = 50 + Math.sin(t * Math.PI) * 15;
        var c = hslToRgb(h, s, l);
        palette.push([c[0], c[1], c[2], 255]);
      }
    }
  }

  function hslToRgb(h, s, l) {
    s /= 100; l /= 100;
    var k = function(n) { return (n + h / 30) % 12; };
    var a = s * Math.min(l, 1 - l);
    var f = function(n) { return l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)); };
    return [Math.round(f(0) * 255), Math.round(f(8) * 255), Math.round(f(4) * 255)];
  }

  function computeGrid() {
    grid = [];
    for (var n = 0; n < maxRows; n++) {
      var row = new Uint8Array(n + 1);
      row[0] = 1 % modN;
      row[n] = 1 % modN;
      if (n > 0) {
        var prev = grid[n - 1];
        for (var k = 1; k < n; k++) {
          row[k] = (prev[k - 1] + prev[k]) % modN;
        }
      }
      grid.push(row);
    }
  }

  function renderToBuffer(nRows) {
    bufW = nRows;
    bufH = nRows;
    var data = new Uint8ClampedArray(bufW * bufH * 4);
    var bg = [10, 10, 15];
    for (var i = 0; i < bufW * bufH; i++) {
      var off = i * 4;
      data[off] = bg[0]; data[off+1] = bg[1]; data[off+2] = bg[2]; data[off+3] = 255;
    }

    for (var n = 0; n < nRows; n++) {
      var row = grid[n];
      var xOff = Math.floor((bufW - (n + 1)) / 2);
      for (var k = 0; k <= n; k++) {
        var val = row[k];
        var c = palette[val];
        var px = xOff + k;
        var py = n;
        if (px >= 0 && px < bufW && py < bufH) {
          var off = (py * bufW + px) * 4;
          data[off] = c[0]; data[off+1] = c[1]; data[off+2] = c[2]; data[off+3] = c[3] || 255;
        }
      }
    }
    imgBuf = new ImageData(data, bufW, bufH);
  }

  function fitView() {
    var triW = maxRows;
    var triH = maxRows;
    var sx = W / (triW + 40);
    var sy = H / (triH + 40);
    scl = Math.min(sx, sy);
    targetScl = scl;
    camX = 0;
    camY = 0;
  }

  function resize() {
    var r = C.getBoundingClientRect();
    W = r.width; H = r.height;
    C.width = W * DPR; C.height = H * DPR;
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    fitView();
    dirty = true;
  }

  function rebuild() {
    computeGrid();
    buildPalette();
    builtRows = animating ? Math.min(8, maxRows) : maxRows;
    lastBuiltRows = -1;
    accum = 0;
    dirty = true;
    updateHud();
  }

  function updateHud() {
    var n = builtRows;
    document.getElementById('hudRows').textContent = n;
    document.getElementById('hudMod').textContent = modN;
    document.getElementById('hudCells').textContent = n > 0 ? (n * (n + 1) / 2) | 0 : 0;
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (builtRows < 1) return;

    if (builtRows !== lastBuiltRows) {
      renderToBuffer(builtRows);
      if (!imgBuf) return;
      if (offCanvas.width !== bufW || offCanvas.height !== bufH) {
        offCanvas.width = bufW;
        offCanvas.height = bufH;
      }
      offCtx.putImageData(imgBuf, 0, 0);
      lastBuiltRows = builtRows;
    }

    ctx.save();
    ctx.translate(W / 2 + camX, H / 2 + camY);
    ctx.scale(scl, scl);
    ctx.imageSmoothingEnabled = scl < 2;
    ctx.drawImage(offCanvas, -bufW / 2, -bufH / 2);
    ctx.restore();
  }

  var lastT = 0;
  function loop(t) {
    requestAnimationFrame(loop);
    var dt = lastT ? (t - lastT) / 1000 : 0.016;
    lastT = t;
    if (dt > 0.1) dt = 0.1;

    scl += (targetScl - scl) * 0.15;

    if (animating && builtRows < maxRows) {
      accum += speed * dt;
      var steps = Math.floor(accum);
      if (steps > 0) {
        accum -= steps;
        builtRows = Math.min(builtRows + steps, maxRows);
        dirty = true;
        updateHud();
        if (builtRows % 4 === 0) {
          if (typeof SFX !== 'undefined') SFX.play('tick', 80);
        }
        if (builtRows >= maxRows) {
          if (typeof SFX !== 'undefined') SFX.play('complete');
        }
      }
    }

    if (dirty || Math.abs(targetScl - scl) > 0.001) {
      draw();
      dirty = false;
    }
  }

  // --- Controls ---
  var modSlider = document.getElementById('modSlider');
  var rowSlider = document.getElementById('rowSlider');
  var speedSlider = document.getElementById('speedSlider');
  var animBtn = document.getElementById('animBtn');
  var fillBtn = document.getElementById('fillBtn');
  var resetBtn = document.getElementById('resetBtn');

  modSlider.addEventListener('input', function() {
    modN = parseInt(this.value);
    document.getElementById('modVal').textContent = modN;
    rebuild();
    fitView();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  rowSlider.addEventListener('input', function() {
    maxRows = parseInt(this.value);
    document.getElementById('rowVal').textContent = maxRows;
    rebuild();
    fitView();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  speedSlider.addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('speedVal').textContent = speed;
  });

  animBtn.addEventListener('click', function() {
    animating = !animating;
    this.textContent = animating ? 'ANIMATE' : 'PAUSED';
    this.classList.toggle('active', animating);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  fillBtn.addEventListener('click', function() {
    builtRows = maxRows;
    lastBuiltRows = -1;
    dirty = true;
    updateHud();
    if (typeof SFX !== 'undefined') SFX.play('complete');
  });

  resetBtn.addEventListener('click', function() {
    builtRows = Math.min(8, maxRows);
    lastBuiltRows = -1;
    accum = 0;
    animating = true;
    animBtn.textContent = 'ANIMATE';
    animBtn.classList.add('active');
    fitView();
    dirty = true;
    updateHud();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // --- Mouse / Touch ---
  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) { pDown = true; pMoved = false; dsx = e.clientX; dsy = e.clientY; dcx = camX; dcy = camY; }
  });
  window.addEventListener('mousemove', function(e) {
    if (pDown) { pMoved = true; camX = dcx + (e.clientX - dsx); camY = dcy + (e.clientY - dsy); dirty = true; }
  });
  window.addEventListener('mouseup', function(e) {
    if (pDown && !pMoved) { fitView(); dirty = true; if (typeof SFX !== 'undefined') SFX.play('click'); }
    pDown = false;
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var d = e.deltaY > 0 ? 0.85 : 1.18;
    targetScl *= d;
    targetScl = Math.max(0.3, Math.min(targetScl, 60));
    dirty = true;
  }, { passive: false });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      pDown = true; pMoved = false;
      dsx = e.touches[0].clientX; dsy = e.touches[0].clientY;
      dcx = camX; dcy = camY;
    } else if (e.touches.length === 2) {
      pDown = false;
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      pinchD = Math.sqrt(dx * dx + dy * dy);
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length === 1 && pDown) {
      pMoved = true;
      camX = dcx + (e.touches[0].clientX - dsx);
      camY = dcy + (e.touches[0].clientY - dsy);
      dirty = true;
    } else if (e.touches.length === 2) {
      var dx = e.touches[1].clientX - e.touches[0].clientX;
      var dy = e.touches[1].clientY - e.touches[0].clientY;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (pinchD > 0) {
        var ratio = d / pinchD;
        targetScl *= ratio;
        targetScl = Math.max(0.3, Math.min(targetScl, 60));
        dirty = true;
      }
      pinchD = d;
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (pDown && !pMoved) { fitView(); dirty = true; if (typeof SFX !== 'undefined') SFX.play('click'); }
      pDown = false;
    }
    pinchD = 0;
  });

  window.addEventListener('resize', resize);
  resize();
  rebuild();
  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
