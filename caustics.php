<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('caustics');

$pageName = 'caustics';
$pageTitle = 'CAUSTICS - PULSE';
$pageDesc = '水面の波で曲げられた光が、底に明るい網目の模様を作ってゆっくり揺れている。触るとその場所から水面に輪が広がり、網目に輪の形の光が加わる。';
$pageCanonical = 'https://pulse.subara3.com/caustics.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-depth">DEPTH</label><input type="range" id="k-depth" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-swell">SWELL</label><input type="range" id="k-swell" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-calm">CALM</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * 水面の高さ h(x, y, t) は数本の進む波の和。上からまっすぐ来た光は水面の傾きで曲がり、
   * 底に着く位置が  (x, y) − depth·∇h  へずれる。光の筋を底の格子に数えると、集まった所が明るい網になる。
   */
  var GW, GH, acc, mineAcc, tmp, jit, img, buf, bctx;
  var RW, RH, rx, ry;
  var waves = [];
  var ripples = [];
  var depth = 0.5, swell = 0.45, calmT = 0;
  /* 波ごとの列・行の三角関数表 */
  var cosA, sinA, cosB, sinB;
  var NWAVE = 5, RD = 1.1;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  function alloc() {
    GW = Math.max(180, Math.min(360, Math.round(W / 3.8)));
    GH = Math.max(110, Math.round(GW * H / W));
    acc = new Float32Array(GW * GH); mineAcc = new Float32Array(GW * GH);
    img = new ImageData(GW, GH);
    buf = document.createElement('canvas');
    buf.width = GW; buf.height = GH;
    bctx = buf.getContext('2d');
    /* 光の筋は画面の少し外からも落とす（端が暗く抜けないように） */
    var mg = Math.round(GW * 0.06);
    RW = Math.round((GW + mg * 2) * RD); RH = Math.round((GH + mg * 2) * RD);
    rx = new Float32Array(RW); ry = new Float32Array(RH);
    for (var i = 0; i < RW; i++) rx[i] = -mg + (i + 0.5) / RW * (GW + mg * 2);
    for (var j = 0; j < RH; j++) ry[j] = -mg + (j + 0.5) / RH * (GH + mg * 2);
    tmp = new Float32Array(GW * GH);
    /* 落ちる位置を筋ごとに少しずらす。格子目のうなりが出ない */
    jit = new Float32Array(RW * RH * 2);
    for (var q = 0; q < jit.length; q++) jit[q] = (Math.random() - 0.5) * 0.4;
    cosA = new Float32Array(NWAVE * RW); sinA = new Float32Array(NWAVE * RW);
    cosB = new Float32Array(NWAVE * RH); sinB = new Float32Array(NWAVE * RH);
    waves.length = 0;
    /* 向きと波長の違う波。長い波ほどゆっくり進む */
    var base = GW / 3.2;
    for (var n = 0; n < NWAVE; n++) {
      var ang = n * 2.399 + 0.4, len = base * (0.45 + 0.75 * ((n * 0.618) % 1));
      var k = Math.PI * 2 / len;
      waves.push({ kx: Math.cos(ang) * k, ky: Math.sin(ang) * k, w: Math.sqrt(k) * 0.9, ph: n * 1.7, amp: 1 / (k * k) });
    }
    for (n = 0; n < NWAVE; n++) {
      var wv = waves[n];
      for (i = 0; i < RW; i++) { cosA[n * RW + i] = Math.cos(wv.kx * rx[i]); sinA[n * RW + i] = Math.sin(wv.kx * rx[i]); }
    }
  }

  function splat(field, x, y, v) {
    if (x < 0 || y < 0 || x >= GW - 1 || y >= GH - 1) return;
    var i = x | 0, j = y | 0, fx = x - i, fy = y - j, k = j * GW + i;
    field[k] += v * (1 - fx) * (1 - fy); field[k + 1] += v * fx * (1 - fy);
    field[k + GW] += v * (1 - fx) * fy; field[k + GW + 1] += v * fx * fy;
  }

  function trace(t) {
    acc.fill(0); mineAcc.fill(0);
    var n, i, j, wv;
    var s = (0.25 + swell * 1.1) * (1 - calmT * 0.85);
    for (n = 0; n < NWAVE; n++) {
      wv = waves[n];
      for (j = 0; j < RH; j++) {
        var b = wv.ky * ry[j] - wv.w * t + wv.ph;
        cosB[n * RH + j] = Math.cos(b); sinB[n * RH + j] = Math.sin(b);
      }
    }
    /* 底までの深さ。深いほど光が強く寄って、網の線が細く鋭くなる */
    var D = (0.25 + depth * 0.75) * 0.95;
    var nr = ripples.length;
    for (j = 0; j < RH; j++) {
      var y = ry[j];
      for (i = 0; i < RW; i++) {
        var x = rx[i], gx = 0, gy = 0;
        for (n = 0; n < NWAVE; n++) {
          wv = waves[n];
          /* cos(a + b) = cos a cos b − sin a sin b */
          var c = cosA[n * RW + i] * cosB[n * RH + j] - sinA[n * RW + i] * sinB[n * RH + j];
          var g = wv.amp * c * s;
          gx += g * wv.kx; gy += g * wv.ky;
        }
        var mx = 0, my = 0;
        for (var r = 0; r < nr; r++) {
          var rp = ripples[r];
          var dx = x - rp.x, dy = y - rp.y, d2 = dx * dx + dy * dy;
          if (d2 > rp.out2 || d2 < rp.in2) continue;
          var d = Math.sqrt(d2) + 1e-3, q = (d - rp.front) / rp.width;
          var e = Math.exp(-q * q) * rp.a;
          var gr = e * Math.cos(d * rp.k - rp.ph) * rp.k;
          mx += gr * dx / d; my += gr * dy / d;
        }
        var q2 = (j * RW + i) * 2;
        var ox = x - D * (gx + mx) + jit[q2], oy = y - D * (gy + my) + jit[q2 + 1];
        splat(acc, ox, oy, 1);
        if (mx !== 0 || my !== 0) {
          var share = Math.min(1, Math.sqrt(mx * mx + my * my) / (Math.sqrt(gx * gx + gy * gy) + 0.05));
          splat(mineAcc, ox, oy, share);
        }
      }
    }
  }

  /* 光の筋の格子目が見えないよう、底の明るさを 3×3 でならす */
  function blur(F) {
    var x, y, k;
    for (y = 0; y < GH; y++) {
      var row = y * GW;
      tmp[row] = F[row]; tmp[row + GW - 1] = F[row + GW - 1];
      for (x = 1; x < GW - 1; x++) { k = row + x; tmp[k] = (F[k - 1] + F[k] * 2 + F[k + 1]) * 0.25; }
    }
    for (y = 1; y < GH - 1; y++) {
      for (x = 0; x < GW; x++) { k = y * GW + x; F[k] = (tmp[k - GW] + tmp[k] * 2 + tmp[k + GW]) * 0.25; }
    }
  }

  var BG = P.bg, ST = P.steel, LN = P.line, AC = P.accent, DP = P.deep;
  function shade() {
    var d = img.data, mean = RD * RD;
    blur(acc); blur(mineAcc);
    for (var k = 0; k < GW * GH; k++) {
      var v = acc[k] / mean;
      /* 平均の明るさは暗く、光が 3 倍以上集まった所だけ白に近づける */
      var t = 1 - Math.exp(-Math.max(0, v - 0.8) * 0.4);
      var r, g, b;
      if (v < 0.8) {
        var u = Math.min(1, (0.8 - v) / 0.8);
        r = BG[0] + (DP[0] - BG[0]) * u; g = BG[1] + (DP[1] - BG[1]) * u; b = BG[2] + (DP[2] - BG[2]) * u;
      } else if (t < 0.5) {
        var t1 = t / 0.5;
        r = BG[0] + (ST[0] - BG[0]) * t1; g = BG[1] + (ST[1] - BG[1]) * t1; b = BG[2] + (ST[2] - BG[2]) * t1;
      } else {
        var t2 = Math.min(1, (t - 0.5) / 0.5) * 0.92;
        r = ST[0] + (LN[0] - ST[0]) * t2; g = ST[1] + (LN[1] - ST[1]) * t2; b = ST[2] + (LN[2] - ST[2]) * t2;
      }
      var m = acc[k] > 0 ? Math.min(1, mineAcc[k] / acc[k] * 1.6) * Math.min(1, t * 1.5) : 0;
      if (m > 0.01) { r += (AC[0] - r) * m * 0.8; g += (AC[1] - g) * m * 0.8; b += (AC[2] - b) * m * 0.8; }
      var o = k * 4;
      d[o] = r; d[o + 1] = g; d[o + 2] = b; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 1, 1, GW - 2, GH - 2, 0, 0, W, H);
  }

  /* 触った所から広がる水面の輪 */
  function ripple(px, py, a) {
    var k = Math.PI * 2 / (GW / 22);
    ripples.push({ x: px / W * GW, y: py / H * GH, age: 0, a: a / (k * k), k: k, ph: 0,
      front: 0, width: GW / 26, in2: 0, out2: 0 });
    if (ripples.length > 6) ripples.shift();
  }
  function ageRipples(dt) {
    var sp = GW / 9;
    for (var n = ripples.length - 1; n >= 0; n--) {
      var rp = ripples[n];
      rp.age += dt;
      rp.front = rp.age * sp;
      rp.ph = rp.age * 5;
      var life = Math.max(0, 1 - rp.age / 5.5);
      rp.a = rp.a0 === undefined ? (rp.a0 = rp.a) : rp.a0 * life * life;
      var lo = Math.max(0, rp.front - rp.width * 2.2), hi = rp.front + rp.width * 2.2;
      rp.in2 = lo * lo; rp.out2 = hi * hi;
      if (life <= 0) ripples.splice(n, 1);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    ripple(last.x, last.y, 2.6);
    SFX.play('ripple', 150);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < 70) return;
    ripple(p.x, p.y, 1.8);
    last = p;
    SFX.play('move', 260);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-depth').addEventListener('input', function () { depth = this.value / 100; });
  document.getElementById('k-swell').addEventListener('input', function () { swell = this.value / 100; });
  /* CALM: 波を一度しずめる。しばらくすると元に戻る */
  document.getElementById('k-calm').addEventListener('click', function () { calmT = 1; ripples.length = 0; SFX.play('click'); });
  depth = document.getElementById('k-depth').value / 100;
  swell = document.getElementById('k-swell').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), clock = 3, tick = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt * 0.55;
    if (calmT > 0) calmT = Math.max(0, calmT - dt / 9);
    ageRipples(dt);
    /* 光の網はゆっくりしか動かないので、計算は 2 フレームに 1 回で足りる */
    if ((tick++ & 1) === 0) { trace(clock); shade(); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
