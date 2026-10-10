<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('packing');

$pageName = 'packing';
$pageTitle = 'CIRCLE PACKING - PULSE';
$pageDesc = '空いた場所に小さな円ができ、隣の円に触れるまで大きくなって止まる。古い円は縮んで消え、空いた場所にまた新しい円ができる。押しているあいだはそこの円が大きくなり、ぶつかった円が縮む。';
$pageCanonical = 'https://pulse.subara3.com/packing.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="drafted">DRAFTED</button><button type="button" data-look="plain">PLAIN</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 円: x, y, r, 状態（0 育つ / 1 止まった / 2 しぼむ）, 寿命 */
  var C = [];
  var pace = 0.4, size = 0.5, look = 'drafted';
  var GAP = 2;
  var held = null;            /* 指で膨らませている円 */

  function maxR() { return Math.min(W, H) * (0.08 + size * 0.2); }
  function room(x, y) {
    /* (x, y) から一番近い円の縁までの距離 */
    var m = 1e9;
    for (var i = 0; i < C.length; i++) {
      var c = C[i], d = Math.hypot(x - c.x, y - c.y) - c.r;
      if (d < m) m = d;
    }
    return m;
  }
  function spawn(tries) {
    for (var t = 0; t < tries; t++) {
      var x = -20 + Math.random() * (W + 40), y = -20 + Math.random() * (H + 40);
      if (room(x, y) > GAP + 4) {
        C.push({ x: x, y: y, r: 1, s: 0, life: 18 + Math.random() * 40, a: 0, v: 0.6 + Math.random() * 0.8, hot: 0 });
        return true;
      }
    }
    return false;
  }
  function seed() {
    C = [];
    /* 最初の数秒で画面が埋まりはじめるよう、いくつかは途中まで育てて置く */
    for (var i = 0; i < 90; i++) spawn(20);
    for (var k = 0; k < 60; k++) grow(0.12, true);
    for (i = 0; i < C.length; i++) { C[i].a = 1; C[i].life *= Math.random(); }
  }

  function grow(dt, quiet) {
    var speed = 10 + pace * 50, mr = maxR();
    for (var i = 0; i < C.length; i++) {
      var c = C[i];
      if (c.s !== 0 || c === held) continue;
      var nr = c.r + speed * c.v * dt * Math.max(0.25, 1 - c.r / mr);
      var ok = nr < mr;
      for (var j = 0; j < C.length && ok; j++) {
        if (j === i) continue;
        var o = C[j];
        if (Math.hypot(c.x - o.x, c.y - o.y) - o.r - nr < GAP) ok = false;
      }
      if (ok) c.r = nr;
      else {
        c.s = 1;
        if (!quiet && c.r > 26) SFX.play('collide', 700);
      }
    }
  }

  /* 指で押さえている円は膨らみ続け、ぶつかった円をしぼませる */
  function press(dt) {
    if (!held) return;
    held.r = Math.min(maxR() * 1.6, held.r + dt * (40 + held.r * 0.5));
    held.hot = 1;
    for (var j = 0; j < C.length; j++) {
      var o = C[j];
      if (o === held) continue;
      var d = Math.hypot(held.x - o.x, held.y - o.y);
      var over = held.r + o.r + GAP - d;
      if (over > 0) {
        o.r -= over;
        if (o.s !== 2) { o.s = 2; o.life = 0; }
      }
    }
  }

  function age(dt) {
    var shrink = 6 + pace * 22;
    for (var i = C.length - 1; i >= 0; i--) {
      var c = C[i];
      c.a = Math.min(1, c.a + dt * 1.5);
      if (c.hot > 0 && c !== held) c.hot = Math.max(0, c.hot - dt * 0.12);
      if (c.s === 1) {
        c.life -= dt * (0.5 + pace);
        if (c.life <= 0) c.s = 2;
      } else if (c.s === 2) {
        c.r -= dt * shrink * (0.4 + c.r / 60);
        if (c.r <= 0.5) { C.splice(i, 1); continue; }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.6 });
    var i, c;
    ctx.lineWidth = 1;
    /* 補助線: 大きな円には同心円と中心の十字を、製図のように細く */
    if (look === 'drafted') {
      ctx.strokeStyle = P.rgba(P.steel, 0.28);
      ctx.beginPath();
      for (i = 0; i < C.length; i++) {
        c = C[i];
        if (c.r < 24) continue;
        var k = Math.min(1, (c.r - 24) / 30) * c.a;
        if (k < 0.2) continue;
        ctx.moveTo(c.x + c.r * 0.62, c.y); ctx.arc(c.x, c.y, c.r * 0.62, 0, Math.PI * 2);
        if (c.r > 60) { ctx.moveTo(c.x + c.r * 0.34, c.y); ctx.arc(c.x, c.y, c.r * 0.34, 0, Math.PI * 2); }
        var t = Math.min(7, c.r * 0.12);
        ctx.moveTo(c.x - t, c.y); ctx.lineTo(c.x + t, c.y);
        ctx.moveTo(c.x, c.y - t); ctx.lineTo(c.x, c.y + t);
      }
      ctx.stroke();
    }
    /* 輪郭: 止まった円は白、育っている円は少し明るく、しぼむ円は薄く */
    var groups = [[], [], []];
    for (i = 0; i < C.length; i++) groups[C[i].s].push(C[i]);
    var style = [P.rgba(P.line, 0.78), P.rgba(P.line, 0.5), P.rgba(P.steel, 0.55)];
    for (var g = 0; g < 3; g++) {
      ctx.strokeStyle = style[g];
      ctx.beginPath();
      for (i = 0; i < groups[g].length; i++) {
        c = groups[g][i];
        if (c.hot > 0.02) continue;
        ctx.moveTo(c.x + c.r, c.y); ctx.arc(c.x, c.y, Math.max(0.5, c.r), 0, Math.PI * 2);
      }
      ctx.stroke();
    }
    /* 触ってできた円だけシアン。時間とともに白へ戻る */
    for (i = 0; i < C.length; i++) {
      c = C[i];
      if (c.hot <= 0.02) continue;
      ctx.strokeStyle = P.rgba(P.mix(P.line, P.accent, c.hot), 0.85);
      ctx.fillStyle = P.rgba(P.accent, c.hot * 0.07);
      ctx.beginPath(); ctx.arc(c.x, c.y, Math.max(0.5, c.r), 0, Math.PI * 2); ctx.fill(); ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    var x = e.clientX, y = e.clientY;
    /* 押した場所にある円は押しのけて、新しい円をそこに置く */
    held = { x: x, y: y, r: 4, s: 1, life: 30 + Math.random() * 30, a: 1, v: 1, hot: 1 };
    C.push(held);
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    var x = e.clientX, y = e.clientY;
    if (Math.hypot(x - held.x, y - held.y) > held.r + 6) {
      /* 指が円の外へ出たら、そこに次の円を置く */
      held = { x: x, y: y, r: 4, s: 1, life: 30 + Math.random() * 30, a: 1, v: 1, hot: 1 };
      C.push(held);
      SFX.play('spawn', 160);
    }
  });
  function up() { held = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kp = document.getElementById('k-pace'), ks = document.getElementById('k-size');
  kp.addEventListener('input', function () { pace = this.value / 100; });
  ks.addEventListener('input', function () { size = this.value / 100; });
  pace = kp.value / 100; size = ks.value / 100;
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; seed(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    acc += dt * (8 + pace * 30);
    var limit = 1400;
    while (acc > 1) { acc -= 1; if (C.length < limit) spawn(14); }
    press(dt);
    grow(dt, false);
    age(dt);
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
