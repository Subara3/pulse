<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('softbody');

$pageName = 'softbody';
$pageTitle = 'SOFTBODY - PULSE';
$pageDesc = '圧力ベースの軟体シミュレーション。バネと内圧で膨らむ軟体を投げて弾ませる。';
$pageCanonical = 'https://pulse.subara3.com/softbody.php';

$extraStyle = '
body{background:#0a0a0f;min-height:100vh;overflow:hidden}
#canvas{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
.hud{position:fixed;top:3.5rem;right:1rem;z-index:100;font-family:"Orbitron","Segoe UI",sans-serif;pointer-events:none;color:rgba(255,255,255,0.34);font-size:0.64rem;text-align:right;letter-spacing:0.05em;line-height:1.8}
.hud .v{color:#00f5d4;font-weight:700}
@media(max-width:768px){.hud{top:3rem;right:0.6rem;font-size:0.54rem}}
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>

<canvas id="canvas"></canvas>

<div class="hud">
  BODIES: <span id="hudBodies" class="v">0</span><br>
  POINTS: <span id="hudPts" class="v">0</span><br>
  ENERGY: <span id="hudE" class="v">0</span>
</div>

<?php
$pageInfo = [
  'pressure-based soft body',
  'spring-mass + internal pressure',
  'Hooke\'s law · gas dynamics',
];
require_once __DIR__ . '/includes/info-panel.php';
?>

<?php
$interactionHints = [
  ['type' => 'click', 'label' => 'spawn'],
  ['type' => 'drag', 'label' => 'grab / throw'],
  ['type' => 'right', 'label' => 'erase'],
];
require_once __DIR__ . '/includes/interaction-hints.php';
?>

<div id="controls" class="drawer-controls">
  <div class="slider-wrap">
    <label class="slider-label">STIFFNESS</label>
    <div class="slider-row">
      <input type="range" id="sldStiff" min="100" max="2000" value="800">
      <span class="slider-value" id="valStiff">800</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESSURE</label>
    <div class="slider-row">
      <input type="range" id="sldPress" min="500" max="15000" value="5000">
      <span class="slider-value" id="valPress">5000</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">GRAVITY</label>
    <div class="slider-row">
      <input type="range" id="sldGrav" min="0" max="1500" value="600">
      <span class="slider-value" id="valGrav">600</span>
    </div>
  </div>
  <div class="slider-wrap">
    <label class="slider-label">PRESET</label>
    <div class="preset-group" id="presetGrp">
      <button class="preset-btn active" data-p="drop">DROP</button>
      <button class="preset-btn" data-p="stack">STACK</button>
      <button class="preset-btn" data-p="rain">RAIN</button>
      <button class="preset-btn" data-p="bounce">BOUNCE</button>
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
  var C = document.getElementById('canvas'), ctx = C.getContext('2d');
  var W, H, dpr;

  var stiff = 800, press = 5000, grav = 600;
  var sDamp = 15, resti = 0.35, SUB = 8, MAX = 15;

  var bodies = [], sparks = [];
  var grab = null, mx = 0, my = 0;
  var pDown = false, sPos = null, didDr = false, isR = false;
  var lt = 0, preset = 'drop', rainT = 0, bCnt = 0;
  var wallHit = false;

  var HUES = [175, 268, 188, 258, 180, 278, 192, 265];

  function resize() {
    dpr = window.devicePixelRatio || 1;
    W = innerWidth; H = innerHeight;
    C.width = W * dpr; C.height = H * dpr;
    C.style.width = W + 'px'; C.style.height = H + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function nPts(r) { return r > 40 ? 24 : r > 25 ? 20 : 16; }

  function mk(cx, cy, r) {
    var n = nPts(r);
    var b = { p: [], rl: [], r: r, h: HUES[bCnt++ % HUES.length], _c: {x:cx,y:cy} };
    for (var i = 0; i < n; i++) {
      var a = i / n * Math.PI * 2;
      b.p.push({ x: cx + Math.cos(a) * r, y: cy + Math.sin(a) * r, vx: 0, vy: 0 });
    }
    for (var i = 0; i < n; i++) {
      var j = (i + 1) % n;
      var dx = b.p[j].x - b.p[i].x, dy = b.p[j].y - b.p[i].y;
      b.rl.push(Math.sqrt(dx * dx + dy * dy));
    }
    return b;
  }

  function sArea(pts) {
    var a = 0, n = pts.length;
    for (var i = 0; i < n; i++) {
      var j = (i + 1) % n;
      a += pts[i].x * pts[j].y - pts[j].x * pts[i].y;
    }
    return a / 2;
  }

  function cen(pts) {
    var x = 0, y = 0, n = pts.length;
    for (var i = 0; i < n; i++) { x += pts[i].x; y += pts[i].y; }
    return { x: x / n, y: y / n };
  }

  function dst(x1, y1, x2, y2) {
    var a = x1 - x2, b = y1 - y2;
    return Math.sqrt(a * a + b * b);
  }

  function step(dt) {
    var bi, i, j, n, b, p, p1, p2, dx, dy, d, sa, el, f;

    for (bi = 0; bi < bodies.length; bi++) {
      b = bodies[bi]; n = b.p.length;

      for (i = 0; i < n; i++) {
        j = (i + 1) % n; p1 = b.p[i]; p2 = b.p[j];
        dx = p2.x - p1.x; dy = p2.y - p1.y;
        d = Math.sqrt(dx * dx + dy * dy);
        if (d < 0.001) continue;
        var sf = stiff * (d - b.rl[i]) / d;
        var dvx = p2.vx - p1.vx, dvy = p2.vy - p1.vy;
        var dm = sDamp * (dvx * dx + dvy * dy) / (d * d);
        var fx = (sf + dm) * dx, fy = (sf + dm) * dy;
        p1.vx += fx * dt; p1.vy += fy * dt;
        p2.vx -= fx * dt; p2.vy -= fy * dt;
      }

      sa = sArea(b.p);
      if (Math.abs(sa) < 1) sa = sa < 0 ? -1 : 1;
      for (i = 0; i < n; i++) {
        j = (i + 1) % n; p1 = b.p[i]; p2 = b.p[j];
        dx = p2.x - p1.x; dy = p2.y - p1.y;
        el = Math.sqrt(dx * dx + dy * dy);
        if (el < 0.001) continue;
        var nx = dy / el, ny = -dx / el;
        f = press * el / sa;
        p1.vx += nx * f * dt * 0.5; p1.vy += ny * f * dt * 0.5;
        p2.vx += nx * f * dt * 0.5; p2.vy += ny * f * dt * 0.5;
      }

      for (i = 0; i < n; i++) {
        p = b.p[i];
        if (grab && grab.bi === bi && grab.pi === i) {
          var lp = 0.4;
          var gx = p.x + (mx - p.x) * lp, gy = p.y + (my - p.y) * lp;
          p.vx = (gx - p.x) / dt; p.vy = (gy - p.y) / dt;
          p.x = gx; p.y = gy;
          continue;
        }
        p.vy += grav * dt;
        p.vx *= 0.9995; p.vy *= 0.9995;
        p.x += p.vx * dt; p.y += p.vy * dt;
        var v = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
        if (v > 2000) { p.vx = p.vx / v * 2000; p.vy = p.vy / v * 2000; }
        if (p.y > H - 2) { p.y = H - 2; if (p.vy > 30) { wallHit = true; spk(p.x, p.y, b.h); } p.vy *= -resti; p.vx *= 0.98; }
        if (p.y < 2) { p.y = 2; p.vy *= -resti; }
        if (p.x < 2) { p.x = 2; if (p.vx < -30) wallHit = true; p.vx *= -resti; }
        if (p.x > W - 2) { p.x = W - 2; if (p.vx > 30) wallHit = true; p.vx *= -resti; }
      }
      b._c = cen(b.p);
    }

    var rr = 14, rf = 600;
    for (i = 0; i < bodies.length; i++) {
      for (j = i + 1; j < bodies.length; j++) {
        var ci = bodies[i]._c, cj = bodies[j]._c;
        if (dst(ci.x, ci.y, cj.x, cj.y) > (bodies[i].r + bodies[j].r) * 3) continue;
        var pi = bodies[i].p, pj = bodies[j].p;
        for (var aa = 0; aa < pi.length; aa++) {
          for (var bb = 0; bb < pj.length; bb++) {
            dx = pj[bb].x - pi[aa].x; dy = pj[bb].y - pi[aa].y;
            var dd = dx * dx + dy * dy;
            if (dd < rr * rr && dd > 0.01) {
              d = Math.sqrt(dd);
              f = rf * (rr - d) / d * dt;
              pi[aa].vx -= dx * f; pi[aa].vy -= dy * f;
              pj[bb].vx += dx * f; pj[bb].vy += dy * f;
            }
          }
        }
      }
    }
  }

  function spk(x, y, h) {
    if (sparks.length > 80) return;
    for (var i = 0; i < 3; i++) {
      var a = Math.random() * Math.PI * 2, v = 30 + Math.random() * 60;
      sparks.push({ x: x, y: y, vx: Math.cos(a) * v, vy: Math.sin(a) * v - 40, l: 1, h: h });
    }
  }

  function drawBody(b) {
    var pts = b.p, n = pts.length;
    var path = new Path2D();
    var sx = (pts[n - 1].x + pts[0].x) * 0.5, sy = (pts[n - 1].y + pts[0].y) * 0.5;
    path.moveTo(sx, sy);
    for (var i = 0; i < n; i++) {
      var ex = (pts[i].x + pts[(i + 1) % n].x) * 0.5;
      var ey = (pts[i].y + pts[(i + 1) % n].y) * 0.5;
      path.quadraticCurveTo(pts[i].x, pts[i].y, ex, ey);
    }
    path.closePath();

    ctx.lineJoin = 'round';
    ctx.strokeStyle = 'hsla(' + b.h + ',70%,55%,0.06)';
    ctx.lineWidth = 14;
    ctx.stroke(path);

    var c = b._c;
    var g = ctx.createRadialGradient(c.x, c.y, 0, c.x, c.y, b.r * 1.3);
    g.addColorStop(0, 'hsla(' + b.h + ',70%,55%,0.18)');
    g.addColorStop(1, 'hsla(' + b.h + ',70%,50%,0.04)');
    ctx.fillStyle = g;
    ctx.fill(path);

    ctx.strokeStyle = 'hsla(' + b.h + ',70%,55%,0.5)';
    ctx.lineWidth = 1.5;
    ctx.stroke(path);

    ctx.fillStyle = 'hsla(' + b.h + ',70%,65%,0.35)';
    for (var i = 0; i < n; i++) {
      ctx.beginPath();
      ctx.arc(pts[i].x, pts[i].y, 1.5, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function render(dt) {
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    ctx.strokeStyle = 'rgba(0,245,212,0.05)';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(0, H - 1); ctx.lineTo(W, H - 1); ctx.stroke();

    for (var i = 0; i < bodies.length; i++) drawBody(bodies[i]);

    for (var i = sparks.length - 1; i >= 0; i--) {
      var s = sparks[i];
      s.x += s.vx * dt; s.y += s.vy * dt; s.vy += 150 * dt; s.l -= dt * 2.5;
      if (s.l <= 0) { sparks.splice(i, 1); continue; }
      ctx.beginPath();
      ctx.arc(s.x, s.y, 2 * s.l, 0, Math.PI * 2);
      ctx.fillStyle = 'hsla(' + s.h + ',80%,65%,' + (s.l * 0.5).toFixed(2) + ')';
      ctx.fill();
    }

    var tp = 0, en = 0;
    for (var i = 0; i < bodies.length; i++) {
      tp += bodies[i].p.length;
      for (var j = 0; j < bodies[i].p.length; j++) {
        var pt = bodies[i].p[j];
        en += pt.vx * pt.vx + pt.vy * pt.vy;
      }
    }
    document.getElementById('hudBodies').textContent = bodies.length;
    document.getElementById('hudPts').textContent = tp;
    document.getElementById('hudE').textContent = Math.round(en / 100);
  }

  function frame(t) {
    var dt = Math.min((t - lt) / 1000, 1 / 30);
    lt = t;

    if (preset === 'rain') {
      rainT += dt;
      if (rainT > 0.8 && bodies.length < MAX) {
        rainT = 0;
        bodies.push(mk(W * 0.1 + Math.random() * W * 0.8, -60, 18 + Math.random() * 28));
        if (typeof SFX !== 'undefined') SFX.play('spawn', 400);
      }
    }

    wallHit = false;
    var sdt = dt / SUB;
    for (var s = 0; s < SUB; s++) step(sdt);
    if (wallHit && typeof SFX !== 'undefined') SFX.play('collide', 120);

    render(dt);
    requestAnimationFrame(frame);
  }

  function nearPt(x, y, md) {
    var bb = -1, bp = -1, bd = md;
    for (var i = 0; i < bodies.length; i++) {
      for (var j = 0; j < bodies[i].p.length; j++) {
        var d = dst(x, y, bodies[i].p[j].x, bodies[i].p[j].y);
        if (d < bd) { bd = d; bb = i; bp = j; }
      }
    }
    return bb >= 0 ? { bi: bb, pi: bp } : null;
  }

  function nearCen(x, y, md) {
    var best = -1, bd = md;
    for (var i = 0; i < bodies.length; i++) {
      var c = bodies[i]._c;
      var d = dst(x, y, c.x, c.y);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  C.addEventListener('pointerdown', function(e) {
    e.preventDefault();
    C.setPointerCapture(e.pointerId);
    pDown = true; didDr = false; isR = e.button === 2;
    mx = e.clientX; my = e.clientY;
    sPos = { x: e.clientX, y: e.clientY };
    if (isR) {
      var bi = nearCen(e.clientX, e.clientY, 120);
      if (bi >= 0) {
        if (grab && grab.bi === bi) grab = null;
        bodies.splice(bi, 1);
        if (grab && grab.bi > bi) grab.bi--;
        if (typeof SFX !== 'undefined') SFX.play('pop');
      }
      return;
    }
    var n = nearPt(e.clientX, e.clientY, 50);
    if (n) grab = n;
  });

  C.addEventListener('pointermove', function(e) {
    mx = e.clientX; my = e.clientY;
    if (!pDown) return;
    e.preventDefault();
    if (sPos && dst(e.clientX, e.clientY, sPos.x, sPos.y) > 5) didDr = true;
    if (grab && typeof SFX !== 'undefined') SFX.play('move', 100);
  });

  C.addEventListener('pointerup', function(e) {
    if (!pDown) return;
    pDown = false;
    if (!isR && !didDr && !grab && bodies.length < MAX) {
      var r = 25 + Math.random() * 35;
      bodies.push(mk(e.clientX, e.clientY, r));
      if (typeof SFX !== 'undefined') SFX.play('spawn');
    }
    grab = null; isR = false;
  });

  C.addEventListener('contextmenu', function(e) { e.preventDefault(); });

  document.getElementById('sldStiff').addEventListener('input', function() {
    stiff = +this.value; document.getElementById('valStiff').textContent = this.value;
  });
  document.getElementById('sldPress').addEventListener('input', function() {
    press = +this.value; document.getElementById('valPress').textContent = this.value;
  });
  document.getElementById('sldGrav').addEventListener('input', function() {
    grav = +this.value; document.getElementById('valGrav').textContent = this.value;
  });

  document.querySelectorAll('#presetGrp .preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      document.querySelectorAll('#presetGrp .preset-btn').forEach(function(b) { b.classList.remove('active'); });
      this.classList.add('active');
      loadP(this.dataset.p);
      if (typeof SFX !== 'undefined') SFX.play('toggle');
    });
  });

  document.getElementById('btnReset').addEventListener('click', function() {
    loadP(preset);
    if (typeof SFX !== 'undefined') SFX.play('click');
  });

  function loadP(name) {
    preset = name; bodies.length = 0; sparks.length = 0; grab = null; rainT = 0;
    if (name === 'drop') {
      bodies.push(mk(W * 0.3, H * 0.12, 50));
      bodies.push(mk(W * 0.55, H * 0.05, 38));
      bodies.push(mk(W * 0.75, H * 0.18, 30));
    } else if (name === 'stack') {
      var r = 32;
      for (var i = 0; i < 5; i++)
        bodies.push(mk(W * 0.5 + (Math.random() - 0.5) * 8, H - 2 - r - i * r * 2.1, r));
    } else if (name === 'bounce') {
      for (var i = 0; i < 6; i++) {
        var r = 22 + Math.random() * 22;
        var bd = mk(W * (0.15 + Math.random() * 0.7), H * (0.1 + Math.random() * 0.3), r);
        for (var j = 0; j < bd.p.length; j++) {
          bd.p[j].vx = (Math.random() - 0.5) * 400;
          bd.p[j].vy = (Math.random() - 0.5) * 300;
        }
        bodies.push(bd);
      }
    }
  }

  resize();
  window.addEventListener('resize', resize);
  loadP('drop');
  lt = performance.now();
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
