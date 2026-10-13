<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ridgeline');

$pageName = 'ridgeline';
$pageTitle = 'RIDGELINE - PULSE';
$pageDesc = '何十本もの横線が重なって山並みを描き、山はゆっくり手前へ流れてくる。押さえるとそこから山が盛り上がって高くなり、離すと山は地形といっしょに流れていく。なぞると指の跡に尾根ができる。';
$pageCanonical = 'https://pulse.subara3.com/ridgeline.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-height">HEIGHT</label><input type="range" id="k-height" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" class="active" data-land="peaks">PEAKS</button><button type="button" data-land="hills">HILLS</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 横線 1 本 = 奥行きの 1 断面。奥から順に、線の下を地の色で塗ってから線を引くと、
     手前の山が奥の線を隠して山並みに見える */
  var N = 60, STEP = 5, NP = 0, TOP = 0, SPAN = 0, UNIT = 0, S = 1;
  var DZ = 0.065;                 /* 断面どうしの奥行きの間隔（地形の座標） */
  var Z = 10;                     /* いちばん奥の断面の位置。増えると地形が手前へ流れる */
  var speed = 0.1, heightK = 1;
  var land = 'peaks', mixT = 1, mixGoal = 1;   /* 1 = PEAKS, 0 = HILLS */
  var bumps = [];                 /* 指が足した山: 地形の座標に置くので地形といっしょに流れる */
  var ys, cs, xs, sky;
  var held = null, pointer = null, lastDrop = null;

  /* ---------------------------------------------------------- 地形 */
  function hash(i, j) {
    var n = Math.imul(i, 374761393) + Math.imul(j, 668265263) | 0;
    n = Math.imul(n ^ (n >>> 13), 1274126177);
    return (n ^ (n >>> 16)) & 7;
  }
  var GX = [1, -1, 0, 0, 0.7071, -0.7071, 0.7071, -0.7071];
  var GY = [0, 0, 1, -1, 0.7071, 0.7071, -0.7071, -0.7071];
  function grad(i, j, x, y) { var h = hash(i, j); return GX[h] * x + GY[h] * y; }
  /* 勾配ノイズ: およそ -0.7..0.7 */
  function noise(x, y) {
    var xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
    var u = xf * xf * xf * (xf * (xf * 6 - 15) + 10), v = yf * yf * yf * (yf * (yf * 6 - 15) + 10);
    var a = grad(xi, yi, xf, yf), b = grad(xi + 1, yi, xf - 1, yf);
    var c = grad(xi, yi + 1, xf, yf - 1), d = grad(xi + 1, yi + 1, xf - 1, yf - 1);
    var ab = a + (b - a) * u, cd = c + (d - c) * u;
    return ab + (cd - ab) * v;
  }
  /* 尖った尾根: 0 付近を折り返して稜線を作る */
  function peaks(x, z) {
    var s = 0, f = 1.6, a = 1, n;
    for (var o = 0; o < 4; o++) {
      n = 1 - Math.abs(noise(x * f + o * 17.3, z * f) * 1.4);
      s += n * n * a * (o === 0 ? 1 : s * 0.9 + 0.1);
      f *= 2.03; a *= 0.48;
    }
    var mask = noise(x * 0.55 + 41.7, z * 0.55) * 0.9 + 0.55;
    return Math.max(0, s * 0.62 * mask - 0.08);
  }
  /* なだらかな丘 */
  function hills(x, z) {
    var s = 0, f = 1.1, a = 1;
    for (var o = 0; o < 3; o++) { s += noise(x * f + o * 9.1, z * f) * a; f *= 2.1; a *= 0.45; }
    return Math.max(0, s * 0.75 + 0.3);
  }
  function terrain(x, z) {
    if (mixT >= 0.999) return peaks(x, z);
    if (mixT <= 0.001) return hills(x, z);
    return peaks(x, z) * mixT + hills(x, z) * (1 - mixT);
  }

  /* 画面の行 u（0 = 奥, 1 = 手前）と地形の座標の対応。手前ほど間隔が広い */
  function rowY(u) { return TOP + SPAN * (0.55 * u + 0.45 * u * u); }
  function rowU(y) {
    var t = Math.max(0, Math.min(1, (y - TOP) / SPAN));
    return (-0.55 + Math.sqrt(0.3025 + 1.8 * t)) / 0.9;
  }
  function persp(u) { return 1.45 - 0.6 * u; }
  function toWorld(x, y) {
    var u = rowU(y);
    return { x: (x - W / 2) / S * persp(u), z: Z - u * (N - 1) * DZ };
  }

  function setup() {
    N = Math.max(44, Math.min(80, Math.round(H / 11)));
    STEP = Math.max(4, Math.round(W / 320));
    NP = Math.ceil(W / STEP) + 3;
    TOP = H * 0.17;
    SPAN = H * 0.9;
    UNIT = H * 0.2;
    S = Math.max(W, H) * 0.42;
    ys = new Float32Array(NP);
    cs = new Float32Array(NP);
    xs = new Float32Array(NP);
    sky = new Float32Array(NP);
    for (var k = 0; k < NP; k++) xs[k] = (k - 1) * STEP;
  }

  /* ---------------------------------------------------------- 指の山 */
  function addBump(x, y, amp, r) {
    var w = toWorld(x, y);
    var b = { x: w.x, z: w.z, amp: 0, goal: amp, r: r, age: 0, life: 1, fresh: 1, free: 0 };
    bumps.push(b);
    if (bumps.length > 240) {
      for (var i = 0; i < bumps.length; i++) if (bumps[i] !== held) { bumps.splice(i, 1); break; }
    }
    return b;
  }

  function updateBumps(dt) {
    var front = Z - (N - 1) * DZ;
    for (var i = bumps.length - 1; i >= 0; i--) {
      var b = bumps[i];
      b.age += dt;
      if (b === held && pointer) {
        /* 押さえているあいだは指の下に留まり、少しずつ高くなる */
        var w = toWorld(pointer.x, pointer.y);
        b.x += (w.x - b.x) * Math.min(1, dt * 14);
        b.z += (w.z - b.z) * Math.min(1, dt * 14);
        b.goal = Math.min(1.5, b.goal + dt * 0.35);
        b.fresh = 1;
      } else {
        b.free += dt;
        b.life = Math.exp(-b.free / 20);
        b.fresh = Math.exp(-b.free / 3.5);
      }
      b.amp += (b.goal * b.life - b.amp) * Math.min(1, dt * 7);
      if ((b !== held && b.life < 0.03) || b.z < front - b.r * 3) bumps.splice(i, 1);
    }
  }

  /* ---------------------------------------------------------- 描く */
  /* 手前の線から順に引き、これまでに引いた線より上に出た部分だけを描く（sky = 列ごとのいちばん高い線）。
     塗りで隠すより軽い */
  var SA = [], SB = [], SC = [], SD = [], SV = [], NS = 0;
  function clipRow() {
    NS = 0;
    for (var k = 1; k < NP; k++) {
      var d0 = ys[k - 1] - sky[k - 1], d1 = ys[k] - sky[k];
      if (d0 >= 0 && d1 >= 0) continue;
      var x0 = xs[k - 1], y0 = ys[k - 1], x1 = xs[k], y1 = ys[k], t;
      if (d0 >= 0) { t = d0 / (d0 - d1); x0 += t * STEP; y0 += t * (y1 - y0); }
      else if (d1 >= 0) { t = d0 / (d0 - d1); y1 = y0 + t * (y1 - y0); x1 = x0 + t * STEP; }
      SA[NS] = x0; SB[NS] = y0; SC[NS] = x1; SD[NS] = y1; SV[NS] = (cs[k] + cs[k - 1]) * 0.5; NS++;
    }
  }
  function strokeSegs(lo, hi) {
    var ex = -1e9, ey = -1e9, any = false;
    ctx.beginPath();
    for (var n = 0; n < NS; n++) {
      if (SV[n] < lo || SV[n] >= hi) continue;
      if (SA[n] !== ex || SB[n] !== ey) ctx.moveTo(SA[n], SB[n]);
      ctx.lineTo(SC[n], SD[n]);
      ex = SC[n]; ey = SD[n]; any = true;
    }
    return any;
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    for (var k = 0; k < NP; k++) sky[k] = H + 40;
    var near = [];
    ctx.lineWidth = 1;
    for (var i = N - 1; i >= 0; i--) {
      var u = i / (N - 1);
      var yb = rowY(u), amp = UNIT * heightK * (0.4 + 0.9 * u), ps = persp(u);
      var wz = Z - i * DZ;
      near.length = 0;
      for (var n = 0; n < bumps.length; n++) {
        var b = bumps[n];
        if (b.amp > 0.004 && Math.abs(b.z - wz) < b.r * 2.6) near.push(b);
      }
      var hot = false;
      for (k = 0; k < NP; k++) {
        var wx = (xs[k] - W / 2) / S * ps;
        var h = terrain(wx, wz) * 0.9, add = 0, c = 0;
        for (var m = 0; m < near.length; m++) {
          var q = near[m], dx = wx - q.x, dz = wz - q.z, r2 = q.r * q.r;
          var d2 = dx * dx + dz * dz;
          if (d2 > r2 * 6.5) continue;
          var g = Math.exp(-d2 / r2) * q.amp;
          add += g; c += g * q.fresh;
        }
        if (add > 0) {
          add = 2.2 * (1 - Math.exp(-add / 2.2));   /* 重ねても高くなりすぎない */
          hot = hot || c > 0.02;
        }
        ys[k] = yb - (h + add) * amp;
        cs[k] = c;
      }
      clipRow();
      /* 稜線。奥は鋼色で薄く、手前ほど白い */
      if (strokeSegs(-1, hot ? 0.02 : 1e9)) {
        ctx.strokeStyle = P.rgba(P.ramp(0.5 + 0.5 * u), 0.3 + 0.65 * u);
        ctx.stroke();
      }
      /* 指が足した山だけ、まだ新しいうちは水色 */
      if (hot) {
        for (var lv = 1; lv <= 3; lv++) {
          var lo = lv === 1 ? 0.02 : lv === 2 ? 0.15 : 0.4, hi = lv === 1 ? 0.15 : lv === 2 ? 0.4 : 1e9;
          if (strokeSegs(lo, hi)) {
            ctx.strokeStyle = P.rgba(P.mix(P.ramp(0.5 + 0.5 * u), P.accent, 0.4 + 0.2 * lv), 0.55 + 0.15 * lv);
            ctx.stroke();
          }
        }
      }
      for (k = 0; k < NP; k++) if (ys[k] < sky[k]) sky[k] = ys[k];
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; setup(); });

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    pointer = pos(e); lastDrop = pointer;
    held = addBump(pointer.x, pointer.y, 0.75, 0.2);
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!pointer) return;
    pointer = pos(e);
    var dist = Math.hypot(pointer.x - lastDrop.x, pointer.y - lastDrop.y);
    if (dist < 14) return;
    var n = Math.ceil(dist / 16);
    for (var i = 1; i <= n; i++) {
      addBump(lastDrop.x + (pointer.x - lastDrop.x) * i / n, lastDrop.y + (pointer.y - lastDrop.y) * i / n, 0.2, 0.08);
    }
    lastDrop = pointer;
    SFX.play('move', 140);
  });
  function up() { pointer = null; held = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speed = 0.015 + document.getElementById('k-speed').value / 100 * 0.2; }
  function readHeight() { heightK = 0.35 + document.getElementById('k-height').value / 100 * 1.2; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-height').addEventListener('input', readHeight);
  document.querySelectorAll('[data-land]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-land]').forEach(function (o) { o.classList.toggle('active', o === b); });
      land = b.dataset.land;
      mixGoal = land === 'peaks' ? 1 : 0;
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readHeight();
  var act = document.querySelector('[data-land].active');
  if (act) { land = act.dataset.land; mixGoal = mixT = land === 'peaks' ? 1 : 0; }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    Z += speed * dt;
    if (mixT !== mixGoal) {
      mixT += (mixGoal > mixT ? 1 : -1) * dt / 1.6;
      mixT = Math.max(0, Math.min(1, mixT));
    }
    updateBumps(dt);
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
