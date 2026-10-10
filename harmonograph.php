<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('harmonograph');

$pageName = 'harmonograph';
$pageTitle = 'HARMONOGRAPH - PULSE';
$pageDesc = '2つの振り子につながったペンが、楕円を少しずつ回しながら重ねて描く。揺れが弱まるにつれて、線は中心へ縮んでいく。押さえるとペンが指の位置に来て、なぞったとおりに線を引き、放すとそこから振り子が大きく揺れ出して、すぐに新しい模様を描き上げる。';
$pageCanonical = 'https://pulse.subara3.com/harmonograph.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-decay">DECAY</label><input type="range" id="k-decay" min="0" max="100" value="18"></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DPR = 1, layer = null, lctx = null, ready = false;
  var ctx = P.fit(canvas, function (w, h, dpr) {
    W = w; H = h; DPR = dpr;
    layer = document.createElement('canvas');
    layer.width = Math.round(w * dpr); layer.height = Math.round(h * dpr);
    lctx = layer.getContext('2d');
    lctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    lctx.lineCap = 'round'; lctx.lineJoin = 'round';
    lastX = null;
    /* 画面の大きさが変わったら、新しい大きさで模様を描き上げ直す（線が消えて 1 本だけにならないように） */
    if (ready) { setup(); preInk(); }
  });

  /* 振り子 4 つ: ペン側（x, y）と台側（x, y）。それぞれ減衰する単振動。
     ペン = ペン側 − 台側。振動数が整数比から少しだけずれているので、輪がゆっくり回る */
  var RATIOS = [
    [2, 3.016, 0.5], [3, 4.014, 0.25], [1, 3.012, 0.5], [3, 5.016, 0.5],
    [2, 5.014, 0.25], [1, 2.012, 0.5], [4, 5.014, 0.5], [3, 2.014, 0.25]
  ];
  var ri = Math.random() * RATIOS.length | 0;
  var om = new Float64Array(4), u = new Float64Array(4), v = new Float64Array(4);
  var gamma = 0.08, speed = 1, startAmp = 1;
  var BASE = 2.4;

  /* fx, fy, fvx, fvy を渡すと、ペンがその位置・速さから始まる組み合わせを選ぶ。
     振れ幅は毎回同じ大きさにそろえ、指の速さは振れの向きのきっかけにだけ使う */
  function setup(px, py, pvx, pvy) {
    ri = (ri + 1 + (Math.random() * (RATIOS.length - 1) | 0)) % RATIOS.length;
    var r = RATIOS[ri], R = Math.min(W, H) * 0.25;
    om[0] = om[1] = r[0] * BASE;
    om[2] = om[3] = r[1] * BASE;
    var best = null, bestScore = 1e9;
    for (var tries = 0; tries < (px == null ? 16 : 40); tries++) {
      var p1 = Math.random() * Math.PI * 2, p2 = Math.random() * Math.PI * 2;
      var amp = [R, R * (0.75 + Math.random() * 0.25), R * 0.95, R * (0.7 + Math.random() * 0.3)];
      var ph = [p1, p1 + Math.PI * r[2] * 2, p2, p2 + Math.PI * (0.3 + Math.random() * 0.4)];
      var U = [], V = [];
      for (var i = 0; i < 4; i++) { U[i] = amp[i] * Math.cos(ph[i]); V[i] = -amp[i] * om[i] * Math.sin(ph[i]); }
      var score = 0;
      if (px != null) {
        /* 台側を、ペンが指の位置に来るように決め直す。台側の振れ幅が R に近いものを選ぶ */
        var dirx = pvx, diry = pvy, dl = Math.hypot(dirx, diry);
        var sp = R * om[0] * 0.6;
        if (dl > 1) { dirx = dirx / dl * sp; diry = diry / dl * sp; } else { dirx = 0; diry = 0; }
        U[2] = U[0] - (px - W / 2); U[3] = U[1] - (py - H / 2);
        V[2] = V[0] - dirx; V[3] = V[1] - diry;
        var a2 = Math.hypot(U[2], V[2] / om[2]), a3 = Math.hypot(U[3], V[3] / om[3]);
        score = Math.abs(a2 - R) + Math.abs(a3 - R * 0.85);
      }
      /* 模様の大きさ（振れ止まりなしで数周ぶん追った外枠）が短い辺の 6 割に届かない組み合わせは避ける */
      var ext = spanOf(U, V), need = Math.min(W, H) * 0.6;
      if (ext < need) score += (need - ext) * 3;
      if (score < bestScore) { bestScore = score; best = [U, V]; }
    }
    for (var k = 0; k < 4; k++) { u[k] = best[0][k]; v[k] = best[1][k]; }
    startAmp = energy();
    lastX = null; drawn = 0;
    if (px == null) lctx.clearRect(0, 0, W, H);
  }
  function spanOf(U, V) {
    var x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
    for (var t = 0; t < 12; t += 0.03) {
      var q = [], k;
      for (k = 0; k < 4; k++) { var A = Math.hypot(U[k], V[k] / om[k]), ph = Math.atan2(-V[k] / om[k], U[k]); q[k] = A * Math.cos(om[k] * t + ph); }
      var X = q[0] - q[2], Y = q[1] - q[3];
      if (X < x0) x0 = X; if (X > x1) x1 = X; if (Y < y0) y0 = Y; if (Y > y1) y1 = Y;
    }
    return Math.min(x1 - x0, y1 - y0);
  }
  /* 開いたとき: 最初の 30 秒ぶんほどを先に描いておく */
  function preInk() { ink(11000, 0.003, 0.42); }
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
    lctx.lineWidth = 1.2;
    lctx.beginPath();
    if (lastX === null) { lastX = penX(); lastY = penY(); }
    lctx.moveTo(lastX, lastY);
    for (var s = 0; s < sub; s++) {
      step(h);
      if (held) pin();
      lastX = penX(); lastY = penY();
      lctx.lineTo(lastX, lastY);
    }
    lctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  /* ペンをつかむ: 台側の振り子をずらして、ペン先を指の位置に合わせる。
     放すと、指の速さを持ったまま振り子が揺れ続け、そこから模様を描き直す */
  var boost = 0, held = false, fx = 0, fy = 0, fvx = 0, fvy = 0, lt = 0, hot = 0;
  function pin() {
    u[2] = u[0] - (fx - W / 2); u[3] = u[1] - (fy - H / 2);
    v[2] = v[0] - fvx; v[3] = v[1] - fvy;
  }
  canvas.addEventListener('pointerdown', function (e) {
    held = true; hot = 1;
    fx = e.clientX; fy = e.clientY; fvx = fvy = 0; lt = performance.now();
    /* いままでの線を薄くして、指から新しい線を始める */
    lctx.save();
    lctx.setTransform(1, 0, 0, 1, 0, 0);
    lctx.globalCompositeOperation = 'destination-out';
    lctx.fillStyle = P.rgba(P.deep, 0.5);
    lctx.fillRect(0, 0, layer.width, layer.height);
    lctx.restore();
    pin();
    lastX = fx; lastY = fy;
    phase = 'draw'; fade = 1;
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    var now = performance.now(), t = Math.max(0.008, (now - lt) / 1000);
    var vx = (e.clientX - fx) / t, vy = (e.clientY - fy) / t;
    fvx += (vx - fvx) * 0.5; fvy += (vy - fvy) * 0.5;
    var cap = Math.min(W, H) * 1.2;
    fvx = Math.max(-cap, Math.min(cap, fvx)); fvy = Math.max(-cap, Math.min(cap, fvy));
    fx = e.clientX; fy = e.clientY; lt = now;
    pin();
    /* 指の動きどおりに線を引く */
    lctx.strokeStyle = P.rgba(P.accent, 0.85);
    lctx.lineWidth = 1.8;
    lctx.beginPath(); lctx.moveTo(lastX, lastY); lctx.lineTo(fx, fy); lctx.stroke();
    lastX = fx; lastY = fy;
    if (Math.abs(vx) + Math.abs(vy) > 60) SFX.play('move', 220);
  });
  function up() {
    if (!held) return;
    held = false;
    if (performance.now() - lt > 120) { fvx = 0; fvy = 0; }
    lastX = fx; lastY = fy;
    setup(fx, fy, fvx, fvy);
    lastX = fx; lastY = fy;
    /* 離したあとの最初の 1.5 秒は速く描いて、すぐ模様が描き上がるようにする */
    boost = 1.5;
    drawn = 0; phase = 'draw'; fade = 1;
    SFX.play('grow');
  }
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
  preInk();
  ready = true;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var bk = 1;
    if (boost > 0) { bk = 1 + 13 * Math.min(1, boost / 0.8); boost -= dt; }
    var h = dt * speed * bk, sub = Math.max(8, Math.ceil(h / 0.003));
    if (phase === 'draw') {
      var amp = energy() / startAmp;
      /* 線が中心に縮むほど細く薄く。真ん中が白く潰れないようにする */
      if (held) { for (var hs = 0; hs < sub; hs++) { step(h / sub); pin(); } }
      else ink(sub, h / sub, 0.32 + 0.3 * Math.min(1, amp * 1.4));
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
      ink(sub, h / sub, 0.55);
      if (fade >= 1) phase = 'draw';
    }
    P.fill(ctx, W, H);
    ctx.globalAlpha = fade;
    ctx.drawImage(layer, 0, 0, W, H);
    ctx.globalAlpha = 1;
    /* ペン先 */
    if (phase === 'draw' || phase === 'in') {
      hot = held ? 1 : Math.max(0, hot - dt * 0.6);
      var c = P.mix(P.line, P.accent, Math.max(0.35, hot));
      ctx.fillStyle = P.rgba(c, 0.95);
      ctx.beginPath(); ctx.arc(penX(), penY(), 2.6 + hot * 2, 0, Math.PI * 2); ctx.fill();
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
