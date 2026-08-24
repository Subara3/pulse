<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('modular');

$pageName = 'modular';
$pageTitle = 'MODULAR - PULSE';
$pageDesc = 'モジュラ乗算。円周上のN点を乗数kで結ぶと、カージオイドやネフロイドが現れる。';
$pageCanonical = 'https://pulse.subara3.com/modular.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; cursor: crosshair; }
.ui { position: fixed; z-index: 100; font-family: "Space Grotesk", "Segoe UI", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.64rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .v { color: #00f5d4; font-weight: 700; }
#kDisplay {
  position: fixed; bottom: 2.5rem; left: 50%; transform: translateX(-50%);
  font-family: "JetBrains Mono", monospace; font-size: 2.8rem; font-weight: 700;
  color: rgba(0, 245, 212, 0.12); z-index: 50; pointer-events: none;
  letter-spacing: 0.08em; white-space: nowrap;
}
#curveName {
  position: fixed; bottom: 6.5rem; left: 50%; transform: translateX(-50%);
  font-family: "Space Grotesk", sans-serif; font-size: 0.72rem; font-weight: 700;
  color: rgba(0, 245, 212, 0); z-index: 50; pointer-events: none;
  letter-spacing: 0.12em; transition: none; white-space: nowrap;
}
@media (max-width: 768px) {
  .hud { top: 0.6rem; right: 0.6rem; font-size: 0.54rem; }
  #kDisplay { font-size: 1.8rem; bottom: 2rem; }
  #curveName { font-size: 0.58rem; bottom: 5rem; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  k: <span id="hudK" class="v">2.00</span><br>
  N: <span id="hudN" class="v">200</span>
</div>

<div id="kDisplay">2.00</div>
<div id="curveName"></div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'set k'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">MULTIPLIER</label>
      <span class="slider-value" id="sliderKVal">2.00</span>
    </div>
    <input type="range" id="sliderK" min="0" max="2000" step="1" value="200">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">POINTS</label>
      <span class="slider-value" id="sliderNVal">200</span>
    </div>
    <input type="range" id="sliderN" min="50" max="500" step="1" value="200">
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label class="slider-label">SPEED</label>
      <span class="slider-value" id="sliderSpeedVal">0.005</span>
    </div>
    <input type="range" id="sliderSpeed" min="1" max="20" step="1" value="5">
  </div>
  <div class="btn-row">
    <button class="control-btn active" id="animBtn">ANIMATE: ON</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudK = document.getElementById('hudK');
  var hudN = document.getElementById('hudN');
  var kDisplay = document.getElementById('kDisplay');
  var curveNameEl = document.getElementById('curveName');

  var sliderK = document.getElementById('sliderK');
  var sliderN = document.getElementById('sliderN');
  var sliderSpeed = document.getElementById('sliderSpeed');
  var sliderKVal = document.getElementById('sliderKVal');
  var sliderNVal = document.getElementById('sliderNVal');
  var sliderSpeedVal = document.getElementById('sliderSpeedVal');
  var animBtn = document.getElementById('animBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0;
  var k = 2.0;
  var N = 200;
  var speed = 0.005;
  var animate = true;
  var curveFlash = 0;
  var curveText = '';

  var curveNames = {
    2: 'CARDIOID',
    3: 'NEPHROID',
    4: '3-CUSP EPICYCLOID',
    5: '4-CUSP EPICYCLOID',
    6: '5-CUSP EPICYCLOID',
    7: '6-CUSP EPICYCLOID'
  };

  function resize() {
    W = canvas.width = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }

  function hslToRgb(h, s, l) {
    h = h / 360;
    var r, g, b;
    if (s === 0) {
      r = g = b = l;
    } else {
      function hue2rgb(p, q, t) {
        if (t < 0) t += 1;
        if (t > 1) t -= 1;
        if (t < 1/6) return p + (q - p) * 6 * t;
        if (t < 1/2) return q;
        if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
        return p;
      }
      var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
      var p = 2 * l - q;
      r = hue2rgb(p, q, h + 1/3);
      g = hue2rgb(p, q, h);
      b = hue2rgb(p, q, h - 1/3);
    }
    return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
  }

  function getLineColor(i, n, alpha) {
    var t = i / n;
    var hue = 175 + t * 100;
    var rgb = hslToRgb(hue, 0.85, 0.55);
    return 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',' + alpha + ')';
  }

  function checkCurveName() {
    var rounded = Math.round(k);
    var frac = Math.abs(k - rounded);
    if (frac < 0.03 && curveNames[rounded]) {
      if (curveText !== curveNames[rounded]) {
        curveText = curveNames[rounded];
        curveFlash = 1.0;
      }
    }
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var cx = W / 2;
    var cy = H / 2;
    var radius = Math.min(W, H) * 0.4;

    // circle outline
    ctx.beginPath();
    ctx.arc(cx, cy, radius, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(0,245,212,0.2)';
    ctx.lineWidth = 1;
    ctx.stroke();

    // precompute point positions
    var px = new Float64Array(N);
    var py = new Float64Array(N);
    for (var i = 0; i < N; i++) {
      var angle = (i / N) * Math.PI * 2 - Math.PI / 2;
      px[i] = cx + Math.cos(angle) * radius;
      py[i] = cy + Math.sin(angle) * radius;
    }

    // draw lines
    ctx.lineWidth = 0.6;
    for (var i = 0; i < N; i++) {
      var target = Math.floor(i * k) % N;
      if (target < 0) target += N;
      var alpha = 0.15 + (i / N) * 0.15;
      ctx.beginPath();
      ctx.moveTo(px[i], py[i]);
      ctx.lineTo(px[target], py[target]);
      ctx.strokeStyle = getLineColor(i, N, alpha);
      ctx.stroke();
    }

    // draw points on circle
    ctx.fillStyle = '#00f5d4';
    var dotR = N > 300 ? 1 : (N > 150 ? 1.5 : 2);
    for (var i = 0; i < N; i++) {
      ctx.beginPath();
      ctx.arc(px[i], py[i], dotR, 0, Math.PI * 2);
      ctx.fill();
    }

    // curve name flash
    if (curveFlash > 0) {
      curveNameEl.textContent = curveText;
      curveNameEl.style.color = 'rgba(0, 245, 212, ' + (curveFlash * 0.8).toFixed(3) + ')';
      curveFlash -= 0.008;
      if (curveFlash < 0) curveFlash = 0;
    } else {
      curveNameEl.style.color = 'rgba(0, 245, 212, 0)';
    }
  }

  function updateHUD() {
    var kStr = k.toFixed(2);
    hudK.textContent = kStr;
    hudN.textContent = String(N);
    kDisplay.textContent = kStr;
  }

  function syncSliderK() {
    sliderK.value = Math.round(k * 100);
    sliderKVal.textContent = k.toFixed(2);
  }

  function loop() {
    if (animate) {
      k += speed;
      if (k > 200) k = 0;
      checkCurveName();
      syncSliderK();
    }
    updateHUD();
    draw();
    requestAnimationFrame(loop);
  }

  // slider events
  sliderK.addEventListener('input', function() {
    k = parseFloat(this.value) / 100;
    sliderKVal.textContent = k.toFixed(2);
    checkCurveName();
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });

  sliderN.addEventListener('input', function() {
    N = parseInt(this.value, 10);
    sliderNVal.textContent = String(N);
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });

  sliderSpeed.addEventListener('input', function() {
    speed = parseFloat(this.value) / 1000;
    sliderSpeedVal.textContent = speed.toFixed(3);
    if (typeof SFX !== 'undefined') SFX.play('tick', 100);
  });

  animBtn.addEventListener('click', function() {
    animate = !animate;
    animBtn.textContent = 'ANIMATE: ' + (animate ? 'ON' : 'OFF');
    if (animate) {
      animBtn.classList.add('active');
    } else {
      animBtn.classList.remove('active');
    }
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  resetBtn.addEventListener('click', function() {
    k = 2.0;
    N = 200;
    speed = 0.005;
    animate = true;
    sliderK.value = 200;
    sliderKVal.textContent = '2.00';
    sliderN.value = 200;
    sliderNVal.textContent = '200';
    sliderSpeed.value = 5;
    sliderSpeedVal.textContent = '0.005';
    animBtn.textContent = 'ANIMATE: ON';
    animBtn.classList.add('active');
    curveFlash = 0;
    checkCurveName();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  // click on canvas: set k based on x position, pause animation
  canvas.addEventListener('click', function(e) {
    var rect = canvas.getBoundingClientRect();
    var x = (e.clientX - rect.left) / rect.width;
    k = x * 200;
    animate = false;
    animBtn.textContent = 'ANIMATE: OFF';
    animBtn.classList.remove('active');
    syncSliderK();
    checkCurveName();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  // touch support
  canvas.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var touch = e.touches[0];
    var rect = canvas.getBoundingClientRect();
    var x = (touch.clientX - rect.left) / rect.width;
    k = x * 200;
    animate = false;
    animBtn.textContent = 'ANIMATE: OFF';
    animBtn.classList.remove('active');
    syncSliderK();
    checkCurveName();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });

  window.addEventListener('resize', resize);

  resize();
  updateHUD();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
