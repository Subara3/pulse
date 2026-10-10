<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ifs');

$pageName = 'ifs';
$pageTitle = 'IFS - PULSE';
$pageDesc = '小さな点が集まってできたシダが左右にしなり、しばらくすると葉や木の形に少しずつ変わっていく。押したところやなぞった跡に同じ形の小さな芽が生えて数秒で消え、まわりの点は明るく光る。';
$pageCanonical = 'https://pulse.subara3.com/ifs.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-dust">DUST</label><input type="range" id="k-dust" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /*
   * 4 枚の縮小写像（x' = a x + b y + e, y' = c x + d y + f）をでたらめな順に当て続けると、
   * 点は 1 つの形の上に集まる。写像の係数を少しずつ回すと形がしなり、
   * 別の形の係数へ補間すると、ある形から別の形へ育ち直す。
   */
  var SHAPES = [
    /* シダ */
    [[0, 0, 0, 0.16, 0, 0], [0.85, 0.04, -0.04, 0.85, 0, 1.6], [0.2, -0.26, 0.23, 0.22, 0, 1.6], [-0.15, 0.28, 0.26, 0.24, 0, 0.44]],
    /* 楓 */
    [[0.14, 0.01, 0, 0.51, -0.08, -1.31], [0.43, 0.52, -0.45, 0.5, 1.49, -0.75], [0.45, -0.49, 0.47, 0.47, -1.62, -0.74], [0.49, 0, 0, 0.51, 0.02, 1.62]],
    /* 木 */
    [[0.05, 0, 0, 0.6, 0, 0], [0.05, 0, 0, -0.5, 0, 1.0], [0.46, -0.32, 0.39, 0.38, 0, 0.6], [0.47, 0.15, -0.15, 0.42, 0, 1.1]],
    /* 渦 */
    [[0.787879, -0.424242, 0.242424, 0.859848, 1.758647, 1.408065], [-0.121212, 0.257576, 0.151515, 0.05303, -6.721654, 1.377236], [0.181818, -0.136364, 0.090909, 0.181818, 6.086107, 1.568035], [0.181818, -0.136364, 0.090909, 0.181818, 6.086107, 1.568035]],
    /* 枝 */
    [[0.0, 0.0, 0.0, 0.5, 0, 0], [0.42, -0.42, 0.42, 0.42, 0, 0.2], [0.42, 0.42, -0.42, 0.42, 0, 0.2], [0.1, 0, 0, 0.1, 0, 0.2]]
  ];

  /* 各形の外枠（ゆれていない状態）を先に測っておく */
  function measure(M) {
    var pr = probs(M), x = 0, y = 0, xs = [], ys = [];
    for (var n = 0; n < 24000; n++) {
      var r = Math.random(), k = 0;
      while (k < 3 && r > pr[k]) { r -= pr[k]; k++; }
      var m = M[k], nx = m[0] * x + m[1] * y + m[4];
      y = m[2] * x + m[3] * y + m[5]; x = nx;
      if (n > 50) { xs.push(x); ys.push(y); }
    }
    xs.sort(function (a, b) { return a - b; }); ys.sort(function (a, b) { return a - b; });
    var lo = Math.floor(xs.length * 0.003), hi = Math.floor(xs.length * 0.997);
    return [xs[lo], xs[hi], ys[lo], ys[hi]];
  }
  function probs(M) {
    var p = [], s = 0;
    for (var i = 0; i < 4; i++) { var d = Math.abs(M[i][0] * M[i][3] - M[i][1] * M[i][2]) + 0.012; p.push(d); s += d; }
    for (var j = 0; j < 4; j++) p[j] /= s;
    return p;
  }
  var BOX = SHAPES.map(measure);

  /* ---------------------------------------------------------- 濃度の格子 */
  var den, img, buf, bctx, NX, NY;
  function alloc() {
    NX = Math.max(320, Math.round(W)); NY = Math.max(180, Math.round(H));
    if (NX * NY > 1400000) { var s = Math.sqrt(1400000 / (NX * NY)); NX = Math.round(NX * s); NY = Math.round(NY * s); }
    den = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas'); buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    peak = 4;
  }
  var LUT = new Uint8Array(256 * 3), LUTA = new Uint8Array(256 * 3);
  for (var i = 0; i < 256; i++) {
    var c = P.ramp(0.06 + i / 255 * 0.94), ca = P.rampAccent(0.1 + i / 255 * 0.9);
    LUT[i * 3] = c[0]; LUT[i * 3 + 1] = c[1]; LUT[i * 3 + 2] = c[2];
    LUTA[i * 3] = ca[0]; LUTA[i * 3 + 1] = ca[1]; LUTA[i * 3 + 2] = ca[2];
  }
  var BG = P.bg;

  /* ---------------------------------------------------------- 形の補間 */
  var from = 0, to = 0, morph = 1, hold = 0;
  var wind = 0.45, dust = 0.5;
  var bend = 0, bendT = 0;            // 指で曲げる量（なめらかに追う）
  var M = [[0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0]];
  var box = [0, 1, 0, 1];
  var t = 0;

  function ease(x) { return x * x * (3 - 2 * x); }
  function build() {
    var e = ease(Math.min(1, morph)), A = SHAPES[from], B = SHAPES[to];
    for (var i = 0; i < 4; i++) {
      var m = M[i];
      for (var k = 0; k < 6; k++) m[k] = A[i][k] + (B[i][k] - A[i][k]) * e;
      /* 風: 写像ごとに少しずつずれた揺れで、線形部分を回す */
      var th = wind * 0.07 * (Math.sin(t * 0.43 + i * 1.7) + 0.6 * Math.sin(t * 0.19 + i * 0.9)) + bend * (i === 1 ? 0.045 : 0.012);
      var cs = Math.cos(th), sn = Math.sin(th);
      var a = m[0], b = m[1], c = m[2], d = m[3];
      m[0] = cs * a - sn * c; m[1] = cs * b - sn * d;
      m[2] = sn * a + cs * c; m[3] = sn * b + cs * d;
    }
    var bA = BOX[from], bB = BOX[to];
    for (var j = 0; j < 4; j++) box[j] = bA[j] + (bB[j] - bA[j]) * e;
  }

  var px = 0, py = 0;
  function scatter(n) {
    var pr = probs(M);
    var p0 = pr[0], p1 = p0 + pr[1], p2 = p1 + pr[2];
    var bw = box[1] - box[0], bh = box[3] - box[2];
    var sc = Math.min(NX * 0.82 / bw, NY * 0.86 / bh);
    var ox = NX / 2 - (box[0] + box[1]) / 2 * sc, oy = NY / 2 + (box[2] + box[3]) / 2 * sc;
    var x = px, y = py;
    for (var k = 0; k < n; k++) {
      var r = Math.random();
      var m = r < p0 ? M[0] : r < p1 ? M[1] : r < p2 ? M[2] : M[3];
      var nx = m[0] * x + m[1] * y + m[4];
      y = m[2] * x + m[3] * y + m[5]; x = nx;
      var ix = (ox + x * sc) | 0, iy = (oy - y * sc) | 0;
      if (ix >= 0 && iy >= 0 && ix < NX && iy < NY) den[iy * NX + ix] += 1;
    }
    if (!isFinite(x) || Math.abs(x) > 1e4) { x = 0; y = 0; }
    px = x; py = y;
    /* 触ったところに芽生えた小さな同じ形 */
    for (var q = 0; q < sprouts.length; q++) {
      var sp = sprouts[q], g = Math.min(1, sp.t / 0.25), life = sp.t < 4 ? 1 : Math.max(0, 1 - (sp.t - 4) / 1.5);
      var g2 = 1 - (1 - g) * (1 - g), s2 = sc * 0.3 * g2 * sp.k;
      var cs = Math.cos(sp.rot), sn = Math.sin(sp.rot);
      var fx = sp.x / W * NX, fy = sp.y / H * NY, mx2 = (box[0] + box[1]) / 2, my2 = (box[2] + box[3]) / 2;
      var cnt = Math.round(14000 * life), x2 = 0, y2 = 0;
      for (var k2 = 0; k2 < cnt; k2++) {
        var r2 = Math.random();
        var m2 = r2 < p0 ? M[0] : r2 < p1 ? M[1] : r2 < p2 ? M[2] : M[3];
        var nx2 = m2[0] * x2 + m2[1] * y2 + m2[4];
        y2 = m2[2] * x2 + m2[3] * y2 + m2[5]; x2 = nx2;
        if (k2 < 20) continue;
        var ux = (x2 - mx2) * s2, uy = (y2 - my2) * s2;
        var jx = (fx + ux * cs - uy * sn) | 0, jy = (fy - (ux * sn + uy * cs)) | 0;
        if (jx >= 0 && jy >= 0 && jx < NX && jy < NY) den[jy * NX + jx] += 1.4;
      }
    }
  }
  var sprouts = [];
  function sprout(x, y) {
    sprouts.push({ x: x, y: y, t: 0, rot: (Math.random() - 0.5) * 0.9, k: 0.8 + Math.random() * 0.4 });
    if (sprouts.length > 7) sprouts.shift();
  }

  var peak = 4;
  function shade() {
    var d = img.data, n = NX * NY, decay = 0.86, mx = 0;
    var inv = 1 / Math.log(1 + peak);
    var hx = touch.on || touch.glow > 0 ? touch.x / W * NX : -1e9, hy = touch.y / H * NY;
    var hr = 70 / W * NX, hr2 = hr * hr, glow = touch.glow;
    for (var k = 0; k < n; k++) {
      var v = den[k], o = k * 4;
      if (v > mx) mx = v;
      den[k] = v * decay;
      if (v < 0.02) { d[o] = BG[0]; d[o + 1] = BG[1]; d[o + 2] = BG[2]; d[o + 3] = 255; continue; }
      var l = Math.sqrt(Math.log(1 + v) * inv);
      if (l > 1) l = 1;
      var li = ((l * 255) | 0) * 3, wa = 0;
      if (glow > 0) {
        var ix = k % NX, iy = (k / NX) | 0, dx = ix - hx, dy = iy - hy, r2 = dx * dx + dy * dy;
        if (r2 < hr2) wa = glow * (1 - r2 / hr2);
      }
      if (wa > 0) {
        d[o] = LUT[li] + (LUTA[li] - LUT[li]) * wa; d[o + 1] = LUT[li + 1] + (LUTA[li + 1] - LUT[li + 1]) * wa;
        d[o + 2] = LUT[li + 2] + (LUTA[li + 2] - LUT[li + 2]) * wa;
      } else { d[o] = LUT[li]; d[o + 1] = LUT[li + 1]; d[o + 2] = LUT[li + 2]; }
      d[o + 3] = 255;
    }
    /* 濃さの目安を、上位の点に合わせてゆっくり追う */
    peak += (Math.max(3, mx * 0.7) - peak) * 0.05;
    warpPass(function (v, o, w) {
      if (v < 0.02) { d[o] = BG[0]; d[o + 1] = BG[1]; d[o + 2] = BG[2]; return; }
      var l = Math.sqrt(Math.log(1 + v) * inv); l = Math.min(1, l * (1 + 0.9 * w) + 0.12 * w);
      var q = ((l * 255) | 0) * 3, ww = w * 0.8;
      d[o] = LUT[q] + (LUTA[q] - LUT[q]) * ww; d[o + 1] = LUT[q + 1] + (LUTA[q + 1] - LUT[q + 1]) * ww; d[o + 2] = LUT[q + 2] + (LUTA[q + 2] - LUT[q + 2]) * ww;
    });
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  function next() {
    if (morph < 1) return;
    from = to; to = (to + 1 + Math.floor(Math.random() * (SHAPES.length - 1))) % SHAPES.length;
    if (to === from) to = (to + 1) % SHAPES.length;
    morph = 0; hold = 0;
    SFX.play('grow', 3000);
  }

  /* ---------------------------------------------------------- 指でずらす場（描いた後の見え方だけを動かす） */
  /* 粗い格子の各点に「ずらし量」を持たせる。押すとそこが押し広げられ、なぞると点が指の向きに流される。
     ずらしは 1 秒ほどで元に戻る。描き終えた濃さの画像を読み替えるだけなので、押した瞬間に効く */
  var FS = 12, FW = 0, FH = 0, FDX = null, FDY = null, FG = null, fLast = performance.now(), fActive = false;
  function fieldFit() {
    var w = Math.ceil(NX / FS) + 2, h = Math.ceil(NY / FS) + 2;
    if (w !== FW || h !== FH) { FW = w; FH = h; FDX = new Float32Array(FW * FH); FDY = new Float32Array(FW * FH); FG = new Float32Array(FW * FH); }
  }
  function fieldAdd(sx, sy, vx, vy, bulge) {
    fieldFit();
    var gx = sx / W * NX, gy = sy / H * NY, sc = NX / W, R = 130 * sc;
    var kx = vx * sc, ky = vy * sc;
    for (var j = 0; j < FH; j++) for (var i = 0; i < FW; i++) {
      var dx = i * FS - gx, dy = j * FS - gy, r = Math.sqrt(dx * dx + dy * dy);
      if (r >= R) continue;
      var f = 1 - r / R; f *= f;
      var k = j * FW + i, tx = FDX[k] + kx * f, ty = FDY[k] + ky * f;
      if (bulge && r > 0.5) { tx += dx / r * bulge * sc * f; ty += dy / r * bulge * sc * f; }
      var m = Math.sqrt(tx * tx + ty * ty), lim = 55 * sc;
      if (m > lim) { tx *= lim / m; ty *= lim / m; }
      FDX[k] = tx; FDY[k] = ty;
      FG[k] = Math.min(1, FG[k] + Math.sqrt(f) * 1.6);          // 触ったところの模様を明るく灯す
    }
    fActive = true;
  }
  function warpPass(paint) {
    var now = performance.now(), dt = Math.min(0.05, (now - fLast) / 1000); fLast = now;
    if (!fActive) return;
    fieldFit();
    var dec = Math.exp(-dt * 1.5), any = false;
    var gdec = Math.exp(-dt * 0.9);
    for (var n = 0; n < FW * FH; n++) { FDX[n] *= dec; FDY[n] *= dec; FG[n] *= gdec; if (FDX[n] * FDX[n] + FDY[n] * FDY[n] > 0.09 || FG[n] > 0.01) any = true; }
    if (!any) { fActive = false; return; }
    var lim = 55 * NX / W;
    for (var cj = 0; cj < FH - 1; cj++) for (var ci = 0; ci < FW - 1; ci++) {
      var k0 = cj * FW + ci, k1 = k0 + 1, k2 = k0 + FW, k3 = k2 + 1;
      var big = Math.max(Math.abs(FDX[k0]), Math.abs(FDY[k0]), Math.abs(FDX[k1]), Math.abs(FDY[k1]),
                         Math.abs(FDX[k2]), Math.abs(FDY[k2]), Math.abs(FDX[k3]), Math.abs(FDY[k3]));
      var gmax = Math.max(FG[k0], FG[k1], FG[k2], FG[k3]);
      if (big < 0.3 && gmax < 0.01) continue;
      var x0 = ci * FS, y0 = cj * FS;
      for (var yy = y0; yy < y0 + FS && yy < NY; yy++) {
        var v = (yy - y0) / FS;
        for (var xx = x0; xx < x0 + FS && xx < NX; xx++) {
          var u = (xx - x0) / FS;
          var ddx = (FDX[k0] * (1 - u) + FDX[k1] * u) * (1 - v) + (FDX[k2] * (1 - u) + FDX[k3] * u) * v;
          var ddy = (FDY[k0] * (1 - u) + FDY[k1] * u) * (1 - v) + (FDY[k2] * (1 - u) + FDY[k3] * u) * v;
          var qx = Math.round(xx - ddx), qy = Math.round(yy - ddy);
          var val = (qx >= 0 && qy >= 0 && qx < NX && qy < NY) ? den[qy * NX + qx] : 0;
          var gg = (FG[k0] * (1 - u) + FG[k1] * u) * (1 - v) + (FG[k2] * (1 - u) + FG[k3] * u) * v;
          paint(val, (yy * NX + xx) * 4, gg);
        }
      }
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var touch = { on: false, x: 0, y: 0, glow: 0 }, lastSprout = [0, 0];
  function aim(e) {
    touch.x = e.clientX; touch.y = e.clientY;
    bendT = Math.max(-1, Math.min(1, (e.clientX - W / 2) / (W * 0.35))) * -2.2;
  }
  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; aim(e);
    fieldAdd(e.clientX, e.clientY, 0, 0, 30);
    sprout(e.clientX, e.clientY); lastSprout = [e.clientX, e.clientY];
    canvas.setPointerCapture(e.pointerId);
    SFX.play('ripple', 250);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    var mvx = e.clientX - touch.x, mvy = e.clientY - touch.y;
    aim(e);
    fieldAdd(e.clientX, e.clientY, mvx * 0.9, mvy * 0.9, 6);
    if (Math.hypot(e.clientX - lastSprout[0], e.clientY - lastSprout[1]) > 90) { sprout(e.clientX, e.clientY); lastSprout = [e.clientX, e.clientY]; SFX.play('grow', 300); }
    SFX.play('move', 220);
  });
  function up() { touch.on = false; bendT = 0; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.getElementById('k-dust').addEventListener('input', function () { dust = this.value / 100; });
  wind = document.getElementById('k-wind').value / 100;
  dust = document.getElementById('k-dust').value / 100;
  document.getElementById('k-next').addEventListener('click', function () { next(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  build();
  for (var w = 0; w < 6; w++) scatter(30000);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    t += dt;
    if (morph < 1) morph += dt / 7; else { hold += dt; if (hold > 16) next(); }
    bend += (bendT - bend) * Math.min(1, dt * 5);
    touch.glow += ((touch.on ? 1 : 0) - touch.glow) * Math.min(1, dt * (touch.on ? 14 : 1.6));
    if (touch.glow < 0.01) touch.glow = 0;
    for (var q = sprouts.length - 1; q >= 0; q--) { sprouts[q].t += dt; if (sprouts[q].t > 5.5) sprouts.splice(q, 1); }
    build();
    scatter(Math.round(18000 + dust * 50000));
    shade();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
