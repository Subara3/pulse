<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lorenz');

$pageName = 'lorenz';
$pageTitle = 'LORENZ - PULSE';
$pageDesc = '数千の白い点が、左右2つの渦のあいだを行き来しながら回り続ける。ドラッグすると見る向きが変わる。触ったところの点がひとかたまり水色に光り、時間がたつと別々の道に分かれて全体に散らばる。';
$pageCanonical = 'https://pulse.subara3.com/lorenz.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-dust">DUST</label><input type="range" id="k-dust" min="0" max="100" value="55"></div>
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
  var MAXN = 9000;
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
  var img = null, buf = null, bctx = null, px32 = null, BGW = 0;
  function alloc() {
    var w = Math.max(1, Math.round(W)), h = Math.max(1, Math.round(H));
    img = new ImageData(w, h);
    px32 = new Uint32Array(img.data.buffer);
    buf = document.createElement('canvas'); buf.width = w; buf.height = h;
    bctx = buf.getContext('2d');
    BGW = (255 << 24) | (P.bg[2] << 16) | (P.bg[1] << 8) | P.bg[0];
  }
  var DR = P.line[0] - P.bg[0], DG = P.line[1] - P.bg[1], DB = P.line[2] - P.bg[2];
  var AR = P.accent[0] - P.bg[0], AG = P.accent[1] - P.bg[1], AB = P.accent[2] - P.bg[2];
  var LR = 0, LG = 0, LB = 0;
  function lighten(d, o, wt) {
    if (wt < 0.25) return;
    var a = Math.min(1, wt * 1.6);
    var r = d[o] + (LR - d[o]) * a, g = d[o + 1] + (LG - d[o + 1]) * a, b = d[o + 2] + (LB - d[o + 2]) * a;
    if (r > d[o]) d[o] = r; if (g > d[o + 1]) d[o + 1] = g; if (b > d[o + 2]) d[o + 2] = b;
  }
  function drawDust(dtf) {
    if (!img || img.width !== Math.round(W) || img.height !== Math.round(H)) alloc();
    var d = img.data, w = img.width, h = img.height;
    px32.fill(BGW);
    var inv = 1 / Math.max(0.008, dtf);
    for (var i = 0; i < N; i++) {
      var vx = (sx[i] - ox[i]) * inv, vy = (sy[i] - oy[i]) * inv;
      /* 筋の長さは「0.04 秒ぶん」。フレームの速さに左右されない */
      var lx = vx * 0.04, ly = vy * 0.04, len = Math.sqrt(lx * lx + ly * ly);
      if (len > 40) { lx = 0; ly = 0; len = 0; }
      else if (len > 7) { lx *= 7 / len; ly *= 7 / len; len = 7; }
      var t = (sd[i] + 28) / 56; t = t < 0 ? 0 : t > 1 ? 1 : t;
      var hv = heat[i], cr, cg, cb, k, lit = hv > 0.04;
      if (lit) {
        var m = Math.min(1, hv * 1.3);
        k = 0.45 + hv * 0.55;
        LR = P.bg[0] + (DR + (AR - DR) * m) * k; LG = P.bg[1] + (DG + (AG - DG) * m) * k; LB = P.bg[2] + (DB + (AB - DB) * m) * k;
      } else {
        k = 0.2 + t * 0.42;
        cr = DR * k; cg = DG * k; cb = DB * k;
      }
      var n = Math.max(1, Math.round(len)) + 1;
      for (var s = 0; s < n; s++) {
        /* 4 画素に割り振って置く（にじみの少ない滑らかな点になる） */
        var f = s / n, fx = sx[i] - lx * f - 0.5, fy = sy[i] - ly * f - 0.5;
        var x = Math.floor(fx), y = Math.floor(fy);
        if (x < 0 || y < 0 || x >= w - 1 || y >= h - 1) continue;
        var ax = fx - x, ay = fy - y, g = 1 - f * 0.7;
        var o = (y * w + x) * 4, o2 = o + w * 4;
        var w00 = (1 - ax) * (1 - ay) * g, w10 = ax * (1 - ay) * g, w01 = (1 - ax) * ay * g, w11 = ax * ay * g;
        if (lit) {
          /* 灯った粒は重ねても白く飛ばないよう、明るい方を残す */
          lighten(d, o, w00); lighten(d, o + 4, w10); lighten(d, o2, w01); lighten(d, o2 + 4, w11);
          continue;
        }
        d[o] += cr * w00; d[o + 1] += cg * w00; d[o + 2] += cb * w00;
        d[o + 4] += cr * w10; d[o + 5] += cg * w10; d[o + 6] += cb * w10;
        d[o2] += cr * w01; d[o2 + 1] += cg * w01; d[o2 + 2] += cb * w01;
        d[o2 + 4] += cr * w11; d[o2 + 5] += cg * w11; d[o2 + 6] += cb * w11;
      }
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
  var down = false, lx = 0, ly = 0, idle = 10;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; lx = e.clientX; ly = e.clientY; idle = 0;
    var i = nearest(e.clientX, e.clientY);
    if (i >= 0) ignite(i, 1, 260);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - lx, dy = e.clientY - ly;
    lx = e.clientX; ly = e.clientY;
    yaw += dx * 0.005;
    pitchGoal = Math.max(-0.4, Math.min(1.5, pitchGoal + dy * 0.004));
    pitch = pitchGoal;
    if (Math.abs(dx) + Math.abs(dy) > 4) SFX.play('move', 220);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed(v) { speed = 0.06 + v / 100 * 0.5; }
  function readDust(v) { N = Math.round(1800 + v / 100 * (MAXN - 1800)); }
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
  var lastT = performance.now(), autoT = 1.2;
  P.fill(ctx, W, H);
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var h = speed * dt, sub = h > 0.006 ? 2 : 1;
    for (var i = 0; i < N; i++) {
      for (var s = 0; s < sub; s++) advance(i, h / sub);
      if (heat[i] > 0) heat[i] = Math.max(0, heat[i] - dt * 0.035);
    }
    if (!down) {
      yaw += dt * 0.05;
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
