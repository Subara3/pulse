<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lorenz');

$pageName = 'lorenz';
$pageTitle = 'LORENZ - PULSE';
$pageDesc = '数千の白い点が、左右2つの渦のあいだを行き来しながら回り続ける。触ったところの点が水色に光り、時間がたつと別々の道に分かれて全体に散らばる。なぞると、なぞったところの点が押し出され、光りながら渦に戻っていく。';
$pageCanonical = 'https://pulse.subara3.com/lorenz.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-dust">DUST</label><input type="range" id="k-dust" min="0" max="100" value="75"></div>
  <div class="ctl-btns"><button type="button" class="active" data-view="side">SIDE</button><button type="button" data-view="top">TOP</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; });

  /* 粒はすべて同じ流れ（σ=10, ρ=28, β=8/3）に乗っている。線ではなく点の雲として見せる */
  var MAXN = 12000;
  var px = new Float32Array(MAXN), py = new Float32Array(MAXN), pz = new Float32Array(MAXN);
  var heat = new Float32Array(MAXN);
  var sx = new Float32Array(MAXN), sy = new Float32Array(MAXN), sd = new Float32Array(MAXN);
  var N = 5000;
  var SIG = 10, RHO = 28, BET = 8 / 3;
  var speed = 0.25;

  function deriv(x, y, z, out) {
    out[0] = SIG * (y - x);
    out[1] = x * (RHO - z) - y;
    out[2] = x * y - BET * z;
  }
  var k1 = [0, 0, 0], k2 = [0, 0, 0];
  function advance(i, h) {
    var x = px[i], y = py[i], z = pz[i];
    deriv(x, y, z, k1);
    deriv(x + k1[0] * h * 0.5, y + k1[1] * h * 0.5, z + k1[2] * h * 0.5, k2);
    px[i] = x + k2[0] * h; py[i] = y + k2[1] * h; pz[i] = z + k2[2] * h;
  }

  /* 1 本の長い軌道から間引いて配ると、最初から形がそろって見える */
  function seedAll() {
    var x = 0.1, y = 0, z = 20, h = 0.005;
    var o = [0, 0, 0];
    for (var s = 0; s < 2000; s++) { deriv(x, y, z, o); x += o[0] * h; y += o[1] * h; z += o[2] * h; }
    for (var i = 0; i < MAXN; i++) {
      var skip = 5 + (Math.random() * 6 | 0);
      for (var t = 0; t < skip; t++) { deriv(x, y, z, o); x += o[0] * h; y += o[1] * h; z += o[2] * h; }
      px[i] = x; py[i] = y; pz[i] = z; heat[i] = 0;
    }
    /* 配った順に並ぶと粒が数珠つなぎになるので、並びを混ぜる */
    for (var j = MAXN - 1; j > 0; j--) {
      var r = Math.random() * (j + 1) | 0, a;
      a = px[j]; px[j] = px[r]; px[r] = a;
      a = py[j]; py[j] = py[r]; py[r] = a;
      a = pz[j]; pz[j] = pz[r]; pz[r] = a;
    }
  }
  seedAll();

  /* ---------------------------------------------------------- 見る向き */
  var yaw = -0.25, pitch = 0.0, pitchGoal = 0.0, view = 'side';
  var ox = new Float32Array(MAXN), oy = new Float32Array(MAXN);
  function project() {
    var cy = Math.cos(yaw), syw = Math.sin(yaw), cp = Math.cos(pitch), sp = Math.sin(pitch);
    var sc = Math.min(W, H) / 54, cx = W / 2, cyy = H / 2;
    for (var i = 0; i < N; i++) {
      var x = px[i], y = py[i], z = pz[i] - 25;
      var rx = x * cy - y * syw, ry = x * syw + y * cy;
      /* pitch=0 で横から、π/2 で真上から */
      var vy = z * cp - ry * sp, dz = ry * cp + z * sp;
      ox[i] = sx[i]; oy[i] = sy[i];
      sx[i] = cx + rx * sc;
      sy[i] = cyy - vy * sc;
      sd[i] = dz;
    }
  }

  /* 1 粒ずつ、進む向きに短い筋を引く。画素に直接足し込む（重なった所が明るくなる） */
  var img = null, buf = null, bctx = null, px32 = null, tr = null, dens = null, BGW = 0;
  /* 濃さ→色の表（藍→鋼→白） */
  var DL = new Uint8ClampedArray(256 * 3);
  for (var li0 = 0; li0 < 256; li0++) { var c0 = P.ramp(0.06 + li0 / 255 * 0.72); DL[li0 * 3] = c0[0]; DL[li0 * 3 + 1] = c0[1]; DL[li0 * 3 + 2] = c0[2]; }
  function alloc() {
    var w = Math.max(1, Math.round(W)), h = Math.max(1, Math.round(H));
    img = new ImageData(w, h);
    px32 = new Uint32Array(img.data.buffer);
    tr = new Float32Array(w * h);
    dens = new Float32Array(w * h);
    buf = document.createElement('canvas'); buf.width = w; buf.height = h;
    bctx = buf.getContext('2d');
    BGW = (255 << 24) | (P.bg[2] << 16) | (P.bg[1] << 8) | P.bg[0];
  }
  var DR = P.line[0] - P.bg[0], DG = P.line[1] - P.bg[1], DB = P.line[2] - P.bg[2];
  var AR = P.accent[0] - P.bg[0], AG = P.accent[1] - P.bg[1], AB = P.accent[2] - P.bg[2];
  var LR = 0, LG = 0, LB = 0;
  function lighten(d, o, wt) {
    if (wt < 0.06) return;
    var a = Math.min(1, wt * 2.4);
    var r = d[o] + (LR - d[o]) * a, g = d[o + 1] + (LG - d[o + 1]) * a, b = d[o + 2] + (LB - d[o + 2]) * a;
    if (r > d[o]) d[o] = r; if (g > d[o + 1]) d[o + 1] = g; if (b > d[o + 2]) d[o + 2] = b;
  }
  function drawDust(dtf) {
    if (!img || img.width !== Math.round(W) || img.height !== Math.round(H)) alloc();
    var d = img.data, w = img.width, h = img.height;
    px32.fill(BGW);
    var inv = 1 / Math.max(0.008, dtf), kd = Math.min(3, dtf * 60);
    for (var i = 0; i < N; i++) {
      var vx = (sx[i] - ox[i]) * inv, vy = (sy[i] - oy[i]) * inv;
      /* 前のフレームの位置から今の位置まで、とぎれない線として溜める */
      var lx = sx[i] - ox[i], ly = sy[i] - oy[i], len = Math.sqrt(lx * lx + ly * ly);
      if (len > 40) { lx = 0; ly = 0; len = 0; }
      var t = (sd[i] + 28) / 56; t = t < 0 ? 0 : t > 1 ? 1 : t;
      var hv = heat[i], cr, cg, cb, k, lit = hv > 0.04;
      if (lit) {
        /* 灯った直後は白に近い水色、薄れるにつれて水色から地へ */
        var lc = hv > 0.7 ? P.mix(P.accent, P.line, (hv - 0.7) * 1.4) : P.mix(P.bg, P.accent, 0.35 + hv * 0.93);
        LR = lc[0]; LG = lc[1]; LB = lc[2];
      } else {
        k = 0.24 + t * 0.34;
        cr = DR * k; cg = DG * k; cb = DB * k;
      }
      var n = Math.max(1, Math.round(len)) + 1;
      for (var s = 0; s < n; s++) {
        /* 4 画素に割り振って置く（にじみの少ない滑らかな点になる） */
        var f = s / n, fx = sx[i] - lx * f - 0.5, fy = sy[i] - ly * f - 0.5;
        var x = Math.floor(fx), y = Math.floor(fy);
        if (x < 0 || y < 0 || x >= w - 1 || y >= h - 1) continue;
        var ax = fx - x, ay = fy - y, g = 1 - f * 0.2;
        var o = (y * w + x) * 4, o2 = o + w * 4;
        var w00 = (1 - ax) * (1 - ay) * g, w10 = ax * (1 - ay) * g, w01 = (1 - ax) * ay * g, w11 = ax * ay * g;
        if (lit) {
          /* 灯った粒は 3x3 の少し大きな点にする。重ねても白く飛ばないよう、明るい方を残す */
          if (x < 1 || y < 1 || x >= w - 2 || y >= h - 2) continue;
          /* 灯った粒は光る尾を残す。尾は別の層に溜めて、少しずつ薄れさせる */
          var q = y * w + x, tv = Math.min(1, hv * 1.4) * g;
          for (var yy = -1; yy <= 1; yy++) for (var xx = -1; xx <= 1; xx++) {
            var qq = q + yy * w + xx, vv = (xx === 0 && yy === 0) ? tv : tv * 0.55;
            if (tr[qq] < vv) tr[qq] = vv;
          }
          continue;
        }
        var qd = y * w + x;
        var kk = k * kd / n * 4;
        dens[qd] += kk * w00; dens[qd + 1] += kk * w10; dens[qd + w] += kk * w01; dens[qd + w + 1] += kk * w11;
      }
    }
    /* 点の通った跡を少しのあいだ溜めて、なめらかな濃淡として塗る（長時間露光のように） */
    var dk = Math.exp(-dtf / 2);
    for (var q1 = 0, n1 = w * h; q1 < n1; q1++) {
      var dv = dens[q1];
      if (dv < 0.004) { if (dv !== 0) dens[q1] = 0; continue; }
      var li = (1 - Math.exp(-dv * 0.08)) * 255 | 0, o1 = q1 * 4;
      d[o1] = DL[li * 3]; d[o1 + 1] = DL[li * 3 + 1]; d[o1 + 2] = DL[li * 3 + 2];
      dens[q1] = dv * dk;
    }
    /* 尾の層を重ねる。新しいほど白に近い水色 */
    var TA = P.accent, TL = P.line, fadeK = Math.exp(-dtf / 0.9);
    for (var q2 = 0, nn = w * h; q2 < nn; q2++) {
      var v = tr[q2];
      if (v < 0.02) { if (v !== 0) tr[q2] = 0; continue; }
      var a = v > 1 ? 1 : v, o3 = q2 * 4;
      var cr2 = TA[0] + (TL[0] - TA[0]) * (0.25 + a * 0.4), cg2 = TA[1] + (TL[1] - TA[1]) * (0.25 + a * 0.4), cb2 = TA[2] + (TL[2] - TA[2]) * (0.25 + a * 0.4);
      var r0 = d[o3] + (cr2 - d[o3]) * a, g0 = d[o3 + 1] + (cg2 - d[o3 + 1]) * a, b0 = d[o3 + 2] + (cb2 - d[o3 + 2]) * a;
      if (r0 > d[o3]) d[o3] = r0; if (g0 > d[o3 + 1]) d[o3 + 1] = g0; if (b0 > d[o3 + 2]) d[o3 + 2] = b0;
      tr[q2] = v * fadeK;
    }
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
  }

  /* ---------------------------------------------------------- 灯す */
  var cursor = 0;
  function ignite(i0, amount, size) {
    var x = px[i0], y = py[i0], z = pz[i0];
    for (var n = 0; n < size; n++) {
      var i = (cursor + n) % N;
      if (i === i0) continue;
      px[i] = x + (Math.random() - 0.5) * 0.08;
      py[i] = y + (Math.random() - 0.5) * 0.08;
      pz[i] = z + (Math.random() - 0.5) * 0.08;
      heat[i] = amount;
    }
    heat[i0] = amount;
    cursor = (cursor + size) % N;
  }
  function nearest(x, y) {
    var best = -1, bd = 1e9;
    for (var i = 0; i < N; i++) {
      var d = (sx[i] - x) * (sx[i] - x) + (sy[i] - y) * (sy[i] - y);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, lx = 0, ly = 0, idle = 0;
  /* 指のまわり（画面上で半径 r）にある点を、すべて水色に灯す */
  function lightAround(x, y, r, amount) {
    var r2 = r * r, n = 0;
    for (var i = 0; i < N; i++) {
      var dx = sx[i] - x, dy = sy[i] - y;
      if (dx * dx + dy * dy < r2) { if (heat[i] < amount) heat[i] = amount; n++; }
    }
    return n;
  }
  /* なぞった所の点を、指の動いた向きへ押し出して灯す。押し出された点はやがて渦に戻る */
  function sweep(x, y, r, dx, dy) {
    var r2 = r * r, sc = Math.min(W, H) / 54;
    var cy = Math.cos(yaw), syw = Math.sin(yaw), cp = Math.cos(pitch), sp = Math.sin(pitch);
    for (var i = 0; i < N; i++) {
      var ex = sx[i] - x, ey = sy[i] - y, dd = ex * ex + ey * ey;
      if (dd >= r2) continue;
      var f = (1 - dd / r2) * 1.6 / sc;
      /* 画面の横・縦の動きを、いまの見る向きに合わせて 3 次元の向きへ戻す */
      var mx = dx * f, my = -dy * f;
      px[i] += mx * cy; py[i] += -mx * syw;
      pz[i] += my * cp; var ry = -my * sp; px[i] += ry * syw; py[i] += ry * cy;
      if (heat[i] < 0.7) heat[i] = 0.7;
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; lx = e.clientX; ly = e.clientY; idle = 0;
    /* 雲の外を押したときは、いちばん近い点にかたまりを作って灯す */
    if (lightAround(e.clientX, e.clientY, 80, 1) < 60) {
      var i = nearest(e.clientX, e.clientY);
      if (i >= 0) ignite(i, 1, 400);
    }
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - lx, dy = e.clientY - ly, d = Math.hypot(dx, dy);
    if (d < 6) return;
    /* なぞった線に沿って点を灯す */
    var n = Math.ceil(d / 20);
    for (var k = 1; k <= n; k++) sweep(lx + dx * k / n, ly + dy * k / n, 55, dx / n, dy / n);
    lx = e.clientX; ly = e.clientY;
    SFX.play('move', 200);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed(v) { speed = 0.05 + v / 100 * 0.32; }
  function readDust(v) { N = Math.round(3000 + v / 100 * (MAXN - 3000)); }
  var kSpeed = document.getElementById('k-speed'), kDust = document.getElementById('k-dust');
  kSpeed.addEventListener('input', function () { readSpeed(+this.value); });
  kDust.addEventListener('input', function () { readDust(+this.value); });
  readSpeed(+kSpeed.value); readDust(+kDust.value);
  var viewBtns = document.querySelectorAll('[data-view]');
  function setView(v) {
    view = v;
    pitchGoal = v === 'top' ? 1.35 : 0.0;
    viewBtns.forEach(function (o) { o.classList.toggle('active', o.dataset.view === v); });
  }
  viewBtns.forEach(function (b) {
    b.addEventListener('click', function () { setView(b.dataset.view); SFX.play('toggle'); });
  });
  /* 共有リンクで .active が復元されていたら拾う */
  setTimeout(function () {
    var a = document.querySelector('[data-view].active');
    if (a && a.dataset.view !== view) setView(a.dataset.view);
  }, 400);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), autoT = 12;
  P.fill(ctx, W, H);
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var h = speed * dt, sub = h > 0.006 ? 2 : 1;
    for (var i = 0; i < N; i++) {
      for (var s = 0; s < sub; s++) advance(i, h / sub);
      if (heat[i] > 0) heat[i] = Math.max(0, heat[i] - dt * 0.33);
    }
    if (!down) {
      yaw += dt * 0.012;
      pitch += (pitchGoal - pitch) * Math.min(1, dt * 1.5);
    }
    /* 放っておいても、ときどき 1 つだけ灯る */
    idle += dt; autoT -= dt;
    if (autoT <= 0 && idle > 8) {
      ignite(Math.random() * N | 0, 0.75, 200);
      autoT = 14 + Math.random() * 6;
    }
    project();
    drawDust(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
