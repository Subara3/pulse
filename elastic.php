<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('elastic');

$pageName = 'elastic';
$pageTitle = 'ELASTIC - PULSE';
$pageDesc = '理想気体の運動論。弾性衝突を繰り返す粒子の速度がマクスウェル・ボルツマン分布に収束する。';
$pageCanonical = 'https://pulse.subara3.com/elastic.php';

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

<div class="hud">
  N: <span id="hudN" class="v">200</span><br>
  &lt;v&gt;: <span id="hudV" class="v">0.00</span><br>
  COLLISIONS: <span id="hudC" class="v">0</span>
</div>

<div id="info">
  kinetic theory · ideal gas<br>
  f(v) = <span class="v">v/σ² · e<sup>−v²/2σ²</sup></span>
</div>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'burst'],
  ['type' => 'drag', 'label' => 'push'],
  ['type' => 'right', 'label' => 'vacuum'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">TEMPERATURE</label>
    <div class="slider-row">
      <input type="range" id="sldT" min="10" max="300" value="100">
      <span class="slider-value" id="valT">100</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PARTICLES</label>
    <div class="slider-row">
      <input type="range" id="sldN" min="20" max="500" value="200">
      <span class="slider-value" id="valN">200</span>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn active" id="btnHist">HISTOGRAM</button>
      <button class="control-btn" id="btnTrail">TRAILS</button>
    </div>
  </div>
  <div class="slider-wrap">
    <div class="btn-row">
      <button class="control-btn" id="btnReset">RESET</button>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>

<script>
(function() {
  'use strict';

  var C = document.getElementById('canvas');
  var ctx = C.getContext('2d');
  var W, H, R = 3, D = 6, D2 = 36;
  var pts = [];
  var temp = 100, goal = 200;
  var hist = true, trails = false;
  var colN = 0;
  var dr = false, dX = 0, dY = 0, dpX = 0, dpY = 0;

  function resize() {
    W = C.width = window.innerWidth;
    H = C.height = window.innerHeight;
    for (var i = 0; i < pts.length; i++) {
      if (pts[i].x < R) pts[i].x = R;
      if (pts[i].x > W - R) pts[i].x = W - R;
      if (pts[i].y < R) pts[i].y = R;
      if (pts[i].y > H - R) pts[i].y = H - R;
    }
  }

  function rv() {
    var s = temp * 0.02;
    var u = Math.random() || 1e-10, v = Math.random();
    var z = Math.sqrt(-2 * Math.log(u));
    return { vx: z * Math.cos(6.2832 * v) * s, vy: z * Math.sin(6.2832 * v) * s };
  }

  function mk(x, y, vx, vy) { return { x: x, y: y, vx: vx, vy: vy }; }

  function init() {
    pts = []; colN = 0;
    for (var i = 0; i < goal; i++) {
      var v = rv();
      pts.push(mk(R + Math.random() * (W - D), R + Math.random() * (H - D), v.vx, v.vy));
    }
  }

  function burst(x, y) {
    if (pts.length > 800) return;
    var sp = temp * 0.04;
    for (var i = 0; i < 12; i++) {
      var a = i / 12 * 6.2832;
      pts.push(mk(x, y, Math.cos(a) * sp, Math.sin(a) * sp));
    }
    sync();
  }

  function vacuum(x, y) {
    pts = pts.filter(function(p) {
      var dx = p.x - x, dy = p.y - y;
      return dx * dx + dy * dy > 3600;
    });
    sync();
  }

  function sync() {
    goal = pts.length;
    var sl = document.getElementById('sldN');
    var v = Math.min(goal, 500);
    sl.value = v;
    document.getElementById('valN').textContent = v;
  }

  function adjust() {
    while (pts.length > goal) pts.pop();
    while (pts.length < goal) {
      var v = rv();
      pts.push(mk(R + Math.random() * (W - D), R + Math.random() * (H - D), v.vx, v.vy));
    }
  }

  function update() {
    var i, j, p, q, dx, dy, d2, d, nx, ny, dvn, ov, n = pts.length;

    for (i = 0; i < n; i++) {
      p = pts[i];
      if (dr) {
        dx = dX - p.x; dy = dY - p.y;
        d2 = dx * dx + dy * dy;
        if (d2 < 14400 && d2 > 1) {
          d = Math.sqrt(d2);
          var f = 0.4 * (1 - d / 120);
          p.vx += dx / d * f;
          p.vy += dy / d * f;
        }
      }
      p.x += p.vx;
      p.y += p.vy;
    }

    for (i = 0; i < n; i++) {
      p = pts[i];
      for (j = i + 1; j < n; j++) {
        q = pts[j];
        dx = q.x - p.x; dy = q.y - p.y;
        d2 = dx * dx + dy * dy;
        if (d2 < D2 && d2 > 0.001) {
          d = Math.sqrt(d2);
          nx = dx / d; ny = dy / d;
          dvn = (p.vx - q.vx) * nx + (p.vy - q.vy) * ny;
          if (dvn > 0) {
            p.vx -= dvn * nx; p.vy -= dvn * ny;
            q.vx += dvn * nx; q.vy += dvn * ny;
            ov = (D - d) * 0.5;
            p.x -= nx * ov; p.y -= ny * ov;
            q.x += nx * ov; q.y += ny * ov;
            colN++;
          }
        }
      }
    }

    for (i = 0; i < n; i++) {
      p = pts[i];
      if (p.x < R) { p.x = R; p.vx = Math.abs(p.vx); }
      else if (p.x > W - R) { p.x = W - R; p.vx = -Math.abs(p.vx); }
      if (p.y < R) { p.y = R; p.vy = Math.abs(p.vy); }
      else if (p.y > H - R) { p.y = H - R; p.vy = -Math.abs(p.vy); }
    }
  }

  function col(spd) {
    var t = Math.min(spd / (temp * 0.06), 1);
    return 'hsl(' + (172 + t * 108) + ',' + (65 + t * 15) + '%,' + (55 - t * 10) + '%)';
  }

  function fmtN(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
    return n;
  }

  function drawHist() {
    if (!hist || pts.length < 2) return;
    var hW = Math.min(240, W * 0.22);
    var hH = Math.min(130, H * 0.15);
    var hX = 16, hY = H - hH - 70;
    if (W < 600) { hW = Math.min(160, W * 0.4); hH = 80; hY = H - hH - 55; }

    var bins = 24, maxS = Math.max(temp * 0.08, 1);
    var b = new Array(bins), k;
    for (k = 0; k < bins; k++) b[k] = 0;
    for (k = 0; k < pts.length; k++) {
      var s = Math.sqrt(pts[k].vx * pts[k].vx + pts[k].vy * pts[k].vy);
      var bi = (s / maxS * bins) | 0;
      if (bi >= bins) bi = bins - 1;
      b[bi]++;
    }
    var mx = 1;
    for (k = 0; k < bins; k++) if (b[k] > mx) mx = b[k];

    ctx.fillStyle = 'rgba(10,10,15,0.75)';
    ctx.fillRect(hX - 2, hY - 2, hW + 4, hH + 4);
    ctx.strokeStyle = 'rgba(0,245,212,0.12)';
    ctx.lineWidth = 0.5;
    ctx.strokeRect(hX - 2, hY - 2, hW + 4, hH + 4);

    var bW = hW / bins;
    for (k = 0; k < bins; k++) {
      var bH = b[k] / mx * (hH - 16);
      var t = k / bins;
      ctx.fillStyle = 'hsla(' + (172 + t * 108) + ',65%,50%,0.5)';
      ctx.fillRect(hX + k * bW, hY + hH - bH, bW - 0.5, bH);
    }

    var sig = temp * 0.02, sig2 = sig * sig, dv = maxS / bins;
    ctx.beginPath();
    ctx.strokeStyle = 'rgba(0,245,212,0.8)';
    ctx.lineWidth = 1.5;
    for (k = 0; k <= 50; k++) {
      var v = k / 50 * maxS;
      var fv = (v / sig2) * Math.exp(-v * v / (2 * sig2));
      var ex = fv * dv * pts.length;
      var px = hX + (v / maxS) * hW;
      var py = hY + hH - ex / mx * (hH - 16);
      if (k === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
    }
    ctx.stroke();

    ctx.fillStyle = 'rgba(255,255,255,0.28)';
    ctx.font = '8px "Orbitron",sans-serif';
    ctx.fillText('SPEED DISTRIBUTION', hX + 2, hY + 10);
  }

  function draw() {
    if (trails) {
      ctx.fillStyle = 'rgba(10,10,15,0.06)';
      ctx.fillRect(0, 0, W, H);
    } else {
      ctx.fillStyle = '#0a0a0f';
      ctx.fillRect(0, 0, W, H);
    }

    ctx.strokeStyle = 'rgba(0,245,212,0.06)';
    ctx.lineWidth = 1;
    ctx.strokeRect(0.5, 0.5, W - 1, H - 1);

    var totS = 0;
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      var s = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      totS += s;
      ctx.beginPath();
      ctx.arc(p.x, p.y, R, 0, 6.2832);
      ctx.fillStyle = col(s);
      ctx.fill();
    }

    if (dr) {
      ctx.beginPath();
      ctx.arc(dX, dY, 120, 0, 6.2832);
      ctx.strokeStyle = 'rgba(0,245,212,0.08)';
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    drawHist();

    document.getElementById('hudN').textContent = pts.length;
    document.getElementById('hudV').textContent = pts.length > 0 ? (totS / pts.length).toFixed(2) : '0';
    document.getElementById('hudC').textContent = fmtN(colN);
  }

  function loop() { update(); draw(); requestAnimationFrame(loop); }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    dr = true; dX = e.clientX; dY = e.clientY; dpX = e.clientX; dpY = e.clientY;
  });
  C.addEventListener('mousemove', function(e) { if (dr) { dX = e.clientX; dY = e.clientY; } });
  C.addEventListener('mouseup', function(e) {
    if (dr) {
      var dx = e.clientX - dpX, dy = e.clientY - dpY;
      if (dx * dx + dy * dy < 36) {
        burst(e.clientX, e.clientY);
        if (typeof SFX !== 'undefined') SFX.play('spawn');
      } else {
        if (typeof SFX !== 'undefined') SFX.play('move', 100);
      }
    }
    dr = false;
  });
  C.addEventListener('mouseleave', function() { dr = false; });
  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    vacuum(e.clientX, e.clientY);
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  var tS = 0, tM = false;
  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var t = e.touches[0];
    dr = true; dX = t.clientX; dY = t.clientY; dpX = t.clientX; dpY = t.clientY;
    tS = Date.now(); tM = false;
  }, { passive: false });
  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (e.touches.length > 0) { dX = e.touches[0].clientX; dY = e.touches[0].clientY; tM = true; }
  }, { passive: false });
  C.addEventListener('touchend', function(e) {
    e.preventDefault();
    var dt = Date.now() - tS;
    if (!tM && dt < 300) {
      burst(dX, dY);
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    } else if (!tM && dt >= 500) {
      vacuum(dX, dY);
      if (typeof SFX !== 'undefined') SFX.play('pop');
    }
    dr = false;
  }, { passive: false });

  document.getElementById('sldT').addEventListener('input', function() {
    var nT = parseInt(this.value);
    var sc = nT / temp;
    temp = nT;
    document.getElementById('valT').textContent = nT;
    for (var i = 0; i < pts.length; i++) {
      pts[i].vx *= sc;
      pts[i].vy *= sc;
    }
  });

  document.getElementById('sldN').addEventListener('input', function() {
    goal = parseInt(this.value);
    document.getElementById('valN').textContent = goal;
    adjust();
  });

  document.getElementById('btnHist').addEventListener('click', function() {
    hist = !hist;
    this.classList.toggle('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnTrail').addEventListener('click', function() {
    trails = !trails;
    this.classList.toggle('active');
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    init();
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  init();
  loop();
})();
</script>

<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
