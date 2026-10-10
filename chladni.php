<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('chladni');

$pageName = 'chladni';
$pageTitle = 'CHLADNI - PULSE';
$pageDesc = '震える四角い板の上で白い砂が跳ね、揺れない線の上に集まって模様になる。15秒ほどごとに振動が変わり、砂が次の模様へ移っていく。指でなぞると砂が払われ、しばらくすると線に戻る。';
$pageCanonical = 'https://pulse.subara3.com/chladni.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-shake">SHAKE</label><input type="range" id="k-shake" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-cycle="on">DRIFT</button><button type="button" data-cycle="off">HOLD</button></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * 正方形の板の振動の形: cos(nπx)cos(mπy) ± cos(mπx)cos(nπy)
   * 揺れの大きい所の砂ほど大きく跳ねるので、揺れない線（節線）にだけ砂が残る。
   */
  var MODES = [[1, 3, -1], [2, 3, 1], [1, 4, 1], [3, 4, -1], [2, 5, 1], [1, 5, -1], [3, 5, 1],
               [4, 5, -1], [2, 6, 1], [1, 6, -1], [3, 7, -1], [4, 7, 1], [5, 6, 1], [2, 7, -1]];
  var NG, gx, gy, still;
  var S = 0, X0 = 0, Y0 = 0;
  var cur = 6, prev = 0, blend = 1, shake = 0.5, cycle = true, sinceSwitch = 0;
  var sweeps = [];
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  function alloc() {
    S = Math.round(Math.min(W * 0.9, H * 0.86));
    X0 = Math.round((W - S) / 2); Y0 = Math.round((H - S) / 2);
    NG = Math.max(6000, Math.min(20000, Math.round(S * S / 22)));
    gx = new Float32Array(NG); gy = new Float32Array(NG); still = new Float32Array(NG);
    for (var i = 0; i < NG; i++) { gx[i] = Math.random(); gy[i] = Math.random(); }
    /* 開いた瞬間から模様が見えるように、砂を少し先まで動かしておく */
    for (var s = 0; s < 70; s++) move(1 / 60);
  }

  /* 振動の形と、その傾き（x, y 方向の微分） */
  var F = 0, FX = 0, FY = 0;
  function evalMode(md, x, y, w) {
    var n = md[0], m = md[1], s = md[2], PI = Math.PI;
    var cnx = Math.cos(n * PI * x), snx = Math.sin(n * PI * x), cmy = Math.cos(m * PI * y), smy = Math.sin(m * PI * y);
    var cmx = Math.cos(m * PI * x), smx = Math.sin(m * PI * x), cny = Math.cos(n * PI * y), sny = Math.sin(n * PI * y);
    F += w * (cnx * cmy + s * cmx * cny);
    FX += w * PI * (-n * snx * cmy - s * m * smx * cny);
    FY += w * PI * (-m * cnx * smy - s * n * cmx * sny);
  }
  function field(x, y) {
    var e = blend * blend * (3 - 2 * blend);
    F = 0; FX = 0; FY = 0;
    evalMode(MODES[cur], x, y, e * 0.5);
    if (e < 1) evalMode(MODES[prev], x, y, (1 - e) * 0.5);
  }

  function move(dt) {
    var k = (0.004 + shake * 0.012) * dt * 60;
    var pull = (0.00012 + shake * 0.00034) * dt * 60;
    for (var i = 0; i < NG; i++) {
      var x = gx[i], y = gy[i];
      field(x, y);
      var a = Math.abs(F);
      /* 揺れの大きさに比例して跳ねる。跳ねた砂は少しずつ揺れの小さい方へ寄っていく */
      var j = k * a * (0.6 + a) + 0.0026;
      var gl = Math.sqrt(FX * FX + FY * FY) + 1e-6;
      var dist = F / gl, d = Math.abs(dist) < pull ? dist * 0.5 : (dist > 0 ? pull : -pull);
      x += (Math.random() - 0.5) * j * 2 - d * FX / gl;
      y += (Math.random() - 0.5) * j * 2 - d * FY / gl;
      if (x < 0) x = -x; else if (x > 1) x = 2 - x;
      if (y < 0) y = -y; else if (y > 1) y = 2 - y;
      gx[i] = x; gy[i] = y;
      still[i] = a;
    }
  }

  function nextMode() {
    prev = cur;
    var n;
    do { n = Math.floor(Math.random() * MODES.length); } while (n === cur);
    cur = n; blend = 0; sinceSwitch = 0;
  }

  /* ---------------------------------------------------------- 描く */
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.6 });
    /* 板: 細い二重の外形線と、四隅の目印 */
    ctx.save();
    ctx.fillStyle = P.rgba(P.deep, 0.55);
    ctx.fillRect(X0, Y0, S, S);
    ctx.strokeStyle = P.rgba(P.line, 0.42);
    ctx.lineWidth = 1;
    ctx.strokeRect(X0 - 0.5, Y0 - 0.5, S + 1, S + 1);
    ctx.strokeStyle = P.rgba(P.line, 0.14);
    ctx.strokeRect(X0 - 6.5, Y0 - 6.5, S + 13, S + 13);
    ctx.strokeStyle = P.rgba(P.line, 0.3);
    ctx.beginPath();
    var c = 14;
    [[X0, Y0], [X0 + S, Y0], [X0, Y0 + S], [X0 + S, Y0 + S]].forEach(function (p) {
      ctx.moveTo(p[0] - c - 8, p[1]); ctx.lineTo(p[0] - 8, p[1]);
      ctx.moveTo(p[0] + 8, p[1]); ctx.lineTo(p[0] + c + 8, p[1]);
      ctx.moveTo(p[0], p[1] - c - 8); ctx.lineTo(p[0], p[1] - 8);
      ctx.moveTo(p[0], p[1] + 8); ctx.lineTo(p[0], p[1] + c + 8);
    });
    ctx.stroke();
    ctx.restore();

    /* 砂: 落ち着いた粒ははっきり、跳ねている粒は淡く */
    var tiers = [[0.06, 0.9, 1.5], [0.18, 0.55, 1.3], [0.4, 0.3, 1.2], [9, 0.16, 1.1]];
    for (var t = 0; t < tiers.length; t++) {
      var lo = t ? tiers[t - 1][0] : -1, hi = tiers[t][0], sz = tiers[t][2];
      ctx.fillStyle = P.rgba(P.line, tiers[t][1]);
      ctx.beginPath();
      for (var i = 0; i < NG; i++) {
        var a = still[i];
        if (a <= lo || a > hi) continue;
        ctx.rect(X0 + gx[i] * S - sz * 0.5, Y0 + gy[i] * S - sz * 0.5, sz, sz);
      }
      ctx.fill();
    }

    /* 指で払った跡 */
    ctx.lineWidth = 1;
    for (var n = sweeps.length - 1; n >= 0; n--) {
      var sw = sweeps[n];
      var al = 1 - sw.t / 1.8;
      if (al <= 0) { sweeps.splice(n, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, al * 0.6);
      ctx.beginPath();
      ctx.arc(sw.x, sw.y, sw.r + sw.t * 18, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  /* 指で砂を払う: 近くの粒を外へ弾く */
  function brush(px, py, r, power) {
    var cx = (px - X0) / S, cy = (py - Y0) / S, rr = r / S;
    sweeps.push({ x: px, y: py, r: r * 0.5, t: 0 });
    if (sweeps.length > 24) sweeps.shift();
    for (var i = 0; i < NG; i++) {
      var dx = gx[i] - cx, dy = gy[i] - cy, d = Math.sqrt(dx * dx + dy * dy);
      if (d > rr || d < 1e-6) continue;
      var push = (1 - d / rr) * rr * power * (0.6 + Math.random() * 0.8);
      var x = gx[i] + dx / d * push, y = gy[i] + dy / d * push;
      if (x < 0) x = -x; else if (x > 1) x = 2 - x;
      if (y < 0) y = -y; else if (y > 1) y = 2 - y;
      gx[i] = Math.max(0, Math.min(1, x)); gy[i] = Math.max(0, Math.min(1, y));
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    brush(last.x, last.y, S * 0.08, 1.6);
    SFX.play('collide', 200);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, d = Math.hypot(p.x - last.x, p.y - last.y);
    if (d < 12) return;
    brush(p.x, p.y, S * 0.055, 1.1);
    last = p;
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-shake').addEventListener('input', function () { shake = this.value / 100; });
  document.querySelectorAll('[data-cycle]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-cycle]').forEach(function (o) { o.classList.toggle('active', o === b); });
      cycle = b.dataset.cycle === 'on';
      sinceSwitch = 0;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-next').addEventListener('click', function () { nextMode(); SFX.play('click'); });
  shake = document.getElementById('k-shake').value / 100;
  cycle = !!document.querySelector('[data-cycle="on"].active');

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    if (blend < 1) blend = Math.min(1, blend + dt / 5);
    sinceSwitch += dt;
    if (cycle && sinceSwitch > 15) { nextMode(); SFX.play('grow', 4000); }
    move(dt);
    for (var n = 0; n < sweeps.length; n++) sweeps[n].t += dt;
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
