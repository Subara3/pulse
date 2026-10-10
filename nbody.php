<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('nbody');

$pageName = 'nbody';
$pageTitle = 'N-BODY - PULSE';
$pageDesc = '同じ重さの3つの星が引き合いながら、1本の8の字の上を追いかけるように回り、まわりに重力の等高線が描かれる。押すとそこに見えない重りが置かれ、水色の輪がいくつも集まって等高線がへこむ。引っぱって離すと小さな粒が飛び出す。';
$pageCanonical = 'https://pulse.subara3.com/nbody.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-time">TIME</label><input type="range" id="k-time" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-sys="eight">EIGHT</button><button type="button" data-sys="triple">TRIPLE</button></div>
  <div class="ctl-btns"><button type="button" id="k-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, CX = 0, CY = 0, U = 1;
  /* 重力の等高線は粗い格子で解いて拡大する */
  var CELL = 4, GX = 0, GY = 0, img = null, buf = null, bctx = null;
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; CX = w / 2; CY = h / 2; scale();
    GX = Math.ceil(w / CELL); GY = Math.ceil(h / CELL);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas'); buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
  });

  /* ---------------------------------------------------------- 星
     EIGHT: 等しい 3 つの星が 1 本の 8 の字を共有して回る周期解。
     TRIPLE: 近い連星のまわりを、3 つめの星がずれていく楕円で回る。 */
  var sys = 'eight', bodies = [], dust = [];
  var TRAIL = 900;
  function body(m, x, y, vx, vy) {
    return { m: m, x: x, y: y, vx: vx, vy: vy, ax: 0, ay: 0, tx: new Float32Array(TRAIL), ty: new Float32Array(TRAIL), tn: 0, th: 0 };
  }
  function setup() {
    bodies.length = 0; dust.length = 0;
    if (sys === 'eight') {
      var x1 = 0.97000436, y1 = -0.24308753, vx3 = -0.93240737, vy3 = -0.86473146;
      bodies.push(body(1, x1, y1, -vx3 / 2, -vy3 / 2));
      bodies.push(body(1, -x1, -y1, -vx3 / 2, -vy3 / 2));
      bodies.push(body(1, 0, 0, vx3, vy3));
    } else {
      var d = 0.42, m = 0.5, v = Math.sqrt(m / (2 * d));
      var m3 = 0.22, r3 = 1.75, v3 = Math.sqrt((2 * m + m3) / r3) * 0.72;
      var bvx = 0, bvy = -m3 * v3 / (2 * m);
      bodies.push(body(m, -d / 2, 0, bvx, -v + bvy));
      bodies.push(body(m, d / 2, 0, bvx, v + bvy));
      bodies.push(body(m3, r3, 0, 0, v3));
      /* 重心を原点に */
      var cx = 0, cy = 0, mt = 0;
      bodies.forEach(function (b) { cx += b.m * b.x; cy += b.m * b.y; mt += b.m; });
      bodies.forEach(function (b) { b.x -= cx / mt; b.y -= cy / mt; });
    }
    accel();
    scale();
  }
  function scale() {
    U = sys === 'eight' ? Math.min(W, H) * 0.42 / 1.08 : Math.min(W, H) * 0.45 / 2.05;
  }

  var SOFT = 1e-4, DSOFT = 0.004;
  function accel() {
    for (var i = 0; i < bodies.length; i++) { bodies[i].ax = 0; bodies[i].ay = 0; }
    for (var i2 = 0; i2 < bodies.length; i2++) {
      var a = bodies[i2];
      for (var j = i2 + 1; j < bodies.length; j++) {
        var b = bodies[j];
        var dx = b.x - a.x, dy = b.y - a.y, r2 = dx * dx + dy * dy + SOFT, inv = 1 / (r2 * Math.sqrt(r2));
        a.ax += dx * inv * b.m; a.ay += dy * inv * b.m;
        b.ax -= dx * inv * a.m; b.ay -= dy * inv * a.m;
      }
    }
  }
  function dustAccel(p) {
    p.ax = 0; p.ay = 0;
    for (var i = 0; i < bodies.length; i++) {
      var b = bodies[i], dx = b.x - p.x, dy = b.y - p.y, r2 = dx * dx + dy * dy + DSOFT, inv = 1 / (r2 * Math.sqrt(r2));
      p.ax += dx * inv * b.m; p.ay += dy * inv * b.m;
      if (r2 < 0.01) SFX.play('tick', 1500);
    }
    /* 指が置いた見えない重り */
    if (well.m > 0.01) {
      var wx = well.x - p.x, wy = well.y - p.y, w2 = wx * wx + wy * wy + 0.01, wi = 1 / (w2 * Math.sqrt(w2));
      p.ax += wx * wi * well.m; p.ay += wy * wi * well.m;
    }
  }
  var well = { x: 0, y: 0, m: 0 };

  /* 星と指の重りが作る、重力の高さの等高線 */
  var BG = P.bg, LNc = P.line;
  function drawField() {
    var d = img.data, k = 0;
    var bx = [], by = [], bm = [];
    for (var i = 0; i < bodies.length; i++) { bx.push(bodies[i].x); by.push(bodies[i].y); bm.push(bodies[i].m); }
    var nb = bx.length, inv = 1 / U, wm = well.m, wxs = well.x, wys = well.y, AC = P.accent;
    for (var gy = 0; gy < GY; gy++) {
      for (var gx = 0; gx < GX; gx++, k += 4) {
        var X = (gx + 0.5) * CELL - CX, Y = (gy + 0.5) * CELL - CY;
        var x = (X * cv + Y * sv) * inv, y = (-X * sv + Y * cv) * inv;
        var phi = 0, pw = 0;
        for (var j = 0; j < nb; j++) { var dx = x - bx[j], dy = y - by[j]; phi += bm[j] / Math.sqrt(dx * dx + dy * dy + 0.004); }
        if (wm > 0.01) { var ux = x - wxs, uy = y - wys; pw = wm / Math.sqrt(ux * ux + uy * uy + 0.002); }
        var tot = phi + pw;
        var v = Math.log(tot) * 4.2, f = v - Math.floor(v), e = (f - 0.5) / 0.085;
        /* 星の近くは輪が詰まりすぎるので薄める。指の重りのまわりは薄めずに水色で描く */
        var share = Math.pow(pw / tot, 2.5);
        var a = Math.exp(-e * e) * (0.36 * Math.min(1, 5 / phi) * (1 - share) + 0.85 * share * Math.min(1, 40 / pw));
        var cr = LNc[0] + (AC[0] - LNc[0]) * share, cg = LNc[1] + (AC[1] - LNc[1]) * share, cb = LNc[2] + (AC[2] - LNc[2]) * share;
        d[k] = BG[0] + (cr - BG[0]) * a; d[k + 1] = BG[1] + (cg - BG[1]) * a; d[k + 2] = BG[2] + (cb - BG[2]) * a; d[k + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, GX * CELL, GY * CELL);
  }
  /* 速度ベルレ。長く回しても 8 の字が崩れない */
  function step(h) {
    var i, b;
    for (i = 0; i < bodies.length; i++) { b = bodies[i]; b.vx += b.ax * h / 2; b.vy += b.ay * h / 2; b.x += b.vx * h; b.y += b.vy * h; }
    for (i = 0; i < dust.length; i++) { b = dust[i]; b.vx += b.ax * h / 2; b.vy += b.ay * h / 2; b.x += b.vx * h; b.y += b.vy * h; }
    accel();
    for (i = 0; i < dust.length; i++) dustAccel(dust[i]);
    for (i = 0; i < bodies.length; i++) { b = bodies[i]; b.vx += b.ax * h / 2; b.vy += b.ay * h / 2; }
    for (i = 0; i < dust.length; i++) { b = dust[i]; b.vx += b.ax * h / 2; b.vy += b.ay * h / 2; }
  }

  /* ---------------------------------------------------------- 粒 */
  var DTRAIL = 260;
  function addDust(x, y, vx, vy, touched) {
    if (dust.length > 46) dust.shift();
    var p = { x: x, y: y, vx: vx, vy: vy, ax: 0, ay: 0, age: 0, touched: touched, tx: new Float32Array(DTRAIL), ty: new Float32Array(DTRAIL), tn: 0, th: 0 };
    dustAccel(p);
    dust.push(p);
  }
  function autoDust() {
    /* 外側から、ゆるく回りこむ向きに投げ入れる */
    var ang = Math.random() * Math.PI * 2, r = sys === 'eight' ? 1.7 : 2.6;
    var x = Math.cos(ang) * r, y = Math.sin(ang) * r;
    var v = Math.sqrt(3 / r) * (0.3 + Math.random() * 0.3), s = Math.random() < 0.5 ? 1 : -1;
    addDust(x, y, -Math.sin(ang) * v * s - x * 0.05, Math.cos(ang) * v * s - y * 0.05, false);
  }
  function record(o, cap) {
    o.tx[o.th] = o.x; o.ty[o.th] = o.y;
    o.th = (o.th + 1) % cap;
    if (o.tn < cap) o.tn++;
  }

  /* ---------------------------------------------------------- 描く */
  var view = 0, cv = 1, sv = 0;
  function toX(x, y) { return CX + (x * cv - y * sv) * U; }
  function toY(x, y) { return CY + (x * sv + y * cv) * U; }
  var LN = P.line, AC = P.accent, ST = P.steel;

  function drawTrail(o, cap, color, peak, width) {
    var n = o.tn;
    if (n < 2) return;
    var B = 10, start = (o.th - n + cap) % cap;
    for (var b = 0; b < B; b++) {
      var i0 = Math.floor(n * b / B), i1 = Math.min(n - 1, Math.floor(n * (b + 1) / B) + 1);
      ctx.beginPath();
      for (var i = i0; i <= i1; i++) {
        var k = (start + i) % cap, X = toX(o.tx[k], o.ty[k]), Y = toY(o.tx[k], o.ty[k]);
        if (i === i0) ctx.moveTo(X, Y); else ctx.lineTo(X, Y);
      }
      var a = Math.pow((b + 1) / B, 1.6);
      ctx.lineWidth = width * (0.5 + 0.5 * a);
      ctx.strokeStyle = P.rgba(color, peak * a);
      ctx.stroke();
    }
  }
  function draw() {
    for (var i = 0; i < dust.length; i++) {
      var p = dust[i], fade = Math.min(1, p.age / 0.5) * Math.max(0, Math.min(1, (LIFE - p.age) / 4));
      drawTrail(p, DTRAIL, p.touched ? AC : ST, (p.touched ? 0.7 : 0.75) * fade, 1);
      ctx.fillStyle = P.rgba(p.touched ? AC : LN, 0.8 * fade);
      ctx.beginPath(); ctx.arc(toX(p.x, p.y), toY(p.x, p.y), 1.5, 0, Math.PI * 2); ctx.fill();
    }
    for (var j = 0; j < bodies.length; j++) drawTrail(bodies[j], TRAIL, LN, 0.75, 1.6);
    for (var k = 0; k < bodies.length; k++) {
      var b = bodies[k], X = toX(b.x, b.y), Y = toY(b.x, b.y), r = 2.5 + Math.sqrt(b.m) * 3.2;
      var g = ctx.createRadialGradient(X, Y, 0, X, Y, r * 4.5);
      g.addColorStop(0, P.rgba(LN, 0.28)); g.addColorStop(1, P.rgba(LN, 0));
      ctx.fillStyle = g;
      ctx.beginPath(); ctx.arc(X, Y, r * 4.5, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = P.rgba(LN, 1);
      ctx.beginPath(); ctx.arc(X, Y, r, 0, Math.PI * 2); ctx.fill();
    }
    /* 引っぱっている間: 打ち出す向き */
    if (drag) {
      ctx.strokeStyle = P.rgba(AC, 0.6);
      ctx.lineWidth = 1;
      ctx.setLineDash([3, 5]);
      ctx.beginPath(); ctx.moveTo(drag.x0, drag.y0); ctx.lineTo(drag.x, drag.y); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = P.rgba(AC, 0.9);
      ctx.beginPath(); ctx.arc(drag.x0, drag.y0, 3, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var drag = null;
  function toSim(X, Y) {
    var x = (X - CX) / U, y = (Y - CY) / U;
    return [x * cv + y * sv, -x * sv + y * cv];
  }
  canvas.addEventListener('pointerdown', function (e) {
    drag = { x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY };
    var ws = toSim(e.clientX, e.clientY); well.x = ws[0]; well.y = ws[1]; well.m = Math.max(well.m, 1.2);
    SFX.play('tick');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!drag) return;
    drag.x = e.clientX; drag.y = e.clientY;
    var ws = toSim(e.clientX, e.clientY); well.x = ws[0]; well.y = ws[1];
    SFX.play('move', 260);
  });
  function up() {
    if (!drag) return;
    var s = toSim(drag.x0, drag.y0), e2 = toSim(drag.x, drag.y);
    var dx = s[0] - e2[0], dy = s[1] - e2[1], len = Math.hypot(dx, dy);
    if (len * U < 8) {
      /* 軽く触ると、その場から粒が輪になってこぼれる */
      for (var i = 0; i < 6; i++) {
        var a = i / 6 * Math.PI * 2;
        addDust(s[0] + Math.cos(a) * 0.03, s[1] + Math.sin(a) * 0.03, Math.cos(a) * 0.08, Math.sin(a) * 0.08, true);
      }
      SFX.play('pop');
    } else {
      /* ゴムのように、引いた向きと反対へ飛ぶ */
      var k = 1.6;
      for (var j = 0; j < 3; j++) addDust(s[0], s[1], dx * k * (1 + j * 0.04), dy * k * (1 + j * 0.04), true);
      SFX.play('spawn');
    }
    drag = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { drag = null; });

  var timeEl = document.getElementById('k-time'), simSpeed = 0.45;
  function readTime() { simSpeed = 0.15 + timeEl.value / 100 * 0.75; }
  timeEl.addEventListener('input', readTime);
  readTime();
  document.querySelectorAll('[data-sys]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-sys]').forEach(function (o) { o.classList.toggle('active', o === b); });
      sys = b.dataset.sys; setup(); warm();
      SFX.play('toggle');
    });
  });
  document.getElementById('k-reset').addEventListener('click', function () { setup(); warm(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  var LIFE = 26, H_STEP = 0.0015, recAcc = 0, dustAcc = 0;
  function advance(simT) {
    var n = Math.ceil(simT / H_STEP), h = simT / n;
    for (var i = 0; i < n; i++) {
      step(h);
      recAcc += h;
      if (recAcc > (sys === 'eight' ? 0.008 : 0.03)) {
        recAcc = 0;
        for (var j = 0; j < bodies.length; j++) record(bodies[j], TRAIL);
        for (var k = 0; k < dust.length; k++) record(dust[k], DTRAIL);
      }
    }
  }
  function warm() {
    /* 最初から軌道の線が見えているように、少し先まで回しておく */
    advance(sys === 'eight' ? 4.5 : 9);
  }
  setup(); warm();
  autoDust(); autoDust();

  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    advance(dt * simSpeed * (sys === 'eight' ? 1 : 1.6));
    for (var i = dust.length - 1; i >= 0; i--) {
      var p = dust[i];
      p.age += dt;
      if (p.age > LIFE || Math.hypot(p.x, p.y) > 9) dust.splice(i, 1);
    }
    dustAcc += dt;
    if (dustAcc > 2.6) { dustAcc = 0; autoDust(); }
    view += dt * 0.012;
    cv = Math.cos(view); sv = Math.sin(view);
    /* 押している間は重りが育ち、離すと消えていく */
    well.m = drag ? Math.min(1.8, well.m + dt * 1.5) : well.m * Math.exp(-dt * 1.1);

    drawField();
    P.grid(ctx, W, H, { alpha: 0.5 });
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
