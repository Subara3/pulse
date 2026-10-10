<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('symicon');

$pageName = 'symicon';
$pageTitle = 'SYMICON - PULSE';
$pageDesc = '回転対称の模様が画面の中央でゆっくり回りながら、少しずつ形を変える。押すとそこの模様が押し広げられて水色に光り、なぞると模様が指の向きに流される。';
$pageCanonical = 'https://pulse.subara3.com/symicon.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-spin">SPIN</label><input type="range" id="k-spin" min="0" max="100" value="15"></div>
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="50"></div>
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
   * 対称カオス写像（Field & Golubitsky）:
   *   z' = (λ + α|z|² + β Re(zⁿ) + iω) z + γ z̄ⁿ⁻¹
   * n 回の回転対称を持つ。1 点を何百万回も跳ねさせ、落ちた場所の濃さを描く。
   * 係数を少しだけ揺らすと、紋章の形がゆっくり呼吸する。
   */
  /* l=λ, a=α, b=β（Re(zⁿ) の係数）, g=γ（z̄ⁿ⁻¹ の係数）, w=ω。どれも点が 1 か所に固まらない組だけを選んである */
  var ICONS = [
    { n: 6, l: -2.7, a: 5, b: 1.5, g: 1, w: 0 },
    { n: 7, l: -2.08, a: 1, b: -0.1, g: 0.167, w: 0 },
    { n: 3, l: 1.56, a: -1, b: 0.1, g: -0.82, w: 0 },
    { n: 5, l: 2.6, a: -2, b: 0, g: -0.5, w: 0 },
    { n: 5, l: -2.5, a: 5, b: -1.9, g: 1, w: 0.188 },
    { n: 23, l: 2.409, a: -2.5, b: 0, g: 0.9, w: 0 },
    { n: 9, l: -2.05, a: 3, b: -16.79, g: 1, w: 0 },
    { n: 5, l: -2.34, a: 2, b: 0.2, g: 0.1, w: 0 },
    { n: 4, l: -1.86, a: 2, b: 0, g: 1, w: 0.1 },
    { n: 16, l: 2.39, a: -2.5, b: -0.1, g: 0.9, w: -0.15 },
    { n: 6, l: -2.42, a: 1, b: -0.04, g: 0.14, w: 0.088 }
  ];
  var cur = ICONS[Math.floor(Math.random() * ICONS.length)];

  var n = 5, lam = 0, alp = 0, gam = 0, bet = 0, omg = 0;
  var zx = 0.1, zy = 0.01, resets = 0, warm = 0, probe = [];
  function iter() {
    var r2 = zx * zx + zy * zy;
    if (r2 > 100 || r2 !== r2) { zx = (Math.random() - 0.5) * 0.2; zy = (Math.random() - 0.5) * 0.2; resets++; warm = 40; return; }
    /* zⁿ⁻¹ を極形式でなく掛け算で作る（atan2 を避けて速く） */
    var pr = 1, pi = 0;
    for (var k = 1; k < n; k++) { var t = pr * zx - pi * zy; pi = pr * zy + pi * zx; pr = t; }
    var znr = pr * zx - pi * zy;                 // Re(zⁿ)
    var s = lam + alp * r2 + bet * znr;
    var nx = s * zx - omg * zy + gam * pr;       // z̄ⁿ⁻¹ = conj(zⁿ⁻¹)
    var ny = s * zy + omg * zx - gam * pi;
    zx = nx; zy = ny;
  }

  /* 形の大きさを測る（中央の 99% が収まる半径） */
  function measure(ic) {
    n = ic.n; lam = ic.l; alp = ic.a; gam = ic.g; bet = ic.b; omg = ic.w;
    zx = 0.01; zy = 0.003;
    var rs = [];
    for (var i = 0; i < 30000; i++) { iter(); if (i > 300) rs.push(Math.sqrt(zx * zx + zy * zy)); }
    rs.sort(function (a, b) { return a - b; });
    return rs[Math.floor(rs.length * 0.995)] || 1;
  }
  ICONS.forEach(function (ic) { ic.r = measure(ic); });

  /* ---------------------------------------------------------- 濃度の格子 */
  var den, img, buf, bctx, NX, NY, peak = 6;
  function alloc() {
    NX = Math.round(W); NY = Math.round(H);
    if (NX * NY > 1400000) { var s = Math.sqrt(1400000 / (NX * NY)); NX = Math.round(NX * s); NY = Math.round(NY * s); }
    den = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas'); buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
  }
  var LUT = new Uint8Array(256 * 3), LUTA = new Uint8Array(256 * 3);
  for (var i = 0; i < 256; i++) {
    var c = P.ramp(0.04 + Math.pow(i / 255, 0.85) * 0.96), ca = P.rampAccent(0.08 + i / 255 * 0.92);
    LUT[i * 3] = c[0]; LUT[i * 3 + 1] = c[1]; LUT[i * 3 + 2] = c[2];
    LUTA[i * 3] = ca[0]; LUTA[i * 3 + 1] = ca[1]; LUTA[i * 3 + 2] = ca[2];
  }
  var BG = P.bg, DEEP = P.deep;

  /* ---------------------------------------------------------- 状態 */
  var t = 0, rot = 0, spin = 0.35, drift = 0.5, driftK = 1;
  var scale = 1, scaleT = 1;
  var lens = { x: 0, y: 0, s: 0, on: false };
  var fade = 1;        // 入れ替え中は 0 → 1

  function setParams() {
    var d = drift * driftK;
    n = cur.n;
    lam = cur.l + d * 0.014 * Math.sin(t * 0.11);
    bet = cur.b + d * 0.012 * Math.sin(t * 0.083 + 1.3);
    gam = cur.g + d * 0.012 * Math.sin(t * 0.067 + 2.1);
    alp = cur.a; omg = cur.w;
  }

  function scatter(cnt) {
    var cx = NX / 2, cy = NY / 2, sc = Math.min(NX, NY) * 0.45 / cur.r * scale;
    var cs = Math.cos(rot) * sc, sn = Math.sin(rot) * sc;
    var r0 = resets;
    for (var i = 0; i < cnt; i++) {
      iter();
      if (warm > 0) { warm--; continue; }      // 振り出し直後の飛び散りは描かない
      var x = cx + zx * cs - zy * sn, y = cy - (zx * sn + zy * cs);
      var ix = x | 0, iy = y | 0;
      if (ix >= 0 && iy >= 0 && ix < NX && iy < NY) den[iy * NX + ix] += 1;
      if ((i & 511) === 0 && probe.length < 160) probe.push(((zx / cur.r * 40) | 0) * 1000 + ((zy / cur.r * 40) | 0));
    }
    /* 点が数か所に固まってしまったら（周期軌道に落ちた）、揺らしを止めて振り出しからやり直す */
    var uniq = {}, nu = 0;
    for (var p = 0; p < probe.length; p++) if (!uniq[probe[p]]) { uniq[probe[p]] = 1; nu++; }
    if (probe.length > 60 && nu < 30) { driftK = 0; zx = 0.01 + Math.random() * 0.05; zy = 0.003; warm = 200; }
    probe.length = 0;
    /* 揺らしすぎて点が逃げるなら、揺らしを弱める */
    if (resets - r0 > cnt * 0.01) driftK = Math.max(0, driftK - 0.1);
    else driftK = Math.min(1, driftK + 0.002);
  }

  function shade() {
    var d = img.data, len = NX * NY, mx = 0, decay = 0.97;
    var inv = 1 / Math.log(1 + peak);
    var lx = lens.x / W * NX, ly = lens.y / H * NY, lr = Math.min(NX, NY) * 0.26, lr2 = lr * lr, ls = lens.s;
    var ex = 1 + 0.7 * ls;
    for (var k = 0; k < len; k++) {
      var v = den[k], o = k * 4, wa = 0;
      if (v > mx) mx = v;
      if (ls > 0.01) {
        /* レンズ: 指の下を拡大して見せる（描いた後の見え方だけを曲げるので、押した瞬間に効く） */
        var px = k % NX, py = (k / NX) | 0, dx = px - lx, dy = py - ly, r2 = dx * dx + dy * dy;
        if (r2 < lr2) {
          var rr = Math.sqrt(r2), f = rr > 0 ? Math.pow(rr / lr, ex - 1) : 0;
          var sx = Math.round(lx + dx * f), sy = Math.round(ly + dy * f);
          v = (sx >= 0 && sy >= 0 && sx < NX && sy < NY) ? den[sy * NX + sx] : 0;
          wa = 1 - r2 / lr2; wa = Math.min(1, wa * 1.6) * ls * 0.75;
        }
      }
      den[k] *= decay;
      if (v < 0.05) { d[o] = BG[0]; d[o + 1] = BG[1]; d[o + 2] = BG[2]; d[o + 3] = 255; continue; }
      var l = Math.log(1 + v) * inv;
      l = l < 0.14 ? 0 : (l - 0.14) / 0.86 * fade;     // ぽつんと落ちた点は地に沈める
      if (l > 1) l = 1;
      var li = ((l * 255) | 0) * 3;
      if (wa > 0) {
        d[o] = LUT[li] + (LUTA[li] - LUT[li]) * wa; d[o + 1] = LUT[li + 1] + (LUTA[li + 1] - LUT[li + 1]) * wa;
        d[o + 2] = LUT[li + 2] + (LUTA[li + 2] - LUT[li + 2]) * wa;
      } else { d[o] = LUT[li]; d[o + 1] = LUT[li + 1]; d[o + 2] = LUT[li + 2]; }
      d[o + 3] = 255;
    }
    peak += (Math.max(4, mx * 0.6) - peak) * 0.04;
    warpPass(function (v, o, w) {
      if (v < 0.05) { d[o] = BG[0]; d[o + 1] = BG[1]; d[o + 2] = BG[2]; return; }
      var l = Math.log(1 + v) * inv; l = l < 0.14 ? 0 : (l - 0.14) / 0.86 * fade; l = Math.min(1, l * (1 + 2.6 * w) + 0.4 * w * (l > 0 ? 1 : 0));
      var q = ((l * 255) | 0) * 3, ww = w * 0.8;
      d[o] = LUT[q] + (LUTA[q] - LUT[q]) * ww; d[o + 1] = LUT[q + 1] + (LUTA[q + 1] - LUT[q + 1]) * ww; d[o + 2] = LUT[q + 2] + (LUTA[q + 2] - LUT[q + 2]) * ww;
    });
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  var hold = 0, swapping = false;
  function next() {
    if (swapping) return;
    swapping = true;
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
    var gdec = Math.exp(-dt * 0.6);
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
  canvas.addEventListener('pointerdown', function (e) {
    lens.on = true; lens.x = e.clientX; lens.y = e.clientY;
    fieldAdd(e.clientX, e.clientY, 0, 0, 75);
    canvas.setPointerCapture(e.pointerId);
    SFX.play('ripple', 250);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!lens.on) return;
    fieldAdd(e.clientX, e.clientY, (e.clientX - lens.x) * 0.9, (e.clientY - lens.y) * 0.9, 6);
    lens.x = e.clientX; lens.y = e.clientY;
    SFX.play('move', 220);
  });
  function up() { lens.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-spin').addEventListener('input', function () { spin = this.value / 100; });
  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  spin = document.getElementById('k-spin').value / 100;
  drift = document.getElementById('k-drift').value / 100;
  document.getElementById('k-next').addEventListener('click', function () { next(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  setParams(); zx = 0.01; zy = 0.003;
  for (var w = 0; w < 6; w++) scatter(60000);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    t += dt;
    rot += dt * (0.004 + spin * 0.05);
    lens.s += ((lens.on ? 1 : 0) - lens.s) * Math.min(1, dt * (lens.on ? 14 : 0.9));
    if (!swapping) { hold += dt; if (hold > 42) next(); }
    if (swapping) {
      /* 薄れて縮み、次の紋章が広がって出てくる */
      fade = Math.max(0, fade - dt / 1.6);
      if (fade === 0) {
        var c2; do { c2 = ICONS[Math.floor(Math.random() * ICONS.length)]; } while (c2 === cur);
        cur = c2; swapping = false; hold = 0; driftK = 1; scale = 0.85;
        zx = 0.01; zy = 0.003; warm = 300;
      }
    } else if (fade < 1) fade = Math.min(1, fade + dt / 2.2);
    scale += (1 - scale) * Math.min(1, dt * 0.6);
    setParams();
    scatter(52000);
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
