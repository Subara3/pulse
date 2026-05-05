<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pascal');

$pageName = 'pascal';
$pageTitle = 'PASCAL - PULSE';
$pageDesc = 'パスカルの三角形。mod Nで色を塗ると、シェルピンスキーの三角形をはじめフラクタルが浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/pascal.php';

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

<div class="hud">
  ROWS: <span id="hudRows" class="v">0</span> / <span id="hudMax" class="v">256</span><br>
  MOD: <span id="hudMod" class="v">2</span><br>
  DENSITY: <span id="hudDensity" class="v">0%</span>
</div>

<div id="info">
  pascal's triangle<br>
  <span class="v">mod N arithmetic</span><br>
  fractal patterns
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'cell info'],
  ['type' => 'drag', 'label' => 'pan'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">MOD</label>
    <div class="slider-row">
      <input type="range" id="sldMod" min="2" max="13" value="2">
      <span class="slider-value" id="valMod">2</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">ROWS</label>
    <div class="slider-row">
      <input type="range" id="sldRows" min="64" max="512" step="64" value="256">
      <span class="slider-value" id="valRows">256</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SPEED</label>
    <div class="slider-row">
      <input type="range" id="sldSpeed" min="1" max="20" value="4">
      <span class="slider-value" id="valSpeed">4</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnRebuild">REBUILD</button>
      <button class="control-btn" id="btnAuto">AUTO</button>
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;
  var modN = 2, maxRows = 256, speed = 4;
  var builtRows = -1, building = true;
  var autoMode = false, autoTimer = 0;
  var zoom = 1, panX = 0, panY = 0;
  var tri = [];
  var offC = document.createElement('canvas'), offCtx = offC.getContext('2d');
  var triW, triH, imgData, imgD;
  var colorRgb = [];
  var tooltip = null;
  var fitScale, drawX, drawY, dispW, dispH;

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    calcLayout();
  }

  function calcLayout() {
    if (!triW) return;
    fitScale = Math.min(W * 0.92 / triW, H * 0.86 / triH);
    dispW = triW * fitScale;
    dispH = triH * fitScale;
    drawX = (W - dispW) / 2;
    drawY = (H - dispH) / 2 + 10;
  }

  function h2r(p, q, t) {
    if (t < 0) t++; if (t > 1) t--;
    if (t < 1 / 6) return p + (q - p) * 6 * t;
    if (t < 1 / 2) return q;
    if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
    return p;
  }

  function hslRgb(h, s, l) {
    h /= 360;
    var q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
    return [
      Math.round(h2r(p, q, h + 1 / 3) * 255),
      Math.round(h2r(p, q, h) * 255),
      Math.round(h2r(p, q, h - 1 / 3) * 255)
    ];
  }

  function buildColors() {
    colorRgb = [[18, 18, 26]];
    if (modN === 2) {
      colorRgb.push([0, 245, 212]);
    } else {
      for (var v = 1; v < modN; v++) {
        var t = modN > 2 ? (v - 1) / (modN - 2) : 0;
        colorRgb.push(hslRgb(175 + t * 110, 0.75, 0.55));
      }
    }
  }

  function buildTriangle() {
    tri = [new Uint8Array([1])];
    for (var n = 1; n <= maxRows; n++) {
      var row = new Uint8Array(n + 1);
      var m = modN, prev = tri[n - 1];
      row[0] = 1; row[n] = 1;
      for (var k = 1; k < n; k++) row[k] = (prev[k - 1] + prev[k]) % m;
      tri.push(row);
    }
  }

  function initOffscreen() {
    triW = (maxRows + 1) * 2;
    triH = (maxRows + 1) * 2;
    offC.width = triW;
    offC.height = triH;
    imgData = offCtx.createImageData(triW, triH);
    imgD = imgData.data;
    clearImg();
  }

  function clearImg() {
    for (var i = 0; i < imgD.length; i += 4) {
      imgD[i] = 10; imgD[i + 1] = 10; imgD[i + 2] = 15; imgD[i + 3] = 255;
    }
  }

  function renderRow(n) {
    var ox = maxRows - n;
    for (var k = 0; k <= n; k++) {
      var c = colorRgb[tri[n][k]];
      var px = ox + k * 2, py = n * 2;
      for (var dy = 0; dy < 2; dy++) {
        var b = ((py + dy) * triW + px) * 4;
        imgD[b] = c[0]; imgD[b + 1] = c[1]; imgD[b + 2] = c[2]; imgD[b + 3] = 255;
        imgD[b + 4] = c[0]; imgD[b + 5] = c[1]; imgD[b + 6] = c[2]; imgD[b + 7] = 255;
      }
    }
  }

  function fullReset() {
    builtRows = -1; building = true; tooltip = null;
    buildColors(); buildTriangle(); initOffscreen(); calcLayout();
  }

  function updateHud() {
    document.getElementById('hudRows').textContent = Math.min(builtRows + 1, maxRows + 1);
    document.getElementById('hudMax').textContent = maxRows + 1;
    document.getElementById('hudMod').textContent = modN;
    var total = 0, nz = 0;
    for (var n = 0; n <= Math.min(builtRows, maxRows); n++) {
      total += n + 1;
      for (var k = 0; k <= n; k++) if (tri[n][k] !== 0) nz++;
    }
    document.getElementById('hudDensity').textContent = total ? Math.round(nz / total * 100) + '%' : '0%';
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    ctx.save();
    ctx.translate(W / 2 + panX, H / 2 + panY);
    ctx.scale(zoom, zoom);
    ctx.translate(-W / 2, -H / 2);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(offC, 0, 0, triW, triH, drawX, drawY, dispW, dispH);
    ctx.restore();

    if (building && builtRows >= 0) {
      var fy = drawY + (builtRows * 2 + 2) * fitScale;
      var sfy = (fy - H / 2) * zoom + H / 2 + panY;
      var grad = ctx.createLinearGradient(0, sfy - 12 * zoom, 0, sfy + 2 * zoom);
      grad.addColorStop(0, 'rgba(0,245,212,0)');
      grad.addColorStop(1, 'rgba(0,245,212,' + (0.12 + 0.08 * Math.sin(Date.now() * 0.006)) + ')');
      ctx.fillStyle = grad;
      ctx.fillRect(0, sfy - 12 * zoom, W, 14 * zoom);
    }

    if (tooltip) {
      var ox = maxRows - tooltip.n;
      var wx = drawX + (ox + tooltip.k * 2) * fitScale;
      var wy = drawY + tooltip.n * 2 * fitScale;
      var sx = (wx - W / 2) * zoom + W / 2 + panX;
      var sy = (wy - H / 2) * zoom + H / 2 + panY;
      var sw = 2 * fitScale * zoom;

      ctx.strokeStyle = '#fff';
      ctx.lineWidth = 2;
      ctx.strokeRect(sx - 1, sy - 1, sw + 2, sw + 2);

      var txt = 'C(' + tooltip.n + ',' + tooltip.k + ') mod ' + modN + ' = ' + tooltip.v;
      ctx.font = '11px "JetBrains Mono", monospace';
      ctx.textAlign = 'center';
      var tw = ctx.measureText(txt).width + 16;
      var tx = Math.max(tw / 2 + 5, Math.min(W - tw / 2 - 5, sx + sw / 2));
      var ty = sy - 14;
      if (ty < 50) ty = sy + sw + 22;
      ctx.fillStyle = 'rgba(10,10,15,0.92)';
      ctx.fillRect(tx - tw / 2, ty - 12, tw, 20);
      ctx.strokeStyle = 'rgba(0,245,212,0.4)';
      ctx.lineWidth = 1;
      ctx.strokeRect(tx - tw / 2, ty - 12, tw, 20);
      ctx.fillStyle = '#00f5d4';
      ctx.fillText(txt, tx, ty + 3);
    }
  }

  function tick() {
    if (building && builtRows < maxRows) {
      for (var i = 0; i < speed; i++) {
        builtRows++;
        renderRow(builtRows);
        if (builtRows >= maxRows) { building = false; break; }
      }
      offCtx.putImageData(imgData, 0, 0);
      if (builtRows % 25 === 0 || !building) updateHud();
      if (builtRows % 25 === 0) SFX.play('tick', 100);
      if (!building) SFX.play('complete');
    }
    if (autoMode && !building) {
      autoTimer++;
      if (autoTimer >= 120) {
        autoTimer = 0;
        modN = modN >= 13 ? 2 : modN + 1;
        document.getElementById('sldMod').value = modN;
        document.getElementById('valMod').textContent = modN;
        buildColors(); buildTriangle();
        clearImg(); builtRows = -1; building = true;
        SFX.play('toggle');
      }
    }
    draw();
    requestAnimationFrame(tick);
  }

  function screenToCell(sx, sy) {
    var wx = (sx - W / 2 - panX) / zoom + W / 2;
    var wy = (sy - H / 2 - panY) / zoom + H / 2;
    var ox = (wx - drawX) / fitScale;
    var oy = (wy - drawY) / fitScale;
    var n = Math.floor(oy / 2);
    if (n < 0 || n > builtRows || n > maxRows) return null;
    var rox = maxRows - n;
    var raw = ox - rox;
    if (raw < 0 || raw >= (n + 1) * 2) return null;
    var k = Math.floor(raw / 2);
    return (k >= 0 && k <= n) ? {n: n, k: k, v: tri[n][k]} : null;
  }

  var pDown = false, didDrag = false, spx = 0, spy = 0, ppx = 0, ppy = 0;

  C.addEventListener('pointerdown', function(e) {
    pDown = true; didDrag = false;
    spx = e.clientX; spy = e.clientY;
    ppx = panX; ppy = panY;
  });

  C.addEventListener('pointermove', function(e) {
    if (!pDown) return;
    if (Math.abs(e.clientX - spx) > 3 || Math.abs(e.clientY - spy) > 3) {
      didDrag = true;
      panX = ppx + e.clientX - spx;
      panY = ppy + e.clientY - spy;
    }
  });

  C.addEventListener('pointerup', function(e) {
    if (!didDrag) {
      var cell = screenToCell(e.clientX, e.clientY);
      tooltip = cell ? {n: cell.n, k: cell.k, v: cell.v} : null;
      if (cell) SFX.play('tick');
    }
    pDown = false;
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    var old = zoom;
    zoom = Math.max(0.3, Math.min(30, zoom * (e.deltaY > 0 ? 0.9 : 1.1)));
    var cx = e.clientX - W / 2, cy = e.clientY - H / 2;
    var f = zoom / old;
    panX = cx - f * (cx - panX);
    panY = cy - f * (cy - panY);
  }, {passive: false});

  document.getElementById('sldMod').addEventListener('input', function() {
    document.getElementById('valMod').textContent = this.value;
  });
  document.getElementById('sldMod').addEventListener('change', function() {
    modN = parseInt(this.value);
    fullReset(); SFX.play('toggle');
  });
  document.getElementById('sldRows').addEventListener('input', function() {
    document.getElementById('valRows').textContent = this.value;
  });
  document.getElementById('sldRows').addEventListener('change', function() {
    maxRows = parseInt(this.value);
    fullReset(); SFX.play('toggle');
  });
  document.getElementById('sldSpeed').addEventListener('input', function() {
    speed = parseInt(this.value);
    document.getElementById('valSpeed').textContent = this.value;
  });
  document.getElementById('btnRebuild').addEventListener('click', function() {
    builtRows = -1; building = true; tooltip = null; clearImg();
    SFX.play('click');
  });
  document.getElementById('btnAuto').addEventListener('click', function() {
    autoMode = !autoMode; autoTimer = 0;
    this.classList.toggle('active', autoMode);
    SFX.play('toggle');
  });
  document.getElementById('btnReset').addEventListener('click', function() {
    zoom = 1; panX = 0; panY = 0; tooltip = null;
    SFX.play('click');
  });

  window.addEventListener('resize', resize);
  fullReset();
  resize();

  for (var i = 0; i <= Math.min(30, maxRows); i++) {
    builtRows++;
    renderRow(builtRows);
  }
  offCtx.putImageData(imgData, 0, 0);
  updateHud();

  tick();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
