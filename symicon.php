<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('symicon');

$pageName = 'symicon';
$pageTitle = 'SYMICON - PULSE';
$pageDesc = '回転対称の模様が画面の中央でゆっくり回りながら、少しずつ形を変える。40秒ほどで別の模様に入れ替わる。触ったところは模様が拡大されて見える。';
$pageCanonical = 'https://pulse.subara3.com/symicon.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-spin">SPIN</label><input type="range" id="k-spin" min="0" max="100" value="35"></div>
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
    var lx = lens.x / W * NX, ly = lens.y / H * NY, lr = Math.min(NX, NY) * 0.17, ls = lens.s;
    var r0 = resets;
    for (var i = 0; i < cnt; i++) {
      iter();
      if (warm > 0) { warm--; continue; }      // 振り出し直後の飛び散りは描かない
      var x = cx + zx * cs - zy * sn, y = cy - (zx * sn + zy * cs);
      if (ls > 0.002) {
        /* レンズ: 指の下を押し広げる（縁で元に戻る） */
        var dx = x - lx, dy = y - ly, r = Math.sqrt(dx * dx + dy * dy);
        if (r < lr && r > 0) {
          var k = Math.pow(r / lr, 1 - 0.28 * ls) * lr / r;
          x = lx + dx * k; y = ly + dy * k;
        }
      }
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
    var d = img.data, len = NX * NY, mx = 0, decay = 0.955;
    var inv = 1 / Math.log(1 + peak);
    var lx = lens.x / W * NX, ly = lens.y / H * NY, lr = Math.min(NX, NY) * 0.17, lr2 = lr * lr, ls = lens.s;
    for (var k = 0; k < len; k++) {
      var v = den[k], o = k * 4;
      if (v > mx) mx = v;
      den[k] = v * decay;
      if (v < 0.05) { d[o] = BG[0]; d[o + 1] = BG[1]; d[o + 2] = BG[2]; d[o + 3] = 255; continue; }
      var l = Math.log(1 + v) * inv;
      l = l < 0.14 ? 0 : (l - 0.14) / 0.86 * fade;     // ぽつんと落ちた点は地に沈める
      if (l > 1) l = 1;
      var li = ((l * 255) | 0) * 3, wa = 0;
      if (ls > 0.01) {
        var dx = (k % NX) - lx, dy = ((k / NX) | 0) - ly, r2 = dx * dx + dy * dy;
        if (r2 < lr2) { wa = 1 - r2 / lr2; wa = wa * wa * ls * 0.9; }
      }
      if (wa > 0) {
        d[o] = LUT[li] + (LUTA[li] - LUT[li]) * wa; d[o + 1] = LUT[li + 1] + (LUTA[li + 1] - LUT[li + 1]) * wa;
        d[o + 2] = LUT[li + 2] + (LUTA[li + 2] - LUT[li + 2]) * wa;
      } else { d[o] = LUT[li]; d[o + 1] = LUT[li + 1]; d[o + 2] = LUT[li + 2]; }
      d[o + 3] = 255;
    }
    peak += (Math.max(4, mx * 0.6) - peak) * 0.04;
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

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    lens.on = true; lens.x = e.clientX; lens.y = e.clientY;
    canvas.setPointerCapture(e.pointerId);
    SFX.play('ripple', 250);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!lens.on) return;
    lens.x += (e.clientX - lens.x) * 0.6; lens.y += (e.clientY - lens.y) * 0.6;
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
    lens.s += ((lens.on ? 1 : 0) - lens.s) * Math.min(1, dt * (lens.on ? 4 : 1.5));
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
    scatter(70000);
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
