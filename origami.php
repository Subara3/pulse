<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('origami');

$pageName = 'origami';
$pageTitle = 'ORIGAMI - PULSE';
$pageDesc = '山折りと谷折りの折り目がついた紙が、平行四辺形の面で画面を埋めている。深くたたまれる部分が波になって紙の上をゆっくり渡り、たたまれたところほど面の明暗が強くなる。押すとそこを中心に紙が深くたたまれて指のほうへ寄り、なぞると指の跡に沿って折り目が深くなる。';
$pageCanonical = 'https://pulse.subara3.com/origami.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-depth">DEPTH</label><input type="range" id="k-depth" min="0" max="100" value="55"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 紙は頂点の格子。横の折り目はジグザグ、縦の折り目はまっすぐ（ミウラ折り） */
  var NX, NY, cw, ch, fold, touch, X, Y, Z, SX, SY;
  var speed = 0.4, depth = 0.55, t = 0;
  var down = false, px = 0, py = 0, pull = 0;

  function alloc() {
    cw = Math.max(36, Math.min(80, Math.min(W, H) / 10));
    ch = cw * 0.72;
    NX = Math.ceil(W * 1.35 / cw) + 4;
    NY = Math.ceil(H * 1.35 / ch) + 6;
    var n = NX * NY;
    fold = new Float32Array(n);
    touch = new Float32Array(n);
    X = new Float32Array(n); Y = new Float32Array(n); Z = new Float32Array(n);
    SX = new Float32Array(n); SY = new Float32Array(n);
    for (var k = 0; k < n; k++) fold[k] = 0.4;
  }

  /* 動いている間ずっと紙の上を渡る、たたみの深さの波 */
  function wave(rx, ry) {
    var s1 = Math.sin(rx * 0.0045 + ry * 0.0026 - t * 0.55);
    var s2 = Math.sin(-rx * 0.0021 + ry * 0.0052 - t * 0.37 + 1.7);
    var s3 = Math.sin(rx * 0.0011 - ry * 0.0013 + t * 0.21);
    return 0.42 + 0.26 * s1 + 0.16 * s2 + 0.1 * s3;
  }

  function solve(dt) {
    var ic = NX >> 1, jc = NY >> 1, k, i, j;
    var a = Math.min(1, dt * 6), decay = Math.exp(-dt * 0.45);
    var R2 = cw * cw * 3.2, dep = 0.35 + depth * 1.1;
    for (j = 0; j < NY; j++) {
      var ry = H / 2 + (j - jc) * ch;
      for (i = 0; i < NX; i++) {
        k = j * NX + i;
        var rx = W / 2 + (i - ic) * cw;
        if (down) {
          var d2 = (rx - px) * (rx - px) + (ry - py) * (ry - py);
          if (d2 < R2 * 9) touch[k] = Math.min(1, touch[k] + dt * 2.2 * Math.exp(-d2 / R2));
        }
        touch[k] *= decay;
        var target = Math.max(0, Math.min(1.25, wave(rx, ry) * dep + touch[k] * 0.95));
        fold[k] += (target - fold[k]) * a;
      }
    }
    /* たたむと紙が縮む。中心の行・列から外へ間隔を足していく */
    for (i = 0; i < NX; i++) {
      Y[jc * NX + i] = H / 2;
      for (j = jc + 1; j < NY; j++) { k = j * NX + i; Y[k] = Y[k - NX] + ch * (1 - 0.14 * fold[k]); }
      for (j = jc - 1; j >= 0; j--) { k = j * NX + i; Y[k] = Y[k + NX] - ch * (1 - 0.14 * fold[k]); }
    }
    for (j = 0; j < NY; j++) {
      var row = j * NX;
      X[row + ic] = W / 2;
      for (i = ic + 1; i < NX; i++) X[row + i] = X[row + i - 1] + cw * (1 - 0.1 * fold[row + i]);
      for (i = ic - 1; i >= 0; i--) X[row + i] = X[row + i + 1] - cw * (1 - 0.1 * fold[row + i]);
    }
    var Rp = cw * cw * 6;
    for (j = 0; j < NY; j++) {
      for (i = 0; i < NX; i++) {
        k = j * NX + i;
        var f = fold[k];
        /* ジグザグの振れ幅と山谷の高さが、たたみの深さで増える */
        Y[k] += ((i & 1) ? 0.5 : -0.5) * ch * (0.22 + 0.32 * f);
        Z[k] = ((j & 1) ? 0.5 : -0.5) * ch * 0.95 * f;
        if (pull > 0.001) {
          var dx = px - X[k], dy = py - Y[k];
          var g = Math.exp(-(dx * dx + dy * dy) / Rp) * pull * 0.26;
          X[k] += dx * g; Y[k] += dy * g;
        }
        SX[k] = X[k] + Z[k] * 0.18;
        SY[k] = Y[k] - Z[k] * 0.55;
      }
    }
  }

  /* 面の色は明るさを 32 段、触った強さを 6 段に刻んで使い回す */
  var cache = {};
  function faceColor(s, h) {
    var qs = Math.round(Math.max(0, Math.min(1, s)) * 31), qh = Math.round(Math.max(0, Math.min(1, h)) * 5);
    var key = qs * 8 + qh, c = cache[key];
    if (!c) {
      var v = 0.03 + qs / 31 * 0.47;
      c = cache[key] = P.rgba(P.mix(P.ramp(v), P.rampAccent(v), qh / 5 * 0.75), 1);
    }
    return c;
  }

  var LX = 0.42, LY = -0.38, LZ = 0.82;
  function draw() {
    P.fill(ctx, W, H, 1);
    var i, j, k;
    for (j = 0; j < NY - 1; j++) {
      for (i = 0; i < NX - 1; i++) {
        var a = j * NX + i, b = a + 1, c = a + NX + 1, d = a + NX;
        if (SX[a] > W + cw && SX[d] > W + cw) continue;
        if (SX[b] < -cw && SX[c] < -cw) continue;
        if (SY[a] > H + ch && SY[b] > H + ch) continue;
        if (SY[d] < -ch && SY[c] < -ch) continue;
        /* 対角線の外積で面の向きを出し、斜めの光で明暗をつける */
        var ux = X[c] - X[a], uy = Y[c] - Y[a], uz = Z[c] - Z[a];
        var vx = X[d] - X[b], vy = Y[d] - Y[b], vz = Z[d] - Z[b];
        var nx = uy * vz - uz * vy, ny = uz * vx - ux * vz, nz = ux * vy - uy * vx;
        var nl = Math.sqrt(nx * nx + ny * ny + nz * nz) || 1;
        if (nz < 0) nl = -nl;
        var lit = (nx * LX + ny * LY + nz * LZ) / nl;
        var h = (touch[a] + touch[b] + touch[c] + touch[d]) * 0.3;
        ctx.fillStyle = faceColor((lit - 0.25) / 0.75, h);
        ctx.beginPath();
        ctx.moveTo(SX[a], SY[a]); ctx.lineTo(SX[b], SY[b]); ctx.lineTo(SX[c], SY[c]); ctx.lineTo(SX[d], SY[d]);
        ctx.closePath();
        ctx.fill();
      }
    }
    /* 折り目: 山の行は明るく、谷の行と縦の折り目は控えめに */
    ctx.lineWidth = 1;
    for (var pass = 0; pass < 2; pass++) {
      ctx.strokeStyle = P.rgba(P.line, pass ? 0.6 : 0.22);
      ctx.beginPath();
      for (j = 0; j < NY; j++) {
        if ((j & 1) !== pass) continue;
        k = j * NX;
        ctx.moveTo(SX[k], SY[k]);
        for (i = 1; i < NX; i++) ctx.lineTo(SX[k + i], SY[k + i]);
      }
      if (!pass) {
        for (i = 0; i < NX; i++) {
          ctx.moveTo(SX[i], SY[i]);
          for (j = 1; j < NY; j++) ctx.lineTo(SX[j * NX + i], SY[j * NX + i]);
        }
      }
      ctx.stroke();
    }
    /* 触ったところの折り目だけ水色に */
    ctx.lineWidth = 1.4;
    for (var q = 1; q <= 4; q++) {
      var lo = q * 0.2, hi = lo + 0.2, any = false;
      ctx.beginPath();
      for (j = 0; j < NY; j++) {
        for (i = 0; i < NX - 1; i++) {
          k = j * NX + i;
          var e = (touch[k] + touch[k + 1]) * 0.5;
          if (e < lo || (q < 4 && e >= hi)) continue;
          ctx.moveTo(SX[k], SY[k]); ctx.lineTo(SX[k + 1], SY[k + 1]);
          any = true;
        }
      }
      if (any) { ctx.strokeStyle = P.rgba(P.accent, 0.2 + q * 0.16); ctx.stroke(); }
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  /* 押した場所の格子点に、たたみの深さを直接足す */
  function press(x, y, amt) {
    var ic = NX >> 1, jc = NY >> 1, R2 = cw * cw * 3.2;
    for (var j = 0; j < NY; j++) {
      var ry = H / 2 + (j - jc) * ch;
      for (var i = 0; i < NX; i++) {
        var rx = W / 2 + (i - ic) * cw, d2 = (rx - x) * (rx - x) + (ry - y) * (ry - y);
        if (d2 < R2 * 9) { var k = j * NX + i; touch[k] = Math.min(1, touch[k] + amt * Math.exp(-d2 / R2)); }
      }
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY;
    press(px, py, 0.7);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY, dist = Math.hypot(x - px, y - py);
    if (dist < 4) return;
    var n = Math.ceil(dist / (cw * 0.5));
    for (var s = 1; s <= n; s++) press(px + (x - px) * s / n, py + (y - py) * s / n, 0.3);
    px = x; py = y;
    SFX.play('move', 130);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = this.value / 100; });
  document.getElementById('k-depth').addEventListener('input', function () { depth = this.value / 100; });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = document.getElementById('k-speed').value / 100;
  depth = document.getElementById('k-depth').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    t += dt * (0.15 + speed * 1.7);
    pull += ((down ? 1 : 0) - pull) * Math.min(1, dt * (down ? 8 : 2.5));
    chime += dt;
    if (chime > 6.5) { chime = 0; SFX.play('tick'); }
    solve(dt);
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
