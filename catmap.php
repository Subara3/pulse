<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('catmap');

$pageName = 'catmap';
$pageTitle = 'CATMAP - PULSE';
$pageDesc = 'アーノルドの猫写像。面積保存カオス変換が色のグラデーションを引き裂き混ぜ合わせ、有限ステップで完全に元に戻る。';
$pageCanonical = 'https://pulse.subara3.com/catmap.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,.34);font-size:.64rem;text-align:right;letter-spacing:.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
.hud .recur{color:#00f5d4;animation:pulse-glow 1s ease-in-out infinite}
@keyframes pulse-glow{0%,100%{opacity:1}50%{opacity:.4}}
#info{position:fixed;bottom:1.5rem;left:1.5rem;color:rgba(255,255,255,.22);font-family:"JetBrains Mono",monospace;font-size:.6rem;line-height:1.7;letter-spacing:.03em;pointer-events:none;z-index:50}
#info .v{color:rgba(0,245,212,.5)}
@media(max-width:768px){.hud{top:3rem;right:.6rem;font-size:.54rem}#info{bottom:4.5rem;left:1rem;font-size:.5rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud" id="hud">
  ITER <span class="v" id="h-iter">0</span> / <span class="v" id="h-period">&mdash;</span><br>
  GRID <span class="v" id="h-grid">64</span><br>
  SHEAR <span class="v" id="h-shear">1</span><br>
  <span id="h-recur" style="display:none" class="recur">&#9679; RECURRENCE</span>
</div>

<div id="info">
  <span class="v">Arnold's Cat Map</span><br>
  x' &equiv; (<span id="i-a">2</span>x + y) mod N<br>
  y' &equiv; (<span id="i-c">1</span>x + y) mod N<br>
  det = 1
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'step'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <div class="btn-row">
      <button class="control-btn active" data-pat="gradient">GRADIENT</button>
      <button class="control-btn" data-pat="disk">DISK</button>
      <button class="control-btn" data-pat="quad">QUAD</button>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn" data-size="32">32</button>
      <button class="control-btn active" data-size="64">64</button>
      <button class="control-btn" data-size="128">128</button>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SHEAR</span>
      <input type="range" id="sl-shear" min="1" max="5" value="1" step="1">
      <span class="slider-value" id="sv-shear">1</span>
    </div>
  </div>
  <div>
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="sl-speed" min="1" max="30" value="5" step="1">
      <span class="slider-value" id="sv-speed">5</span>
    </div>
  </div>
  <div>
    <div class="btn-row">
      <button class="control-btn active" id="btn-auto">AUTO</button>
      <button class="control-btn" id="btn-step">&#9654;</button>
      <button class="control-btn" id="btn-back">&#9664;</button>
      <button class="control-btn" id="btn-reset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function(){
var C = document.getElementById('canvas'), ctx = C.getContext('2d');
var W, H;
var N = 64;
var grid, original, imgData;
var iter = 0, period = 0;
var autoPlay = true, speed = 5, lastStepTime = 0;
var shearK = 1;
var matA, matB, matC, matD;
var pattern = 'gradient';
var recurrenceFlash = 0;
var offCanvas = document.createElement('canvas');
var offCtx = offCanvas.getContext('2d');

function hslToRgb(h, s, l) {
  h = ((h % 360) + 360) % 360;
  var c = (1 - Math.abs(2 * l - 1)) * s;
  var x = c * (1 - Math.abs((h / 60) % 2 - 1));
  var m = l - c / 2;
  var r, g, b;
  if (h < 60) { r = c; g = x; b = 0; }
  else if (h < 120) { r = x; g = c; b = 0; }
  else if (h < 180) { r = 0; g = c; b = x; }
  else if (h < 240) { r = 0; g = x; b = c; }
  else if (h < 300) { r = x; g = 0; b = c; }
  else { r = c; g = 0; b = x; }
  return [Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255)];
}

function updateMatrix() {
  matA = 1 + shearK;
  matB = 1;
  matC = shearK;
  matD = 1;
}

function computePeriod() {
  var a = matA, b = matB, c = matC, d = matD;
  var ka = a % N, kb = b % N, kc = c % N, kd = d % N;
  for (var i = 1; i <= 12 * N; i++) {
    if (ka === 1 && kb === 0 && kc === 0 && kd === 1) return i;
    var na = (ka * a + kb * c) % N;
    var nb = (ka * b + kb * d) % N;
    var nc = (kc * a + kd * c) % N;
    var nd = (kc * b + kd * d) % N;
    ka = na; kb = nb; kc = nc; kd = nd;
  }
  return 0;
}

function initGrid() {
  grid = new Uint8Array(N * N * 4);
  for (var y = 0; y < N; y++) {
    for (var x = 0; x < N; x++) {
      var idx = (y * N + x) * 4;
      var rgb;
      if (pattern === 'gradient') {
        var hue = 170 + (x / N) * 110;
        var lum = 0.25 + (1 - y / N) * 0.4;
        rgb = hslToRgb(hue, 0.85, lum);
      } else if (pattern === 'disk') {
        var cx = x - N / 2, cy = y - N / 2;
        var dist = Math.sqrt(cx * cx + cy * cy) / (N * 0.35);
        if (dist < 1) {
          rgb = hslToRgb(170 + Math.pow(dist, 1.8) * 115, 0.9, 0.5 - dist * 0.15);
        } else {
          rgb = hslToRgb(270, 0.5, 0.08);
        }
      } else {
        var qx = x < N / 2, qy = y < N / 2;
        if (qx && qy) rgb = hslToRgb(172, 0.8, 0.45);
        else if (!qx && qy) rgb = hslToRgb(186, 0.8, 0.35);
        else if (qx && !qy) rgb = hslToRgb(260, 0.8, 0.35);
        else rgb = hslToRgb(280, 0.8, 0.25);
      }
      grid[idx] = rgb[0];
      grid[idx + 1] = rgb[1];
      grid[idx + 2] = rgb[2];
      grid[idx + 3] = 255;
    }
  }
  original = new Uint8Array(grid);
  offCanvas.width = N;
  offCanvas.height = N;
  imgData = offCtx.createImageData(N, N);
  iter = 0;
  updateMatrix();
  period = computePeriod();
  recurrenceFlash = 0;
  document.getElementById('h-period').textContent = period || '—';
  document.getElementById('h-grid').textContent = N;
  document.getElementById('h-shear').textContent = shearK;
  document.getElementById('h-iter').textContent = 0;
  document.getElementById('h-recur').style.display = 'none';
  document.getElementById('i-a').textContent = matA;
  document.getElementById('i-c').textContent = matC;
}

function applyStep(forward) {
  var next = new Uint8Array(N * N * 4);
  for (var y = 0; y < N; y++) {
    for (var x = 0; x < N; x++) {
      var mx = (matA * x + matB * y) % N;
      var my = (matC * x + matD * y) % N;
      var si, di;
      if (forward) {
        si = (y * N + x) * 4;
        di = (my * N + mx) * 4;
      } else {
        si = (my * N + mx) * 4;
        di = (y * N + x) * 4;
      }
      next[di] = grid[si];
      next[di + 1] = grid[si + 1];
      next[di + 2] = grid[si + 2];
      next[di + 3] = 255;
    }
  }
  grid = next;
  iter += forward ? 1 : -1;
  if (period > 0 && iter > 0 && iter % period === 0) {
    recurrenceFlash = 1;
    document.getElementById('h-recur').style.display = '';
    if (typeof SFX !== 'undefined') SFX.play('complete');
  } else {
    document.getElementById('h-recur').style.display = 'none';
  }
  document.getElementById('h-iter').textContent = iter;
}

function resize() {
  W = C.width = window.innerWidth;
  H = C.height = window.innerHeight;
}

function render(timestamp) {
  if (autoPlay && timestamp - lastStepTime > 1000 / speed) {
    applyStep(true);
    lastStepTime = timestamp;
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  }
  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  var size = Math.min(W, H) * 0.72;
  var ox = (W - size) / 2;
  var oy = (H - size) / 2;

  imgData.data.set(grid);
  offCtx.putImageData(imgData, 0, 0);
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(offCanvas, ox, oy, size, size);

  var ba = 0.12 + recurrenceFlash * 0.7;
  ctx.strokeStyle = 'rgba(0,245,212,' + ba.toFixed(2) + ')';
  ctx.lineWidth = recurrenceFlash > 0.1 ? 2.5 : 1;
  ctx.strokeRect(ox - 0.5, oy - 0.5, size + 1, size + 1);

  if (recurrenceFlash > 0.01) {
    ctx.save();
    ctx.shadowColor = '#00f5d4';
    ctx.shadowBlur = 40 * recurrenceFlash;
    ctx.strokeStyle = 'rgba(0,245,212,' + (recurrenceFlash * 0.4).toFixed(2) + ')';
    ctx.lineWidth = 2;
    ctx.strokeRect(ox - 3, oy - 3, size + 6, size + 6);
    ctx.restore();
    recurrenceFlash *= 0.985;
  }

  if (period > 0) {
    var progress = (((iter % period) + period) % period) / period;
    var barY = oy + size + 12;
    ctx.fillStyle = 'rgba(255,255,255,0.04)';
    ctx.fillRect(ox, barY, size, 3);
    ctx.fillStyle = 'rgba(0,245,212,0.45)';
    ctx.fillRect(ox, barY, size * progress, 3);
  }

  requestAnimationFrame(render);
}

C.addEventListener('pointerup', function(e) {
  if (e.pointerType === 'mouse' && e.button !== 0) return;
  if (!autoPlay) {
    applyStep(true);
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === ' ') {
    e.preventDefault();
    autoPlay = !autoPlay;
    document.getElementById('btn-auto').classList.toggle('active', autoPlay);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  } else if (e.key === 'ArrowRight') {
    e.preventDefault();
    applyStep(true);
    if (typeof SFX !== 'undefined') SFX.play('tick');
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault();
    if (iter > 0) applyStep(false);
    if (typeof SFX !== 'undefined') SFX.play('tick');
  } else if (e.key === 'r' || e.key === 'R') {
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('click');
  }
});

document.querySelectorAll('[data-pat]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    pattern = this.dataset.pat;
    document.querySelectorAll('[data-pat]').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.querySelectorAll('[data-size]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    N = parseInt(this.dataset.size);
    document.querySelectorAll('[data-size]').forEach(function(b) { b.classList.remove('active'); });
    this.classList.add('active');
    initGrid();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
});

document.getElementById('sl-shear').addEventListener('input', function() {
  shearK = parseInt(this.value);
  document.getElementById('sv-shear').textContent = shearK;
  initGrid();
});

document.getElementById('sl-speed').addEventListener('input', function() {
  speed = parseInt(this.value);
  document.getElementById('sv-speed').textContent = speed;
});

document.getElementById('btn-auto').addEventListener('click', function() {
  autoPlay = !autoPlay;
  this.classList.toggle('active', autoPlay);
  if (typeof SFX !== 'undefined') SFX.play('toggle');
});

document.getElementById('btn-step').addEventListener('click', function() {
  autoPlay = false;
  document.getElementById('btn-auto').classList.remove('active');
  applyStep(true);
  if (typeof SFX !== 'undefined') SFX.play('tick');
});

document.getElementById('btn-back').addEventListener('click', function() {
  if (iter > 0) {
    autoPlay = false;
    document.getElementById('btn-auto').classList.remove('active');
    applyStep(false);
    if (typeof SFX !== 'undefined') SFX.play('tick');
  }
});

document.getElementById('btn-reset').addEventListener('click', function() {
  initGrid();
  if (typeof SFX !== 'undefined') SFX.play('click');
});

window.addEventListener('resize', resize);
resize();
initGrid();
requestAnimationFrame(render);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
