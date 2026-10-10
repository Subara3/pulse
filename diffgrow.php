<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('diffgrow');

$pageName = 'diffgrow';
$pageTitle = 'DIFFGROW - PULSE';
$pageDesc = '小さな輪の線が少しずつ長くなり、線どうしが押し合って、曲がりくねった模様に折りたたまれていく。少し前の輪郭は細い線で残る。押すと指のまわりの線が円く押しのけられ、なぞると指の通った跡に沿って線がよける。';
$pageCanonical = 'https://pulse.subara3.com/diffgrow.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-grow">GROW</label><input type="range" id="k-grow" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-fold">FOLD</label><input type="range" id="k-fold" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /* 閉じた 1 本のひも。隣とは引き合い、近くの他の点とは押し合い、ときどき点が増えて長くなる */
  var CAP = 3200;
  var X = new Float32Array(CAP), Y = new Float32Array(CAP), VX = new Float32Array(CAP), VY = new Float32Array(CAP),
      HOT = new Float32Array(CAP), n = 0;
  var TX = new Float32Array(CAP), TY = new Float32Array(CAP);
  var L = 6, RR = 16;                     /* ひもの刻み・押し合う距離 (px) */
  var grow = 0.45, fold = 0.5;
  var cx, cy, bound, hist = [], histT = 0, phase = 'grow', phaseT = 0, life = 1;
  var GW, GH, head, nxt;

  function start() {
    cx = W / 2 + (Math.random() - 0.5) * W * 0.06;
    cy = H / 2 + (Math.random() - 0.5) * H * 0.06;
    bound = Math.min(W, H) * 0.46;
    var r0 = Math.min(W, H) * 0.24, m = Math.round(r0 * 6.2832 / L);
    n = m;
    for (var i = 0; i < m; i++) {
      var a = i / m * 6.2832, rj = r0 * (1 + Math.sin(a * 5 + 1) * 0.06 + Math.sin(a * 9) * 0.03 + (Math.random() - 0.5) * 0.04);
      X[i] = cx + Math.cos(a) * rj; Y[i] = cy + Math.sin(a) * rj; VX[i] = VY[i] = 0; HOT[i] = 0;
    }
    hist.length = 0; histT = 0; phase = 'grow'; phaseT = 0; life = 1;
  }

  function alloc() {
    GW = Math.ceil(W / RR) + 1; GH = Math.ceil(H / RR) + 1;
    head = new Int32Array(GW * GH);
    nxt = new Int32Array(CAP);
    start();
    /* 開いた瞬間からひだが見えるように、少し先まで進めておく */
    for (var k = 0; k < 420; k++) { step(1 / 60, true); if (k % 60 === 59) snap(); }
  }

  function insertAfter(i) {
    if (n >= CAP) return;
    var j = (i + 1) % n;
    for (var k = n; k > i + 1; k--) {
      X[k] = X[k - 1]; Y[k] = Y[k - 1]; VX[k] = VX[k - 1]; VY[k] = VY[k - 1]; HOT[k] = HOT[k - 1];
    }
    if (j === 0) { X[i + 1] = (X[i] + X[0]) / 2; Y[i + 1] = (Y[i] + Y[0]) / 2; }
    else { X[i + 1] = (X[i] + X[i + 2]) / 2; Y[i + 1] = (Y[i] + Y[i + 2]) / 2; }
    VX[i + 1] = VY[i + 1] = 0;
    HOT[i + 1] = HOT[i];
    n++;
  }

  var press = null;
  function step(dt, quiet) {
    var i, k;
    /* 格子に振り分けて、近くの点だけを調べる */
    head.fill(-1);
    for (i = 0; i < n; i++) {
      var gx = (X[i] / RR) | 0, gy = (Y[i] / RR) | 0;
      if (gx < 0) gx = 0; else if (gx >= GW) gx = GW - 1;
      if (gy < 0) gy = 0; else if (gy >= GH) gy = GH - 1;
      k = gy * GW + gx;
      nxt[i] = head[k]; head[k] = i;
    }
    var kr = 0.22 + fold * 0.3, R2 = RR * RR;
    for (i = 0; i < n; i++) {
      var x = X[i], y = Y[i];
      var a = i === 0 ? n - 1 : i - 1, b = i === n - 1 ? 0 : i + 1;
      /* 隣どうしの真ん中へ寄る（ひもをなめらかに保つ） */
      var fx = ((X[a] + X[b]) * 0.5 - x) * 0.45, fy = ((Y[a] + Y[b]) * 0.5 - y) * 0.45;
      var cgx = (x / RR) | 0, cgy = (y / RR) | 0;
      for (var oy = -1; oy <= 1; oy++) {
        var yy = cgy + oy;
        if (yy < 0 || yy >= GH) continue;
        for (var ox = -1; ox <= 1; ox++) {
          var xx = cgx + ox;
          if (xx < 0 || xx >= GW) continue;
          for (var j = head[yy * GW + xx]; j >= 0; j = nxt[j]) {
            if (j === i || j === a || j === b) continue;
            var dx = x - X[j], dy = y - Y[j], d2 = dx * dx + dy * dy;
            if (d2 >= R2 || d2 < 1e-6) continue;
            var d = Math.sqrt(d2), f = (RR - d) / RR * kr;
            fx += dx / d * f * 1.6; fy += dy / d * f * 1.6;
          }
        }
      }
      /* 外の輪にはやわらかく押し戻される */
      var ex = x - cx, ey = y - cy, er = Math.sqrt(ex * ex + ey * ey);
      if (er > bound) { fx -= ex / er * (er - bound) * 0.08; fy -= ey / er * (er - bound) * 0.08; }
      /* 触っているところからはよける */

      TX[i] = fx; TY[i] = fy;
    }
    var mv = quiet ? 1.6 : 1.2;
    for (i = 0; i < n; i++) {
      VX[i] = (VX[i] + TX[i]) * 0.5; VY[i] = (VY[i] + TY[i]) * 0.5;
      var sp = Math.sqrt(VX[i] * VX[i] + VY[i] * VY[i]);
      if (sp > mv) { VX[i] *= mv / sp; VY[i] *= mv / sp; }
      X[i] += VX[i]; Y[i] += VY[i];
      if (HOT[i] > 0) HOT[i] = Math.max(0, HOT[i] - dt * 0.3);
    }
    /* 指のまわりの円の中からは、縁がすぐに押し出される */
    if (press) {
      var PR = 95, ms = Math.sqrt(press.vx * press.vx + press.vy * press.vy);
      /* 動かしているときは進む向きの左右へよけさせ、縁を引きずらない */
      var ux = ms > 0.5 ? press.vx / ms : 0, uy = ms > 0.5 ? press.vy / ms : 0;
      for (i = 0; i < n; i++) {
        var qx = X[i] - press.x, qy = Y[i] - press.y, q2 = qx * qx + qy * qy;
        if (q2 < PR * PR) {
          var qd = Math.sqrt(q2) + 1e-3, tx2, ty2;
          if (ms > 0.5) {
            var along = qx * ux + qy * uy, side = -qx * uy + qy * ux, sg = side >= 0 ? 1 : -1;
            var half = Math.sqrt(Math.max(0, PR * PR - along * along)) * 0.8;
            if (Math.abs(side) >= half) continue;
            tx2 = press.x + ux * along - uy * sg * half; ty2 = press.y + uy * along + ux * sg * half;
          } else { tx2 = press.x + qx / qd * PR; ty2 = press.y + qy / qd * PR; }
          X[i] += (tx2 - X[i]) * 0.35; Y[i] += (ty2 - Y[i]) * 0.35;
          VX[i] = VY[i] = 0;
          HOT[i] = 1;
        }
      }
    }
    if (phase !== 'grow') return;
    /* 伸びすぎた区間に点を足す。さらにところどころで点を足して、ひもを長くする */
    for (i = n - 1; i >= 0; i--) {
      var i2 = i === n - 1 ? 0 : i + 1;
      var ddx = X[i2] - X[i], ddy = Y[i2] - Y[i];
      if (ddx * ddx + ddy * ddy > L * L * 2.1) insertAfter(i);
    }
    var births = (0.6 + grow * 3.2) * dt * 60 * 0.32;
    while (births > 0) {
      if (Math.random() < births) {
        var r = (Math.random() * n) | 0;
        /* 触ったあとの区間はよく伸びる */
        insertAfter(r);
        if (HOT[r] > 0.2 && Math.random() < 0.8) insertAfter(r);
      }
      births -= 1;
    }
  }

  function snap() {
    var m = Math.ceil(n / 2), a = new Float32Array(m * 2);
    for (var i = 0, k = 0; i < n; i += 2, k++) { a[k * 2] = X[i]; a[k * 2 + 1] = Y[i]; }
    hist.push({ p: a, m: m });
    if (hist.length > 7) hist.shift();
  }

  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, rings = [];
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    press = { x: e.clientX, y: e.clientY, vx: 0, vy: 0 };
    rings.push({ x: press.x, y: press.y, t: 0 });
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - press.x, dy = e.clientY - press.y;
    press = { x: e.clientX, y: e.clientY, vx: press.vx * 0.5 + dx * 0.5, vy: press.vy * 0.5 + dy * 0.5 };
    if (dx * dx + dy * dy > 16) SFX.play('move', 200);
  });
  function up() { down = false; press = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kGrow = document.getElementById('k-grow'), kFold = document.getElementById('k-fold');
  kGrow.addEventListener('input', function () { grow = this.value / 100; });
  kFold.addEventListener('input', function () { fold = this.value / 100; });
  document.getElementById('k-new').addEventListener('click', function () {
    if (phase === 'grow' || phase === 'hold') { phase = 'fade'; phaseT = 0; }
    SFX.play('click');
  });
  grow = kGrow.value / 100;
  fold = kFold.value / 100;

  /* ---------------------------------------------------------- 描く */
  function path(xs, ys, m, stride) {
    ctx.beginPath();
    ctx.moveTo(xs[0], ys[0]);
    for (var i = 1; i < m; i++) ctx.lineTo(xs[i * stride], ys[i * stride]);
    ctx.closePath();
  }
  function draw(dt) {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.5 });
    var i;
    /* 中を暗い藍で塗る */
    ctx.beginPath();
    ctx.moveTo(X[0], Y[0]);
    for (i = 1; i < n; i++) ctx.lineTo(X[i], Y[i]);
    ctx.closePath();
    ctx.fillStyle = P.rgba(P.mix(P.bg, P.steel, 0.3), 0.9 * life);
    ctx.fill('evenodd');

    /* 前の輪郭。古いほど薄い */
    ctx.lineJoin = 'round';
    for (var hI = 0; hI < hist.length; hI++) {
      var h = hist[hI], p = h.p, age = (hist.length - hI) / hist.length;
      ctx.beginPath();
      ctx.moveTo(p[0], p[1]);
      for (i = 1; i < h.m; i++) ctx.lineTo(p[i * 2], p[i * 2 + 1]);
      ctx.closePath();
      ctx.strokeStyle = P.rgba(P.steel, (0.5 - age * 0.38) * life);
      ctx.lineWidth = 1;
      ctx.stroke();
    }

    /* いまの縁 */
    ctx.beginPath();
    ctx.moveTo(X[0], Y[0]);
    for (i = 1; i < n; i++) ctx.lineTo(X[i], Y[i]);
    ctx.closePath();
    ctx.strokeStyle = P.rgba(P.line, 0.92 * life);
    ctx.lineWidth = 1.5;
    ctx.stroke();

    /* 触って伸びている区間だけ、シアンで重ねる */
    ctx.strokeStyle = P.rgba(P.accent, 0.9 * life);
    ctx.lineWidth = 1.7;
    ctx.beginPath();
    var on = false;
    for (i = 0; i < n; i++) {
      if (HOT[i] > 0.05) {
        if (!on) { ctx.moveTo(X[i], Y[i]); on = true; } else ctx.lineTo(X[i], Y[i]);
      } else on = false;
    }
    ctx.stroke();

    ctx.lineWidth = 1;
    for (i = rings.length - 1; i >= 0; i--) {
      var rg = rings[i];
      rg.t += dt;
      var al = 1 - rg.t / 1.2;
      if (al <= 0) { rings.splice(i, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, al * 0.5);
      ctx.beginPath();
      ctx.arc(rg.x, rg.y, 10 + rg.t * 60, 0, Math.PI * 2);
      ctx.stroke();
    }
    if (press) {
      ctx.strokeStyle = P.rgba(P.accent, 0.35);
      ctx.beginPath();
      ctx.arc(press.x, press.y, 95, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    phaseT += dt;
    if (press) { press.vx *= 0.8; press.vy *= 0.8; }
    step(dt, false);
    histT += dt;
    if (phase === 'grow' && histT > 5) { histT = 0; snap(); }
    if (phase === 'grow' && n >= CAP - 40) { phase = 'hold'; phaseT = 0; SFX.play('tick', 3000); }
    if (phase === 'hold' && phaseT > 8) { phase = 'fade'; phaseT = 0; }
    if (phase === 'fade') {
      life = Math.max(0, 1 - phaseT / 5);
      if (life <= 0) { start(); SFX.play('spawn', 3000); }
    } else if (life < 1) life = Math.min(1, life + dt);
    draw(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
