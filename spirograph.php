<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('spirograph');

$pageName = 'spirograph';
$pageTitle = 'SPIROGRAPH - PULSE';
$pageDesc = '大きな歯車の内側を小さな歯車が転がり、穴に差したペンが輪の重なった線を描く。1本描き終えるたびにペンの穴が変わり、線が重なっていく。円に沿ってドラッグすると歯車を手で回せる。';
$pageCanonical = 'https://pulse.subara3.com/spirograph.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-side="in">INSIDE</button><button type="button" data-side="out">OUTSIDE</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, layer = null, lctx = null;
  var ctx = P.fit(canvas, function (w, h, dpr) {
    W = w; H = h;
    layer = document.createElement('canvas');
    layer.width = Math.round(w * dpr); layer.height = Math.round(h * dpr);
    lctx = layer.getContext('2d');
    lctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    lctx.lineJoin = 'round';
    if (typeof begin === 'function' && RING) begin(theta);
  });

  /* 歯数で決める。輪 96 枚、転がる歯車は下の中から。何周で閉じるかは歯数の比で決まる */
  var RING = 96;
  var WHEELS = [30, 36, 40, 42, 45, 52, 56, 60, 63, 64, 70, 75, 80, 84];
  var HOLES = [0.88, 0.72, 0.56, 0.4, 0.26];
  var side = 'in', wheel = 60, hole = 0.88, holeI = 0, layers = 0;
  var theta = 0, theta0 = 0, total = 1, rate = 1;

  function gcd(a, b) { while (b) { var t = a % b; a = b; b = t; } return a; }
  function geom() {
    var m = Math.min(W, H);
    var R = side === 'in' ? m * 0.4 : m * 0.44 / (1 + 2 * wheel / RING);
    return { R: R, r: R * wheel / RING };
  }
  var G = { R: 1, r: 1 };
  function pen(th, out) {
    var R = G.R, r = G.r, cx = W / 2, cy = H / 2, d = hole * r;
    var k, ccx, ccy, ph;
    if (side === 'in') { k = R - r; ph = -th * (R - r) / r; }
    else { k = R + r; ph = th * (R + r) / r; }
    ccx = cx + Math.cos(th) * k; ccy = cy + Math.sin(th) * k;
    out[0] = ccx; out[1] = ccy;
    out[2] = ccx + Math.cos(ph) * d; out[3] = ccy + Math.sin(ph) * d;
    out[4] = ph;
    return out;
  }
  var PT = [0, 0, 0, 0, 0], last = [0, 0];

  function begin(th) {
    G = geom();
    theta0 = th; theta = th;
    var laps = wheel / gcd(RING, wheel);
    total = laps * Math.PI * 2;
    /* 何周かかる線でも、だいたい 14〜30 秒で描き終わる */
    rate = total / (12 + laps * 2.4);
    pen(th, PT); last[0] = PT[2]; last[1] = PT[3];
  }
  /* 描き終えた線は少し沈めて、次の線を上に重ねる */
  function nextCurve(reset) {
    if (reset || layers >= 5) {
      clearing = 1;
      return;
    }
    lctx.save();
    lctx.setTransform(1, 0, 0, 1, 0, 0);
    lctx.globalCompositeOperation = 'destination-out';
    lctx.fillStyle = P.rgba(P.deep, 0.3);
    lctx.fillRect(0, 0, layer.width, layer.height);
    lctx.restore();
    holeI = (holeI + 1) % HOLES.length;
    hole = HOLES[holeI];
    layers++;
    begin(theta);
  }
  function freshSet() {
    var w;
    do { w = WHEELS[Math.random() * WHEELS.length | 0]; } while (w === wheel || w / gcd(RING, w) > 16 || w / gcd(RING, w) < 3);
    wheel = w; holeI = 0; hole = HOLES[0]; layers = 1;
    lctx.clearRect(0, 0, W, H);
    begin(Math.random() * Math.PI * 2);
  }

  function inkTo(th) {
    var dth = th - theta, R = G.R, r = G.r;
    var reach = (side === 'in' ? R - r : R + r) + hole * (side === 'in' ? R - r : R + r);
    var n = Math.max(1, Math.ceil(Math.abs(dth) * reach / 2));
    n = Math.min(n, 2000);
    lctx.strokeStyle = P.rgba(P.line, 0.62);
    lctx.lineWidth = 0.9;
    lctx.beginPath();
    lctx.moveTo(last[0], last[1]);
    for (var i = 1; i <= n; i++) {
      pen(theta + dth * i / n, PT);
      lctx.lineTo(PT[2], PT[3]);
    }
    lctx.stroke();
    last[0] = PT[2]; last[1] = PT[3];
    theta = th;
  }

  /* ---------------------------------------------------------- 歯車の絵 */
  function gear(cx, cy, rad, teeth, rot, alpha, inward) {
    ctx.strokeStyle = P.rgba(P.steel, alpha);
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.arc(cx, cy, rad, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath();
    var tl = inward ? -5 : 5;
    for (var i = 0; i < teeth; i++) {
      var a = rot + i / teeth * Math.PI * 2, c = Math.cos(a), s = Math.sin(a);
      ctx.moveTo(cx + c * rad, cy + s * rad);
      ctx.lineTo(cx + c * (rad + tl), cy + s * (rad + tl));
    }
    ctx.stroke();
  }
  function drawRig() {
    pen(theta, PT);
    var R = G.R, r = G.r;
    gear(W / 2, H / 2, R, RING, 0, 0.28, side === 'in' ? false : true);
    gear(W / 2, H / 2, side === 'in' ? R + 9 : R - 9, 0, 0, 0.12, false);
    /* 転がる歯車と、その穴 */
    gear(PT[0], PT[1], r, wheel, PT[4], 0.5, side === 'in' ? false : true);
    ctx.fillStyle = P.rgba(P.steel, 0.45);
    for (var h = 0; h < HOLES.length; h++) {
      var d = HOLES[h] * r;
      ctx.beginPath(); ctx.arc(PT[0] + Math.cos(PT[4]) * d, PT[1] + Math.sin(PT[4]) * d, 1.6, 0, Math.PI * 2); ctx.fill();
    }
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.beginPath(); ctx.moveTo(PT[0], PT[1]); ctx.lineTo(PT[2], PT[3]); ctx.stroke();
    ctx.fillStyle = P.rgba(P.accent, 0.95);
    ctx.beginPath(); ctx.arc(PT[2], PT[3], 3, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = P.rgba(P.accent, 0.35 + hot * 0.4);
    ctx.beginPath(); ctx.arc(PT[2], PT[3], 7 + hot * 4, 0, Math.PI * 2); ctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, moved = 0, lastAng = 0, sx = 0, sy = 0, hot = 0;
  function ang(e) { return Math.atan2(e.clientY - H / 2, e.clientX - W / 2); }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0; lastAng = ang(e); sx = e.clientX; sy = e.clientY; hot = 1;
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    moved = Math.max(moved, Math.hypot(e.clientX - sx, e.clientY - sy));
    if (moved < 8) return;
    var a = ang(e), da = a - lastAng;
    if (da > Math.PI) da -= Math.PI * 2;
    if (da < -Math.PI) da += Math.PI * 2;
    lastAng = a;
    /* 指の回した角度だけ歯車が進む（戻すこともできる） */
    inkTo(theta + da);
    hot = 1;
    SFX.play('move', 160);
  });
  function up(e) {
    if (!down) return;
    down = false;
    if (moved < 8) {
      /* タップ: 中心からの距離でペンの穴を選び直し、そこから新しい線 */
      var d = Math.hypot(e.clientX - W / 2, e.clientY - H / 2) / (Math.min(W, H) * 0.4);
      var best = 0;
      for (var i = 1; i < HOLES.length; i++) if (Math.abs(HOLES[i] - d) < Math.abs(HOLES[best] - d)) best = i;
      holeI = (best - 1 + HOLES.length) % HOLES.length;
      if (layers >= 5) layers = 4;
      nextCurve(false);
      SFX.play('spawn');
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; });

  var speed = 1, kSpeed = document.getElementById('k-speed');
  function readSpeed() { speed = 0.3 + kSpeed.value / 100 * 1.8; }
  kSpeed.addEventListener('input', readSpeed);
  readSpeed();
  var sideBtns = document.querySelectorAll('[data-side]');
  function setSide(s) {
    side = s;
    sideBtns.forEach(function (o) { o.classList.toggle('active', o.dataset.side === s); });
    clearing = 1;
  }
  sideBtns.forEach(function (b) { b.addEventListener('click', function () { if (b.dataset.side !== side) { setSide(b.dataset.side); SFX.play('toggle'); } }); });
  document.getElementById('k-clear').addEventListener('click', function () { clearing = 1; SFX.play('click'); });
  setTimeout(function () {
    var a = document.querySelector('[data-side].active');
    if (a && a.dataset.side !== side) setSide(a.dataset.side);
  }, 400);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), pause = 0, clearing = 0, fade = 1, tickT = 0;
  wheel = 52; freshSet();
  /* 開いた瞬間に線が出ているように、1 本目を少し進めておく */
  inkTo(theta + rate * 3);
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    hot = Math.max(0, hot - dt * 0.8);
    if (clearing) {
      fade -= dt * 0.7;
      if (fade <= 0) { fade = 0; clearing = 0; freshSet(); }
    } else {
      fade = Math.min(1, fade + dt * 1.5);
      if (!down) {
        if (pause > 0) {
          pause -= dt;
          if (pause <= 0) nextCurve(false);
        } else {
          var goal = Math.min(theta0 + total, theta + rate * speed * dt);
          inkTo(goal);
          tickT -= dt;
          if (tickT <= 0) { SFX.play('tick', 3000); tickT = 7; }
        }
      }
      if (pause <= 0 && Math.abs(theta - theta0) >= total - 1e-6) pause = 2.2;
    }
    P.fill(ctx, W, H);
    ctx.globalAlpha = fade;
    ctx.drawImage(layer, 0, 0, W, H);
    drawRig();
    ctx.globalAlpha = 1;
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
