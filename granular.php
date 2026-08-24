<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('granular');

$pageName = 'granular';
$pageTitle = 'GRANULAR - PULSE';
$pageDesc = '粒状体の力学。ファネルを流れる粒子が力の鎖を描き、開口幅がジャミング転移を決める。';
$pageCanonical = 'https://pulse.subara3.com/granular.php';
$extraStyle = '
  #hud { position:fixed; top:80px; right:20px; color:rgba(0,245,212,0.6); font:12px "Space Grotesk",monospace; text-align:right; pointer-events:none; z-index:10; line-height:1.8; }
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>
<div id="hud">
  <div>PARTICLES <span id="hud-n">0</span></div>
  <div>CONTACTS <span id="hud-c">0</span></div>
</div>
<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'pour'],
  ['type' => 'drag', 'label' => 'tilt'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>
<div class="drawer-controls">
  <div class="slider-row">
    <label class="slider-label">NECK</label>
    <input type="range" id="sl-neck" min="2" max="12" value="5">
    <span class="slider-value" id="sv-neck">5</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">SIZE</label>
    <input type="range" id="sl-size" min="3" max="8" value="5">
    <span class="slider-value" id="sv-size">5</span>
  </div>
  <div class="slider-row">
    <label class="slider-label">GRAVITY</label>
    <input type="range" id="sl-grav" min="1" max="20" value="10">
    <span class="slider-value" id="sv-grav">10</span>
  </div>
  <div class="btn-row">
    <button class="ctrl-btn active" id="btn-pour">POUR</button>
    <button class="ctrl-btn" id="btn-shake">SHAKE</button>
    <button class="ctrl-btn active" id="btn-chains">CHAINS</button>
    <button class="ctrl-btn" id="btn-reset">RESET</button>
  </div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<script>
(function(){
  var C = document.getElementById('canvas'), X = C.getContext('2d');
  var W, H;

  function Pt(x, y, r) {
    this.x = x; this.y = y;
    this.ox = x; this.oy = y;
    this.r = r; this.f = 0;
  }

  var ps = [], cts = [], ws = [];
  var MAX = 500, R = 5, neckR = 5;
  var gMag = 800, gx = 0, gy = 1, tgx = 0, tgy = 1;
  var chains = true, autoP = true, shakeT = 0, frame = 0;
  var DT = 1 / 60, DAMP = 0.985, ITER = 4;

  var hudN = document.getElementById('hud-n');
  var hudC = document.getElementById('hud-c');

  var hSz, hMap;
  function buildH() {
    hSz = R * 2.5;
    hMap = {};
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      var k = (p.x / hSz | 0) + '|' + (p.y / hSz | 0);
      if (!hMap[k]) hMap[k] = [];
      hMap[k].push(i);
    }
  }

  function near(i) {
    var p = ps[i], kx = p.x / hSz | 0, ky = p.y / hSz | 0, res = [];
    for (var dy = -1; dy <= 1; dy++)
      for (var dx = -1; dx <= 1; dx++) {
        var b = hMap[(kx + dx) + '|' + (ky + dy)];
        if (b) for (var j = 0; j < b.length; j++) if (b[j] > i) res.push(b[j]);
      }
    return res;
  }

  function ptSeg(px, py, ax, ay, bx, by) {
    var abx = bx - ax, aby = by - ay, apx = px - ax, apy = py - ay;
    var l2 = abx * abx + aby * aby;
    if (l2 < 0.01) {
      var d = Math.sqrt(apx * apx + apy * apy);
      return { d: d, nx: d > 0 ? apx / d : 0, ny: d > 0 ? apy / d : 1 };
    }
    var t = Math.max(0, Math.min(1, (apx * abx + apy * aby) / l2));
    var dx = px - (ax + t * abx), dy = py - (ay + t * aby);
    var d = Math.sqrt(dx * dx + dy * dy);
    return { d: d, nx: d > 0 ? dx / d : 0, ny: d > 0 ? dy / d : 1 };
  }

  function mkW() {
    ws = [];
    var cx = W * 0.5;
    var ft = H * 0.30, nt = H * 0.52;
    var nb = nt + R * 8, fy = H - 25;
    var nw = neckR * R;
    var fw = Math.min(W * 0.38, 260);
    ws.push({ a: 10, b: ft, c: cx - fw, d: ft });
    ws.push({ a: cx + fw, b: ft, c: W - 10, d: ft });
    ws.push({ a: cx - fw, b: ft, c: cx - nw, d: nt });
    ws.push({ a: cx + fw, b: ft, c: cx + nw, d: nt });
    ws.push({ a: cx - nw, b: nt, c: cx - nw, d: nb });
    ws.push({ a: cx + nw, b: nt, c: cx + nw, d: nb });
    ws.push({ a: 10, b: fy, c: W - 10, d: fy });
    ws.push({ a: 10, b: 10, c: 10, d: fy });
    ws.push({ a: W - 10, b: 10, c: W - 10, d: fy });
    ws.push({ a: 10, b: 10, c: W - 10, d: 10 });
  }

  function resize() {
    var d = Math.min(window.devicePixelRatio || 1, 2);
    W = window.innerWidth; H = window.innerHeight;
    C.width = W * d; C.height = H * d;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    X.setTransform(d, 0, 0, d, 0, 0);
    mkW();
  }

  function step() {
    var ax = gx * gMag, ay = gy * gMag;
    if (shakeT > 0) {
      shakeT--;
      ax += (Math.random() - 0.5) * 2000;
      ay += (Math.random() - 0.5) * 2000;
    }

    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      var vx = (p.x - p.ox) * DAMP, vy = (p.y - p.oy) * DAMP;
      p.ox = p.x; p.oy = p.y;
      p.x += vx + ax * DT * DT;
      p.y += vy + ay * DT * DT;
      p.f = 0;
    }

    cts = [];
    for (var it = 0; it < ITER; it++) {
      buildH();
      var last = it === ITER - 1;

      for (var i = 0; i < ps.length; i++) {
        var p1 = ps[i], ns = near(i);
        for (var j = 0; j < ns.length; j++) {
          var p2 = ps[ns[j]];
          var dx = p2.x - p1.x, dy = p2.y - p1.y;
          var d = Math.sqrt(dx * dx + dy * dy);
          var md = p1.r + p2.r;
          if (d < md && d > 0.001) {
            var ov = md - d, nx = dx / d, ny = dy / d, c = ov * 0.5;
            p1.x -= nx * c; p1.y -= ny * c;
            p2.x += nx * c; p2.y += ny * c;
            p1.f += ov; p2.f += ov;
            var rvx = (p2.x - p2.ox) - (p1.x - p1.ox);
            var rvy = (p2.y - p2.oy) - (p1.y - p1.oy);
            var rvn = rvx * nx + rvy * ny;
            var rtx = rvx - rvn * nx, rty = rvy - rvn * ny;
            p1.ox -= rtx * 0.04; p1.oy -= rty * 0.04;
            p2.ox += rtx * 0.04; p2.oy += rty * 0.04;
            if (last) cts.push({ a: i, b: ns[j], f: ov });
          }
        }
      }

      for (var i = 0; i < ps.length; i++) {
        var p = ps[i];
        for (var w = 0; w < ws.length; w++) {
          var wl = ws[w];
          var r = ptSeg(p.x, p.y, wl.a, wl.b, wl.c, wl.d);
          if (r.d < p.r && r.d > 0.001) {
            var ov = p.r - r.d;
            p.x += r.nx * ov; p.y += r.ny * ov;
            p.f += ov * 2;
            p.ox += (p.x - p.ox) * 0.25;
            p.oy += (p.y - p.oy) * 0.25;
          }
        }
      }
    }

    for (var i = ps.length - 1; i >= 0; i--) {
      if (ps[i].y > H + 100 || ps[i].x < -100 || ps[i].x > W + 100) ps.splice(i, 1);
    }

    gx += (tgx - gx) * 0.08; gy += (tgy - gy) * 0.08;
    var gl = Math.sqrt(gx * gx + gy * gy);
    if (gl > 0) { gx /= gl; gy /= gl; }
  }

  function pour(x, y, n) {
    for (var i = 0; i < n && ps.length < MAX; i++) {
      ps.push(new Pt(x + (Math.random() - 0.5) * R * 6, y + (Math.random() - 0.5) * R * 3, R));
    }
  }

  function draw() {
    X.fillStyle = '#0a0a0f';
    X.fillRect(0, 0, W, H);

    X.lineCap = 'round';
    for (var i = 0; i < ws.length; i++) {
      var w = ws[i];
      X.beginPath();
      X.moveTo(w.a, w.b); X.lineTo(w.c, w.d);
      X.strokeStyle = 'rgba(123,44,191,0.15)';
      X.lineWidth = 6;
      X.stroke();
      X.beginPath();
      X.moveTo(w.a, w.b); X.lineTo(w.c, w.d);
      X.strokeStyle = 'rgba(123,44,191,0.45)';
      X.lineWidth = 2;
      X.stroke();
    }

    if (chains) {
      for (var i = 0; i < cts.length; i++) {
        var ct = cts[i];
        var p1 = ps[ct.a], p2 = ps[ct.b];
        if (!p1 || !p2) continue;
        var I = Math.min(1, ct.f / (R * 0.4));
        if (I < 0.06) continue;
        X.beginPath();
        X.moveTo(p1.x, p1.y); X.lineTo(p2.x, p2.y);
        X.strokeStyle = 'rgba(0,245,212,' + (I * 0.35).toFixed(2) + ')';
        X.lineWidth = 0.5 + I * 2.5;
        X.stroke();
      }
    }

    for (var i = 0; i < ps.length; i++) {
      var p = ps[i], f = Math.min(1, p.f / (R * 3));
      X.beginPath();
      X.arc(p.x, p.y, p.r - 0.5, 0, Math.PI * 2);
      var cr = Math.floor(f * 80);
      var cg = Math.floor(80 + f * 165);
      var cb = Math.floor(70 + f * 142);
      X.fillStyle = 'rgba(' + cr + ',' + cg + ',' + cb + ',' + (0.45 + f * 0.55).toFixed(2) + ')';
      X.fill();
    }

    var tilt = Math.abs(gx) + Math.abs(1 - gy);
    if (tilt > 0.08) {
      var ix = 50, iy = H - 80;
      X.beginPath();
      X.arc(ix, iy, 18, 0, Math.PI * 2);
      X.strokeStyle = 'rgba(123,44,191,0.3)';
      X.lineWidth = 1;
      X.stroke();
      X.beginPath();
      X.moveTo(ix, iy);
      X.lineTo(ix + gx * 14, iy + gy * 14);
      X.strokeStyle = 'rgba(0,245,212,0.5)';
      X.lineWidth = 2;
      X.stroke();
      var a = Math.atan2(gy, gx);
      X.beginPath();
      X.moveTo(ix + gx * 14, iy + gy * 14);
      X.lineTo(ix + gx * 14 - 6 * Math.cos(a - 0.5), iy + gy * 14 - 6 * Math.sin(a - 0.5));
      X.moveTo(ix + gx * 14, iy + gy * 14);
      X.lineTo(ix + gx * 14 - 6 * Math.cos(a + 0.5), iy + gy * 14 - 6 * Math.sin(a + 0.5));
      X.stroke();
    }
  }

  function loop() {
    frame++;
    if (autoP && frame % 4 === 0 && ps.length < MAX) {
      pour(W * 0.5 + (Math.random() - 0.5) * 30, H * 0.15, 2);
      if (frame % 16 === 0 && typeof SFX !== 'undefined') SFX.play('spawn', 100);
    }
    step();
    draw();
    if (frame % 12 === 0) {
      hudN.textContent = ps.length;
      hudC.textContent = cts.length;
    }
    requestAnimationFrame(loop);
  }

  var isDrag = false, startP = null, dragD = 0;
  function pos(e) {
    var t = e.touches ? e.touches[0] : e;
    var r = C.getBoundingClientRect();
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }

  C.addEventListener('mousedown', function(e) {
    if (e.button === 2) return;
    e.preventDefault();
    var p = pos(e);
    isDrag = true; startP = p; dragD = 0;
    pour(p.x, p.y, 5);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  });

  C.addEventListener('mousemove', function(e) {
    if (!isDrag) return;
    var p = pos(e);
    dragD += Math.abs(p.x - startP.x) + Math.abs(p.y - startP.y);
    startP = p;
    if (dragD > 30) {
      var dx = p.x - W * 0.5, dy = p.y - H * 0.5;
      var l = Math.sqrt(dx * dx + dy * dy);
      if (l > 20) { tgx = dx / l; tgy = dy / l; }
    }
  });

  C.addEventListener('mouseup', function() { isDrag = false; tgx = 0; tgy = 1; });
  C.addEventListener('mouseleave', function() { isDrag = false; tgx = 0; tgy = 1; });

  C.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    var p = pos(e), er = 40;
    for (var i = ps.length - 1; i >= 0; i--) {
      var dx = ps[i].x - p.x, dy = ps[i].y - p.y;
      if (dx * dx + dy * dy < er * er) ps.splice(i, 1);
    }
    if (typeof SFX !== 'undefined') SFX.play('pop');
  });

  C.addEventListener('touchstart', function(e) {
    e.preventDefault();
    var p = pos(e);
    isDrag = true; startP = p; dragD = 0;
    pour(p.x, p.y, 5);
    if (typeof SFX !== 'undefined') SFX.play('spawn');
  }, { passive: false });

  C.addEventListener('touchmove', function(e) {
    e.preventDefault();
    if (!isDrag) return;
    var t = e.touches[0], r = C.getBoundingClientRect();
    var p = { x: t.clientX - r.left, y: t.clientY - r.top };
    dragD += Math.abs(p.x - startP.x) + Math.abs(p.y - startP.y);
    startP = p;
    if (dragD > 40) {
      var dx = p.x - W * 0.5, dy = p.y - H * 0.5;
      var l = Math.sqrt(dx * dx + dy * dy);
      if (l > 20) { tgx = dx / l; tgy = dy / l; }
    }
  }, { passive: false });

  C.addEventListener('touchend', function() { isDrag = false; tgx = 0; tgy = 1; });

  var slN = document.getElementById('sl-neck'), svN = document.getElementById('sv-neck');
  var slS = document.getElementById('sl-size'), svS = document.getElementById('sv-size');
  var slG = document.getElementById('sl-grav'), svG = document.getElementById('sv-grav');
  var bP = document.getElementById('btn-pour'), bSh = document.getElementById('btn-shake');
  var bCh = document.getElementById('btn-chains'), bR = document.getElementById('btn-reset');

  slN.addEventListener('input', function() {
    neckR = +this.value; svN.textContent = this.value; mkW();
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });
  slS.addEventListener('input', function() {
    R = +this.value; svS.textContent = this.value;
    for (var i = 0; i < ps.length; i++) ps[i].r = R;
    mkW();
    if (typeof SFX !== 'undefined') SFX.play('tick');
  });
  slG.addEventListener('input', function() {
    gMag = +this.value * 80; svG.textContent = this.value;
  });
  bP.addEventListener('click', function() {
    autoP = !autoP; this.classList.toggle('active', autoP);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  bSh.addEventListener('click', function() {
    shakeT = 15;
    if (typeof SFX !== 'undefined') SFX.play('collide');
  });
  bCh.addEventListener('click', function() {
    chains = !chains; this.classList.toggle('active', chains);
    if (typeof SFX !== 'undefined') SFX.play('toggle');
  });
  bR.addEventListener('click', function() {
    ps = []; cts = [];
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  window.addEventListener('resize', resize);
  resize();
  pour(W * 0.5, H * 0.15, 40);
  for (var i = 0; i < 30; i++) step();
  loop();
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
