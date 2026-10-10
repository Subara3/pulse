<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('galaxy');

$pageName = 'galaxy';
$pageTitle = 'GALAXY - PULSE';
$pageDesc = '何万もの星が中心のまわりを回っている。星は流れていくのに、渦の腕は形を保ったままゆっくり回る。触った星は水色に光り、そのまま渦に乗って流れていく。';
$pageCanonical = 'https://pulse.subara3.com/galaxy.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-twist">TWIST</label><input type="range" id="k-twist" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-tilt">TILT</label><input type="range" id="k-tilt" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" class="active" data-shape="spiral">SPIRAL</button><button type="button" data-shape="bar">BAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, CX = 0, CY = 0, RS = 1;
  var CELL = 2, GX = 0, GY = 0, dens, img, buf, bctx;
  var ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; CX = w / 2; CY = h / 2;
    RS = Math.min(w * 0.5, h * 0.62) * 1.05;
    GX = Math.ceil(w / CELL); GY = Math.ceil(h / CELL);
    dens = new Float32Array(GX * GY);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas'); buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
  });

  /* ---------------------------------------------------------- 星
     どの星も楕円の軌道を回る。楕円の向きを半径ごとに少しずつずらすと、
     楕円が混み合うところが腕になる。腕は星の集まりではなく、混み具合の波。 */
  var N = 25000;
  var A = new Float32Array(N), TH = new Float32Array(N), LUM = new Float32Array(N);
  var OX = new Float32Array(N), OY = new Float32Array(N), HEAT = new Float32Array(N), LX = new Float32Array(N), LY = new Float32Array(N);
  var EC = new Float32Array(N), OC = new Float32Array(N), OS = new Float32Array(N);
  var JO = new Float32Array(N), JE = new Float32Array(N);   /* 星ごとのばらつき（腕がぼやけて見える） */
  function gauss() { return (Math.random() + Math.random() + Math.random() - 1.5) * 1.15; }
  for (var i = 0; i < N; i++) {
    /* 円盤: 中ほどまでほぼ一様、外へなだらかに減る */
    var a = -Math.log(1 - Math.random() * 0.96) * 0.27;
    if (Math.random() < 0.35) a = Math.sqrt(Math.random()) * 0.62;
    if (Math.random() < 0.06) a = Math.random() * Math.random() * 0.12;   /* 中心のふくらみ */
    A[i] = Math.min(1.1, a + 0.006);
    TH[i] = Math.random() * Math.PI * 2;
    JO[i] = gauss() * 0.16; JE[i] = gauss() * 0.22;
    var r = Math.random();
    LUM[i] = (r < 0.012 ? 2.8 : 0.5 + r * 0.7) * (A[i] < 0.08 ? 0.45 : 1);
  }
  /* 半径ごとの楕円のつぶれと向きは、形を変えたときだけ計算し直す */
  function prepare() {
    for (var i = 0; i < N; i++) {
      EC[i] = 1 - ecc(A[i]) * (1 + JE[i]);
      var o = orient(A[i]) + JO[i];
      OC[i] = Math.cos(o); OS[i] = Math.sin(o);
    }
  }

  var shape = 'spiral', twist = 3.2, tilt = 0.35, t = 0;
  var pattern = 0, view = 0;

  function ecc(a) {
    if (shape === 'bar') return a < 0.24 ? 0.62 * Math.min(1, a / 0.05) : 0.42 * Math.exp(-(a - 0.24) * 1.4) + 0.12;
    return 0.42 * Math.min(1, a / 0.14) * Math.exp(-a * 0.6);
  }
  function orient(a) {
    if (shape === 'bar') return a < 0.22 ? 0 : (a - 0.22) * twist * 1.25;
    return a * twist;
  }

  function splat() {
    var f = 0.6;                       /* 前のフレームを少し残して流れをなめらかに */
    for (var k = 0; k < dens.length; k++) dens[k] *= f;
    var cv = Math.cos(view + pattern), sv = Math.sin(view + pattern);
    var incl = 1 - tilt * 0.62 - 0.06 * Math.sin(t * 0.021);
    var cxg = CX / CELL, cyg = CY / CELL, sc = RS / CELL;
    var px = pe > 0.01 ? ptr.x : -1e9, py = pe > 0.01 ? ptr.y : -1e9, pr2 = 85 * 85;
    for (var i = 0; i < N; i++) {
      var a = A[i];
      var psi = TH[i] - pattern;
      var x0 = a * Math.cos(psi), y0 = a * EC[i] * Math.sin(psi);
      var cp = OC[i], sp = OS[i];
      var x = x0 * cp - y0 * sp, y = x0 * sp + y0 * cp;
      var xr = x * cv - y * sv, yr = (x * sv + y * cv) * incl;
      var sxp = CX + xr * RS + OX[i], syp = CY + yr * RS + OY[i];
      LX[i] = sxp; LY[i] = syp;
      /* 指に引かれて集まり、集まった星は明るく光る */
      if (pe > 0.01) {
        var dx = px - sxp, dy = py - syp, d2 = dx * dx + dy * dy;
        if (d2 < pr2 * 4) {
          var ex = Math.exp(-d2 / pr2), g = Math.min(0.12, ex * 1.5 * DT * pe);
          OX[i] += dx * g; OY[i] += dy * g;
          if (ex * pe * 0.7 > HEAT[i]) HEAT[i] = ex * pe * 0.7;
        }
      }
      OX[i] *= RELAX; OY[i] *= RELAX;
      HEAT[i] *= COOL;
      /* 隣の 4 画素に分けて置く（動いてもちらつかない） */
      var fx = sxp / CELL - 0.5, fy = syp / CELL - 0.5, gx = Math.floor(fx), gy = Math.floor(fy);
      if (gx < 0 || gy < 0 || gx >= GX - 1 || gy >= GY - 1) continue;
      var ax = fx - gx, ay = fy - gy, L = LUM[i], k0 = gy * GX + gx;
      dens[k0] += L * (1 - ax) * (1 - ay); dens[k0 + 1] += L * ax * (1 - ay);
      dens[k0 + GX] += L * (1 - ax) * ay; dens[k0 + GX + 1] += L * ax * ay;
    }
  }

  /* 明るさの対応表: 藍 → 鋼 → 白。密なところほど白に近づくが、白には届かせない */
  var LUT = 1024, LR = new Uint8ClampedArray(LUT), LG = new Uint8ClampedArray(LUT), LB = new Uint8ClampedArray(LUT);
  (function () {
    var BG = P.bg, ST = P.steel, LN = P.line;
    for (var i = 0; i < LUT; i++) {
      var v = 1 - Math.exp(-i / LUT * 6 * 0.42 * 0.85);
      var c = v < 0.55 ? P.mix(BG, ST, Math.pow(v / 0.55, 0.85)) : P.mix(ST, LN, (v - 0.55) / 0.45 * 0.9);
      LR[i] = c[0]; LG[i] = c[1]; LB[i] = c[2];
    }
  })();
  function shade() {
    var d = img.data, k2 = LUT / 6;
    for (var k = 0, o = 0; k < dens.length; k++, o += 4) {
      var q = (dens[k] * k2) | 0;
      if (q >= LUT) q = LUT - 1;
      d[o] = LR[q]; d[o + 1] = LG[q]; d[o + 2] = LB[q]; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, GX * CELL, GY * CELL);
  }

  /* 触った星: 水色に光り、そのまま渦に乗って流れていく */
  function drawLit() {
    var B = 4, paths = [];
    for (var q = 0; q < B; q++) paths.push(new Path2D());
    var any = false;
    for (var i = 0; i < N; i++) {
      var h = HEAT[i];
      if (h < 0.06) continue;
      any = true;
      var r = 0.7 + h * 1.1;
      paths[Math.min(B - 1, Math.floor(h * B))].rect(LX[i] - r, LY[i] - r, r * 2, r * 2);
    }
    if (!any) return;
    var c = P.mix(P.accent, P.line, 0.35);
    for (var q2 = 0; q2 < B; q2++) {
      ctx.fillStyle = P.rgba(c, 0.25 + 0.75 * (q2 + 0.5) / B);
      ctx.fill(paths[q2]);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var hold = false, ptr = { x: 0, y: 0 }, mark = 0, DT = 0.016, RELAX = 1, COOL = 1, pe = 0;
  canvas.addEventListener('pointerdown', function (e) {
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY; pe = Math.max(pe, 0.7);
    /* 押した瞬間、まわりの星がひと息にそこへ寄って光る */
    var R2 = 85 * 85;
    for (var i = 0; i < N; i++) {
      var dx = ptr.x - LX[i], dy = ptr.y - LY[i], d2 = dx * dx + dy * dy;
      if (d2 > R2 * 4) continue;
      var ex = Math.exp(-d2 / R2);
      OX[i] += dx * 0.25 * ex; OY[i] += dy * 0.25 * ex;
      if (ex > HEAT[i]) HEAT[i] = ex;
    }
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!hold) return;
    ptr.x = e.clientX; ptr.y = e.clientY;
    SFX.play('move', 300);
  });
  function up() { hold = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var twistEl = document.getElementById('k-twist'), tiltEl = document.getElementById('k-tilt');
  function readTwist() { twist = 2 + twistEl.value / 100 * 8; }
  function readTilt() { tilt = tiltEl.value / 100; }
  twistEl.addEventListener('input', readTwist);
  tiltEl.addEventListener('input', readTilt);
  readTwist(); readTilt(); prepare();
  twistEl.addEventListener('input', prepare);
  document.querySelectorAll('[data-shape]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-shape]').forEach(function (o) { o.classList.toggle('active', o === b); });
      shape = b.dataset.shape;
      prepare();
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    t += dt; DT = dt; RELAX = Math.exp(-dt * 1.3); COOL = Math.exp(-dt * 0.8);
    pe = hold ? Math.min(1, pe + dt * 6) : pe * Math.exp(-dt * 3);
    /* 星はそれぞれの速さで、腕の模様はゆっくり一定の速さで回る */
    for (var i = 0; i < N; i++) TH[i] += dt * 0.11 / Math.sqrt(A[i] * A[i] + 0.012);
    pattern += dt * 0.025;
    view += dt * 0.004;
    splat();
    shade();
    drawLit();
    P.grid(ctx, W, H, { alpha: 0.45 });
    mark += ((hold ? 1 : 0) - mark) * Math.min(1, dt * 3);
    if (mark > 0.02) {
      ctx.strokeStyle = P.rgba(P.accent, 0.45 * mark);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(ptr.x, ptr.y, 14 + (1 - mark) * 10, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = P.rgba(P.accent, 0.8 * mark);
      ctx.beginPath(); ctx.arc(ptr.x, ptr.y, 2.4, 0, Math.PI * 2); ctx.fill();
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
