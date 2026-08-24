<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chirikov');

$pageName = 'chirikov';
$pageTitle = 'CHIRIKOV - PULSE';
$pageDesc = 'チリコフ標準写像。キックされた回転子の位相空間にKAMトーラスが浮かび、摂動が増すとカオスの海に溶ける。';
$pageCanonical = 'https://pulse.subara3.com/chirikov.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Space Grotesk","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
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
  K: <span id="hudK" class="v">0.80</span><br>
  ORBITS: <span id="hudO" class="v">0</span><br>
  POINTS: <span id="hudP" class="v">0</span>
</div>

<div id="info">
  chirikov standard map<br>
  <span class="v">KAM tori · Hamiltonian chaos</span><br>
  area-preserving symplectic map
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'seed orbit'],
  ['type' => 'drag', 'label' => 'seed many'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">K (KICK)</label>
    <div class="slider-row">
      <input type="range" id="sK" min="0" max="600" value="80">
      <span class="slider-value" id="vK">0.80</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">ITER / ORBIT</label>
    <div class="slider-row">
      <input type="range" id="sIter" min="100" max="5000" value="1000" step="100">
      <span class="slider-value" id="vIter">1000</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">SIZE</label>
    <div class="slider-row">
      <input type="range" id="sSize" min="1" max="6" value="1">
      <span class="slider-value" id="vSize">1</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnAuto">AUTO</button>
      <button class="control-btn" id="btnClear">CLEAR</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESETS</label>
    <div class="btn-row">
      <button class="control-btn" id="preKAM">KAM</button>
      <button class="control-btn" id="preCrit">CRITICAL</button>
      <button class="control-btn" id="preChaos">CHAOS</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H, SZ;
  var offC, offX;
  var TWO_PI = Math.PI * 2;

  function resize() {
    var old = offC;
    W = C.width = innerWidth;
    H = C.height = innerHeight;
    SZ = Math.min(W, H) * 0.88;
    offC = document.createElement('canvas');
    offC.width = W; offC.height = H;
    offX = offC.getContext('2d');
    offX.fillStyle = '#0a0a0f';
    offX.fillRect(0, 0, W, H);
    if (old) offX.drawImage(old, 0, 0);
  }
  resize();
  addEventListener('resize', resize);

  var K = 0.80;
  var iterPerOrbit = 1000;
  var dotSize = 1;
  var totalOrbits = 0;
  var totalPoints = 0;
  var autoMode = true;
  var autoTimer = 0;
  var autoInterval = 120;

  var hudK = document.getElementById('hudK');
  var hudO = document.getElementById('hudO');
  var hudP = document.getElementById('hudP');
  var sK = document.getElementById('sK');
  var vK = document.getElementById('vK');
  var sIter = document.getElementById('sIter');
  var vIter = document.getElementById('vIter');
  var sSize = document.getElementById('sSize');
  var vSize = document.getElementById('vSize');

  function toScreen(theta, p) {
    var ox = (W - SZ) / 2;
    var oy = (H - SZ) / 2;
    return [ox + (theta / TWO_PI) * SZ, oy + (1 - p / TWO_PI) * SZ];
  }

  function fromScreen(sx, sy) {
    var ox = (W - SZ) / 2;
    var oy = (H - SZ) / 2;
    var theta = ((sx - ox) / SZ) * TWO_PI;
    var p = (1 - (sy - oy) / SZ) * TWO_PI;
    return [theta, p];
  }

  function mod2pi(x) {
    x = x % TWO_PI;
    if (x < 0) x += TWO_PI;
    return x;
  }

  function orbitColor(theta0, p0) {
    var t = p0 / TWO_PI;
    var h = 170 + t * 120;
    var s = 60 + Math.sin(theta0 * 3) * 15;
    var l = 50 + Math.cos(p0 * 2.7) * 12;
    return 'hsla(' + h + ',' + s + '%,' + l + '%,0.55)';
  }

  function seedOrbit(theta0, p0) {
    var theta = mod2pi(theta0);
    var p = mod2pi(p0);
    var col = orbitColor(theta, p);
    offX.fillStyle = col;
    var n = iterPerOrbit;
    var ds = dotSize;

    for (var i = 0; i < n; i++) {
      var pt = toScreen(theta, p);
      offX.fillRect(pt[0] - ds * 0.5, pt[1] - ds * 0.5, ds, ds);
      var pNew = mod2pi(p + K * Math.sin(theta));
      var tNew = mod2pi(theta + pNew);
      theta = tNew;
      p = pNew;
    }

    totalOrbits++;
    totalPoints += n;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('tick', 60);
  }

  function seedRandom() {
    var theta = Math.random() * TWO_PI;
    var p = Math.random() * TWO_PI;
    seedOrbit(theta, p);
  }

  function clearCanvas() {
    offX.fillStyle = '#0a0a0f';
    offX.fillRect(0, 0, W, H);
    totalOrbits = 0;
    totalPoints = 0;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  function updateHUD() {
    hudK.textContent = K.toFixed(2);
    hudO.textContent = totalOrbits;
    hudP.textContent = totalPoints > 99999 ? (totalPoints / 1000).toFixed(0) + 'k' : totalPoints;
  }

  function drawGrid() {
    var ox = (W - SZ) / 2;
    var oy = (H - SZ) / 2;
    X.strokeStyle = 'rgba(255,255,255,0.06)';
    X.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
      var f = i / 4;
      X.beginPath();
      X.moveTo(ox + f * SZ, oy);
      X.lineTo(ox + f * SZ, oy + SZ);
      X.stroke();
      X.beginPath();
      X.moveTo(ox, oy + f * SZ);
      X.lineTo(ox + SZ, oy + f * SZ);
      X.stroke();
    }

    X.strokeStyle = 'rgba(0,245,212,0.12)';
    X.strokeRect(ox, oy, SZ, SZ);

    X.fillStyle = 'rgba(255,255,255,0.18)';
    X.font = '0.5rem "JetBrains Mono", monospace';
    X.textAlign = 'center';
    X.fillText('0', ox, oy + SZ + 14);
    X.fillText('θ → 2π', ox + SZ, oy + SZ + 14);
    X.textAlign = 'right';
    X.fillText('p=0', ox - 6, oy + SZ);
    X.fillText('p=2π', ox - 6, oy + 4);
  }

  function frame() {
    requestAnimationFrame(frame);
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);
    X.drawImage(offC, 0, 0);
    drawGrid();

    if (autoMode) {
      autoTimer++;
      if (autoTimer >= autoInterval) {
        autoTimer = 0;
        seedRandom();
      }
    }
  }

  sK.addEventListener('input', function() {
    K = this.value / 100;
    vK.textContent = K.toFixed(2);
    hudK.textContent = K.toFixed(2);
  });

  sIter.addEventListener('input', function() {
    iterPerOrbit = parseInt(this.value);
    vIter.textContent = iterPerOrbit;
  });

  sSize.addEventListener('input', function() {
    dotSize = parseInt(this.value);
    vSize.textContent = dotSize;
  });

  document.getElementById('btnAuto').addEventListener('click', function() {
    autoMode = !autoMode;
    this.classList.toggle('active', autoMode);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnClear').addEventListener('click', clearCanvas);

  document.getElementById('preKAM').addEventListener('click', function() {
    K = 0.30; sK.value = 30; vK.textContent = '0.30';
    hudK.textContent = '0.30';
    clearCanvas();
    for (var i = 0; i < 30; i++) seedRandom();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('preCrit').addEventListener('click', function() {
    K = 0.97; sK.value = 97; vK.textContent = '0.97';
    hudK.textContent = '0.97';
    clearCanvas();
    for (var i = 0; i < 30; i++) seedRandom();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('preChaos').addEventListener('click', function() {
    K = 3.00; sK.value = 300; vK.textContent = '3.00';
    hudK.textContent = '3.00';
    clearCanvas();
    for (var i = 0; i < 30; i++) seedRandom();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  var isDown = false;
  function handleStart(sx, sy) {
    isDown = true;
    var coords = fromScreen(sx, sy);
    if (coords[0] >= 0 && coords[0] <= TWO_PI && coords[1] >= 0 && coords[1] <= TWO_PI) {
      seedOrbit(coords[0], coords[1]);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
  }

  function handleMove(sx, sy) {
    if (!isDown) return;
    var coords = fromScreen(sx, sy);
    if (coords[0] >= 0 && coords[0] <= TWO_PI && coords[1] >= 0 && coords[1] <= TWO_PI) {
      seedOrbit(coords[0], coords[1]);
    }
  }

  function handleEnd() {
    isDown = false;
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    handleStart(e.clientX, e.clientY);
  });
  C.addEventListener('mousemove', function(e) {
    handleMove(e.clientX, e.clientY);
  });
  C.addEventListener('mouseup', handleEnd);
  C.addEventListener('mouseleave', handleEnd);

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    handleStart(t.clientX, t.clientY);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    handleMove(t.clientX, t.clientY);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    handleEnd();
  }, { passive: false });

  for (var i = 0; i < 15; i++) seedRandom();
  updateHUD();
  frame();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>