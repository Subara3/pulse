<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('halo');

$pageName = 'halo';
$pageTitle = 'HALO - PULSE';
$pageDesc = '凍った空気に細かな氷の粒が漂ってきらめき、太陽のまわりに大きな光の輪と、左右に明るい点が浮かぶ。押すとそこから水色の氷の粒が舞い上がって輪の上できらめき、なぞると粒が指についてきて、太陽を押さえて動かすと輪もついてくる。';
$pageCanonical = 'https://pulse.subara3.com/halo.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-dust">DUST</label><input type="range" id="k-dust" min="0" max="100" value="65"></div>
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="45"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 空の明るさは粗い格子で計算して拡大する */
  var GX = 0, GY = 0, img = null, buf = null, bctx = null;
  var LUT = new Uint8Array(256 * 3);
  (function () {
    for (var i = 0; i < 256; i++) {
      var c = P.ramp(i / 255);
      LUT[i * 3] = c[0]; LUT[i * 3 + 1] = c[1]; LUT[i * 3 + 2] = c[2];
    }
  })();

  /* 氷の粒 */
  var MAXN = 6000, N = 0, nAct = 0, cursor = 0;
  var px = new Float32Array(MAXN), py = new Float32Array(MAXN);
  var vx = new Float32Array(MAXN), vy = new Float32Array(MAXN);
  var ph = new Float32Array(MAXN), tw = new Float32Array(MAXN);
  var fall = new Float32Array(MAXN), hot = new Float32Array(MAXN);
  var bright = new Float32Array(MAXN);

  var R = 100, Rd = 100, hy = 0;          /* 輪の半径・幻日までの距離・地平線 */
  var sunX = 0, sunY = 0, offX = 0, offY = 0;
  var dust = 0.65, drift = 0.45, T = 0, clock = 0;
  var trees = [];

  function sq(v) { return v * v; }

  /* 太陽から (dx, dy) の位置で、氷の粒が光を曲げて届ける明るさ */
  function field(dx, dy) {
    var d = Math.sqrt(dx * dx + dy * dy), v = 0;
    /* 22 度の輪: 内側の縁はくっきり、外へはなだらかに薄れる */
    if (d < R) v += 0.7 * Math.exp(-sq((R - d) / (0.045 * R)));
    else v += 0.7 * Math.exp(-(d - R) / (0.2 * R));
    /* 幻日: 太陽と同じ高さの左右。外へ尾を引く */
    var ay = Math.exp(-sq(dy / (0.05 * R)));
    if (ay > 0.01) {
      for (var s = -1; s <= 1; s += 2) {
        var ex = (dx - s * Rd) * s;
        v += 1.25 * ay * (ex >= 0 ? Math.exp(-ex / (0.16 * R)) : Math.exp(-sq(ex / (0.04 * R))));
      }
    }
    /* 幻日環: 太陽を通る水平の細い線 */
    v += 0.3 * Math.exp(-sq(dy / (0.012 * R + 1.2))) * (1 - Math.exp(-sq(dx / (0.35 * R))));
    /* 上端接弧: 輪の真上に接して、両翼が持ち上がる */
    var yy = -R - 0.42 * dx * dx / R;
    v += 0.55 * Math.exp(-sq((dy - yy) / (0.035 * R))) * Math.exp(-sq(dx / (0.75 * R)));
    /* 46 度の輪: 外側にかすかに */
    v += 0.16 * Math.exp(-sq((d - 2.09 * R) / (0.06 * R)));
    return v;
  }

  function alloc() {
    GX = Math.max(96, Math.min(260, Math.round(W / 5)));
    GY = Math.max(60, Math.round(GX * H / W));
    img = new ImageData(GX, GY);
    buf = document.createElement('canvas');
    buf.width = GX; buf.height = GY;
    bctx = buf.getContext('2d');
    R = Math.min(W, H) * 0.37;
    if (H > W) R = Math.min(W * 0.36, H * 0.25);
    hy = H * 0.9;
    N = Math.max(1600, Math.min(MAXN, Math.round(W * H / 220)));
    for (var i = 0; i < N; i++) seed(i, Math.random() * W, Math.random() * H);
    trees.length = 0;
    var x = -10;
    while (x < W + 20) {
      var h = H * (0.018 + Math.random() * 0.04) * (Math.random() < 0.3 ? 0.4 : 1);
      trees.push({ x: x, h: h });
      x += 6 + Math.random() * (Math.random() < 0.25 ? 90 : 22);
    }
    setDust();
  }

  function seed(i, x, y) {
    px[i] = x; py[i] = y; vx[i] = 0; vy[i] = 0;
    ph[i] = Math.random() * 6.2832;
    tw[i] = 0.7 + Math.random() * 1.8;
    fall[i] = 5 + Math.random() * 12;
    hot[i] = 0;
  }

  function setDust() { nAct = Math.round(N * (0.3 + 0.7 * dust)); if (cursor >= nAct) cursor = 0; }

  function place(dt) {
    T += dt * (0.25 + drift * 1.5);
    var bx = W * (0.5 + (H > W ? 0.06 : 0.17) * Math.sin(T * 0.045)), by = H * (0.55 + 0.07 * Math.sin(T * 0.031 + 1));
    if (H > W) by = H * (0.5 + 0.08 * Math.sin(T * 0.031 + 1));
    if (!grab) { var k = Math.exp(-dt * 0.04); offX *= k; offY *= k; }
    sunX = bx + offX; sunY = by + offY;
    /* 太陽が高いほど幻日は輪の外へ離れる */
    var e = Math.max(0, Math.min(1.0, (hy - sunY) / R * 0.38));
    Rd = R / Math.cos(e);
  }

  function sky() {
    var d = img.data, sx = W / GX, sy = H / GY, o = 0;
    for (var j = 0; j < GY; j++) {
      var y = (j + 0.5) * sy, dy = y - sunY;
      var top = y < hy ? sq(1 - y / hy) * 0.42 : 0.35;
      var base = 0.05 + (y < hy ? 0.05 * y / hy : 0);
      for (var i = 0; i < GX; i++) {
        var x = (i + 0.5) * sx, dx = x - sunX;
        var dd = Math.sqrt(dx * dx + dy * dy);
        var v = base + field(dx, dy) * 0.42 + Math.exp(-dd / (0.09 * R)) * 1.1 + Math.exp(-dd / (0.55 * R)) * 0.16;
        if (dd < R) v -= 0.035 * (1 - dd / R);
        var t = v * 0.62; if (t < 0) t = 0; if (t > 1) t = 1;
        var q = (t * 255 | 0) * 3;
        d[o] = LUT[q] + (P.deep[0] - LUT[q]) * top;
        d[o + 1] = LUT[q + 1] + (P.deep[1] - LUT[q + 1]) * top;
        d[o + 2] = LUT[q + 2] + (P.deep[2] - LUT[q + 2]) * top;
        d[o + 3] = 255;
        o += 4;
      }
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  function ground() {
    ctx.fillStyle = P.rgba(P.deep, 0.55);
    ctx.fillRect(0, hy, W, H - hy);
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.line, 0.07);
    ctx.beginPath();
    for (var n = 1; n < 6; n++) {
      var y = hy + (H - hy) * sq(n / 6) + 0.5;
      ctx.moveTo(0, y); ctx.lineTo(W, y);
    }
    ctx.stroke();
    /* 遠くの針葉樹 */
    ctx.beginPath();
    for (var i = 0; i < trees.length; i++) {
      var tr = trees[i], w = tr.h * 0.34;
      ctx.moveTo(tr.x - w, hy); ctx.lineTo(tr.x, hy - tr.h); ctx.lineTo(tr.x + w, hy);
      ctx.moveTo(tr.x - w * 0.75, hy - tr.h * 0.35); ctx.lineTo(tr.x, hy - tr.h * 1.0);
      ctx.lineTo(tr.x + w * 0.75, hy - tr.h * 0.35);
    }
    ctx.fillStyle = P.rgba(P.deep, 0.9);
    ctx.fill();
    ctx.strokeStyle = P.rgba(P.steel, 0.45);
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.beginPath(); ctx.moveTo(0, hy + 0.5); ctx.lineTo(W, hy + 0.5); ctx.stroke();
  }

  function sun() {
    var g = ctx.createRadialGradient(sunX, sunY, 0, sunX, sunY, R * 0.22);
    g.addColorStop(0, P.rgba(P.line, 0.95));
    g.addColorStop(0.12, P.rgba(P.line, 0.6));
    g.addColorStop(1, P.rgba(P.line, 0));
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(sunX, sunY, R * 0.22, 0, 6.2832); ctx.fill();
    ctx.fillStyle = P.rgba(P.line, 1);
    ctx.beginPath(); ctx.arc(sunX, sunY, Math.max(3, R * 0.022), 0, 6.2832); ctx.fill();
  }

  function moveDust(dt) {
    var wind = 9 * Math.sin(clock * 0.07) + 4 + drift * 8, dk = Math.exp(-dt * 1.4), hk = dt * 0.22;
    for (var i = 0; i < nAct; i++) {
      var y = py[i];
      px[i] += (wind + 5 * Math.sin(y * 0.012 + clock * 0.4 + ph[i]) + vx[i]) * dt;
      py[i] += (fall[i] + 3 * Math.cos(px[i] * 0.01 + clock * 0.3) + vy[i]) * dt;
      vx[i] *= dk; vy[i] *= dk;
      if (hot[i] > 0) hot[i] = Math.max(0, hot[i] - hk);
      if (py[i] > H + 4) { seed(i, Math.random() * W, -4); }
      else if (py[i] < -30) py[i] = H + 2;
      if (px[i] > W + 4) px[i] -= W + 8; else if (px[i] < -4) px[i] += W + 8;
      /* きらめき: 粒が回るたびに、光を曲げる向きになった一瞬だけ明るい */
      var s = 0.5 + 0.5 * Math.sin(ph[i] + clock * tw[i]);
      var g = 0.3 + 0.7 * s * s * s;
      var b = 0.06 + field(px[i] - sunX, py[i] - sunY) * g * 1.3;
      bright[i] = Math.max(b, hot[i] * (0.45 + 0.55 * g));
    }
  }

  /* 明るさごとにまとめて描く */
  var BINS = 7, binList = [], hotList = [];
  for (var bb = 0; bb < BINS; bb++) { binList.push([]); hotList.push([]); }
  function drawDust() {
    var bi, i;
    for (bi = 0; bi < BINS; bi++) { binList[bi].length = 0; hotList[bi].length = 0; }
    for (i = 0; i < nAct; i++) {
      var b = bright[i]; if (b > 1) b = 1;
      bi = Math.min(BINS - 1, (b * BINS) | 0);
      (hot[i] > 0.12 ? hotList : binList)[bi].push(i);
    }
    for (var pass = 0; pass < 2; pass++) {
      var lists = pass ? hotList : binList, col = pass ? P.accent : P.line;
      for (bi = 0; bi < BINS; bi++) {
        var L = lists[bi]; if (!L.length) continue;
        var a = 0.18 + 0.82 * (bi + 0.5) / BINS, sz = bi < 3 ? 1.1 : 1.5;
        ctx.fillStyle = P.rgba(col, a);
        ctx.beginPath();
        for (var n = 0; n < L.length; n++) { i = L[n]; ctx.rect(px[i] - sz * 0.5, py[i] - sz * 0.5, sz, sz); }
        ctx.fill();
        if (bi >= 4) {
          /* 明るい粒は小さな十字に光る */
          ctx.strokeStyle = P.rgba(col, a * 0.7);
          ctx.lineWidth = 0.8;
          ctx.beginPath();
          var arm = 1.5 + (bi - 3) * 1.1;
          for (n = 0; n < L.length; n++) {
            i = L[n];
            ctx.moveTo(px[i] - arm, py[i]); ctx.lineTo(px[i] + arm, py[i]);
            ctx.moveTo(px[i], py[i] - arm); ctx.lineTo(px[i], py[i] + arm);
          }
          ctx.stroke();
        }
      }
    }
  }

  /* 状態をすべて宣言してから呼ぶ */
  var grab = false;
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); place(0); });

  /* ---------------------------------------------------------- 操作 */
  function puff(x, y, n, sp, ux, uy) {
    for (var k = 0; k < n; k++) {
      var i = cursor; cursor = (cursor + 1) % nAct;
      var a = Math.random() * 6.2832, r = Math.random() * 8, v = sp * (0.3 + Math.random());
      seed(i, x + Math.cos(a) * r, y + Math.sin(a) * r);
      vx[i] = Math.cos(a) * v + ux; vy[i] = Math.sin(a) * v + uy - sp * 0.25;
      hot[i] = 0.85 + Math.random() * 0.15;
    }
  }
  function push(x, y, rad, ux, uy, kick) {
    for (var i = 0; i < nAct; i++) {
      var dx = px[i] - x, dy = py[i] - y, d2 = dx * dx + dy * dy;
      if (d2 > rad * rad) continue;
      var d = Math.sqrt(d2) + 0.01, f = 1 - d / rad;
      vx[i] += (dx / d * kick + ux) * f; vy[i] += (dy / d * kick + uy) * f;
      if (hot[i] < f * 0.7) hot[i] = f * 0.7;
    }
  }

  var down = false, last = null, lastTime = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; lastTime = performance.now();
    canvas.setPointerCapture(e.pointerId);
    if (Math.hypot(last.x - sunX, last.y - sunY) < Math.max(28, R * 0.14)) {
      grab = true;
      SFX.play('tick');
      return;
    }
    puff(last.x, last.y, 170, 120, 0, 0);
    push(last.x, last.y, R * 0.3, 0, 0, 90);
    SFX.play('spawn');
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, now = performance.now();
    var dx = p.x - last.x, dy = p.y - last.y, dist = Math.hypot(dx, dy);
    if (grab) {
      offX += dx; offY += dy; last = p;
      SFX.play('move', 160);
      return;
    }
    if (dist < 6) return;
    var dtp = Math.max(0.008, (now - lastTime) / 1000), ux = dx / dtp * 0.5, uy = dy / dtp * 0.5;
    var sp = Math.hypot(ux, uy), lim = 400;
    if (sp > lim) { ux *= lim / sp; uy *= lim / sp; }
    push(p.x, p.y, R * 0.22, ux, uy, 10);
    puff(p.x, p.y, Math.min(40, 6 + (dist / 3) | 0), 35, ux * 0.6, uy * 0.6);
    last = p; lastTime = now;
    SFX.play('move', 140);
  });
  function up() { down = false; grab = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-dust').addEventListener('input', function () { dust = this.value / 100; setDust(); });
  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  dust = document.getElementById('k-dust').value / 100; setDust();
  drift = document.getElementById('k-drift').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 3;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    clock += dt;
    place(dt);
    moveDust(dt);
    sky();
    ground();
    sun();
    drawDust();
    chime -= dt;
    if (chime < 0) { chime = 5 + Math.random() * 4; SFX.play('tick'); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
