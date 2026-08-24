<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kaleidoscope');

$pageName = 'kaleidoscope';
$pageTitle = 'KALEIDOSCOPE - PULSE';
$pageDesc = '万華鏡。二面体群D_Nの対称性で粒子の軌跡が反射・回転し、幾何学模様が浮かび上がる。';
$pageCanonical = 'https://pulse.subara3.com/kaleidoscope.php';

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
  D<sub><span id="hudN" class="v">6</span></sub><br>
  PARTICLES: <span id="hudP" class="v">0</span>
</div>

<div id="info">
  dihedral group D<sub><span class="v" id="infoN">6</span></sub><br>
  <span class="v" id="infoM">12</span> symmetry copies
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'paint'],
  ['type' => 'right', 'label' => 'clear'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">SYMMETRY</label>
    <div class="btn-row" id="symBtns">
      <button class="control-btn" data-n="3">3</button>
      <button class="control-btn" data-n="4">4</button>
      <button class="control-btn" data-n="5">5</button>
      <button class="control-btn active" data-n="6">6</button>
      <button class="control-btn" data-n="8">8</button>
      <button class="control-btn" data-n="12">12</button>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">FADE</label>
    <div class="slider-row">
      <input type="range" id="fadeSlider" min="1" max="10" value="4">
      <span class="slider-value" id="fadeVal">4</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="autoBtn">AUTO</button>
      <button class="control-btn" id="clearBtn">CLEAR</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var TC = document.createElement('canvas');
  var tctx = TC.getContext('2d');

  var W, H, cx, cy, R;
  var symN = 6;
  var fadeRate = 0.02;
  var autoOn = true;
  var particles = [];
  var painting = false;
  var frame = 0;

  function resize() {
    W = C.width = TC.width = window.innerWidth;
    H = C.height = TC.height = window.innerHeight;
    cx = W / 2;
    cy = H / 2;
    R = Math.min(W, H) * 0.42;
    tctx.fillStyle = '#0a0a0f';
    tctx.fillRect(0, 0, W, H);
  }

  window.addEventListener('resize', resize);
  resize();

  function mkP(x, y, vx, vy) {
    var d = Math.sqrt(x * x + y * y);
    var t = Math.min(d / R, 1);
    return {
      x: x, y: y,
      vx: vx !== undefined ? vx : (Math.random() - 0.5) * 2,
      vy: vy !== undefined ? vy : (Math.random() - 0.5) * 2,
      life: 1,
      decay: 0.002 + Math.random() * 0.004,
      size: 1.2 + Math.random() * 1.8,
      hue: 175 + t * 110
    };
  }

  function drawSym(x, y, sz) {
    var step = 2 * Math.PI / symN;
    for (var k = 0; k < symN; k++) {
      var c = Math.cos(k * step), s = Math.sin(k * step);
      tctx.beginPath();
      tctx.arc(cx + x * c - y * s, cy + x * s + y * c, sz, 0, 6.283);
      tctx.fill();
      tctx.beginPath();
      tctx.arc(cx + x * c + y * s, cy + x * s - y * c, sz, 0, 6.283);
      tctx.fill();
    }
  }

  function spawnBurst(sx, sy, n) {
    var dx = sx - cx, dy = sy - cy;
    n = n || 10;
    for (var i = 0; i < n; i++) {
      var a = Math.random() * 6.283;
      var v = 0.5 + Math.random() * 2;
      if (particles.length < 500) {
        particles.push(mkP(dx, dy, Math.cos(a) * v, Math.sin(a) * v));
      }
    }
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }

  function tick() {
    frame++;

    if (autoOn && frame % 4 === 0 && particles.length < 350) {
      var t = frame * 0.008;
      var sr = R * (0.08 + 0.18 * (Math.sin(t * 0.37) * 0.5 + 0.5));
      var sa = t * 0.6;
      var va = Math.random() * 6.283;
      var vs = 0.3 + Math.random() * 1.5;
      particles.push(mkP(
        Math.cos(sa) * sr, Math.sin(sa) * sr,
        Math.cos(va) * vs, Math.sin(va) * vs
      ));
    }

    tctx.fillStyle = 'rgba(10,10,15,' + fadeRate + ')';
    tctx.fillRect(0, 0, W, H);

    tctx.globalCompositeOperation = 'lighter';
    for (var i = particles.length - 1; i >= 0; i--) {
      var p = particles[i];

      var a = Math.atan2(p.vy, p.vx) + (Math.random() - 0.5) * 0.15;
      var spd = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      p.vx = Math.cos(a) * spd;
      p.vy = Math.sin(a) * spd;

      p.x += p.vx;
      p.y += p.vy;
      p.life -= p.decay;

      var dist = Math.sqrt(p.x * p.x + p.y * p.y);
      if (dist > R) {
        var nx = p.x / dist, ny = p.y / dist;
        var dot = p.vx * nx + p.vy * ny;
        p.vx -= 2 * dot * nx;
        p.vy -= 2 * dot * ny;
        p.x = nx * (R - 1);
        p.y = ny * (R - 1);
      }

      if (p.life <= 0) {
        particles.splice(i, 1);
        continue;
      }

      var dt = Math.min(Math.sqrt(p.x * p.x + p.y * p.y) / R, 1);
      p.hue = 175 + dt * 110;

      var al = p.life * 0.3;
      tctx.fillStyle = 'hsla(' + Math.round(p.hue) + ',80%,55%,' + al.toFixed(3) + ')';
      drawSym(p.x, p.y, p.size * p.life);
    }
    tctx.globalCompositeOperation = 'source-over';

    ctx.drawImage(TC, 0, 0);

    ctx.strokeStyle = 'rgba(0,245,212,0.07)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, 6.283);
    ctx.stroke();

    ctx.strokeStyle = 'rgba(0,245,212,0.025)';
    ctx.lineWidth = 0.5;
    for (var k = 0; k < symN; k++) {
      var la = k * Math.PI / symN;
      ctx.beginPath();
      ctx.moveTo(cx + Math.cos(la) * R, cy + Math.sin(la) * R);
      ctx.lineTo(cx - Math.cos(la) * R, cy - Math.sin(la) * R);
      ctx.stroke();
    }

    document.getElementById('hudP').textContent = particles.length;
    requestAnimationFrame(tick);
  }

  for (var i = 0; i < 20; i++) {
    var ia = Math.random() * 6.283;
    var id = Math.random() * R * 0.25;
    particles.push(mkP(Math.cos(ia) * id, Math.sin(ia) * id));
  }

  tick();

  function pos(e) {
    if (e.touches) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    return { x: e.clientX, y: e.clientY };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    painting = true;
    var p = pos(e);
    spawnBurst(p.x, p.y, 8);
  });

  C.addEventListener('mousemove', function(e) {
    if (!painting) return;
    var p = pos(e);
    if (particles.length < 500) {
      particles.push(mkP(p.x - cx, p.y - cy, (Math.random() - 0.5) * 0.5, (Math.random() - 0.5) * 0.5));
    }
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  });

  C.addEventListener('mouseup', function() { painting = false; });
  C.addEventListener('mouseleave', function() { painting = false; });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    painting = true;
    var p = pos(e);
    spawnBurst(p.x, p.y, 8);
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!painting) return;
    var p = pos(e);
    if (particles.length < 500) {
      particles.push(mkP(p.x - cx, p.y - cy, (Math.random() - 0.5) * 0.5, (Math.random() - 0.5) * 0.5));
    }
    if (typeof SFX !== 'undefined') SFX.play('move', 80);
  }, { passive: false });

  C.addEventListener('touchend', function() { painting = false; });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    particles = [];
    tctx.fillStyle = '#0a0a0f';
    tctx.fillRect(0, 0, W, H);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  document.getElementById('symBtns').addEventListener('click', function(e) {
    var btn = e.target.closest('[data-n]');
    if (!btn) return;
    symN = parseInt(btn.dataset.n);
    var all = document.querySelectorAll('#symBtns .control-btn');
    for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
    btn.classList.add('active');
    document.getElementById('hudN').textContent = symN;
    document.getElementById('infoN').textContent = symN;
    document.getElementById('infoM').textContent = symN * 2;
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('fadeSlider').addEventListener('input', function() {
    fadeRate = parseInt(this.value) * 0.005;
    document.getElementById('fadeVal').textContent = this.value;
  });

  document.getElementById('autoBtn').addEventListener('click', function() {
    autoOn = !autoOn;
    if (autoOn) this.classList.add('active');
    else this.classList.remove('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('clearBtn').addEventListener('click', function() {
    particles = [];
    tctx.fillStyle = '#0a0a0f';
    tctx.fillRect(0, 0, W, H);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
