<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('clifford');

$pageName = 'clifford';
$pageTitle = 'CLIFFORD - PULSE';
$pageDesc = '1つの式で点を何十万回も打ち続け、点が多く集まるところほど白く描く。形は十数秒ごとに別の形へ入れ替わる。ドラッグすると式の係数がずれて形が曲がり、指のまわりの点が水色に光る。';
$pageCanonical = 'https://pulse.subara3.com/clifford.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-glow">GLOW</label><input type="range" id="k-glow" min="0" max="100" value="50"></div>
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

  /* x' = sin(a y) + c cos(a x),  y' = sin(b x) + d cos(b y)
     点の落ちた回数を粗い格子に数え、濃さとして描く */
  var SHAPES = [
    [-1.4, 1.6, 1.0, 0.7],
    [1.7, 1.7, 0.6, 1.2],
    [-1.7, 1.3, -0.1, -1.2],
    [1.5, -1.8, 1.6, 0.9],
    [-1.8, -2.0, -0.5, -0.9],
    [-1.24, -1.25, -1.81, -1.91],
    [-1.6, 1.6, 0.9, -0.85]
  ];
  /* 式の係数は少し動かすだけで、点が数個に固まる（周期解に落ちる）所がある。
     形を移るときは係数を補間せず、古い煙が薄れるあいだに新しい形を積もらせる */
  var to = 0, hold = 14, dissolve = 0;
  var prm = SHAPES[0].slice();
  var push = [0, 0];
  /* その係数で点が十分に散るか（固まらないか）を軽く調べる */
  var seen = new Uint8Array(96 * 96);
  function spreads(a, b, c, d) {
    seen.fill(0);
    var x = 0.1, y = 0.1, n = 0, ex = 1 + Math.abs(c), ey = 1 + Math.abs(d);
    for (var k = 0; k < 9000; k++) {
      var nx = Math.sin(a * y) + c * Math.cos(a * x), ny = Math.sin(b * x) + d * Math.cos(b * y);
      x = nx; y = ny;
      if (k < 60) continue;
      var o = (((y / ey * 0.5 + 0.5) * 95) | 0) * 96 + (((x / ex * 0.5 + 0.5) * 95) | 0);
      if (!seen[o]) { seen[o] = 1; n++; }
    }
    return n > 900;
  }

  var GW, GH, hist, acc, img, buf, bctx;
  function alloc() {
    var k = W > 900 ? 1.6 : 1.25;
    GW = Math.max(200, Math.round(W / k));
    GH = Math.max(150, Math.round(H / k));
    hist = new Float32Array(GW * GH);
    acc = new Float32Array(GW * GH);
    img = new ImageData(GW, GH);
    buf = document.createElement('canvas');
    buf.width = GW; buf.height = GH;
    bctx = buf.getContext('2d');
  }

  var M = 2000;
  var wx = new Float32Array(M), wy = new Float32Array(M);
  for (var i = 0; i < M; i++) { wx[i] = Math.random() * 2 - 1; wy[i] = Math.random() * 2 - 1; }

  var ext = [2, 2], rot = 0;
  var gx = -1e9, gy = -1e9, touching = false;
  var perFrame = 26;

  function scatter(n) {
    var a = prm[0] + push[0], b = prm[1] + push[1], c = prm[2], d = prm[3];
    var ca = Math.cos(rot), sa = Math.sin(rot);
    var ex0 = 1 + Math.abs(c), ey0 = 1 + Math.abs(d);
    var ex = ex0 * Math.abs(ca) + ey0 * Math.abs(sa), ey = ex0 * Math.abs(sa) + ey0 * Math.abs(ca);
    ext[0] += (ex - ext[0]) * 0.03; ext[1] += (ey - ext[1]) * 0.03;
    var sc = Math.min(GW / (ext[0] * 2), GH / (ext[1] * 2)) * 0.9;
    var cx = GW / 2, cy = GH / 2;
    var gr = 70 / (W / GW), gr2 = gr * gr;
    for (var k = 0; k < n; k++) {
      for (var i = 0; i < M; i++) {
        var x = wx[i], y = wy[i];
        var nx = Math.sin(a * y) + c * Math.cos(a * x);
        var ny = Math.sin(b * x) + d * Math.cos(b * y);
        wx[i] = nx; wy[i] = ny;
        var X = cx + (nx * ca - ny * sa) * sc, Y = cy + (nx * sa + ny * ca) * sc;
        var ix = X | 0, iy = Y | 0;
        if (ix < 0 || iy < 0 || ix >= GW || iy >= GH) continue;
        var o = iy * GW + ix;
        hist[o] += 1;
        if (touching) {
          var ddx = X - gx, ddy = Y - gy, dd = ddx * ddx + ddy * ddy;
          if (dd < gr2) acc[o] += 1 - dd / gr2;
        }
      }
    }
  }

  var maxS = 1, glow = 0.5;
  var BG = P.bg, ST = P.steel, LN = P.line, AC = P.accent, DP = P.deep;
  /* 濃さ→色の表。256 段で引く */
  var LUT = new Float32Array(256 * 3), lutG = -1;
  function buildLut(g) {
    for (var i = 0; i < 256; i++) {
      /* 1〜2 回しか落ちていない所は地に沈め、ざらつきを消す */
      var v = Math.pow(Math.max(0, (i / 255 - 0.17) / 0.83), g), r, gg, b;
      if (v < 0.55) {
        var t = v / 0.55;
        r = BG[0] + (ST[0] - BG[0]) * t; gg = BG[1] + (ST[1] - BG[1]) * t; b = BG[2] + (ST[2] - BG[2]) * t;
      } else {
        var u = (v - 0.55) / 0.45; u = u * u * 0.92;
        r = ST[0] + (LN[0] - ST[0]) * u; gg = ST[1] + (LN[1] - ST[1]) * u; b = ST[2] + (LN[2] - ST[2]) * u;
      }
      LUT[i * 3] = r; LUT[i * 3 + 1] = gg; LUT[i * 3 + 2] = b;
    }
    lutG = g;
  }
  function shade(decay, accDecay) {
    var d = img.data, n = GW * GH, mx = 0;
    var inv = 255 / Math.log(1 + maxS);
    var g = 0.7 + (1 - glow) * 0.6;
    if (g !== lutG) buildLut(g);
    for (var k = 0; k < n; k++) {
      var h = hist[k], o = k * 4, li = 0;
      if (h > 0.03) {
        if (h > mx) mx = h;
        li = Math.log(1 + h) * inv | 0;
        if (li > 255) li = 255;
        hist[k] = h * decay;
      } else if (h !== 0) hist[k] = 0;
      li *= 3;
      var r = LUT[li], gg = LUT[li + 1], b = LUT[li + 2];
      var a = acc[k];
      if (a !== 0) {
        if (a > 0.01) {
          var w = Math.min(0.85, a * 0.02);
          r += (AC[0] - r) * w; gg += (AC[1] - gg) * w; b += (AC[2] - b) * w;
          acc[k] = a * accDecay;
        } else acc[k] = 0;
      }
      d[o] = r; d[o + 1] = gg; d[o + 2] = b; d[o + 3] = 255;
    }
    /* いちばん濃い所を白の手前に合わせる（上限を少し低めに追うと全体が明るく出る） */
    maxS += (Math.max(4, mx * 0.55) - maxS) * 0.08;
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  function next() {
    var n;
    do { n = Math.random() * SHAPES.length | 0; } while (n === to);
    to = n; prm = SHAPES[n].slice(); push[0] = push[1] = 0;
    dissolve = 3.5;
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, lx = 0, ly = 0, lastCheck = 0;
  function toGrid(e) { gx = e.clientX / W * GW; gy = e.clientY / H * GH; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; touching = true; lx = e.clientX; ly = e.clientY; toGrid(e);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - lx, dy = e.clientY - ly;
    lx = e.clientX; ly = e.clientY; toGrid(e);
    /* なぞった向きに係数をずらす。点が固まってしまう値なら、そこへは行かない */
    var nx0 = Math.max(-0.35, Math.min(0.35, push[0] + dx / W * 0.35));
    var ny0 = Math.max(-0.35, Math.min(0.35, push[1] + dy / H * 0.35));
    var now = performance.now();
    if (now - lastCheck > 50) {
      lastCheck = now;
      if (spreads(prm[0] + nx0, prm[1] + ny0, prm[2], prm[3])) { push[0] = nx0; push[1] = ny0; }
    }
    if (Math.abs(dx) + Math.abs(dy) > 3) SFX.play('move', 200);
  });
  function up() { down = false; touching = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var drift = 0.4;
  var kDrift = document.getElementById('k-drift'), kGlow = document.getElementById('k-glow');
  kDrift.addEventListener('input', function () { drift = this.value / 100; });
  kGlow.addEventListener('input', function () { glow = this.value / 100; });
  document.getElementById('k-next').addEventListener('click', function () { next(); hold = 10 + (1 - drift) * 14; SFX.play('click'); });
  drift = kDrift.value / 100; glow = kGlow.value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  scatter(40);
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    hold -= dt;
    if (hold <= 0 && !down) { next(); hold = 10 + (1 - drift) * 14; }
    /* ゆっくり左右に傾く */
    rot = Math.sin(now / 1000 * 0.035) * 0.16;
    dissolve = Math.max(0, dissolve - dt);
    scatter(perFrame);
    shade(Math.exp(-dt / (dissolve > 0 ? 1.1 : 3)), Math.exp(-dt / 0.7));
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
