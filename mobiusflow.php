<?php
require_once __DIR__ . '/includes/config.php';
recordPageView('mobiusflow');

$pageName = 'mobiusflow';
$pageTitle = 'MOBIUS FLOW - PULSE';
$pageDesc = 'メビウスの帯を流れる粒子。ねじれた面の上で光の航跡がゆっくり巡る。';
$pageCanonical = 'https://pulse.subara3.com/mobiusflow.php';

$extraStyle = '
body { background: #0a0a0f; min-height: 100vh; overflow: hidden; }
#canvas { position: fixed; inset: 0; width: 100%; height: 100%; touch-action: none; }
.ui { position: fixed; z-index: 10; pointer-events: none; font-family: "Space Grotesk", "Segoe UI", sans-serif; letter-spacing: 0.05em; }
.hud { top: 1rem; right: 1rem; color: rgba(255,255,255,0.35); font-size: 0.62rem; line-height: 1.8; text-align: right; }
.hud .v { color: #00f5d4; font-weight: 700; }
.info { left: 1rem; bottom: 1rem; color: rgba(255,255,255,0.3); font-size: 0.56rem; line-height: 1.7; max-width: min(72vw, 460px); }
@media (max-width: 768px) {
  .hud { top: 0.7rem; right: 0.7rem; font-size: 0.54rem; }
  .info { left: 0.75rem; bottom: 0.75rem; font-size: 0.5rem; max-width: 70vw; }
}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div class="ui hud">
  TWIST: <span class="v" id="hudTwist">1.00</span><br>
  PARTICLES: <span class="v" id="hudParticles">0</span><br>
  SPEED: <span class="v" id="hudSpeed">0.34</span>
</div>

<div class="ui info">
  TAP: DROP LIGHT<br>
  DRAG: ORBIT CAMERA<br>
  RIGHT-CLICK: CLEAR TRAILS<br>
  A SINGLE SURFACE, A TWISTED LOOP
</div>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label>TWIST</label>
    <input type="range" id="twistSlider" min="0" max="200" value="100">
  </div>
  <div class="slider-wrap">
    <label>FLOW SPEED</label>
    <input type="range" id="speedSlider" min="8" max="80" value="34">
  </div>
  <div class="slider-wrap">
    <label>RIBBON WIDTH</label>
    <input type="range" id="widthSlider" min="8" max="90" value="42">
  </div>
  <div class="btn-row">
    <button class="control-btn" id="autoBtn">AUTO: ON</button>
    <button class="control-btn" id="spawnBtn">SPAWN BURST</button>
    <button class="control-btn" id="resetBtn">RESET</button>
  </div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'drop light'],
  ['type' => 'drag', 'label' => 'orbit'],
  ['type' => 'right', 'label' => 'clear']
];
require_once __DIR__ . '/includes/interaction-hints.php';
require_once __DIR__ . '/includes/drawer-controls.php';
?>

<script>
(function() {
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d', { alpha: false });

  var hudTwist = document.getElementById('hudTwist');
  var hudParticles = document.getElementById('hudParticles');
  var hudSpeed = document.getElementById('hudSpeed');

  var twistSlider = document.getElementById('twistSlider');
  var speedSlider = document.getElementById('speedSlider');
  var widthSlider = document.getElementById('widthSlider');
  var autoBtn = document.getElementById('autoBtn');
  var spawnBtn = document.getElementById('spawnBtn');
  var resetBtn = document.getElementById('resetBtn');

  var W = 0, H = 0, DPR = 1;
  var cx = 0, cy = 0;
  var time = 0;
  var yaw = 0.3;
  var pitch = 0.35;
  var autoOrbit = true;
  var dragging = false;
  var dragId = null;
  var lastX = 0, lastY = 0;

  var particles = [];
  var maxParticles = 380;

  function rgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function mix(a, b, t) {
    return [
      Math.round(a[0] + (b[0] - a[0]) * t),
      Math.round(a[1] + (b[1] - a[1]) * t),
      Math.round(a[2] + (b[2] - a[2]) * t)
    ];
  }

  function resize() {
    DPR = Math.min(2, window.devicePixelRatio || 1);
    W = Math.floor(window.innerWidth);
    H = Math.floor(window.innerHeight);
    canvas.width = Math.floor(W * DPR);
    canvas.height = Math.floor(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    cx = W * 0.5;
    cy = H * 0.52;
  }

  function mapToStrip(u, v, twist) {
    var R = Math.min(W, H) * 0.24;
    var halfW = parseFloat(widthSlider.value) * 0.5;
    var t = u * Math.PI * 2;
    var h = twist * 0.5 * t;
    var ch = Math.cos(h), sh = Math.sin(h);
    var x = (R + v * ch) * Math.cos(t);
    var y = (R + v * ch) * Math.sin(t);
    var z = v * sh;
    return [x, y, z];
  }

  function rotate3(p) {
    var x = p[0], y = p[1], z = p[2];
    var cyaw = Math.cos(yaw), syaw = Math.sin(yaw);
    var cp = Math.cos(pitch), sp = Math.sin(pitch);

    var x1 = x * cyaw - z * syaw;
    var z1 = x * syaw + z * cyaw;
    var y1 = y * cp - z1 * sp;
    var z2 = y * sp + z1 * cp;
    return [x1, y1, z2];
  }

  function project(p) {
    var r = rotate3(p);
    var f = 360 / (360 + r[2] + 220);
    return [cx + r[0] * f, cy + r[1] * f, f, r[2]];
  }

  function spawn(count, burstU) {
    var twist = parseFloat(twistSlider.value) / 100;
    var flow = parseFloat(speedSlider.value) / 100;
    for (var i = 0; i < count; i++) {
      if (particles.length >= maxParticles) particles.shift();
      var u = burstU != null ? (burstU + (Math.random() - 0.5) * 0.09 + 1) % 1 : Math.random();
      var v = (Math.random() * 2 - 1) * parseFloat(widthSlider.value) * 0.5;
      particles.push({
        u: u,
        v: v,
        du: (0.00065 + Math.random() * 0.0016) * flow,
        phase: Math.random() * Math.PI * 2,
        age: 0,
        life: 320 + Math.random() * 400,
        trail: [],
        col: mix([0, 245, 212], [123, 44, 191], Math.random())
      });
    }
    hudParticles.textContent = particles.length;
    if (typeof SFX !== 'undefined') SFX.play('spawn', 40);
  }

  function resetScene() {
    particles = [];
    spawn(150);
    if (typeof SFX !== 'undefined') SFX.play('click');
  }

  function update(dt) {
    var twist = parseFloat(twistSlider.value) / 100;
    var flow = parseFloat(speedSlider.value) / 100;
    hudTwist.textContent = twist.toFixed(2);
    hudSpeed.textContent = flow.toFixed(2);

    if (autoOrbit && !dragging) yaw += dt * 0.00013;

    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];
      p.age += dt * 0.06;
      p.u = (p.u + p.du * dt * 0.03 + 1) % 1;
      p.v += Math.sin((time * 0.00028) + p.phase + p.u * Math.PI * 6) * dt * 0.004;
      var limit = parseFloat(widthSlider.value) * 0.5;
      if (p.v > limit) p.v = limit;
      if (p.v < -limit) p.v = -limit;

      var world = mapToStrip(p.u, p.v, twist);
      var s = project(world);
      p.trail.push(s[0], s[1], s[2], s[3]);
      if (p.trail.length > 56) p.trail.splice(0, p.trail.length - 56);

      if (p.age > p.life) particles.splice(i, 1);
    }

    if (particles.length < 120) spawn(10);
    hudParticles.textContent = particles.length;
  }

  function drawRibbon() {
    var twist = parseFloat(twistSlider.value) / 100;
    var width = parseFloat(widthSlider.value);

    ctx.save();
    ctx.globalCompositeOperation = 'source-over';
    for (var i = 0; i < 90; i++) {
      var u0 = i / 90;
      var u1 = (i + 1) / 90;
      var a = project(mapToStrip(u0, -width * 0.5, twist));
      var b = project(mapToStrip(u0, width * 0.5, twist));
      var c = project(mapToStrip(u1, width * 0.5, twist));
      var d = project(mapToStrip(u1, -width * 0.5, twist));

      var blend = 0.5 + 0.5 * Math.sin(u0 * Math.PI * 2 + time * 0.00008);
      var col = mix([0, 245, 212], [123, 44, 191], blend);
      var alpha = 0.08 + 0.08 * (a[2] + b[2]);

      ctx.fillStyle = rgba(col, alpha);
      ctx.beginPath();
      ctx.moveTo(a[0], a[1]);
      ctx.lineTo(b[0], b[1]);
      ctx.lineTo(c[0], c[1]);
      ctx.lineTo(d[0], d[1]);
      ctx.closePath();
      ctx.fill();

      ctx.strokeStyle = rgba(col, alpha * 1.8);
      ctx.lineWidth = 0.6;
      ctx.stroke();
    }
    ctx.restore();
  }

  function drawParticles() {
    ctx.globalCompositeOperation = 'lighter';
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      var tr = p.trail;
      if (tr.length < 8) continue;
      for (var k = 4; k < tr.length; k += 4) {
        var t = k / tr.length;
        var alpha = 0.06 + 0.32 * t;
        ctx.strokeStyle = rgba(p.col, alpha);
        ctx.lineWidth = 0.8 + t * 1.8;
        ctx.beginPath();
        ctx.moveTo(tr[k - 4], tr[k - 3]);
        ctx.lineTo(tr[k], tr[k + 1]);
        ctx.stroke();
      }
      var x = tr[tr.length - 4], y = tr[tr.length - 3], f = tr[tr.length - 2];
      ctx.fillStyle = rgba(p.col, 0.8);
      ctx.beginPath();
      ctx.arc(x, y, 1.2 + f * 2.2, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalCompositeOperation = 'source-over';
  }

  function draw() {
    ctx.fillStyle = 'rgba(10,10,15,0.18)';
    ctx.fillRect(0, 0, W, H);

    var g = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.max(W, H) * 0.7);
    g.addColorStop(0, 'rgba(20,36,58,0.18)');
    g.addColorStop(1, 'rgba(10,10,15,0.0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    drawRibbon();
    drawParticles();
  }

  function pointerDown(e) {
    dragging = true;
    dragId = e.pointerId;
    lastX = e.clientX;
    lastY = e.clientY;
    canvas.setPointerCapture(e.pointerId);
  }

  function pointerMove(e) {
    if (!dragging || e.pointerId !== dragId) return;
    var dx = e.clientX - lastX;
    var dy = e.clientY - lastY;
    yaw += dx * 0.006;
    pitch += dy * 0.004;
    pitch = Math.max(-1.15, Math.min(1.15, pitch));
    lastX = e.clientX;
    lastY = e.clientY;
    if (typeof SFX !== 'undefined') SFX.play('move', 90);
  }

  function pointerUp(e) {
    if (e.pointerId !== dragId) return;
    dragging = false;
    dragId = null;
  }

  canvas.addEventListener('click', function(e) {
    var burstU = ((e.clientX / W) + (time * 0.00002)) % 1;
    spawn(26, burstU);
  });

  canvas.addEventListener('pointerdown', pointerDown);
  canvas.addEventListener('pointermove', pointerMove);
  canvas.addEventListener('pointerup', pointerUp);
  canvas.addEventListener('pointercancel', pointerUp);

  canvas.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    for (var i = 0; i < particles.length; i++) particles[i].trail = [];
    if (typeof SFX !== 'undefined') SFX.play('ripple', 60);
  });

  autoBtn.addEventListener('click', function() {
    autoOrbit = !autoOrbit;
    autoBtn.textContent = 'AUTO: ' + (autoOrbit ? 'ON' : 'OFF');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  spawnBtn.addEventListener('click', function() { spawn(120); });

  resetBtn.addEventListener('click', function() {
    yaw = 0.3;
    pitch = 0.35;
    twistSlider.value = 100;
    speedSlider.value = 34;
    widthSlider.value = 42;
    resetScene();
  });

  twistSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  speedSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });
  widthSlider.addEventListener('input', function() {
    if (typeof SFX !== 'undefined') SFX.play('tick', 80);
  });

  window.addEventListener('resize', resize);
  resize();
  resetScene();

  var prev = performance.now();
  function frame(now) {
    var dt = Math.min(40, now - prev);
    prev = now;
    time += dt;
    update(dt);
    draw();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
