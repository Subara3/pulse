<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('galaxy');

$pageName = 'galaxy';
$pageTitle = 'GALAXY - PULSE';
$pageDesc = '渦巻銀河の密度波シミュレーション。数千の星が差動回転し、腕の密度波が構造を生む。';
$pageCanonical = 'https://pulse.subara3.com/galaxy.php';

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
  STARS: <span class="v" id="hud-stars">0</span><br>
  ARMS: <span class="v" id="hud-arms">2</span><br>
  SPEED: <span class="v" id="hud-speed">1.0</span>
</div>

<div id="info">
  <span class="v">density wave theory</span><br>
  Lin & Shu (1964) · differential rotation
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'burst'],
  ['type' => 'drag', 'label' => 'perturb'],
  ['type' => 'wheel', 'label' => 'zoom'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div>
    <span class="slider-label">ARMS</span>
    <div class="btn-row" id="arms-btns">
      <button class="control-btn" data-arms="1">1</button>
      <button class="control-btn active" data-arms="2">2</button>
      <button class="control-btn" data-arms="3">3</button>
      <button class="control-btn" data-arms="4">4</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">TIGHTNESS</span>
      <input type="range" id="tight-slider" min="10" max="80" value="35">
      <span class="slider-value" id="tight-val">35</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">SPEED</span>
      <input type="range" id="speed-slider" min="10" max="200" value="100">
      <span class="slider-value" id="speed-val">1.0</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">DISPERSION</span>
      <input type="range" id="disp-slider" min="5" max="60" value="25">
      <span class="slider-value" id="disp-val">25</span>
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
  var W, H, cx, cy;
  var dpr = window.devicePixelRatio || 1;

  var NUM_STARS = 4000;
  var arms = 2;
  var tightness = 0.35;
  var speedMul = 1.0;
  var dispersion = 0.25;
  var zoom = 1.0;
  var time = 0;

  var stars = [];
  var perturbX = 0, perturbY = 0;
  var dragging = false;
  var dragX = 0, dragY = 0;

  function resize() {
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    C.style.width = W + 'px';
    C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    cx = W / 2;
    cy = H / 2;
  }

  function createStar(rOverride) {
    var r = rOverride !== undefined ? rOverride : Math.pow(Math.random(), 0.6) * Math.min(W, H) * 0.42;
    var armIdx = Math.floor(Math.random() * arms);
    var baseAngle = (armIdx / arms) * Math.PI * 2;
    var spiralOffset = tightness * Math.log(1 + r * 0.01);
    var scatter = (Math.random() - 0.5) * dispersion * (1 + r * 0.002);
    var angle = baseAngle + spiralOffset + scatter;
    var orbitalSpeed = 0.3 / Math.sqrt(0.5 + r * 0.005);
    var brightness = 0.3 + Math.random() * 0.7;
    var size = 0.5 + Math.random() * 1.5;
    if (r < 30) { brightness = 0.8 + Math.random() * 0.2; size *= 0.7; }
    var hue = 175 + (r / (Math.min(W, H) * 0.42)) * 80;
    return { r: r, angle: angle, orbitalSpeed: orbitalSpeed, brightness: brightness, size: size, hue: hue, armIdx: armIdx };
  }

  function initStars() {
    stars = [];
    for (var i = 0; i < NUM_STARS; i++) {
      stars.push(createStar());
    }
  }

  function addBurst(x, y) {
    var dx = x - cx, dy = y - cy;
    var dist = Math.sqrt(dx * dx + dy * dy);
    for (var i = 0; i < 60; i++) {
      var r = dist + (Math.random() - 0.5) * 40;
      if (r < 5) r = 5;
      var s = createStar(r);
      s.angle = Math.atan2(dy, dx) + (Math.random() - 0.5) * 0.5;
      stars.push(s);
    }
    if (stars.length > 8000) stars.splice(0, stars.length - 8000);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function update(dt) {
    time += dt * speedMul;
    perturbX *= 0.95;
    perturbY *= 0.95;
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.15)';
    ctx.fillRect(0, 0, W, H);

    var coreGrad = ctx.createRadialGradient(cx, cy, 0, cx, cy, 20 * zoom);
    coreGrad.addColorStop(0, 'rgba(0,245,212,0.15)');
    coreGrad.addColorStop(0.5, 'rgba(123,44,191,0.05)');
    coreGrad.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = coreGrad;
    ctx.fillRect(cx - 30 * zoom, cy - 30 * zoom, 60 * zoom, 60 * zoom);

    for (var i = 0; i < stars.length; i++) {
      var s = stars[i];
      s.angle += s.orbitalSpeed * speedMul * 0.002;
      var spiralPhase = tightness * Math.log(1 + s.r * 0.01);
      var armAngle = (s.armIdx / arms) * Math.PI * 2;
      var densityAngle = armAngle + spiralPhase - time * 0.0003 * speedMul;
      var angleDiff = ((s.angle - densityAngle) % (Math.PI * 2 / arms) + Math.PI * 2) % (Math.PI * 2 / arms);
      var nearArm = Math.cos(angleDiff * arms) * 0.5 + 0.5;
      var effBright = s.brightness * (0.4 + nearArm * 0.6);

      var px = cx + Math.cos(s.angle) * s.r * zoom + perturbX * (s.r * 0.001);
      var py = cy + Math.sin(s.angle) * s.r * zoom + perturbY * (s.r * 0.001);

      var alpha = effBright * (0.5 + nearArm * 0.5);
      var sat = 60 + nearArm * 30;
      var light = 45 + effBright * 20;
      ctx.fillStyle = 'hsla(' + s.hue + ',' + sat + '%,' + light + '%,' + alpha + ')';
      var sz = s.size * (0.7 + nearArm * 0.5) * zoom;
      ctx.beginPath();
      ctx.arc(px, py, sz, 0, Math.PI * 2);
      ctx.fill();
    }

    document.getElementById('hud-stars').textContent = stars.length;
  }

  var last = 0;
  function loop(ts) {
    var dt = last ? Math.min(ts - last, 50) : 16;
    last = ts;
    update(dt);
    draw();
    requestAnimationFrame(loop);
  }

  function handlePointerDown(x, y, btn) {
    if (btn === 2) return;
    dragging = true;
    dragX = x;
    dragY = y;
  }

  function handlePointerMove(x, y) {
    if (!dragging) return;
    perturbX += (x - dragX) * 0.5;
    perturbY += (y - dragY) * 0.5;
    dragX = x;
    dragY = y;
    if (typeof SFX !== 'undefined') SFX.play('move', 100);
  }

  function handlePointerUp(x, y, wasDrag) {
    if (!wasDrag) {
      addBurst(x, y);
    }
    dragging = false;
  }

  var pointerMoved = false;
  C.addEventListener('mousedown', function(e) {
    e.preventDefault();
    pointerMoved = false;
    handlePointerDown(e.clientX, e.clientY, e.button);
  });
  C.addEventListener('mousemove', function(e) {
    pointerMoved = true;
    handlePointerMove(e.clientX, e.clientY);
  });
  C.addEventListener('mouseup', function(e) {
    handlePointerUp(e.clientX, e.clientY, pointerMoved && dragging);
  });
  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  var touchMoved = false;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    touchMoved = false;
    var t = e.touches[0];
    handlePointerDown(t.clientX, t.clientY, 0);
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    touchMoved = true;
    var t = e.touches[0];
    handlePointerMove(t.clientX, t.clientY);
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    handlePointerUp(dragX, dragY, touchMoved);
  });

  C.addEventListener('wheel', function(e) {
    e.preventDefault();
    zoom *= e.deltaY > 0 ? 0.95 : 1.05;
    zoom = Math.max(0.3, Math.min(3.0, zoom));
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  }, { passive: false });

  var armBtns = document.querySelectorAll('#arms-btns .control-btn');
  armBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      armBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      arms = parseInt(btn.dataset.arms);
      document.getElementById('hud-arms').textContent = arms;
      initStars();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  var tightSlider = document.getElementById('tight-slider');
  tightSlider.addEventListener('input', function() {
    tightness = parseInt(this.value) / 100;
    document.getElementById('tight-val').textContent = this.value;
    initStars();
  });

  var speedSlider = document.getElementById('speed-slider');
  speedSlider.addEventListener('input', function() {
    speedMul = parseInt(this.value) / 100;
    document.getElementById('speed-val').textContent = speedMul.toFixed(1);
    document.getElementById('hud-speed').textContent = speedMul.toFixed(1);
  });

  var dispSlider = document.getElementById('disp-slider');
  dispSlider.addEventListener('input', function() {
    dispersion = parseInt(this.value) / 100;
    document.getElementById('disp-val').textContent = this.value;
    initStars();
  });

  document.getElementById('reset-btn').addEventListener('click', function() {
    zoom = 1.0;
    perturbX = 0;
    perturbY = 0;
    time = 0;
    initStars();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  resize();
  window.addEventListener('resize', resize);
  initStars();

  ctx.fillStyle = '#0a0a0f';
  ctx.fillRect(0, 0, W, H);

  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
