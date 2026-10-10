<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('harmonograph');

$pageName = 'harmonograph';
$pageTitle = 'HARMONOGRAPH - PULSE';
$pageDesc = '2つの振り子につながったペンが、楕円を少しずつ回しながら重ねて描く。揺れが弱まるにつれて、線は中心へ縮んでいく。ドラッグすると振り子が押されて、その向きに線が大きく広がる。';
$pageCanonical = 'https://pulse.subara3.com/harmonograph.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-decay">DECAY</label><input type="range" id="k-decay" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DPR = 1, layer = null, lctx = null;
  var ctx = P.fit(canvas, function (w, h, dpr) {
    W = w; H = h; DPR = dpr;
    layer = document.createElement('canvas');
    layer.width = Math.round(w * dpr); layer.height = Math.round(h * dpr);
    lctx = layer.getContext('2d');
    lctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    lctx.lineCap = 'round'; lctx.lineJoin = 'round';
    lastX = null;
  });

  /* 振り子 4 つ: ペン側（x, y）と台側（x, y）。それぞれ減衰する単振動。
     ペン = ペン側 − 台側。振動数が整数比から少しだけずれているので、輪がゆっくり回る */
  var RATIOS = [
    [2, 3.006, 0.5], [3, 4.004, 0.25], [1, 3.004, 0.5], [3, 5.006, 0.5],
    [2, 5.005, 0.25], [1, 2.003, 0.5], [4, 5.004, 0.5], [3, 2.004, 0.25]
  ];
  var ri = Math.random() * RATIOS.length | 0;
  var om = new Float64Array(4), u = new Float64Array(4), v = new Float64Array(4);
  var gamma = 0.08, speed = 1, startAmp = 1;
  var BASE = 2.4;

  function setup() {
    ri = (ri + 1 + (Math.random() * (RATIOS.length - 1) | 0)) % RATIOS.length;
    var r = RATIOS[ri], R = Math.min(W, H) * 0.23;
    om[0] = om[1] = r[0] * BASE;
    om[2] = om[3] = r[1] * BASE;
    var p1 = Math.random() * Math.PI * 2, p2 = Math.random() * Math.PI * 2;
    /* ペン側は円に近い楕円、台側は向きの違う楕円 */
    var amp = [R, R * (0.75 + Math.random() * 0.25), R * 0.95, R * (0.7 + Math.random() * 0.3)];
    var ph = [p1, p1 + Math.PI * r[2] * 2, p2, p2 + Math.PI * (0.3 + Math.random() * 0.4)];
    for (var i = 0; i < 4; i++) { u[i] = amp[i] * Math.cos(ph[i]); v[i] = -amp[i] * om[i] * Math.sin(ph[i]); }
    startAmp = energy();
    lastX = null; drawn = 0;
    lctx.clearRect(0, 0, W, H);
  }
  function energy() {
    var e = 0;
    for (var i = 0; i < 4; i++) e += v[i] * v[i] / (om[i] * om[i]) + u[i] * u[i];
    return Math.sqrt(e);
  }
  function step(h) {
    for (var i = 0; i < 4; i++) {
      v[i] += (-om[i] * om[i] * u[i] - gamma * v[i]) * h;
      u[i] += v[i] * h;
    }
  }
  function penX() { return W / 2 + u[0] - u[2]; }
  function penY() { return H / 2 + u[1] - u[3]; }

  /* ---------------------------------------------------------- 描く */
  var lastX = null, lastY = 0, drawn = 0;
  function ink(sub, h, alpha) {
    lctx.strokeStyle = P.rgba(P.line, alpha);
    lctx.lineWidth = 0.8;
    lctx.beginPath();
    if (lastX === null) { lastX = penX(); lastY = penY(); }
    lctx.moveTo(lastX, lastY);
    for (var s = 0; s < sub; s++) {
      step(h);
      lastX = penX(); lastY = penY();
      lctx.lineTo(lastX, lastY);
    }
    lctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, lx = 0, ly = 0, hot = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; lx = e.clientX; ly = e.clientY; hot = 1;
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - lx, dy = e.clientY - ly;
    lx = e.clientX; ly = e.clientY;
    /* 台側の振り子を、なぞった向きに軽く押す */
    v[2] -= dx * 0.35; v[3] -= dy * 0.35;
    var cap = Math.min(W, H) * 0.3 * om[2];
    v[2] = Math.max(-cap, Math.min(cap, v[2])); v[3] = Math.max(-cap, Math.min(cap, v[3]));
    hot = 1;
    if (phase !== 'draw') { phase = 'draw'; fade = 1; }
    drawn = Math.min(drawn, 20);
    if (Math.abs(dx) + Math.abs(dy) > 3) SFX.play('move', 220);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kDecay = document.getElementById('k-decay');
  function readSpeed() { speed = 0.4 + kSpeed.value / 100 * 1.4; }
  function readDecay() { gamma = 0.015 + kDecay.value / 100 * 0.08; }
  kSpeed.addEventListener('input', readSpeed);
  kDecay.addEventListener('input', readDecay);
  readSpeed(); readDecay();
  document.getElementById('k-next').addEventListener('click', function () { phase = 'out'; SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), phase = 'draw', fade = 1, holdT = 0;
  setup();
  /* 開いた瞬間から線が見えるように、最初の数秒ぶんは先に描いておく */
  ink(1000, 0.003, 0.4);
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var h = dt * speed, sub = Math.max(8, Math.ceil(h / 0.003));
    if (phase === 'draw') {
      var amp = energy() / startAmp;
      /* 線が中心に縮むほど細く薄く。真ん中が白く潰れないようにする */
      ink(sub, h / sub, 0.14 + 0.3 * Math.min(1, amp * 1.4));
      drawn += dt;
      if (amp < 0.14 || drawn > 150) { phase = 'hold'; holdT = 5; }
    } else if (phase === 'hold') {
      holdT -= dt;
      if (holdT <= 0) phase = 'out';
    } else if (phase === 'out') {
      fade -= dt * 0.35;
      if (fade <= 0) { fade = 0; setup(); phase = 'in'; SFX.play('tick'); }
    } else {
      fade = Math.min(1, fade + dt * 2);
      ink(sub, h / sub, 0.44);
      if (fade >= 1) phase = 'draw';
    }
    P.fill(ctx, W, H);
    ctx.globalAlpha = fade;
    ctx.drawImage(layer, 0, 0, W, H);
    ctx.globalAlpha = 1;
    /* ペン先 */
    if (phase === 'draw' || phase === 'in') {
      hot = Math.max(0, hot - dt * 0.6);
      var c = P.mix(P.line, P.accent, Math.max(0.35, hot));
      ctx.fillStyle = P.rgba(c, 0.95);
      ctx.beginPath(); ctx.arc(penX(), penY(), 2.6, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = P.rgba(c, 0.25 + hot * 0.3);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(penX(), penY(), 7 + hot * 5, 0, Math.PI * 2); ctx.stroke();
    }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
