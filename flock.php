<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('flock');

$pageName = 'flock';
$pageTitle = 'FLOCK - PULSE';
$pageDesc = '数百羽の鳥がひとかたまりで飛び、向きを揃えながら画面の中を大きく旋回する。押したところから鳥が輪になって飛び立ち、なぞると指の跡に沿って鳥が飛び出して群れに加わる。';
$pageCanonical = 'https://pulse.subara3.com/flock.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-birds">BIRDS</label><input type="range" id="k-birds" min="0" max="100" value="70"></div>
  <div class="ctl-row"><label for="k-calm">CALM</label><input type="range" id="k-calm" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-scatter">SCATTER</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = canvas.getContext('2d');

  /* 鳥は位置・速度・奥行きだけを持つ。奥行きは大きさと濃さにだけ効く */
  var MAX = 1600, N = 0;
  var px = new Float32Array(MAX), py = new Float32Array(MAX);
  var vx = new Float32Array(MAX), vy = new Float32Array(MAX);
  var startle = new Float32Array(MAX), dz = new Float32Array(MAX), ax = new Float32Array(MAX), ay = new Float32Array(MAX);
  var CELL = 44, GW = 1, GH = 1, head, nextI = new Int32Array(MAX);
  var speed = 1;

  function allocGrid() {
    GW = Math.ceil(W / CELL) + 1; GH = Math.ceil(H / CELL) + 1;
    head = new Int32Array(GW * GH);
  }

  function spawn(i, cx, cy, spread) {
    var a = Math.random() * Math.PI * 2, r = Math.sqrt(Math.random()) * spread;
    px[i] = cx + Math.cos(a) * r; py[i] = cy + Math.sin(a) * r * 0.6;
    var h = -0.4 + Math.random() * 0.3;
    vx[i] = Math.cos(h) * 100; vy[i] = Math.sin(h) * 100;
    dz[i] = 0.45 + Math.random() * 0.55;
    for (var h2 = 0; h2 < HL; h2++) { hx[i * HL + h2] = px[i]; hy[i * HL + h2] = py[i]; }
  }
  function setCount(n) {
    n = Math.max(80, Math.min(MAX, n | 0));
    for (var i = N; i < n; i++) spawn(i, W * 0.45, H * 0.45, Math.min(W, H) * 0.22);
    N = n;
  }

  /* 少し前の位置を覚えておき、羽ばたいた跡を細い尾にする */
  var HL = 8, hx = new Float32Array(MAX * HL), hy = new Float32Array(MAX * HL), hHead = 0, hAcc = 0;
  function record(dt) {
    hAcc += dt;
    if (hAcc < 0.07) return;
    hAcc = 0;
    hHead = (hHead + 1) % HL;
    for (var i = 0; i < N; i++) { hx[i * HL + hHead] = px[i]; hy[i * HL + hHead] = py[i]; }
  }

  /* 群れが漂って戻ってくる「ねぐら」。ゆっくり8の字を描いて動き、群れ全体を大きく振り回す */
  var T = 0, roostX = 0, roostY = 0;
  function roost() {
    roostX = W * (0.5 + 0.17 * Math.sin(T * 0.071) + 0.04 * Math.sin(T * 0.23));
    roostY = H * (0.47 + 0.14 * Math.sin(T * 0.113 + 1.3));
  }

  /* 驚かせる点（指・自動の小さな乱れ） */
  var hawk = { x: 0, y: 0, on: 0 };
  var gust = { x: 0, y: 0, t: 0 };
  var marks = [];

  function step(dt) {
    var i, j, k;
    head.fill(-1);
    for (i = 0; i < N; i++) {
      var gx = Math.max(0, Math.min(GW - 1, (px[i] / CELL) | 0));
      var gy = Math.max(0, Math.min(GH - 1, (py[i] / CELL) | 0));
      k = gy * GW + gx; nextI[i] = head[k]; head[k] = i;
    }
    var R2 = CELL * CELL, S = 36, S2 = S * S;
    var vmax = 150 * speed, vmin = 75 * speed;
    var m = Math.min(W, H) * 0.2;
    for (i = 0; i < N; i++) {
      var x = px[i], y = py[i];
      var cx = (x / CELL) | 0, cy = (y / CELL) | 0;
      var seen = 0, n = 0, sx = 0, sy = 0, mvx = 0, mvy = 0, mx = 0, my = 0;
      for (var oy = -1; oy <= 1; oy++) {
        var yy = cy + oy; if (yy < 0 || yy >= GH) continue;
        for (var ox = -1; ox <= 1; ox++) {
          var xx = cx + ox; if (xx < 0 || xx >= GW) continue;
          for (j = head[yy * GW + xx], seen = 0; j >= 0 && seen < 14; j = nextI[j]) {
            if (j === i) continue;
            var ddx = px[j] - x, ddy = py[j] - y, d2 = ddx * ddx + ddy * ddy;
            if (d2 > R2) continue;
            seen++;
            /* 前方の仲間ほどよく見える */
            var front = (ddx * vx[i] + ddy * vy[i]) > 0 ? 1 : 0.45;
            n += front; mvx += vx[j] * front; mvy += vy[j] * front; mx += ddx * front; my += ddy * front;
            if (d2 < S2) { var f = (S2 - d2) / S2; sx -= ddx * f; sy -= ddy * f; }
          }
        }
      }
      var fx = 0, fy = 0;
      if (n > 0) {
        fx += (mvx / n - vx[i]) * 1.15 + (mx / n) * 1.0;
        fy += (mvy / n - vy[i]) * 1.15 + (my / n) * 1.0;
      }
      fx += sx * 5 + (Math.random() - 0.5) * 60; fy += sy * 5 + (Math.random() - 0.5) * 60;
      /* ねぐらへ弱く引かれる */
      fx += (roostX - x) * 0.11; fy += (roostY - y) * 0.13;
      /* 画面の縁でやわらかく折り返す */
      if (x < m) fx += (m - x) * 9; else if (x > W - m) fx -= (x - (W - m)) * 9;
      if (y < m) fy += (m - y) * 9; else if (y > H - m) fy -= (y - (H - m)) * 9;
      /* 驚き: 近いほど強く、外向きに逃げる */
      if (hawk.on > 0 && !down) {
        var hx = x - hawk.x, hy = y - hawk.y, hd = Math.sqrt(hx * hx + hy * hy) + 1;
        if (hd < 150 && hd > 40) { var hf = (1 - hd / 150) * 900 * hawk.on; fx += hx / hd * hf; fy += hy / hd * hf; }
      }
      if (gust.t > 0) {
        var gx2 = x - gust.x, gy2 = y - gust.y, gd = Math.sqrt(gx2 * gx2 + gy2 * gy2) + 1;
        if (gd < 110) { var gf = (1 - gd / 110) * 700 * Math.min(1, gust.t); fx += gx2 / gd * gf; fy += gy2 / gd * gf; }
      }
      ax[i] = fx; ay[i] = fy;
    }
    for (i = 0; i < N; i++) {
      vx[i] += ax[i] * dt; vy[i] += ay[i] * dt;
      var sp = Math.sqrt(vx[i] * vx[i] + vy[i] * vy[i]) + 1e-6;
      var lim = sp > vmax ? vmax / sp : sp < vmin ? vmin / sp : 1;
      vx[i] *= lim; vy[i] *= lim;
      px[i] += vx[i] * dt * (0.75 + dz[i] * 0.25);
      py[i] += vy[i] * dt * (0.75 + dz[i] * 0.25);
    }
  }

  /* 描画: 鳥は進む向きの短い線。遠い鳥ほど細く淡い */
  var LN = P.line, ST = P.steel;
  function draw() {
    P.fill(ctx, W, H);
    /* 地平の薄い線と方眼で、空の広さを出す */
    P.grid(ctx, W, H, { step: 48, major: 4, alpha: 0.55 });
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    /* 尾: 古い点ほど淡く。古い→新しいを 3 段に分けて、段ごとにまとめて引く */
    var bands = [[0, 3], [3, 6], [6, 8]];
    for (var seg = 1; seg < 3; seg++) {
      ctx.strokeStyle = P.rgba(P.mix(ST, LN, 0.3 + seg * 0.2), 0.16 + seg * 0.14);
      ctx.lineWidth = 1;
      ctx.beginPath();
      for (var bd = 0; bd < N; bd++) {
        var o = bd * HL, q0 = bands[seg][0], q1 = bands[seg][1];
        var kk = (hHead + 1 + q0) % HL;
        ctx.moveTo(hx[o + kk], hy[o + kk]);
        for (var q = q0 + 1; q <= q1; q++) {
          if (q === HL) { ctx.lineTo(px[bd], py[bd]); break; }
          kk = (hHead + 1 + q) % HL;
          ctx.lineTo(hx[o + kk], hy[o + kk]);
        }
      }
      ctx.stroke();
    }
    for (var layer = 0; layer < 3; layer++) {
      var lo = 0.45 + layer * 0.183, hi = lo + 0.184;
      var a = 0.6 + layer * 0.2;
      ctx.strokeStyle = P.rgba(layer === 0 ? P.mix(ST, LN, 0.5) : LN, a);
      ctx.lineWidth = 1.2 + layer * 0.45;
      ctx.beginPath();
      for (var i = 0; i < N; i++) {
        var z = dz[i]; if (z < lo || z >= hi) continue;
        var sp = Math.sqrt(vx[i] * vx[i] + vy[i] * vy[i]) + 1e-6;
        var L = 3.5 + z * 4;
        var ux = vx[i] / sp, uy = vy[i] / sp;
        /* 羽ばたき: 胴に直交する短い線を、時間で少しだけ開閉させる */
        var flap = (Math.sin(T * 7 + i * 1.7) * 0.5 + 0.5) * 1.8 * z;
        ctx.moveTo(px[i] - ux * L - uy * flap, py[i] - uy * L + ux * flap);
        ctx.lineTo(px[i], py[i]);
        ctx.lineTo(px[i] - ux * L + uy * flap, py[i] - uy * L - ux * flap);
      }
      ctx.stroke();
    }
    /* 驚いた鳥: 羽を大きく広げて白く光り、1 秒ほどで元に戻る */
    ctx.strokeStyle = P.rgba(LN, 0.95);
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (var q = 0; q < N; q++) {
      var st = startle[q]; if (st < 0.08) continue;
      var sp2 = Math.sqrt(vx[q] * vx[q] + vy[q] * vy[q]) + 1e-6, ux2 = vx[q] / sp2, uy2 = vy[q] / sp2;
      var L2 = 5 + st * 9, f2 = 2 + st * 6;
      ctx.moveTo(px[q] - ux2 * L2 - uy2 * f2, py[q] - uy2 * L2 + ux2 * f2);
      ctx.lineTo(px[q], py[q]);
      ctx.lineTo(px[q] - ux2 * L2 + uy2 * f2, py[q] - uy2 * L2 - ux2 * f2);
    }
    ctx.stroke();
    /* 触ったところにだけ、シアンの細い輪 */
    for (var k = marks.length - 1; k >= 0; k--) {
      var mk = marks[k];
      var al = 1 - mk.t / 1.4;
      if (al <= 0) { marks.splice(k, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, al * 0.5);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(mk.x, mk.y, 8 + mk.t * 60, 0, Math.PI * 2); ctx.stroke();
    }
    if (hawk.on > 0.02) {
      ctx.fillStyle = P.rgba(P.accent, 0.7 * hawk.on);
      ctx.beginPath(); ctx.arc(hawk.x, hawk.y, 2.5, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* 宣言がそろってから画面に合わせる（P.fit はその場でコールバックを呼ぶ） */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; allocGrid(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, lastMark = 0, dragFrom = { x: 0, y: 0 };
  canvas.addEventListener('pointerdown', function (e) {
    down = true; hawk.x = e.clientX; hawk.y = e.clientY; hawk.on = 1;
    marks.push({ x: hawk.x, y: hawk.y, t: 0 });
    scare(hawk.x, hawk.y, 170);
    launch(hawk.x, hawk.y, 140, 0, 0);
    dragFrom = { x: hawk.x, y: hawk.y };
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var mdx = e.clientX - dragFrom.x, mdy = e.clientY - dragFrom.y, md = Math.hypot(mdx, mdy);
    if (md > 10) { launch(e.clientX, e.clientY, Math.min(36, Math.round(md / 1.3)), mdx, mdy); dragFrom = { x: e.clientX, y: e.clientY }; }
    scare(e.clientX, e.clientY, 90);
    hawk.x = e.clientX; hawk.y = e.clientY;
    if (T - lastMark > 0.25) { marks.push({ x: hawk.x, y: hawk.y, t: 0.5 }); lastMark = T; }
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  /* 指から鳥が飛び立つ: 群れの中から順に選んだ鳥を指の位置へ移し、外向き（なぞった向き）に放つ */
  var recycle = 0;
  function scare(x, y, r) {
    for (var i = 0; i < N; i++) {
      var d = Math.hypot(px[i] - x, py[i] - y);
      if (d < r) startle[i] = Math.max(startle[i], 1 - d / r * 0.5);
    }
  }
  function launch(x, y, n, dx, dy) {
    for (var k = 0; k < n; k++) {
      var i = recycle; recycle = (recycle + 1) % N;
      var a = dx === 0 && dy === 0 ? k / n * Math.PI * 2 : Math.atan2(dy, dx) + (k % 2 ? 1 : -1) * (0.6 + Math.random() * 0.9);
      var r = Math.random() * 14, sp = dx === 0 && dy === 0 ? 80 + Math.random() * 120 : 75 + Math.random() * 40;
      if (dx || dy) { var tt = Math.random(); px[i] = x - dx * tt; py[i] = y - dy * tt; }
      if (!(dx || dy)) { px[i] = x + Math.cos(a) * r; py[i] = y + Math.sin(a) * r; }
      vx[i] = Math.cos(a) * sp; vy[i] = Math.sin(a) * sp;
      dz[i] = 0.75 + Math.random() * 0.25; startle[i] = 1;
      for (var h = 0; h < HL; h++) { hx[i * HL + h] = px[i]; hy[i * HL + h] = py[i]; }
    }
  }
  canvas.addEventListener('pointercancel', up);

  function birdsFrom(v) { return 200 + v / 100 * 1300; }
  function calmFrom(v) { return 1.35 - v / 100 * 0.7; }
  document.getElementById('k-birds').addEventListener('input', function () {
    var n = birdsFrom(+this.value) | 0;
    if (n > N) setCount(n); else N = n;
  });
  document.getElementById('k-calm').addEventListener('input', function () { speed = calmFrom(+this.value); });
  document.getElementById('k-scatter').addEventListener('click', function () {
    for (var i = 0; i < N; i++) { var a = Math.random() * Math.PI * 2; vx[i] = Math.cos(a) * 160; vy[i] = Math.sin(a) * 160; }
    SFX.play('click');
  });
  setCount(birdsFrom(+document.getElementById('k-birds').value));
  speed = calmFrom(+document.getElementById('k-calm').value);

  /* 最初の数秒で群れの形がまとまるよう、少し先まで進めておく */
  roost();
  for (var w = 0; w < 90; w++) { T += 1 / 30; roost(); step(1 / 30); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), gustWait = 9;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    roost();
    if (!down) hawk.on = Math.max(0, hawk.on - dt * 1.5);
    for (var sq = 0; sq < N; sq++) if (startle[sq] > 0) startle[sq] = Math.max(0, startle[sq] - dt * 0.8);
    /* ときどき群れの縁で小さな乱れが起きて、波が中へ伝わる */
    gustWait -= dt;
    if (gustWait <= 0) {
      var r = (Math.random() * N) | 0;
      gust.x = px[r]; gust.y = py[r]; gust.t = 0.6;
      gustWait = 10 + Math.random() * 10;
    }
    gust.t = Math.max(0, gust.t - dt);
    for (var k = 0; k < marks.length; k++) marks[k].t += dt;
    step(dt);
    record(dt);
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
