<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('slit');

$pageName = 'slit';
$pageTitle = 'SLIT - PULSE';
$pageDesc = '左から来た波が壁のすき間を抜けて広がり、右の板に粒が1つずつ当たる。当たった跡はしだいに縞模様になる。上下にドラッグするとすき間の間隔が変わり、縞の間隔も変わる。';
$pageCanonical = 'https://pulse.subara3.com/slit.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-gap">GAP</label><input type="range" id="k-gap" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-rate">RATE</label><input type="range" id="k-rate" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" data-slits="1">ONE</button><button type="button" class="active" data-slits="2">TWO</button><button type="button" data-slits="4">FOUR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W = 0, H = 0;

  /* 配置: 左に波の出どころ、中ほどにすき間のある壁、右に粒が当たる板 */
  var SX = 0, BX = 0, PX = 0, PW = 0, CY = 0;
  var LAM = 22, K = 2 * Math.PI / 22, OMEGA = 2.2;
  var slits = 2, gap = 70, SLW = 10;

  /* 波は粗い格子で、各マスの振れ幅と位相を先に求めておき、毎フレームは cos だけ取る */
  var C = 4, GX = 1, GY = 1, amp, pha, acs, asn, img, buf, bctx;
  var cdf = null, cdfN = 0;
  function layout() {
    SX = W * 0.06; BX = W * 0.34; PX = W * 0.8; PW = Math.min(W * 0.13, 150); CY = H / 2;
    if (W < 700) { BX = W * 0.3; PX = W * 0.76; PW = W * 0.17; }
    LAM = Math.max(14, Math.min(24, W / 55)); K = 2 * Math.PI / LAM;
    GX = Math.ceil(W / C); GY = Math.ceil(H / C);
    amp = new Float32Array(GX * GY); pha = new Float32Array(GX * GY);
    acs = new Float32Array(GX * GY); asn = new Float32Array(GX * GY);
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas'); buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
    solve();
  }
  function slitYs() {
    var ys = [];
    for (var s = 0; s < slits; s++) ys.push(CY + (s - (slits - 1) / 2) * gap);
    return ys;
  }
  function solve() {
    var ys = slitYs(), pts = [];
    /* すき間 1 本を 5 点で近似する */
    for (var s = 0; s < ys.length; s++) for (var q = -2; q <= 2; q++) pts.push(ys[s] + q * SLW / 5);
    var norm = 1 / Math.sqrt(pts.length);
    for (var j = 0; j < GY; j++) {
      var y = (j + 0.5) * C;
      for (var i = 0; i < GX; i++) {
        var x = (i + 0.5) * C, k = j * GX + i;
        if (x < BX) { amp[k] = 0.34 * Math.min(1, (x - SX) / 60 + 1); pha[k] = K * x; continue; }
        if (x > PX - 6) { amp[k] = 0; pha[k] = 0; continue; }
        var re = 0, im = 0;
        for (var p = 0; p < pts.length; p++) {
          var dx = x - BX, dy = y - pts[p], r = Math.sqrt(dx * dx + dy * dy) + 1;
          var a = 1 / Math.sqrt(r / 40 + 1), ph = K * (BX + r);
          re += Math.cos(ph) * a; im += Math.sin(ph) * a;
        }
        re *= norm; im *= norm;
        amp[k] = Math.min(1, Math.sqrt(re * re + im * im) * 1.25);
        pha[k] = Math.atan2(im, re);
      }
    }
    /* 板の上の明るさの分布（当たりやすさ）を作る */
    cdfN = Math.ceil(H / 2);
    cdf = new Float32Array(cdfN);
    var acc = 0, L = PX - BX;
    for (var n = 0; n < cdfN; n++) {
      var yy = (n + 0.5) * H / cdfN, rr = 0, ii = 0;
      for (var p2 = 0; p2 < pts.length; p2++) {
        var rdy = yy - pts[p2], rlen = Math.sqrt(L * L + rdy * rdy);
        rr += Math.cos(K * rlen); ii += Math.sin(K * rlen);
      }
      acc += rr * rr + ii * ii;
      cdf[n] = acc;
    }
    for (n = 0; n < cdfN; n++) cdf[n] /= acc;
    /* 毎フレームの cos を省くため、振れ幅×cos(位相)・振れ幅×sin(位相) にしておく */
    for (var q2 = 0; q2 < GX * GY; q2++) { acs[q2] = amp[q2] * Math.cos(pha[q2]); asn[q2] = amp[q2] * Math.sin(pha[q2]); }
  }
  function sampleY() {
    var u = Math.random(), lo = 0, hi = cdfN - 1;
    while (lo < hi) { var mid = (lo + hi) >> 1; if (cdf[mid] < u) lo = mid + 1; else hi = mid; }
    return (lo + Math.random()) * H / cdfN;
  }

  var BG = P.bg, ST = P.steel;
  function shade(t) {
    var d = img.data, cw = Math.cos(OMEGA * t), sw = Math.sin(OMEGA * t);
    for (var k = 0, n = GX * GY; k < n; k++) {
      var a = amp[k], o = k * 4;
      var b = a > 0 ? 0.5 * a + 0.5 * (acs[k] * cw + asn[k] * sw) : 0;
      b = b * b * 0.5;
      d[o] = BG[0] + (ST[0] - BG[0]) * b; d[o + 1] = BG[1] + (ST[1] - BG[1]) * b; d[o + 2] = BG[2] + (ST[2] - BG[2]) * b; d[o + 3] = 255;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  /* ---------------------------------------------------------- 粒 */
  var flying = [];
  var HMAX = 6000, hx = new Float32Array(HMAX), hy = new Float32Array(HMAX), hb = new Float32Array(HMAX), hN = 0, hHead = 0;
  var LIFE = 70;
  function emit() {
    var ys = slitYs(), sy = ys[(Math.random() * ys.length) | 0] + (Math.random() - 0.5) * SLW;
    flying.push({ x: SX, y: CY + (Math.random() - 0.5) * 20, sy: sy, ty: sampleY(), t: 0, stage: 0 });
  }
  function land(y) {
    hx[hHead] = PX + 4 + Math.random() * PW; hy[hHead] = y; hb[hHead] = T;
    hHead = (hHead + 1) % HMAX; if (hN < HMAX) hN++;
  }
  function moveFlying(dt) {
    var SPEED = Math.max(220, W * 0.28);
    for (var i = flying.length - 1; i >= 0; i--) {
      var f = flying[i];
      var tx = f.stage ? PX + 2 : BX, ty = f.stage ? f.ty : f.sy;
      var dx = tx - f.x, dy = ty - f.y, d = Math.sqrt(dx * dx + dy * dy);
      var stepLen = SPEED * dt;
      if (d <= stepLen) {
        f.x = tx; f.y = ty;
        if (f.stage) { land(f.ty); flying.splice(i, 1); SFX.play('tick', 2600); continue; }
        f.stage = 1;
      } else { f.x += dx / d * stepLen; f.y += dy / d * stepLen; }
    }
  }

  var T = 0, LN = P.line;
  function draw() {
    shade(T);
    /* 壁: すき間を残して白い線 */
    var ys = slitYs(), i;
    ctx.strokeStyle = P.rgba(LN, 0.8);
    ctx.lineWidth = 2;
    ctx.beginPath();
    var yPrev = 0;
    for (i = 0; i < ys.length; i++) { ctx.moveTo(BX, yPrev); ctx.lineTo(BX, ys[i] - SLW / 2); yPrev = ys[i] + SLW / 2; }
    ctx.moveTo(BX, yPrev); ctx.lineTo(BX, H);
    ctx.stroke();
    /* 出どころ */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(LN, 0.5);
    ctx.beginPath(); ctx.arc(SX, CY, 5, 0, Math.PI * 2); ctx.stroke();
    /* 板 */
    ctx.strokeStyle = P.rgba(LN, 0.35);
    ctx.beginPath(); ctx.moveTo(PX, 0); ctx.lineTo(PX, H); ctx.stroke();
    ctx.strokeStyle = P.rgba(ST, 0.25);
    ctx.beginPath(); ctx.moveTo(PX + PW + 8, 0); ctx.lineTo(PX + PW + 8, H); ctx.stroke();

    /* 当たった跡: 古いものほど淡い。新しい 1 つだけシアン */
    var bands = 5;
    for (var bnd = 0; bnd < bands; bnd++) {
      var lo = bnd / bands, hi = (bnd + 1) / bands;
      ctx.fillStyle = P.rgba(LN, 0.12 + (1 - lo) * 0.6);
      ctx.beginPath();
      for (i = 0; i < hN; i++) {
        var age = (T - hb[i]) / LIFE;
        if (age < lo || age >= hi || age < 0) continue;
        ctx.rect(hx[i] - 0.8, hy[i] - 0.8, 1.6, 1.6);
      }
      ctx.fill();
    }
    if (hN) {
      var last = (hHead - 1 + HMAX) % HMAX, la = 1 - (T - hb[last]) / 0.8;
      if (la > 0) { ctx.fillStyle = P.rgba(P.accent, la); ctx.beginPath(); ctx.arc(hx[last], hy[last], 2.2, 0, Math.PI * 2); ctx.fill(); }
    }
    /* 飛んでいる粒 */
    ctx.fillStyle = P.rgba(LN, 0.9);
    ctx.beginPath();
    for (i = 0; i < flying.length; i++) { var f = flying[i]; ctx.moveTo(f.x + 1.6, f.y); ctx.arc(f.x, f.y, 1.6, 0, Math.PI * 2); }
    ctx.fill();
    /* 触ったところ */
    for (i = rings.length - 1; i >= 0; i--) {
      var rg = rings[i], a = 1 - rg.t / 1.4;
      if (a <= 0) { rings.splice(i, 1); continue; }
      ctx.strokeStyle = P.rgba(P.accent, a * 0.5);
      ctx.beginPath(); ctx.arc(rg.x, rg.y, 6 + rg.t * 50, 0, Math.PI * 2); ctx.stroke();
    }
    if (dragging) {
      ctx.fillStyle = P.rgba(P.accent, 0.85);
      for (i = 0; i < ys.length; i++) { ctx.beginPath(); ctx.arc(BX, ys[i], 3, 0, Math.PI * 2); ctx.fill(); }
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var rings = [], down = false, dragging = false, startY = 0, startGap = 0;
  var gapEl = document.getElementById('k-gap');
  function gapFrom(v) { return 24 + v / 100 * 150; }
  function setGap(g) {
    g = Math.max(24, Math.min(174, g));
    if (Math.abs(g - gap) < 0.5) return;
    gap = g; solve();
    /* 古い跡は早めに薄れさせ、新しい縞に入れ替わりやすくする */
    for (var i = 0; i < hN; i++) hb[i] -= 0.4;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; dragging = false; startY = e.clientY; startGap = gap;
    rings.push({ x: e.clientX, y: e.clientY, t: 0 });
    for (var i = 0; i < 24; i++) emit();
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dy = startY - e.clientY;
    if (!dragging && Math.abs(dy) > 8) dragging = true;
    if (dragging) {
      setGap(startGap + dy * 0.6);
      gapEl.value = Math.round((gap - 24) / 150 * 100);
      SFX.play('move', 160);
    }
  });
  function up() { down = false; dragging = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var rate = 20;
  function rateFrom(v) { return 4 + v / 100 * 60; }
  gapEl.addEventListener('input', function () { setGap(gapFrom(+this.value)); });
  document.getElementById('k-rate').addEventListener('input', function () { rate = rateFrom(+this.value); });
  document.querySelectorAll('[data-slits]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-slits]').forEach(function (o) { o.classList.toggle('active', o === b); });
      slits = +b.dataset.slits; solve();
      for (var i = 0; i < hN; i++) hb[i] -= LIFE * 0.5;
      SFX.play('toggle');
    });
  });
  gap = gapFrom(+gapEl.value);
  rate = rateFrom(+document.getElementById('k-rate').value);

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; layout(); hN = 0; hHead = 0; flying.length = 0; });
  /* 開いたときから縞がうっすら見えているよう、跡を少し置いておく */
  for (var w0 = 0; w0 < 1400; w0++) { land(sampleY()); hb[(hHead - 1 + HMAX) % HMAX] = -Math.random() * LIFE * 0.6; }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now; T += dt;
    acc += dt * rate;
    while (acc > 1) { acc -= 1; emit(); }
    moveFlying(dt);
    for (var i = 0; i < rings.length; i++) rings[i].t += dt;
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
