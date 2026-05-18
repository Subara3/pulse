<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('doppler');

$pageName = 'doppler';
$pageTitle = 'DOPPLER - PULSE';
$pageDesc = 'ドップラー効果。波源が動くと波長が変わる。超音速でマッハコーンが現れる。';
$pageCanonical = 'https://pulse.subara3.com/doppler.php';

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
  MACH <span class="v" id="hud-mach">0.50</span><br>
  WAVES <span class="v" id="hud-waves">0</span><br>
  <span class="v" id="hud-regime">SUBSONIC</span>
</div>

<div id="info">
  <span class="v">doppler effect</span><br>
  f' = f &middot; c / (c &plusmn; v<sub>s</sub>)
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'place'],
  ['type' => 'drag', 'label' => 'move'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">MACH</span>
      <input type="range" id="sl-mach" min="0" max="250" value="50">
      <span class="slider-value" id="v-mach">0.50</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="slider-row">
      <span class="slider-label">WAVE SPEED</span>
      <input type="range" id="sl-c" min="40" max="200" value="100">
      <span class="slider-value" id="v-c">100</span>
    </div>
  </div>
  <div>
    <span class="slider-label">MODE</span>
    <div class="btn-row" id="mode-btns">
      <button class="control-btn active" data-m="orbit">ORBIT</button>
      <button class="control-btn" data-m="linear">LINEAR</button>
      <button class="control-btn" data-m="manual">MANUAL</button>
    </div>
  </div>
  <div class="btn-row">
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
    dpr = window.devicePixelRatio || 1;
    W = window.innerWidth;
    H = window.innerHeight;
    C.width = W * dpr;
    C.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  resize();
  window.addEventListener('resize', resize);

  var mach = 0.50;
  var wSpd = 100;
  var mode = 'orbit';
  var emitHz = 10;
  var maxAge = 7;

  var sx, sy, px, py;
  var waves = [];
  var t = 0, lastEmit = 0;
  var oAngle = 0, oRadius;
  var linDir = 1;
  var dragging = false, dragTX = 0, dragTY = 0;
  var trail = [];
  var resetFlag = false;

  function init() {
    waves = [];
    trail = [];
    t = 0;
    lastEmit = 0;
    oAngle = 0;
    linDir = 1;
    oRadius = Math.min(W, H) * 0.18;
    if (mode === 'orbit') {
      sx = W / 2 + oRadius;
      sy = H / 2;
    } else if (mode === 'linear') {
      sx = W * 0.15;
      sy = H / 2;
    } else {
      sx = W / 2;
      sy = H / 2;
    }
    px = sx;
    py = sy;
    resetFlag = true;
  }
  init();

  function updateSource(dt) {
    px = sx;
    py = sy;
    var speed = mach * wSpd;

    if (dragging) {
      var f = Math.min(12 * dt, 0.85);
      sx += (dragTX - sx) * f;
      sy += (dragTY - sy) * f;
    } else if (mode === 'orbit') {
      oRadius = Math.min(W, H) * 0.18;
      if (oRadius > 10) {
        oAngle += (speed / oRadius) * dt;
      }
      sx = W / 2 + Math.cos(oAngle) * oRadius;
      sy = H / 2 + Math.sin(oAngle) * oRadius;
    } else if (mode === 'linear') {
      sx += linDir * speed * dt;
      sy = H / 2;
      if (sx > W * 0.85) linDir = -1;
      if (sx < W * 0.15) linDir = 1;
    }

    trail.push({ x: sx, y: sy });
    if (trail.length > 300) trail.shift();
  }

  function emitWave() {
    waves.push({ x: sx, y: sy, born: t });
    if (waves.length > 150) waves.shift();
    if (typeof SFX !== 'undefined') SFX.play('ripple', 80);
  }

  function draw() {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    if (mode === 'orbit' && !dragging) {
      ctx.beginPath();
      ctx.arc(W / 2, H / 2, Math.min(W, H) * 0.18, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(255,255,255,0.04)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }
    if (mode === 'linear' && !dragging) {
      ctx.beginPath();
      ctx.moveTo(W * 0.15, H / 2);
      ctx.lineTo(W * 0.85, H / 2);
      ctx.strokeStyle = 'rgba(255,255,255,0.04)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    if (trail.length > 2) {
      ctx.beginPath();
      ctx.moveTo(trail[0].x, trail[0].y);
      for (var i = 1; i < trail.length; i++) ctx.lineTo(trail[i].x, trail[i].y);
      ctx.strokeStyle = 'rgba(0,245,212,0.06)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    for (var i = 0; i < waves.length; i++) {
      var w = waves[i];
      var age = t - w.born;
      var r = wSpd * age;
      var a = 1 - age / maxAge;
      if (a <= 0 || r < 1) continue;
      a *= 0.35;
      ctx.beginPath();
      ctx.arc(w.x, w.y, r, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(0,245,212,' + a.toFixed(3) + ')';
      ctx.lineWidth = 1.2;
      ctx.stroke();
    }

    if (mach > 1.001) {
      var vx = sx - px;
      var vy = sy - py;
      var vLen = Math.sqrt(vx * vx + vy * vy);
      if (vLen > 0.3) {
        var halfA = Math.asin(Math.min(1, 1 / mach));
        var backD = Math.atan2(vy, vx) + Math.PI;
        var cLen = Math.max(W, H) * 1.5;
        var x1 = sx + Math.cos(backD - halfA) * cLen;
        var y1 = sy + Math.sin(backD - halfA) * cLen;
        var x2 = sx + Math.cos(backD + halfA) * cLen;
        var y2 = sy + Math.sin(backD + halfA) * cLen;

        ctx.beginPath();
        ctx.moveTo(sx, sy);
        ctx.lineTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.closePath();
        ctx.fillStyle = 'rgba(123,44,191,0.035)';
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(sx, sy);
        ctx.lineTo(x1, y1);
        ctx.moveTo(sx, sy);
        ctx.lineTo(x2, y2);
        ctx.strokeStyle = 'rgba(123,44,191,0.15)';
        ctx.lineWidth = 8;
        ctx.stroke();
        ctx.strokeStyle = 'rgba(123,44,191,0.55)';
        ctx.lineWidth = 2;
        ctx.stroke();
      }
    }

    var grd = ctx.createRadialGradient(sx, sy, 0, sx, sy, 20);
    grd.addColorStop(0, 'rgba(0,245,212,0.35)');
    grd.addColorStop(1, 'rgba(0,245,212,0)');
    ctx.beginPath();
    ctx.arc(sx, sy, 20, 0, Math.PI * 2);
    ctx.fillStyle = grd;
    ctx.fill();

    ctx.beginPath();
    ctx.arc(sx, sy, 4, 0, Math.PI * 2);
    ctx.fillStyle = '#00f5d4';
    ctx.fill();
  }

  function updateHUD() {
    document.getElementById('hud-mach').textContent = mach.toFixed(2);
    document.getElementById('hud-waves').textContent = waves.length;
    var el = document.getElementById('hud-regime');
    if (mach < 0.95) {
      el.textContent = 'SUBSONIC';
      el.style.color = '#00f5d4';
    } else if (mach < 1.05) {
      el.textContent = 'TRANSONIC';
      el.style.color = '#ffffff';
    } else {
      el.textContent = 'SUPERSONIC';
      el.style.color = '#c77dff';
    }
  }

  var lastTS = 0;
  function loop(ts) {
    if (resetFlag) { lastTS = ts; resetFlag = false; }
    var dt = Math.min((ts - lastTS) / 1000, 0.05);
    lastTS = ts;
    t += dt;

    updateSource(dt);

    var interval = 1 / emitHz;
    if (t - lastEmit > interval * 5) lastEmit = t - interval;
    while (t - lastEmit >= interval) {
      lastEmit += interval;
      emitWave();
    }

    var cutoff = t - maxAge;
    while (waves.length > 0 && waves[0].born < cutoff) waves.shift();

    draw();
    updateHUD();
    requestAnimationFrame(loop);
  }

  document.getElementById('sl-mach').addEventListener('input', function() {
    mach = this.value / 100;
    document.getElementById('v-mach').textContent = mach.toFixed(2);
  });
  document.getElementById('sl-c').addEventListener('input', function() {
    wSpd = +this.value;
    document.getElementById('v-c').textContent = this.value;
  });

  var modeBtns = document.querySelectorAll('#mode-btns .control-btn');
  for (var i = 0; i < modeBtns.length; i++) {
    modeBtns[i].addEventListener('click', function() {
      for (var j = 0; j < modeBtns.length; j++) modeBtns[j].classList.remove('active');
      this.classList.add('active');
      mode = this.dataset.m;
      if (mode === 'orbit') oAngle = Math.atan2(sy - H / 2, sx - W / 2);
      if (mode === 'linear') linDir = 1;
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  }

  document.getElementById('btn-reset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  C.addEventListener('mousedown', function(e) {
    if (e.button !== 0) return;
    dragging = true;
    dragTX = e.clientX;
    dragTY = e.clientY;
    sx = dragTX; sy = dragTY;
    px = sx; py = sy;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });
  C.addEventListener('mousemove', function(e) {
    if (dragging) { dragTX = e.clientX; dragTY = e.clientY; }
  });
  window.addEventListener('mouseup', function() {
    if (dragging) {
      dragging = false;
      if (mode === 'orbit') oAngle = Math.atan2(sy - H / 2, sx - W / 2);
    }
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var p = e.touches[0];
    dragging = true;
    dragTX = p.clientX; dragTY = p.clientY;
    sx = dragTX; sy = dragTY;
    px = sx; py = sy;
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (dragging && e.touches.length) {
      dragTX = e.touches[0].clientX;
      dragTY = e.touches[0].clientY;
    }
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    dragging = false;
    if (mode === 'orbit') oAngle = Math.atan2(sy - H / 2, sx - W / 2);
  }, { passive: false });

  requestAnimationFrame(loop);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
