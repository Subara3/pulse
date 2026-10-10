<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pendulum');

$pageName = 'pendulum';
$pageTitle = 'PENDULUM - PULSE';
$pageDesc = '40本の二重振り子がほぼ同じ角度から同時に動き出す。はじめは重なって1本に見えるが、少しずつずれてばらばらに回るようになる。押さえると先端がその位置まで持ち上がり、放すと40本が一斉に落ちる。';
$pageCanonical = 'https://pulse.subara3.com/pendulum.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-spread">SPREAD</label><input type="range" id="k-spread" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, L = 100, OX = 0, OY = 0;
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    L = Math.min(w, h) * 0.235;
    OX = w / 2; OY = h * 0.44;
  });

  /* 同じ長さ・同じ重さの二重振り子。角度は真下から測る */
  var N = 40, G = 6.4;
  var t1 = new Float64Array(N), t2 = new Float64Array(N), w1 = new Float64Array(N), w2 = new Float64Array(N);
  var TR = 48;
  var tx = new Float32Array(N * TR), ty = new Float32Array(N * TR), th = 0, tn = 0;

  function acc(a1, a2, v1, v2, out) {
    var d = a1 - a2, den = 2 - Math.cos(2 * d);
    out[0] = (-3 * G * Math.sin(a1) - G * Math.sin(a1 - 2 * a2) - 2 * Math.sin(d) * (v2 * v2 + v1 * v1 * Math.cos(d))) / den;
    out[1] = (2 * Math.sin(d) * (2 * v1 * v1 + 2 * G * Math.cos(a1) + v2 * v2 * Math.cos(d))) / den;
  }
  var K1 = [0, 0], K2 = [0, 0], K3 = [0, 0], K4 = [0, 0];
  function rk4(i, h) {
    var a1 = t1[i], a2 = t2[i], v1 = w1[i], v2 = w2[i];
    acc(a1, a2, v1, v2, K1);
    acc(a1 + v1 * h / 2, a2 + v2 * h / 2, v1 + K1[0] * h / 2, v2 + K1[1] * h / 2, K2);
    var b1 = v1 + K1[0] * h / 2, b2 = v2 + K1[1] * h / 2;
    acc(a1 + b1 * h / 2, a2 + b2 * h / 2, v1 + K2[0] * h / 2, v2 + K2[1] * h / 2, K3);
    var c1 = v1 + K2[0] * h / 2, c2 = v2 + K2[1] * h / 2;
    acc(a1 + c1 * h, a2 + c2 * h, v1 + K3[0] * h, v2 + K3[1] * h, K4);
    var d1 = v1 + K3[0] * h, d2 = v2 + K3[1] * h;
    t1[i] = a1 + h / 6 * (v1 + 2 * b1 + 2 * c1 + d1);
    t2[i] = a2 + h / 6 * (v2 + 2 * b2 + 2 * c2 + d2);
    w1[i] = v1 + h / 6 * (K1[0] + 2 * K2[0] + 2 * K3[0] + K4[0]);
    w2[i] = v2 + h / 6 * (K1[1] + 2 * K2[1] + 2 * K3[1] + K4[1]);
  }

  var spread = 0.001;
  function setAll(a1, a2) {
    for (var i = 0; i < N; i++) {
      var e = (i - N / 2) * spread;
      t1[i] = a1 + e; t2[i] = a2 + e * 0.5; w1[i] = 0; w2[i] = 0;
    }
    tn = 0;
  }
  var STARTS = [[2.3, 2.9], [1.9, -2.6], [-2.5, 2.2], [2.7, 0.4], [-1.8, -3.0], [3.0, 1.6]];
  var startI = 0;
  function freshStart() {
    startI = (startI + 1 + (Math.random() * (STARTS.length - 1) | 0)) % STARTS.length;
    var s = STARTS[startI];
    setAll(s[0] + (Math.random() - 0.5) * 0.2, s[1] + (Math.random() - 0.5) * 0.2);
  }

  /* ---------------------------------------------------------- 描く */
  var hot = 0, fade = 1, spreadQ = 0;
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.7 });
    /* 届く範囲の円と、支点 */
    ctx.strokeStyle = P.rgba(P.steel, 0.16);
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(OX, OY, L * 2, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.arc(OX, OY, L, 0, Math.PI * 2); ctx.stroke();

    var col = P.mix(P.line, P.accent, hot);
    var i, k;
    /* 先端がどれだけ散ったか。散るほど腕を薄く、先端の軌跡を濃くする */
    var mxs = 0, mys = 0, vs = 0;
    for (i = 0; i < N; i++) { mxs += Math.sin(t1[i]) + Math.sin(t2[i]); mys += Math.cos(t1[i]) + Math.cos(t2[i]); }
    mxs /= N; mys /= N;
    for (i = 0; i < N; i++) {
      var ex = Math.sin(t1[i]) + Math.sin(t2[i]) - mxs, ey = Math.cos(t1[i]) + Math.cos(t2[i]) - mys;
      vs += ex * ex + ey * ey;
    }
    var q = Math.min(1, Math.sqrt(vs / N) / 1.1);
    spreadQ += (q - spreadQ) * 0.05;
    q = spreadQ;
    /* 先端の短い軌跡 */
    if (tn > 1) {
      ctx.lineWidth = 1;
      ctx.strokeStyle = P.rgba(col, (0.05 + q * 0.14) * fade);
      for (i = 0; i < N; i++) {
        ctx.beginPath();
        for (k = 0; k < tn; k++) {
          var qi = ((th - tn + k + TR) % TR) * N + i;
          if (k === 0) ctx.moveTo(tx[qi], ty[qi]); else ctx.lineTo(tx[qi], ty[qi]);
        }
        ctx.stroke();
      }
    }
    /* 腕 */
    ctx.lineWidth = 1.3;
    ctx.lineCap = 'round';
    /* 1 本ずつ引く。重なった所ほど白く濃くなる */
    ctx.strokeStyle = P.rgba(col, 0.11 * (1 - q * 0.72) * fade);
    ctx.fillStyle = P.rgba(col, (0.14 + q * 0.06) * fade);
    for (i = 0; i < N; i++) {
      var x1 = OX + Math.sin(t1[i]) * L, y1 = OY + Math.cos(t1[i]) * L;
      var x2 = x1 + Math.sin(t2[i]) * L, y2 = y1 + Math.cos(t2[i]) * L;
      ctx.beginPath(); ctx.moveTo(OX, OY); ctx.lineTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
      ctx.beginPath(); ctx.arc(x1, y1, 3, 0, Math.PI * 2); ctx.moveTo(x2 + 3.8, y2); ctx.arc(x2, y2, 3.8, 0, Math.PI * 2); ctx.fill();
    }
    ctx.fillStyle = P.rgba(P.line, 0.9);
    ctx.beginPath(); ctx.arc(OX, OY, 3, 0, Math.PI * 2); ctx.fill();
  }
  function recordTips() {
    for (var i = 0; i < N; i++) {
      var x = OX + (Math.sin(t1[i]) + Math.sin(t2[i])) * L, y = OY + (Math.cos(t1[i]) + Math.cos(t2[i])) * L;
      tx[th * N + i] = x; ty[th * N + i] = y;
    }
    th = (th + 1) % TR; if (tn < TR) tn++;
  }

  /* ---------------------------------------------------------- 操作 */
  var held = false;
  function grab(e) {
    var dx = e.clientX - OX, dy = e.clientY - OY, d = Math.hypot(dx, dy);
    d = Math.max(L * 0.1, Math.min(L * 1.98, d));
    var phi = Math.atan2(dx, dy), al = Math.acos(d / (2 * L));
    setAll(phi + al, phi - al);
  }
  canvas.addEventListener('pointerdown', function (e) {
    held = true; hot = 1; fade = 1; phase = 'run'; runT = 0;
    grab(e);
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    grab(e);
    SFX.play('move', 240);
  });
  function up() { if (held) { held = false; SFX.play('tick'); } }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var speed = 1;
  var kSpeed = document.getElementById('k-speed'), kSpread = document.getElementById('k-spread');
  function readSpeed() { speed = 0.35 + kSpeed.value / 100 * 1.1; }
  function readSpread() { spread = Math.pow(10, -5.5 + kSpread.value / 100 * 3.5); }
  kSpeed.addEventListener('input', readSpeed);
  kSpread.addEventListener('input', readSpread);
  readSpeed(); readSpread();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), phase = 'run', runT = 0;
  setAll(2.3, 2.9);
  /* 最初の数秒は 1 本に見えるだけなので、少し進めた所から始める */
  for (var s0 = 0; s0 < 160; s0++) for (var i0 = 0; i0 < N; i0++) rk4(i0, 0.01);
  var hum = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    if (!held) {
      var sub = 6, h = dt * speed / sub;
      for (var s = 0; s < sub; s++) for (var i = 0; i < N; i++) rk4(i, h);
      hot = Math.max(0, hot - dt * 0.25);
      runT += dt;
      /* 40 秒ほどで静かに消えて、別の角度から始め直す */
      if (phase === 'run' && runT > 30) phase = 'out';
      if (phase === 'out') { fade -= dt * 0.6; if (fade <= 0) { fade = 0; freshStart(); phase = 'in'; } }
      if (phase === 'in') { fade += dt * 0.8; if (fade >= 1) { fade = 1; phase = 'run'; runT = 0; } }
      hum -= dt;
      if (hum <= 0) { SFX.play('tick', 4000); hum = 6 + Math.random() * 4; }
    }
    recordTips();
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
