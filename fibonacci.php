<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fibonacci');

$pageName = 'fibonacci';
$pageTitle = 'FIBONACCI - PULSE';
$pageDesc = '中心から種が一粒ずつ生まれて外へ押し出され、ひまわりの種のような渦が二方向に重なる。押すとそこの種が押しのけられて大きな穴があき、縁の種が水色に光る。横にドラッグすると種の角度がずれて渦がほどけ、離すと元に戻る。';
$pageCanonical = 'https://pulse.subara3.com/fibonacci.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-grow">GROW</label><input type="range" id="k-grow" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="seeds">SEEDS</button><button type="button" data-look="net">NET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, CX = 0, CY = 0, R = 1, C = 1;
  var N = 1300;                       /* 画面にいる種の数 */
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; CX = w / 2; CY = h / 2;
    R = Math.min(w, h) * 0.48;
    C = R / Math.sqrt(N);
  });

  var GOLD = Math.PI * (3 - Math.sqrt(5));   /* 黄金角 */
  var FIB = [3, 5, 8, 13, 21, 34, 55, 89];
  var total = N + 0.0;                 /* これまでに生まれた種の数（連続値） */
  var rate = 4;                        /* 1 秒あたりの誕生数 */
  var dev = 0, devHand = 0, devAuto = 0; /* 黄金角からのずれ */
  var rot = 0, t = 0;
  var look = 'seeds';

  /* 種の位置（毎フレーム計算。保存しない） */
  var px = new Float32Array(N + 2), py = new Float32Array(N + 2), pr = new Float32Array(N + 2);
  var M = N + 2;
  var glowB = new Float32Array(M);     /* 触ったところの灯り（誕生番号で持つ） */
  var glow = new Float32Array(M);      /* その並びを今の位置順に写したもの */

  function layout() {
    var top = Math.floor(total), frac = total - top;
    for (var a = 0; a <= N; a++) {
      var b = top - a;                 /* 誕生番号 */
      var age = a + frac;
      var r = C * Math.sqrt(age + 0.3);
      var ang = (b * GOLD) % (Math.PI * 2) - age * dev + rot;
      var gi = ((b % M) + M) % M;
      var x = CX + Math.cos(ang) * r, y = CY + Math.sin(ang) * r;
      /* 指の近くの種は押しのけられて穴があき、穴のふちの種がふくらむ。離すとゆっくり戻る */
      var lift = 0;
      if (pe > 0.01) {
        var dx = x - ptr.x, dy = y - ptr.y, d = Math.sqrt(dx * dx + dy * dy) + 0.01, HOLE = 120;
        if (d < HOLE * 1.8) {
          /* 穴の中の種は縁まで押し出す。縁の外は押されて詰まる */
          var push = d < HOLE ? (HOLE - d) + 18 * (d / HOLE) : 18 * Math.max(0, 1 - (d - HOLE) / (HOLE * 0.8));
          x += dx / d * push * pe; y += dy / d * push * pe;
          var nd = d + push * pe;
          lift = Math.max(lift, Math.exp(-Math.pow((nd - HOLE * pe) / 40, 2)) * pe);
        }
      }
      /* 押した所から輪が広がり、輪にかかった種が外へ押されてふくらむ */
      for (var w = 0; w < waves.length; w++) {
        var wv = waves[w], ddx = x - wv.x, ddy = y - wv.y, dd = Math.sqrt(ddx * ddx + ddy * ddy) + 0.01;
        var front = 40 + wv.t * 260, band = (dd - front) / 22;
        if (band > -3 && band < 3) {
          var amp = Math.exp(-band * band) * Math.max(0, 1 - wv.t / 1.0);
          x += ddx / dd * 10 * amp; y += ddy / dd * 10 * amp;
          lift = Math.max(lift, amp * 0.8);
        }
      }
      glowB[gi] = Math.max(glowB[gi], lift);
      glow[a] = glowB[gi];
      px[a] = x; py[a] = y; pr[a] = r;
    }
  }

  function edgeFade(r) {
    var f = r / R;
    return f < 0.8 ? 1 : Math.max(0, 1 - (f - 0.8) / 0.2);
  }

  var LN = P.line, AC = P.accent, ST = P.steel;
  function drawSeeds() {
    /* 明るさを 6 段に分けてまとめて塗る */
    var BUCKETS = 6;
    for (var q = 0; q < BUCKETS; q++) {
      ctx.beginPath();
      for (var a = 0; a <= N; a++) {
        var f = edgeFade(pr[a]);
        if (f <= 0) continue;
        var b0 = Math.floor(total) - a;
        /* 21 本の渦にそって、明るさの帯をつける */
        var band = 0.62 + 0.38 * Math.cos(Math.PI * 2 * (b0 % 21) / 21);
        var bright = (0.6 + 0.4 * Math.min(1, pr[a] / (R * 0.45))) * f * band;
        if (Math.min(BUCKETS - 1, Math.floor(bright * BUCKETS)) !== q) continue;
        var age = a + (total % 1);
        var born = Math.min(1, age / 6);
        var rad = C * (0.24 + 0.24 * Math.min(1, pr[a] / R)) * born * (look === 'net' ? 0.6 : 1);
        ctx.moveTo(px[a] + rad, py[a]);
        ctx.arc(px[a], py[a], rad, 0, Math.PI * 2);
      }
      ctx.fillStyle = P.rgba(LN, 0.36 + 0.64 * (q + 0.5) / BUCKETS);
      ctx.fill();
    }
    /* 触った所だけシアン */
    for (var a2 = 0; a2 <= N; a2++) {
      if (glow[a2] < 0.02) continue;
      var rad2 = C * (0.24 + 0.24 * Math.min(1, pr[a2] / R)) * (1 + glow[a2] * 0.9);
      ctx.fillStyle = P.rgba(P.mix(LN, AC, 0.35 + 0.3 * (1 - glow[a2])), Math.min(1, glow[a2] * 1.6) * edgeFade(pr[a2]));
      ctx.beginPath();
      ctx.arc(px[a2], py[a2], rad2, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  /* 斜めに走る 2 本の渦の列: 近い方の 2 つのフィボナッチ番手の隣とつなぐ */
  function drawNet() {
    var BUCKETS = 4;
    var paths = [];
    for (var q = 0; q < BUCKETS; q++) paths.push(new Path2D());
    for (var a = 2; a <= N; a++) {
      var f = edgeFade(pr[a]);
      if (f <= 0) continue;
      var best1 = -1, best2 = -1, d1 = 1e9, d2 = 1e9;
      for (var j = 0; j < FIB.length; j++) {
        var o = a + FIB[j];
        if (o > N) break;
        var dx = px[o] - px[a], dy = py[o] - py[a], d = dx * dx + dy * dy;
        if (d < d1) { d2 = d1; best2 = best1; d1 = d; best1 = o; }
        else if (d < d2) { d2 = d; best2 = o; }
      }
      var bright = (0.3 + 0.7 * Math.min(1, pr[a] / (R * 0.6))) * f;
      var p = paths[Math.min(BUCKETS - 1, Math.floor(bright * BUCKETS))];
      var lim = C * C * 9;
      if (best1 >= 0 && d1 < lim) { p.moveTo(px[a], py[a]); p.lineTo(px[best1], py[best1]); }
      if (best2 >= 0 && d2 < lim) { p.moveTo(px[a], py[a]); p.lineTo(px[best2], py[best2]); }
    }
    ctx.lineWidth = 1;
    for (var q2 = 0; q2 < BUCKETS; q2++) {
      ctx.strokeStyle = P.rgba(LN, 0.1 + 0.55 * (q2 + 0.5) / BUCKETS);
      ctx.stroke(paths[q2]);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var hold = false, ptr = { x: 0, y: 0 }, startX = 0, devStart = 0, waves = [], pe = 0;
  canvas.addEventListener('pointerdown', function (e) {
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY; pe = 1;
    startX = e.clientX; devStart = devHand;
    waves.push({ x: ptr.x, y: ptr.y, t: 0 });
    if (waves.length > 4) waves.shift();
    SFX.play('tick');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!hold) return;
    var mx = e.clientX - ptr.x, my = e.clientY - ptr.y;
    ptr.x = e.clientX; ptr.y = e.clientY;
    devHand = devStart + (e.clientX - startX) / Math.max(320, W) * 0.05;
    if (Math.abs(mx) + Math.abs(my) > 2) SFX.play('move', 220);
  });
  function up() { hold = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var grow = document.getElementById('k-grow');
  function readGrow() { rate = 0.6 + grow.value / 100 * 5; }
  grow.addEventListener('input', readGrow);
  readGrow();
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    t += dt;
    total += rate * dt;
    rot += dt * 0.006;
    pe = hold ? 1 : pe * Math.exp(-dt * 1.4);
    for (var wi = waves.length - 1; wi >= 0; wi--) { waves[wi].t += dt; if (waves[wi].t > 1.0) waves.splice(wi, 1); }
    /* 離すと黄金角へゆっくり戻る */
    if (!hold) devHand *= Math.exp(-dt * 0.5);
    dev += (devHand + devAuto - dev) * Math.min(1, dt * 3);
    for (var i = 0; i < M; i++) glowB[i] *= Math.exp(-dt * 3.5);

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.7 });
    layout();
    if (look === 'net') drawNet();
    drawSeeds();

    chime += dt;
    if (chime > 5) { chime = 0; SFX.play('grow', 4000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
