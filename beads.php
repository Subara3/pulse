<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('beads');

$pageName = 'beads';
$pageTitle = 'BEADS - PULSE';
$pageDesc = '台の上の小さな磁石の玉が引き合って鎖や輪につながり、ときどき台のどこかが揺れると、そこの鎖がほどけて別の形につながり直す。押すとまわりの玉が指に吸い寄せられて水色に光り、指から放射状に鎖が伸びて、なぞると玉の束が指についてくる。';
$pageCanonical = 'https://pulse.subara3.com/beads.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-density">DENSITY</label><input type="range" id="k-density" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-shake">SHAKE</label><input type="range" id="k-shake" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" id="k-scatter">SCATTER</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 玉: 位置・速さ・磁石の向き・回る速さ・光り具合 */
  var N = 0, R = 6, DC = 36;
  var x, y, vx, vy, th, om, hl, ax, ay, tq, cs, sn;
  /* 近所探しの格子 */
  var GX = 1, GY = 1, head, next;
  /* 鎖として描く組 */
  var bondI, bondJ, nb = 0;
  var phi = 0.16, shake = 0.4;
  var K = 240, KT = 12, DAMP = 3.2, DAMPR = 5;
  /* 指 */
  var down = false, px = 0, py = 0, pvx = 0, pvy = 0, lastMove = 0, RW = 120;
  /* ときどき台のどこかを揺らす */
  var nextQuake = 3, snaps = 0;

  function gauss() {
    var u = 1 - Math.random(), v = Math.random();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(6.2831853 * v);
  }

  function alloc() {
    var m = Math.min(W, H);
    R = Math.max(4.5, Math.min(9, m / 90));
    N = Math.min(900, Math.max(120, Math.round(phi * W * H / (Math.PI * R * R))));
    DC = R * 6.5;
    K = 40 * R;
    RW = Math.max(80, m * 0.16);
    x = new Float32Array(N); y = new Float32Array(N);
    vx = new Float32Array(N); vy = new Float32Array(N);
    th = new Float32Array(N); om = new Float32Array(N);
    hl = new Float32Array(N); ax = new Float32Array(N); ay = new Float32Array(N);
    tq = new Float32Array(N); cs = new Float32Array(N); sn = new Float32Array(N);
    next = new Int32Array(N);
    GX = Math.max(1, Math.ceil(W / DC)); GY = Math.max(1, Math.ceil(H / DC));
    head = new Int32Array(GX * GY);
    bondI = new Int32Array(N * 3); bondJ = new Int32Array(N * 3); nb = 0;
    for (var i = 0; i < N; i++) {
      x[i] = R + Math.random() * (W - 2 * R);
      y[i] = R + Math.random() * (H - 2 * R);
      th[i] = Math.random() * 6.2831853;
    }
    /* 開いた瞬間から短い鎖が見えるよう、少しだけ先に進めておく */
    for (var s = 0; s < 45; s++) step(1 / 60);
  }

  function step(h) {
    var i, j, c, n, R2 = 2 * R, DC2 = DC * DC;
    head.fill(-1);
    for (i = 0; i < N; i++) {
      var gx = Math.min(GX - 1, Math.max(0, (x[i] / DC) | 0));
      var gy = Math.min(GY - 1, Math.max(0, (y[i] / DC) | 0));
      c = gy * GX + gx;
      next[i] = head[c]; head[c] = i;
      cs[i] = Math.cos(th[i]); sn[i] = Math.sin(th[i]);
      ax[i] = 0; ay[i] = 0; tq[i] = 0;
    }
    nb = 0;
    var cap = bondI.length;
    for (i = 0; i < N; i++) {
      var cx = Math.min(GX - 1, Math.max(0, (x[i] / DC) | 0));
      var cy = Math.min(GY - 1, Math.max(0, (y[i] / DC) | 0));
      var mix = cs[i], miy = sn[i];
      for (var oy = -1; oy <= 1; oy++) {
        var yy = cy + oy;
        if (yy < 0 || yy >= GY) continue;
        for (var ox = -1; ox <= 1; ox++) {
          var xx = cx + ox;
          if (xx < 0 || xx >= GX) continue;
          for (j = head[yy * GX + xx]; j >= 0; j = next[j]) {
            if (j <= i) continue;
            var dx = x[i] - x[j], dy = y[i] - y[j], d2 = dx * dx + dy * dy;
            if (d2 > DC2 || d2 < 1e-6) continue;
            var d = Math.sqrt(d2), ux = dx / d, uy = dy / d;
            var mjx = cs[j], mjy = sn[j];
            var a = mix * ux + miy * uy, b = mjx * ux + mjy * uy, cc = mix * mjx + miy * mjy;
            var s = Math.max(0.9, d / R2), s2 = s * s;
            /* 2 つの磁石のあいだの力と、向きをそろえるねじれ */
            var f = K / (s2 * s2);
            var fx = f * (cc * ux + mix * b + mjx * a - 5 * a * b * ux);
            var fy = f * (cc * uy + miy * b + mjy * a - 5 * a * b * uy);
            ax[i] += fx; ay[i] += fy; ax[j] -= fx; ay[j] -= fy;
            var g = KT / (s2 * s), mm = mix * mjy - miy * mjx;
            tq[i] += g * (3 * b * (mix * uy - miy * ux) - mm);
            tq[j] += g * (3 * a * (mjx * uy - mjy * ux) + mm);
            if (d < R2) {
              /* 重なりを押し戻し、近づく速さを消す */
              var ov = (R2 - d) * 0.5;
              x[i] += ux * ov; y[i] += uy * ov; x[j] -= ux * ov; y[j] -= uy * ov;
              var vn = (vx[i] - vx[j]) * ux + (vy[i] - vy[j]) * uy;
              if (vn < 0) {
                if (vn < -R * 5) snaps++;
                var imp = -vn * 0.6;
                vx[i] += imp * ux; vy[i] += imp * uy; vx[j] -= imp * ux; vy[j] -= imp * uy;
              }
              /* 触れている玉どうしで光が少しずつ伝わる */
              if (hl[i] > hl[j]) hl[j] += (hl[i] * 0.92 - hl[j]) * 0.06;
              else hl[i] += (hl[j] * 0.92 - hl[i]) * 0.06;
            }
            if (d < R2 * 1.18 && a * b > 0.35 && nb < cap) { bondI[nb] = i; bondJ[nb] = j; nb++; }
          }
        }
      }
    }

    var dv = Math.exp(-DAMP * h), dr = Math.exp(-DAMPR * h);
    var sq = Math.sqrt(h), nv = shake * R * 7 * sq, nr = shake * 2.2 * sq;
    var blend = Math.min(1, 7 * h);
    for (i = 0; i < N; i++) {
      var axx = ax[i], ayy = ay[i];
      if (down) {
        var ddx = px - x[i], ddy = py - y[i], dd = Math.sqrt(ddx * ddx + ddy * ddy);
        if (dd < RW && dd > 0.01) {
          var k = 1 - dd / RW, near = Math.min(1, dd / (R * 3));
          var pull = 1900 * k * near;
          axx += ddx / dd * pull; ayy += ddy / dd * pull;
          /* 指へ向けて磁石が回り、指から放射状に鎖が立つ */
          var tgt = Math.atan2(ddy, ddx);
          om[i] += Math.sin(tgt - th[i]) * 26 * k * h;
          if (dd < RW * 0.5) { vx[i] += (pvx - vx[i]) * blend * k; vy[i] += (pvy - vy[i]) * blend * k; }
          if (dd < RW * 0.75 && hl[i] < 1) hl[i] = Math.max(hl[i], Math.min(1, k * 2.2));
        }
      }
      vx[i] = (vx[i] + axx * h) * dv + gauss() * nv;
      vy[i] = (vy[i] + ayy * h) * dv + gauss() * nv;
      om[i] = (om[i] + tq[i] * h) * dr + gauss() * nr;
      var sp = vx[i] * vx[i] + vy[i] * vy[i], vmax = R * 90;
      if (sp > vmax * vmax) { sp = vmax / Math.sqrt(sp); vx[i] *= sp; vy[i] *= sp; }
      x[i] += vx[i] * h; y[i] += vy[i] * h; th[i] += om[i] * h;
      if (x[i] < R) { x[i] = R; vx[i] = Math.abs(vx[i]) * 0.3; }
      else if (x[i] > W - R) { x[i] = W - R; vx[i] = -Math.abs(vx[i]) * 0.3; }
      if (y[i] < R) { y[i] = R; vy[i] = Math.abs(vy[i]) * 0.3; }
      else if (y[i] > H - R) { y[i] = H - R; vy[i] = -Math.abs(vy[i]) * 0.3; }
    }
  }

  /* 台の一部を揺すって、そこだけ鎖をほどく */
  function quake(qx, qy, rad, power) {
    for (var i = 0; i < N; i++) {
      var d = Math.hypot(x[i] - qx, y[i] - qy);
      if (d > rad) continue;
      var k = 1 - d / rad;
      vx[i] += gauss() * R * 22 * power * k;
      vy[i] += gauss() * R * 22 * power * k;
      om[i] += gauss() * 7 * power * k;
    }
  }

  var TAU = Math.PI * 2;
  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.7 });
    var i, n;
    /* 磁石の N 側を半分だけ塗る */
    ctx.fillStyle = P.rgba(P.steel, 0.5);
    ctx.beginPath();
    for (i = 0; i < N; i++) {
      ctx.moveTo(x[i], y[i]);
      ctx.arc(x[i], y[i], R * 0.8, th[i] - 1.5708, th[i] + 1.5708);
      ctx.closePath();
    }
    ctx.fill();
    /* 鎖のつながり */
    ctx.strokeStyle = P.rgba(P.line, 0.45);
    ctx.lineWidth = Math.max(1, R * 0.2);
    ctx.beginPath();
    for (n = 0; n < nb; n++) {
      var a = bondI[n], b = bondJ[n];
      ctx.moveTo(x[a], y[a]); ctx.lineTo(x[b], y[b]);
    }
    ctx.stroke();
    /* 玉の輪郭 */
    ctx.strokeStyle = P.rgba(P.line, 0.78);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (i = 0; i < N; i++) {
      ctx.moveTo(x[i] + R, y[i]);
      ctx.arc(x[i], y[i], R, 0, TAU);
    }
    ctx.stroke();
    /* 触った玉と、そこから鎖で伝わった玉 */
    for (var lv = 4; lv >= 1; lv--) {
      var lo = (lv - 1) / 4, hi = lv / 4, any = false;
      ctx.beginPath();
      for (i = 0; i < N; i++) {
        var v = hl[i];
        if (v > 0.06 && v > lo && v <= hi + (lv === 4 ? 1 : 0)) {
          ctx.moveTo(x[i] + R, y[i]);
          ctx.arc(x[i], y[i], R, 0, TAU);
          any = true;
        }
      }
      if (!any) continue;
      ctx.fillStyle = P.rgba(P.accent, 0.3 * hi);
      ctx.fill();
      ctx.strokeStyle = P.rgba(P.accent, 0.9 * hi);
      ctx.lineWidth = 1.3;
      ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY; pvx = 0; pvy = 0; lastMove = performance.now();
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now(), dt = Math.max(0.008, (now - lastMove) / 1000);
    var nx = e.clientX, ny = e.clientY;
    pvx += ((nx - px) / dt - pvx) * 0.5;
    pvy += ((ny - py) / dt - pvy) * 0.5;
    px = nx; py = ny; lastMove = now;
    SFX.play('move', 140);
  });
  /* 放すと指の下の塊がほぐれて、鎖に戻っていく */
  function up() {
    if (down) quake(px, py, RW * 0.8, 0.8);
    down = false; pvx = 0; pvy = 0;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kd = document.getElementById('k-density'), ks = document.getElementById('k-shake');
  function readDensity() { phi = 0.08 + kd.value / 100 * 0.18; }
  function readShake() { shake = 0.05 + ks.value / 100 * 0.9; }
  kd.addEventListener('input', function () { readDensity(); alloc(); });
  ks.addEventListener('input', readShake);
  document.getElementById('k-scatter').addEventListener('click', function () {
    quake(W / 2, H / 2, Math.hypot(W, H), 1.6);
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readShake();
  var phi0 = phi;
  readDensity();
  if (phi !== phi0) alloc();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    if (down && now - lastMove > 120) { pvx *= 0.8; pvy *= 0.8; }
    nextQuake -= dt;
    if (nextQuake <= 0) {
      nextQuake = 4 + Math.random() * 3;
      var m = Math.min(W, H);
      quake(Math.random() * W, Math.random() * H, m * (0.18 + Math.random() * 0.14), 0.9 + shake);
      SFX.play('ripple', 3000);
    }
    snaps = 0;
    step(dt * 0.5); step(dt * 0.5);
    if (snaps > 0) SFX.play('collide', down ? 160 : 2600);
    var fade = Math.exp(-0.55 * dt);
    for (var i = 0; i < N; i++) hl[i] *= fade;
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
