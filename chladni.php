<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chladni');

$pageName = 'chladni';
$pageTitle = 'CHLADNI - PULSE';
$pageDesc = 'クラドニ図形。振動モードが作る節線に粒子が集まり、音とともに砂紋がゆっくり現れる。';
$pageCanonical = 'https://pulse.subara3.com/chladni.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 100; font-family: "Orbitron", "Segoe UI", sans-serif; pointer-events: none; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.34); font-size: 0.64rem; text-align: right; letter-spacing: 0.05em; line-height: 1.8; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1.3rem; bottom: 1.3rem; color: rgba(255,255,255,0.24); font-size: 0.58rem; letter-spacing: 0.05em; line-height: 1.75; max-width: min(74vw, 440px); }

@media (max-width: 768px) {
  .hud { top: 0.6rem; right: 0.6rem; font-size: 0.54rem; }
  .info { left: 0.9rem; bottom: 0.9rem; font-size: 0.5rem; max-width: 76vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="ui hud">
  MODE: <span id="hudMode" class="v">3×5</span><br>
  DENSITY: <span id="hudDensity" class="v">1800</span><br>
  ENERGY: <span id="hudEnergy" class="v">0.35</span>
</div>

<div class="ui info">
  TAP: EXCITE PLATE<br>
  DRAG: SWEEP VIBRATION SOURCE<br>
  PARTICLES DRIFT TO NODAL LINES<br>
  SLOW MOTION FOR LONG WATCHING
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'excite'],
  ['type' => 'drag', 'label' => 'sweep'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>MODE X</label>
    <input type="range" id="modeX" min="1" max="9" value="3">
  </div>
  <div class="slider-wrap">
    <label>MODE Y</label>
    <input type="range" id="modeY" min="1" max="9" value="5">
  </div>
  <div class="slider-wrap">
    <label>DAMP</label>
    <input type="range" id="damp" min="4" max="40" value="19">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO: ON</button>
    <button class="control-btn" id="densityBtn">DENSITY</button>
    <button class="control-btn" id="clearBtn">CLEAR</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudMode = document.getElementById('hudMode');
  var hudDensity = document.getElementById('hudDensity');
  var hudEnergy = document.getElementById('hudEnergy');

  var modeXSlider = document.getElementById('modeX');
  var modeYSlider = document.getElementById('modeY');
  var dampSlider = document.getElementById('damp');
  var autoBtn = document.getElementById('autoBtn');
  var densityBtn = document.getElementById('densityBtn');
  var clearBtn = document.getElementById('clearBtn');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0;

  var plateW = 300;
  var plateH = 180;
  var field = new Float32Array(plateW * plateH);
  var particles = [];
  var densityMode = 1;

  var autoMode = true;
  var pointerDown = false;
  var pointerX = 0.5;
  var pointerY = 0.5;
  var impulse = 0.22;
  var lastPulse = 0;
  var lastAutoSwitch = 0;

  var modeX = parseInt(modeXSlider.value, 10);
  var modeY = parseInt(modeYSlider.value, 10);

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
  }

  function rebuildField() {
    var idx = 0;
    for (var y = 0; y < plateH; y++) {
      var ny = y / (plateH - 1);
      for (var x = 0; x < plateW; x++) {
        var nx = x / (plateW - 1);
        var a = Math.sin(Math.PI * modeX * nx) * Math.sin(Math.PI * modeY * ny);
        var b = Math.sin(Math.PI * modeY * nx) * Math.sin(Math.PI * modeX * ny);
        field[idx++] = a - b;
      }
    }
    hudMode.textContent = modeX + '×' + modeY;
  }

  function targetCount() {
    return densityMode === 0 ? 1100 : (densityMode === 1 ? 1800 : 2800);
  }

  function resetParticles(full) {
    var count = targetCount();
    if (full || particles.length === 0) {
      particles.length = 0;
      for (var i = 0; i < count; i++) {
        particles.push({
          x: Math.random(),
          y: Math.random(),
          vx: (Math.random() - 0.5) * 0.0012,
          vy: (Math.random() - 0.5) * 0.0012,
          life: Math.random()
        });
      }
    } else if (particles.length < count) {
      while (particles.length < count) {
        particles.push({ x: Math.random(), y: Math.random(), vx: 0, vy: 0, life: Math.random() });
      }
    } else if (particles.length > count) {
      particles.length = count;
    }
    hudDensity.textContent = String(count);
  }

  function sampleField(nx, ny) {
    nx = nx < 0 ? 0 : (nx > 1 ? 1 : nx);
    ny = ny < 0 ? 0 : (ny > 1 ? 1 : ny);

    var fx = nx * (plateW - 1);
    var fy = ny * (plateH - 1);
    var x0 = fx | 0;
    var y0 = fy | 0;
    var x1 = Math.min(plateW - 1, x0 + 1);
    var y1 = Math.min(plateH - 1, y0 + 1);
    var tx = fx - x0;
    var ty = fy - y0;

    var i00 = y0 * plateW + x0;
    var i10 = y0 * plateW + x1;
    var i01 = y1 * plateW + x0;
    var i11 = y1 * plateW + x1;

    var v0 = field[i00] * (1 - tx) + field[i10] * tx;
    var v1 = field[i01] * (1 - tx) + field[i11] * tx;
    return v0 * (1 - ty) + v1 * ty;
  }

  function gradient(nx, ny) {
    var e = 1 / 420;
    var dx = sampleField(nx + e, ny) - sampleField(nx - e, ny);
    var dy = sampleField(nx, ny + e) - sampleField(nx, ny - e);
    return { x: dx, y: dy };
  }

  function pulse(nx, ny, force) {
    var fx = nx - 0.5;
    var fy = ny - 0.5;
    var radial = Math.exp(-(fx * fx + fy * fy) * 7.2);
    impulse = Math.min(1.2, impulse + force * (0.4 + radial * 0.8));
    pointerX = nx;
    pointerY = ny;
  }

  function updateParticles(t) {
    var damp = 1 - parseInt(dampSlider.value, 10) / 1000;
    var attract = 0.0013;
    var jitter = 0.00012;

    impulse *= 0.986;

    var localEnergy = 0;

    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var g = gradient(p.x, p.y);
      var f = sampleField(p.x, p.y);
      var towardNode = -Math.sign(f) * Math.abs(f);

      p.vx += g.x * towardNode * attract;
      p.vy += g.y * towardNode * attract;

      var dx = p.x - pointerX;
      var dy = p.y - pointerY;
      var d2 = dx * dx + dy * dy + 0.00003;
      var pulseForce = impulse / d2 * 0.00002;
      p.vx += dx * pulseForce;
      p.vy += dy * pulseForce;

      p.vx += (Math.random() - 0.5) * jitter;
      p.vy += (Math.random() - 0.5) * jitter;

      p.vx *= damp;
      p.vy *= damp;

      p.x += p.vx;
      p.y += p.vy;

      if (p.x < 0) p.x += 1;
      if (p.x > 1) p.x -= 1;
      if (p.y < 0) p.y += 1;
      if (p.y > 1) p.y -= 1;

      p.life += 0.004 + Math.abs(f) * 0.006;
      if (p.life > 1) p.life -= 1;

      localEnergy += Math.abs(p.vx) + Math.abs(p.vy);
    }

    hudEnergy.textContent = (localEnergy / particles.length * 190).toFixed(2);

    if (autoMode && t - lastPulse > 1900) {
      var nx = 0.5 + Math.sin(t * 0.00019) * 0.32;
      var ny = 0.5 + Math.cos(t * 0.00013) * 0.24;
      pulse(nx, ny, 0.18);
      lastPulse = t;
      if (typeof SFX !== 'undefined') SFX.play('ripple', 120);
    }

    if (autoMode && t - lastAutoSwitch > 9000) {
      lastAutoSwitch = t;
      modeX = 1 + ((modeX + (Math.random() * 3 | 0)) % 9);
      modeY = 1 + ((modeY + 2 + (Math.random() * 4 | 0)) % 9);
      modeXSlider.value = modeX;
      modeYSlider.value = modeY;
      rebuildField();
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    }
  }

  function drawField() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    var scale = Math.min(canvas.width / plateW, canvas.height / plateH) * 0.92;
    ctx.scale(scale, scale);
    ctx.translate(-plateW / 2, -plateH / 2);

    ctx.fillStyle = 'rgba(123,44,191,0.10)';
    ctx.fillRect(0, 0, plateW, plateH);

    ctx.strokeStyle = 'rgba(0,245,212,0.16)';
    ctx.lineWidth = 1.2 / scale;
    ctx.strokeRect(0.5, 0.5, plateW - 1, plateH - 1);

    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var f = Math.abs(sampleField(p.x, p.y));
      var glow = Math.max(0, 1 - f * 9);
      var x = p.x * plateW;
      var y = p.y * plateH;
      var r = 0.42 + glow * 0.95;

      ctx.beginPath();
      ctx.arc(x, y, r, 0, Math.PI * 2);
      if (glow > 0.66) {
        ctx.fillStyle = 'rgba(0,245,212,' + (0.48 + glow * 0.4).toFixed(3) + ')';
      } else {
        ctx.fillStyle = 'rgba(123,44,191,' + (0.14 + glow * 0.28).toFixed(3) + ')';
      }
      ctx.fill();
    }

    var markerX = pointerX * plateW;
    var markerY = pointerY * plateH;
    var pulseR = 4 + impulse * 80;

    ctx.beginPath();
    ctx.arc(markerX, markerY, pulseR, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(255,255,255,0.18)';
    ctx.lineWidth = 1.1 / scale;
    ctx.stroke();

    ctx.restore();
  }

  function getNormFromEvent(e) {
    var p = e.touches ? e.touches[0] : e;
    var rect = canvas.getBoundingClientRect();
    return {
      x: (p.clientX - rect.left) / rect.width,
      y: (p.clientY - rect.top) / rect.height
    };
  }

  canvas.addEventListener('pointerdown', function(e) {
    pointerDown = true;
    var n = getNormFromEvent(e);
    pulse(n.x, n.y, 0.26);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  canvas.addEventListener('pointermove', function(e) {
    if (!pointerDown) return;
    var n = getNormFromEvent(e);
    pulse(n.x, n.y, 0.08);
    if (typeof SFX !== 'undefined') SFX.play('move', 70);
  });

  window.addEventListener('pointerup', function() {
    pointerDown = false;
  });

  canvas.addEventListener('touchstart', function(e) { e.preventDefault(); }, { passive: false });

  modeXSlider.addEventListener('input', function() {
    modeX = parseInt(modeXSlider.value, 10);
    rebuildField();
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  modeYSlider.addEventListener('input', function() {
    modeY = parseInt(modeYSlider.value, 10);
    rebuildField();
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  dampSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 70);
  });

  autoBtn.addEventListener('click', function() {
    autoMode = !autoMode;
    autoBtn.textContent = 'AUTO: ' + (autoMode ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  densityBtn.addEventListener('click', function() {
    densityMode = (densityMode + 1) % 3;
    resetParticles(false);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  clearBtn.addEventListener('click', function() {
    resetParticles(true);
    impulse = 0.18;
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  window.addEventListener('resize', resize);

  function loop(t) {
    updateParticles(t || performance.now());
    drawField();
    requestAnimationFrame(loop);
  }

  resize();
  rebuildField();
  resetParticles(true);
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
