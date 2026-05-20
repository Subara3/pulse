<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('magnetic');

$pageName = 'magnetic';
$pageTitle = 'MAGNETIC - PULSE';
$pageDesc = '磁場の可視化。双極子が作る閉じた磁力線を粒子の流れで描く。棒磁石を配置して場の構造を観察する。';
$pageCanonical = 'https://pulse.subara3.com/magnetic.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
.ui { position: fixed; z-index: 100; pointer-events: none; font-family: "Orbitron", "Segoe UI", sans-serif; }
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
  MAGNETS: <span id="hudCount" class="v">2</span><br>
  MODE: <span id="hudMode" class="v">FLOW</span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'add magnet'],
  ['type' => 'drag', 'label' => 'move'],
  ['type' => 'right', 'label' => 'remove'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="btn-row">
    <button class="control-btn" id="compassBtn">COMPASS</button>
    <button class="control-btn" id="clearBtn">CLEAR</button>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label>ANGLE</label>
      <input type="range" id="angleSlider" min="0" max="359" value="0">
      <span class="slider-value" id="angleVal">0&deg;</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <label>DENSITY</label>
      <input type="range" id="densitySlider" min="500" max="4000" value="2500">
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudCount = document.getElementById('hudCount');
  var hudMode = document.getElementById('hudMode');
  var compassBtn = document.getElementById('compassBtn');
  var clearBtn = document.getElementById('clearBtn');
  var angleSlider = document.getElementById('angleSlider');
  var angleVal = document.getElementById('angleVal');
  var densitySlider = document.getElementById('densitySlider');

  var DPR = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
  var W = 0, H = 0;
  var MAX_MAGNETS = 8;
  var compassMode = false;

  var magnets = [];
  var particles = [];
  var PARTICLE_COUNT = 2500;
  var SPEED = 2.5;
  var BAR_LEN = 28;
  var BAR_WID = 9;

  var dragging = null;
  var dragOffX = 0, dragOffY = 0;
  var pointerDown = false;
  var pointerStartX = 0, pointerStartY = 0;
  var pointerMoved = false;
  var longPressTimer = null;
  var longPressTriggered = false;
  var selected = -1;

  function resize() {
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  function bField(px, py) {
    var bx = 0, by = 0;
    for (var i = 0; i < magnets.length; i++) {
      var m = magnets[i];
      var mx = Math.cos(m.a);
      var my = Math.sin(m.a);
      var dx = px - m.x;
      var dy = py - m.y;
      var r2 = dx * dx + dy * dy;
      if (r2 < 196) r2 = 196;
      var r = Math.sqrt(r2);
      var r5 = r2 * r2 * r;
      var dot = mx * dx + my * dy;
      bx += (3 * dot * dx - mx * r2) / r5;
      by += (3 * dot * dy - my * r2) / r5;
    }
    return [bx, by];
  }

  function spawn() {
    var x, y;
    if (magnets.length > 0 && Math.random() < 0.4) {
      var m = magnets[Math.floor(Math.random() * magnets.length)];
      var pole = Math.random() < 0.5 ? 1 : -1;
      var dist = 22 + Math.random() * 50;
      x = m.x + Math.cos(m.a) * dist * pole + (Math.random() - 0.5) * 30;
      y = m.y + Math.sin(m.a) * dist * pole + (Math.random() - 0.5) * 30;
    } else {
      x = Math.random() * W;
      y = Math.random() * H;
    }
    return { x: x, y: y, age: 0, life: 100 + Math.random() * 160 };
  }

  function initParticles() {
    particles = [];
    for (var i = 0; i < PARTICLE_COUNT; i++) {
      var p = spawn();
      p.age = Math.random() * p.life;
      particles.push(p);
    }
  }

  function updateParticles() {
    while (particles.length < PARTICLE_COUNT) particles.push(spawn());
    while (particles.length > PARTICLE_COUNT) particles.pop();
    if (magnets.length === 0) return;

    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      p.age++;

      if (p.age > p.life || p.x < -40 || p.x > W + 40 || p.y < -40 || p.y > H + 40) {
        var n = spawn(); p.x = n.x; p.y = n.y; p.age = 0; p.life = n.life;
        continue;
      }

      var tooClose = false;
      for (var j = 0; j < magnets.length; j++) {
        var dx = p.x - magnets[j].x, dy = p.y - magnets[j].y;
        if (dx * dx + dy * dy < 225) { tooClose = true; break; }
      }
      if (tooClose) {
        var n = spawn(); p.x = n.x; p.y = n.y; p.age = 0; p.life = n.life;
        continue;
      }

      var b = bField(p.x, p.y);
      var mag = Math.sqrt(b[0] * b[0] + b[1] * b[1]);
      if (mag > 1e-8) {
        var spd = SPEED * Math.min(2, Math.max(0.15, mag * 120000));
        p.x += (b[0] / mag) * spd;
        p.y += (b[1] / mag) * spd;
      } else {
        p.age += 4;
      }
    }
  }

  function draw() {
    if (compassMode) {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, W, H);
      drawCompass();
      drawParticles(0.2);
    } else {
      ctx.fillStyle = 'rgba(10,10,15,0.055)';
      ctx.fillRect(0, 0, W, H);
      drawParticles(1);
    }
    drawMagnets();
  }

  function drawParticles(aScale) {
    if (magnets.length === 0) return;
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var t = p.age / p.life;
      var a;
      if (t < 0.06) a = t / 0.06;
      else if (t > 0.75) a = (1 - t) / 0.25;
      else a = 1;
      a *= 0.65 * aScale;
      if (a < 0.015) continue;
      ctx.fillStyle = 'rgba(0,210,185,' + a.toFixed(3) + ')';
      ctx.fillRect(p.x - 0.7, p.y - 0.7, 1.4, 1.4);
    }
  }

  function drawCompass() {
    if (magnets.length === 0) return;
    var step = 32;
    var cols = Math.ceil(W / step) + 1;
    var rows = Math.ceil(H / step) + 1;
    ctx.lineCap = 'round';

    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        var px = c * step + step * 0.5;
        var py = r * step + step * 0.5;
        var skip = false;
        for (var k = 0; k < magnets.length; k++) {
          var dx = px - magnets[k].x, dy = py - magnets[k].y;
          if (dx * dx + dy * dy < 1024) { skip = true; break; }
        }
        if (skip) continue;

        var b = bField(px, py);
        var mag = Math.sqrt(b[0] * b[0] + b[1] * b[1]);
        if (mag < 1e-9) continue;

        var ang = Math.atan2(b[1], b[0]);
        var str = Math.min(1, mag * 60000);
        var len = 5 + str * 9;
        var alpha = (0.12 + str * 0.55).toFixed(3);
        var g = Math.floor(170 + str * 75);
        var bl = Math.floor(165 + str * 47);

        ctx.strokeStyle = 'rgba(0,' + g + ',' + bl + ',' + alpha + ')';
        ctx.lineWidth = 0.8 + str * 0.6;
        ctx.beginPath();
        var cx = Math.cos(ang), sy = Math.sin(ang);
        ctx.moveTo(px - cx * len * 0.35, py - sy * len * 0.35);
        ctx.lineTo(px + cx * len * 0.65, py + sy * len * 0.65);
        ctx.stroke();

        var tx = px + cx * len * 0.65, ty = py + sy * len * 0.65;
        var as = 2.5 + str * 1.5;
        ctx.beginPath();
        ctx.moveTo(tx, ty);
        ctx.lineTo(tx - Math.cos(ang + 0.5) * as, ty - Math.sin(ang + 0.5) * as);
        ctx.moveTo(tx, ty);
        ctx.lineTo(tx - Math.cos(ang - 0.5) * as, ty - Math.sin(ang - 0.5) * as);
        ctx.stroke();
      }
    }
  }

  function drawMagnets() {
    var pulse = 0.7 + 0.3 * Math.sin(Date.now() * 0.003);
    for (var i = 0; i < magnets.length; i++) {
      var m = magnets[i];
      ctx.save();
      ctx.translate(m.x, m.y);
      ctx.rotate(m.a);

      var gN = ctx.createRadialGradient(BAR_LEN * 0.6, 0, 0, BAR_LEN * 0.6, 0, 55);
      gN.addColorStop(0, 'rgba(0,245,212,' + (0.18 * pulse).toFixed(3) + ')');
      gN.addColorStop(1, 'rgba(0,245,212,0)');
      ctx.fillStyle = gN;
      ctx.beginPath();
      ctx.arc(BAR_LEN * 0.6, 0, 55, 0, Math.PI * 2);
      ctx.fill();

      var gS = ctx.createRadialGradient(-BAR_LEN * 0.6, 0, 0, -BAR_LEN * 0.6, 0, 55);
      gS.addColorStop(0, 'rgba(123,44,191,' + (0.18 * pulse).toFixed(3) + ')');
      gS.addColorStop(1, 'rgba(123,44,191,0)');
      ctx.fillStyle = gS;
      ctx.beginPath();
      ctx.arc(-BAR_LEN * 0.6, 0, 55, 0, Math.PI * 2);
      ctx.fill();

      ctx.fillStyle = 'rgba(0,245,212,0.25)';
      ctx.fillRect(0, -BAR_WID, BAR_LEN, BAR_WID * 2);
      ctx.fillStyle = 'rgba(123,44,191,0.25)';
      ctx.fillRect(-BAR_LEN, -BAR_WID, BAR_LEN, BAR_WID * 2);

      ctx.strokeStyle = 'rgba(255,255,255,0.18)';
      ctx.lineWidth = 1;
      ctx.strokeRect(-BAR_LEN, -BAR_WID, BAR_LEN * 2, BAR_WID * 2);
      ctx.beginPath();
      ctx.moveTo(0, -BAR_WID);
      ctx.lineTo(0, BAR_WID);
      ctx.stroke();

      ctx.font = 'bold 8px "Orbitron",sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = '#00f5d4';
      ctx.fillText('N', BAR_LEN * 0.5, 1);
      ctx.fillStyle = '#7b2cbf';
      ctx.fillText('S', -BAR_LEN * 0.5, 1);

      if (i === selected) {
        ctx.strokeStyle = 'rgba(0,245,212,0.45)';
        ctx.lineWidth = 1.2;
        ctx.setLineDash([3, 3]);
        ctx.strokeRect(-BAR_LEN - 4, -BAR_WID - 4, BAR_LEN * 2 + 8, BAR_WID * 2 + 8);
        ctx.setLineDash([]);
      }

      ctx.restore();
    }
  }

  function nearest(px, py, thresh) {
    var best = -1, bd = thresh * thresh;
    for (var i = 0; i < magnets.length; i++) {
      var dx = px - magnets[i].x, dy = py - magnets[i].y;
      var d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  function addMagnet(x, y) {
    if (magnets.length >= MAX_MAGNETS) return;
    var a = parseFloat(angleSlider.value) * Math.PI / 180;
    magnets.push({ x: x, y: y, a: a });
    selected = magnets.length - 1;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function removeMagnet(idx) {
    magnets.splice(idx, 1);
    if (selected >= magnets.length) selected = magnets.length - 1;
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('pop');
  }

  function updateHUD() {
    hudCount.textContent = magnets.length;
    hudMode.textContent = compassMode ? 'COMPASS' : 'FLOW';
  }

  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }

  canvas.addEventListener('pointerdown', function(e) {
    if (e.button === 2) return;
    var p = pos(e);
    pointerDown = true;
    pointerStartX = p.x; pointerStartY = p.y;
    pointerMoved = false;
    longPressTriggered = false;

    var idx = nearest(p.x, p.y, 38);
    if (idx >= 0) {
      dragging = idx;
      selected = idx;
      dragOffX = magnets[idx].x - p.x;
      dragOffY = magnets[idx].y - p.y;
      angleSlider.value = Math.round(magnets[idx].a * 180 / Math.PI + 360) % 360;
      angleVal.innerHTML = angleSlider.value + '&deg;';
      canvas.setPointerCapture(e.pointerId);
      return;
    }

    longPressTimer = setTimeout(function() {
      var idx2 = nearest(p.x, p.y, 50);
      if (idx2 >= 0) {
        dragging = idx2; selected = idx2;
        dragOffX = magnets[idx2].x - p.x;
        dragOffY = magnets[idx2].y - p.y;
        longPressTriggered = true;
      }
    }, 400);
  });

  canvas.addEventListener('pointermove', function(e) {
    var p = pos(e);
    if (dragging !== null && dragging < magnets.length) {
      magnets[dragging].x = p.x + dragOffX;
      magnets[dragging].y = p.y + dragOffY;
      pointerMoved = true;
      if (typeof SFX !== 'undefined') SFX.play('move', 80);
      return;
    }
    if (pointerDown) {
      var dx = p.x - pointerStartX, dy = p.y - pointerStartY;
      if (dx * dx + dy * dy > 25) {
        pointerMoved = true;
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
      }
    }
  });

  canvas.addEventListener('pointerup', function(e) {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    if (dragging !== null) { dragging = null; pointerDown = false; return; }
    if (pointerDown && !pointerMoved && !longPressTriggered) {
      var p = pos(e);
      addMagnet(p.x, p.y);
    }
    pointerDown = false; dragging = null;
  });

  canvas.addEventListener('pointercancel', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    pointerDown = false; dragging = null;
  });

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var p = pos(e);
    var idx = nearest(p.x, p.y, 50);
    if (idx >= 0) removeMagnet(idx);
  });

  compassBtn.addEventListener('click', function() {
    compassMode = !compassMode;
    compassBtn.classList.toggle('active', compassMode);
    updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  clearBtn.addEventListener('click', function() {
    magnets = []; selected = -1; updateHUD();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  angleSlider.addEventListener('input', function() {
    var deg = parseInt(this.value, 10);
    angleVal.innerHTML = deg + '&deg;';
    if (selected >= 0 && selected < magnets.length) {
      magnets[selected].a = deg * Math.PI / 180;
    }
    if (typeof SFX !== 'undefined') SFX.play('tick', 60);
  });

  densitySlider.addEventListener('input', function() {
    PARTICLE_COUNT = parseInt(this.value, 10);
    if (typeof SFX !== 'undefined') SFX.play('tick', 60);
  });

  window.addEventListener('resize', resize);

  function seed() {
    var cx = W / 2, cy = H / 2;
    var sep = Math.min(W, H) * 0.2;
    magnets.push({ x: cx - sep, y: cy, a: 0 });
    magnets.push({ x: cx + sep, y: cy, a: 0 });
    selected = 1;
    updateHUD();
  }

  function loop() {
    updateParticles();
    draw();
    requestAnimationFrame(loop);
  }

  resize();
  seed();
  initParticles();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
