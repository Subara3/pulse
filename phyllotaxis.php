<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('phyllotaxis');

$pageName = 'phyllotaxis';
$pageTitle = 'PHYLLOTAXIS - PULSE';
$pageDesc = '葉序パターン。黄金角137.508°が生み出す螺旋配列の可視化。角度をずらすとパターンが劇的に変化する。';
$pageCanonical = 'https://pulse.subara3.com/phyllotaxis.php';

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
  SEEDS: <span class="v" id="hud-seeds">0</span><br>
  ANGLE: <span class="v" id="hud-angle">137.508°</span><br>
  SPIRALS: <span class="v" id="hud-spirals">—</span>
</div>

<div id="info">
  <span class="v">phyllotaxis</span><br>
  θ = n × 137.508°
</div>

<?php
$interactionHints = [
  ['type' => 'drag', 'label' => 'angle'],
  ['type' => 'click', 'label' => 'burst'],
  ['type' => 'wheel', 'label' => 'scale'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-row">
    <label>ANGLE</label>
    <input type="range" id="sl-angle" min="100" max="180" step="0.001" value="137.508">
    <span class="slider-value" id="sv-angle">137.508</span>
  </div>
  <div class="slider-row">
    <label>SEEDS</label>
    <input type="range" id="sl-seeds" min="50" max="2000" step="10" value="800">
    <span class="slider-value" id="sv-seeds">800</span>
  </div>
  <div class="slider-row">
    <label>SIZE</label>
    <input type="range" id="sl-size" min="1" max="8" step="0.5" value="3.5">
    <span class="slider-value" id="sv-size">3.5</span>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-spirals">SPIRALS</button>
    <button class="control-btn active" id="btn-growth">GROWTH</button>
  </div>
  <div class="btn-row">
    <button class="control-btn" id="btn-golden">GOLDEN</button>
    <button class="control-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, dpr;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  window.addEventListener('resize', resize);
  resize();

  var GOLDEN = 137.50776405003785;
  var angle = GOLDEN;
  var maxSeeds = 800;
  var seedSize = 3.5;
  var showSpirals = false;
  var growing = true;
  var visible = 0;
  var growRate = 3;
  var scale = 1;

  var slAngle = document.getElementById('sl-angle');
  var slSeeds = document.getElementById('sl-seeds');
  var slSize = document.getElementById('sl-size');
  var svAngle = document.getElementById('sv-angle');
  var svSeeds = document.getElementById('sv-seeds');
  var svSize = document.getElementById('sv-size');
  var hudSeeds = document.getElementById('hud-seeds');
  var hudAngle = document.getElementById('hud-angle');
  var hudSpirals = document.getElementById('hud-spirals');

  function computeScale() {
    var maxR = Math.sqrt(maxSeeds) * 6;
    var fit = Math.min(W, H) * 0.45;
    scale = fit / maxR;
    if (scale < 0.5) scale = 0.5;
    if (scale > 12) scale = 12;
  }
  computeScale();

  function spiralCount() {
    var aRad = angle * Math.PI / 180;
    var n = visible;
    if (n < 30) return '—';
    var fibs = [3, 5, 8, 13, 21, 34, 55, 89, 144];
    var found = [];
    for (var i = 0; i < fibs.length; i++) {
      var f = fibs[i];
      if (f >= n * 0.8) break;
      var rem = (f * aRad) % (2 * Math.PI);
      if (rem < 0) rem += 2 * Math.PI;
      if (rem > Math.PI) rem = 2 * Math.PI - rem;
      if (rem < 0.7) found.push(f);
    }
    if (found.length >= 2) return found[found.length - 2] + ', ' + found[found.length - 1];
    return found.length ? '' + found[found.length - 1] : '—';
  }

  function updateHUD() {
    hudSeeds.textContent = visible;
    hudAngle.textContent = angle.toFixed(3) + '°';
    hudSpirals.textContent = spiralCount();
    svAngle.textContent = angle.toFixed(3);
    svSeeds.textContent = maxSeeds;
    svSize.textContent = seedSize.toFixed(1);
  }

  slAngle.addEventListener('input', function() {
    angle = parseFloat(this.value);
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });
  slSeeds.addEventListener('input', function() {
    maxSeeds = parseInt(this.value);
    if (visible > maxSeeds) visible = maxSeeds;
    computeScale();
    updateHUD();
  });
  slSize.addEventListener('input', function() {
    seedSize = parseFloat(this.value);
    updateHUD();
  });

  document.getElementById('btn-spirals').addEventListener('click', function() {
    showSpirals = !showSpirals;
    this.classList.toggle('active', showSpirals);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-growth').addEventListener('click', function() {
    growing = !growing;
    this.classList.toggle('active', growing);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  document.getElementById('btn-golden').addEventListener('click', function() {
    angle = GOLDEN;
    slAngle.value = GOLDEN;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
  document.getElementById('btn-reset').addEventListener('click', function() {
    angle = GOLDEN;
    maxSeeds = 800;
    seedSize = 3.5;
    visible = 0;
    growing = true;
    slAngle.value = GOLDEN;
    slSeeds.value = 800;
    slSize.value = 3.5;
    document.getElementById('btn-growth').classList.add('active');
    computeScale();
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  var dragging = false;
  var dragStartX = 0;
  var dragStartAngle = 0;
  var dragMoved = false;

  C.addEventListener('mousedown', function(e) {
    if (e.button === 0) {
      dragging = true;
      dragStartX = e.clientX;
      dragStartAngle = angle;
      dragMoved = false;
    }
  });
  C.addEventListener('mousemove', function(e) {
    if (dragging) {
      var dx = e.clientX - dragStartX;
      if (Math.abs(dx) > 3) dragMoved = true;
      angle = dragStartAngle + dx * 0.04;
      if (angle < 100) angle = 100;
      if (angle > 180) angle = 180;
      slAngle.value = angle;
      updateHUD();
    }
  });
  window.addEventListener('mouseup', function(e) {
    if (dragging && !dragMoved) {
      var burst = Math.min(80, maxSeeds - visible);
      if (burst > 0) {
        visible = Math.min(visible + burst, maxSeeds);
        updateHUD();
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      }
    }
    dragging = false;
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    scale *= e.deltaY > 0 ? 0.95 : 1.05;
    if (scale < 0.3) scale = 0.3;
    if (scale > 15) scale = 15;
  }, { passive: false });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchId = null;
  var touchStartX = 0;
  var touchStartAngle = 0;
  var touchMoved = false;

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    if (e.touches.length === 1) {
      var t = e.touches[0];
      touchId = t.identifier;
      touchStartX = t.clientX;
      touchStartAngle = angle;
      touchMoved = false;
    }
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    for (var i = 0; i < e.touches.length; i++) {
      if (e.touches[i].identifier === touchId) {
        var dx = e.touches[i].clientX - touchStartX;
        if (Math.abs(dx) > 5) touchMoved = true;
        angle = touchStartAngle + dx * 0.06;
        if (angle < 100) angle = 100;
        if (angle > 180) angle = 180;
        slAngle.value = angle;
        updateHUD();
      }
    }
  }, { passive: false });

  C.addEventListener('touchend', function(e) {
    if (e.touches.length === 0) {
      if (!touchMoved) {
        var burst = Math.min(80, maxSeeds - visible);
        if (burst > 0) {
          visible = Math.min(visible + burst, maxSeeds);
          updateHUD();
          if (typeof SFX !== 'undefined') SFX.play('spawn');
        }
      }
      touchId = null;
    }
  });

  var time = 0;

  function draw() {
    requestAnimationFrame(draw);
    time += 0.016;

    if (growing && visible < maxSeeds) {
      visible = Math.min(visible + growRate, maxSeeds);
      updateHUD();
    }

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cx = W / 2;
    var cy = H / 2;
    var aRad = angle * Math.PI / 180;
    var sc = scale;
    var n = visible;

    if (showSpirals && n > 30) {
      var groups = [
        { step: 5, col: 'rgba(0,245,212,0.07)' },
        { step: 8, col: 'rgba(123,44,191,0.08)' },
        { step: 13, col: 'rgba(0,245,212,0.06)' },
        { step: 21, col: 'rgba(123,44,191,0.06)' },
        { step: 34, col: 'rgba(0,245,212,0.05)' }
      ];
      for (var g = 0; g < groups.length; g++) {
        var step = groups[g].step;
        if (step >= n) continue;
        ctx.strokeStyle = groups[g].col;
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (var j = step; j < n; j++) {
          var r1 = Math.sqrt(j) * sc * 6;
          var a1 = j * aRad;
          var x1 = cx + r1 * Math.cos(a1);
          var y1 = cy + r1 * Math.sin(a1);
          var k = j - step;
          var r2 = Math.sqrt(k) * sc * 6;
          var a2 = k * aRad;
          var x2 = cx + r2 * Math.cos(a2);
          var y2 = cy + r2 * Math.sin(a2);
          ctx.moveTo(x1, y1);
          ctx.lineTo(x2, y2);
        }
        ctx.stroke();
      }
    }

    for (var i = 0; i < n; i++) {
      var r = Math.sqrt(i) * sc * 6;
      var a = i * aRad;
      var x = cx + r * Math.cos(a);
      var y = cy + r * Math.sin(a);

      if (x < -20 || x > W + 20 || y < -20 || y > H + 20) continue;

      var t = i / Math.max(n - 1, 1);
      var h = 172 + t * 108;
      var s = 75 + t * 15;
      var l = 58 - t * 15;

      var age = n - i;
      var pulse = age < 12 ? 1 + (1 - age / 12) * 0.6 : 1;
      var sz = seedSize * pulse;
      var alpha = age < 12 ? 0.6 + 0.4 * (1 - age / 12) : 0.75;

      ctx.fillStyle = 'hsla(' + h + ',' + s + '%,' + l + '%,' + (alpha * 0.12) + ')';
      ctx.beginPath();
      ctx.arc(x, y, sz * 2.8, 0, 6.2832);
      ctx.fill();

      ctx.fillStyle = 'hsla(' + h + ',' + s + '%,' + l + '%,' + alpha + ')';
      ctx.beginPath();
      ctx.arc(x, y, sz, 0, 6.2832);
      ctx.fill();
    }

    ctx.strokeStyle = 'rgba(0,245,212,0.15)';
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + 25, cy);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(cx, cy, 25, 0, aRad, false);
    ctx.stroke();
    ctx.fillStyle = 'rgba(0,245,212,0.08)';
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.arc(cx, cy, 25, 0, aRad, false);
    ctx.closePath();
    ctx.fill();

    if (n === 0) {
      ctx.fillStyle = 'rgba(255,255,255,0.15)';
      ctx.font = '12px "Orbitron",sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('CLICK TO GROW', cx, cy);
    }
  }

  draw();
  updateHUD();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
