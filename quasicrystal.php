<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('quasicrystal');

$pageName = 'quasicrystal';
$pageTitle = 'QUASICRYSTAL - PULSE';
$pageDesc = '向きの違う7組の縞を重ねると、明るい点が七回対称の形に並ぶ。模様は中心から外へゆっくり動き続けるが、同じ並びはくり返さない。触るとそこから輪が広がり、輪が通ったところの模様がずれる。';
$pageCanonical = 'https://pulse.subara3.com/quasicrystal.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-fold">FOLD</label><input type="range" id="k-fold" min="5" max="11" step="1" value="7"></div>
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="lines">LINES</button><button type="button" data-look="light">LIGHT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 平面波 N 本の和。cos(k·x) と sin(k·x) は格子ごとに先に計算しておき、
     毎フレームは位相を回すだけにする */
  var S = 2.5, NX, NY, N = 7, CSN, VIG, F, img, buf, bctx;
  var look = 'lines', pace = 0.35, phase = 0;
  var ripples = [];

  function alloc() {
    NX = Math.ceil(W / S) + 2; NY = Math.ceil(H / S) + 2;
    F = new Float32Array(NX * NY);
    img = new ImageData(NX, NY);
    buf = document.createElement('canvas');
    buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    waves();
  }
  function waves() {
    /* 画素ごとに [cos, sin] を N 組並べて持つ */
    CSN = new Float32Array(NX * NY * N * 2);
    VIG = new Float32Array(NX * NY);
    var k = 2 * Math.PI / (44 / S);
    var cx = NX / 2, cy = NY / 2, R2 = cx * cx + cy * cy;
    var kx = [], ky = [];
    for (var n = 0; n < N; n++) { var a = Math.PI * n / N; kx.push(Math.cos(a) * k); ky.push(Math.sin(a) * k); }
    for (var j = 0; j < NY; j++) for (var i = 0; i < NX; i++) {
      var p = j * NX + i, o = p * N * 2, dx = i - cx, dy = j - cy;
      for (n = 0; n < N; n++) {
        var th = kx[n] * dx + ky[n] * dy;
        CSN[o + n * 2] = Math.cos(th); CSN[o + n * 2 + 1] = Math.sin(th);
      }
      VIG[p] = 1 - 0.6 * (dx * dx + dy * dy) / R2;
    }
  }
  function sumAt(o, c, s) {
    var v = 0, e = o + N * 2;
    for (var q = o; q < e; q += 2) v += CSN[q] * c - CSN[q + 1] * s;
    return v;
  }

  function field() {
    var len = NX * NY, cp = Math.cos(phase), sp = Math.sin(phase), n2 = N * 2;
    for (var k = 0, o = 0; k < len; k++, o += n2) {
      var v = 0, e = o + n2;
      for (var q = o; q < e; q += 2) v += CSN[q] * cp - CSN[q + 1] * sp;
      F[k] = v;
    }
    if (!ripples.length) return;
    /* 輪の通るところだけ、位相を少しずらして計算し直す */
    for (var r = 0; r < ripples.length; r++) {
      var rp = ripples[r], gx = rp.x / S, gy = rp.y / S, R = rp.r / S, wdt = 7;
      var x0 = Math.max(1, Math.floor(gx - R - wdt * 3)), x1 = Math.min(NX - 1, Math.ceil(gx + R + wdt * 3));
      var y0 = Math.max(1, Math.floor(gy - R - wdt * 3)), y1 = Math.min(NY - 1, Math.ceil(gy + R + wdt * 3));
      for (var j = y0; j < y1; j++) for (var i = x0; i < x1; i++) {
        var d = Math.hypot(i - gx, j - gy) - R;
        if (d < -wdt * 3 || d > wdt * 3) continue;
        var del = rp.a * Math.exp(-d * d / (wdt * wdt));
        if (Math.abs(del) < 0.02) continue;
        var kk = j * NX + i;
        F[kk] = sumAt(kk * n2, Math.cos(phase + del), Math.sin(phase + del));
        TINT[kk] = Math.max(TINT[kk], Math.min(1, Math.abs(del) * 0.6));
      }
    }
  }
  var TINT = null;

  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel, AC = P.accent;
  function shade() {
    var d = img.data;
    var inv = 1 / N;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = j * NX + i, o = k * 4, f = F[k];
        var gx = (i > 0 && i < NX - 1) ? (F[k + 1] - F[k - 1]) * 0.5 : 0;
        var gy = (j > 0 && j < NY - 1) ? (F[k + NX] - F[k - NX]) * 0.5 : 0;
        var g = Math.sqrt(gx * gx + gy * gy) + 0.05;
        var u = f * inv;
        var vig = VIG[k];
        var r, gg, b, t;
        if (look === 'lines') {
          /* 高い山だけが光の点になる。点は七回対称の花の形に並んで、ゆっくり流れる */
          t = (u - 0.24) * 1.5;
          t = t < 0 ? 0 : t > 1 ? 1 : t;
          t = t * t * vig;
          var lo = t < 0.5 ? t * 2 : 1, up = t < 0.5 ? 0 : (t - 0.5) * 2;
          r = BG[0] + (ST[0] - BG[0]) * lo + (LN[0] - ST[0]) * up;
          gg = BG[1] + (ST[1] - BG[1]) * lo + (LN[1] - ST[1]) * up;
          b = BG[2] + (ST[2] - BG[2]) * lo + (LN[2] - ST[2]) * up;
          /* 高さ 0 の細い鋼の線で、花のつながりを下書きのように見せる */
          var a0 = 1 - Math.abs(f) / g / 0.6;
          if (a0 > 0) { a0 *= 0.26 * vig; r += (ST[0] - r) * a0; gg += (ST[1] - gg) * a0; b += (ST[2] - b) * a0; }
          if (u < -0.25) { var dk = Math.min(1, (-u - 0.25) * 1.6) * 0.6; r += (DEEP[0] - r) * dk; gg += (DEEP[1] - gg) * dk; b += (DEEP[2] - b) * dk; }
          var tn = TINT[k];
          if (tn > 0) { var ta = tn * (0.07 + t * 0.7); r += (AC[0] - r) * ta; gg += (AC[1] - gg) * ta; b += (AC[2] - b) * ta; }
        } else {
          t = Math.max(0, (u + 0.15) / 1.15);
          t = t * t * vig;
          r = BG[0] + (ST[0] - BG[0]) * Math.min(1, t * 2) + (LN[0] - ST[0]) * Math.max(0, t * 2 - 1) * 0.8;
          gg = BG[1] + (ST[1] - BG[1]) * Math.min(1, t * 2) + (LN[1] - ST[1]) * Math.max(0, t * 2 - 1) * 0.8;
          b = BG[2] + (ST[2] - BG[2]) * Math.min(1, t * 2) + (LN[2] - ST[2]) * Math.max(0, t * 2 - 1) * 0.8;
          var tn2 = TINT[k];
          if (tn2 > 0) { r += (AC[0] - r) * tn2 * 0.5; gg += (AC[1] - gg) * tn2 * 0.5; b += (AC[2] - b) * tn2 * 0.5; }
        }
        d[o] = r; d[o + 1] = gg; d[o + 2] = b; d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
  }
  function blit() {
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, NX * S, NY * S);
  }

  /* ---------------------------------------------------------- 操作 */
  function ripple(x, y, a) {
    ripples.push({ x: x, y: y, r: 0, a: a, life: 1 });
    if (ripples.length > 6) ripples.shift();
  }
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    ripple(last.x, last.y, 2.2);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < 70) return;
    last = p;
    ripple(p.x, p.y, 1.3);
    SFX.play('move', 220);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kf = document.getElementById('k-fold'), kp = document.getElementById('k-pace');
  kf.addEventListener('input', function () {
    var n = +this.value;
    if (n !== N) { N = n; waves(); SFX.play('tick', 120); }
  });
  kp.addEventListener('input', function () { pace = this.value / 100; });
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  N = +kf.value; pace = kp.value / 100;

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); TINT = new Float32Array(NX * NY); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0, odd = false;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    phase -= dt * (0.12 + pace * 0.9);
    for (var n = ripples.length - 1; n >= 0; n--) {
      var rp = ripples[n];
      rp.r += dt * 120;
      rp.a *= Math.pow(0.55, dt);
      if (rp.a < 0.05) ripples.splice(n, 1);
    }
    /* 色づきは少しずつ引いていく */
    for (var k = 0; k < TINT.length; k++) if (TINT[k] > 0) TINT[k] = Math.max(0, TINT[k] - dt * 0.9);
    /* 模様の動きはゆっくりなので、場の計算は 1 フレームおきで足りる */
    odd = !odd;
    if (odd) { field(); shade(); }
    blit();
    chime += dt;
    if (chime > 7.2) { chime = 0; SFX.play('ripple', 4000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
