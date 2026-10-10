<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('lenia');

$pageName = 'lenia';
$pageTitle = 'LENIA - PULSE';
$pageDesc = '点の集まりでできた丸い形がいくつか、後ろに薄い跡を残しながらゆっくり進んでいく。ぶつかると混ざったり消えたりする。押したところに新しい形が1つ置かれ、なぞった跡には点の筋が残る。';
$pageCanonical = 'https://pulse.subara3.com/lenia.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-crowd">CROWD</label><input type="range" id="k-crowd" min="0" max="100" value="75"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="trail">TRAIL</button><button type="button" data-look="halo">HALO</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 連続値のセルオートマトン。各点は「まわりの輪の中の濃さ」で増えたり減ったりする。
     輪の重みでのたたみ込みは FFT で一度に計算する（格子は 2 のべき乗） */
  var NX, NY, N, A, A0, T, fade, label, queue, U, KR, KI, FR, FI, heat;
  var R = 18, MU = 0.15, SIG = 0.015, DT = 0.1;
  var look = 'trail', pace = 0.45, crowd = 0.75;

  /* 滑るように進む形（Orbium）。20×20 の濃さの表 */
  var ORB = [
    [0,0,0,0,0,0,0.1,0.14,0.1,0,0,0.03,0.03,0,0,0.3,0,0,0,0],
    [0,0,0,0,0,0.08,0.24,0.3,0.3,0.18,0.14,0.15,0.16,0.15,0.09,0.2,0,0,0,0],
    [0,0,0,0,0,0.15,0.34,0.44,0.46,0.38,0.18,0.14,0.11,0.13,0.19,0.18,0.45,0,0,0],
    [0,0,0,0,0.06,0.13,0.39,0.5,0.5,0.37,0.06,0,0,0,0.02,0.16,0.68,0,0,0],
    [0,0,0,0.11,0.17,0.17,0.33,0.4,0.38,0.28,0.14,0,0,0,0,0,0.18,0.42,0,0],
    [0,0,0.09,0.18,0.13,0.06,0.08,0.26,0.32,0.32,0.27,0,0,0,0,0,0,0.82,0,0],
    [0.27,0,0.16,0.12,0,0,0,0.25,0.38,0.44,0.45,0.34,0,0,0,0,0,0.22,0.17,0],
    [0,0.07,0.2,0.02,0,0,0,0.31,0.48,0.57,0.6,0.57,0,0,0,0,0,0,0.49,0],
    [0,0.59,0.19,0,0,0,0,0.2,0.57,0.69,0.76,0.76,0.49,0,0,0,0,0,0.36,0],
    [0,0.58,0.19,0,0,0,0,0,0.67,0.83,0.9,0.92,0.87,0.12,0,0,0,0,0.22,0.07],
    [0,0,0.46,0,0,0,0,0,0.7,0.93,1,1,1,0.61,0,0,0,0,0.18,0.11],
    [0,0,0.82,0,0,0,0,0,0.47,1,1,0.98,1,0.96,0.27,0,0,0,0.19,0.1],
    [0,0,0.46,0,0,0,0,0,0.25,1,1,0.84,0.92,0.97,0.54,0.14,0.04,0.1,0.21,0.05],
    [0,0,0,0.4,0,0,0,0,0.09,0.8,1,0.82,0.8,0.85,0.63,0.31,0.18,0.19,0.2,0.01],
    [0,0,0,0.36,0.1,0,0,0,0.05,0.54,0.86,0.79,0.74,0.72,0.6,0.39,0.28,0.24,0.13,0],
    [0,0,0,0.01,0.3,0.07,0,0,0.08,0.36,0.64,0.7,0.64,0.6,0.51,0.39,0.29,0.19,0.04,0],
    [0,0,0,0,0.1,0.24,0.14,0.1,0.15,0.29,0.45,0.53,0.52,0.46,0.4,0.31,0.21,0.08,0,0],
    [0,0,0,0,0,0.08,0.21,0.21,0.22,0.29,0.36,0.39,0.37,0.33,0.26,0.18,0.09,0,0,0],
    [0,0,0,0,0,0,0.03,0.13,0.19,0.22,0.24,0.24,0.23,0.18,0.13,0.05,0,0,0,0],
    [0,0,0,0,0,0,0,0,0.02,0.06,0.08,0.09,0.07,0.05,0.01,0,0,0,0,0]
  ];
  var ORB_MASS = 0;
  ORB.forEach(function (r) { r.forEach(function (v) { ORB_MASS += v; }); });
  ORB_MASS *= (R / 13) * (R / 13);

  /* ---------------------------------------------------------- FFT */
  var bufR, bufI;
  function fft1(re, im, n, inv) {
    for (var i = 1, j = 0; i < n; i++) {
      var bit = n >> 1;
      for (; j & bit; bit >>= 1) j ^= bit;
      j ^= bit;
      if (i < j) { var t = re[i]; re[i] = re[j]; re[j] = t; t = im[i]; im[i] = im[j]; im[j] = t; }
    }
    for (var len = 2; len <= n; len <<= 1) {
      var ang = (inv ? 2 : -2) * Math.PI / len, wr = Math.cos(ang), wi = Math.sin(ang), half = len >> 1;
      for (var s = 0; s < n; s += len) {
        var cr = 1, ci = 0;
        for (var k = 0; k < half; k++) {
          var p = s + k, q = p + half;
          var xr = re[q] * cr - im[q] * ci, xi = re[q] * ci + im[q] * cr;
          re[q] = re[p] - xr; im[q] = im[p] - xi; re[p] += xr; im[p] += xi;
          var tt = cr * wr - ci * wi; ci = cr * wi + ci * wr; cr = tt;
        }
      }
    }
  }
  function fft2(re, im, inv) {
    var i, j;
    for (j = 0; j < NY; j++) {
      var o = j * NX;
      for (i = 0; i < NX; i++) { bufR[i] = re[o + i]; bufI[i] = im[o + i]; }
      fft1(bufR, bufI, NX, inv);
      for (i = 0; i < NX; i++) { re[o + i] = bufR[i]; im[o + i] = bufI[i]; }
    }
    for (i = 0; i < NX; i++) {
      for (j = 0; j < NY; j++) { bufR[j] = re[j * NX + i]; bufI[j] = im[j * NX + i]; }
      fft1(bufR, bufI, NY, inv);
      for (j = 0; j < NY; j++) { re[j * NX + i] = bufR[j]; im[j * NX + i] = bufI[j]; }
    }
  }

  /* 画面の向きに合わせて 256×128 か 128×256。はみ出す分は端を切る */
  var cell = 10, ox = 0, oy = 0, dots, dotCell, live;
  function alloc() {
    var land = W >= H;
    var nx = land ? 256 : 128, ny = land ? 128 : 256;
    var fresh = nx !== NX || ny !== NY;
    NX = nx; NY = ny; N = NX * NY;
    cell = Math.max(W / NX, H / NY);
    ox = (W - cell * NX) / 2; oy = (H - cell * NY) / 2;
    if (!fresh) { buildDots(); return; }
    buildDots();
    live = new Uint8Array(N);
    bufR = new Float64Array(Math.max(NX, NY)); bufI = new Float64Array(Math.max(NX, NY));
    A = new Float32Array(N); A0 = new Float32Array(N); T = new Float32Array(N); fade = new Float32Array(N);
    label = new Int32Array(N); queue = new Int32Array(N); U = new Float32Array(N); heat = new Float32Array(N);
    FR = new Float64Array(N); FI = new Float64Array(N);
    KR = new Float64Array(N); KI = new Float64Array(N);
    var sum = 0;
    for (var dy = -R; dy <= R; dy++) {
      for (var dx = -R; dx <= R; dx++) {
        var r = Math.hypot(dx, dy) / R;
        if (r <= 0 || r >= 1) continue;
        var w = Math.exp(4 - 1 / (r * (1 - r)));
        KR[((dy + NY) % NY) * NX + (dx + NX) % NX] = w; sum += w;
      }
    }
    for (var k = 0; k < N; k++) KR[k] /= sum;
    fft2(KR, KI, false);
    for (var n = 0; n < 5; n++) spawnFree(false);
    for (var s = 0; s < 30; s++) step();
  }

  /* 点描の点は、ゆらぎを持たせた格子に置く（画面に固定。形が動くと点の大きさが変わる） */
  function buildDots() {
    var gap = 4.6, list = [];
    var seedv = 12345;
    function rnd() { seedv = (seedv * 16807) % 2147483647; return seedv / 2147483647; }
    for (var y = gap * 0.5; y < H; y += gap) {
      for (var x = gap * 0.5; x < W; x += gap) list.push(x + (rnd() - 0.5) * gap * 0.8, y + (rnd() - 0.5) * gap * 0.8);
    }
    dots = new Float32Array(list);
    dotCell = new Int32Array(list.length / 2);
    for (var n = 0; n < dotCell.length; n++) {
      var gi = Math.floor((dots[n * 2] - ox) / cell - 0.5), gj = Math.floor((dots[n * 2 + 1] - oy) / cell - 0.5);
      dotCell[n] = ((gj + NY) % NY) * NX + (gi + NX) % NX;
    }
  }

  function placeOrb(gx, gy, ang, touched) {
    var c = Math.cos(ang), s = Math.sin(ang);
    var sc = 13 / R, ext = Math.ceil(10 / sc) + 1;
    for (var j = -ext; j <= ext; j++) {
      for (var i = -ext; i <= ext; i++) {
        var u = (c * i + s * j) * sc + 9.5, v = (-s * i + c * j) * sc + 9.5;
        if (u < 0 || v < 0 || u >= 19 || v >= 19) continue;
        var u0 = u | 0, v0 = v | 0, fu = u - u0, fv = v - v0;
        var val = (ORB[v0][u0] * (1 - fu) + ORB[v0][u0 + 1] * fu) * (1 - fv)
                + (ORB[v0 + 1][u0] * (1 - fu) + ORB[v0 + 1][u0 + 1] * fu) * fv;
        var ii = ((Math.round(gx) + i) % NX + NX) % NX, jj = ((Math.round(gy) + j) % NY + NY) % NY;
        var k = jj * NX + ii;
        if (val > A[k]) A[k] = val;
        if (touched) heat[k] = Math.max(heat[k], 1);
      }
    }
  }
  /* 空いている場所を探して 1 匹置く */
  function spawnFree(touched) {
    var best = null, bestScore = -1;
    var reach = Math.round(R * 1.4), stepq = Math.max(2, Math.round(R / 4));
    for (var t = 0; t < 14; t++) {
      var x = Math.random() * NX, y = Math.random() * NY, near = 0;
      for (var j = -reach; j <= reach; j += stepq) for (var i = -reach; i <= reach; i += stepq) {
        near += A[((Math.round(y) + j + NY * 2) % NY) * NX + (Math.round(x) + i + NX * 2) % NX];
      }
      var score = -near + Math.random() * 0.01;
      if (bestScore === -1 || score > bestScore) { bestScore = score; best = { x: x, y: y }; }
    }
    placeOrb(best.x, best.y, Math.random() * Math.PI * 2, touched);
  }

  function step() {
    var k;
    A0.set(A);
    for (k = 0; k < N; k++) { FR[k] = A[k]; FI[k] = 0; }
    fft2(FR, FI, false);
    for (k = 0; k < N; k++) {
      var a = FR[k], b = FI[k];
      FR[k] = a * KR[k] - b * KI[k]; FI[k] = a * KI[k] + b * KR[k];
    }
    fft2(FR, FI, true);
    var inv = 1 / N, s2 = 2 * SIG * SIG;
    for (k = 0; k < N; k++) {
      var u = FR[k] * inv, d = u - MU;
      U[k] = u;
      var v = A[k] + DT * (2 * Math.exp(-d * d / s2) - 1);
      if (fade[k] > 0) { v -= fade[k] * 0.06; fade[k] *= 0.99; }
      A[k] = v < 0 ? 0 : v > 1 ? 1 : v;
      var tr = T[k] * 0.982;
      T[k] = A[k] > tr ? A[k] : tr;
    }
  }

  /* ぶつかって大きな塊になったものは、広がって画面を埋める前にゆっくり溶かす */
  function dissolveBig() {
    label.fill(0);
    var id = 0;
    for (var s0 = 0; s0 < N; s0++) {
      if (label[s0] || A[s0] < 0.05) continue;
      id++;
      var head = 0, tail = 0, m = 0;
      queue[tail++] = s0; label[s0] = id;
      while (head < tail) {
        var k = queue[head++], i = k % NX, j = (k - i) / NX;
        m += A[k];
        var nb = [j * NX + (i + 1) % NX, j * NX + (i + NX - 1) % NX, ((j + 1) % NY) * NX + i, ((j + NY - 1) % NY) * NX + i];
        for (var q = 0; q < 4; q++) {
          var o = nb[q];
          if (!label[o] && A[o] >= 0.05) { label[o] = id; queue[tail++] = o; }
        }
      }
      if (m > ORB_MASS * 2.6) for (var t = 0; t < tail; t++) fade[queue[t]] = 1;
    }
  }

  /* ---------------------------------------------------------- 描く */
  var frac = 1;
  function sampleA(x, y) {
    var gx = (x - ox) / cell - 0.5, gy = (y - oy) / cell - 0.5;
    var i0 = Math.floor(gx), j0 = Math.floor(gy), fx = gx - i0, fy = gy - j0;
    var i1 = (i0 + 1 + NX) % NX, j1 = (j0 + 1 + NY) % NY;
    i0 = (i0 + NX) % NX; j0 = (j0 + NY) % NY;
    var k00 = j0 * NX + i0, k10 = j0 * NX + i1, k01 = j1 * NX + i0, k11 = j1 * NX + i1;
    var cur = (A[k00] * (1 - fx) + A[k10] * fx) * (1 - fy) + (A[k01] * (1 - fx) + A[k11] * fx) * fy;
    var old = (A0[k00] * (1 - fx) + A0[k10] * fx) * (1 - fy) + (A0[k01] * (1 - fx) + A0[k11] * fx) * fy;
    return old + (cur - old) * frac;
  }
  function sample(F, x, y) {
    var gx = (x - ox) / cell - 0.5, gy = (y - oy) / cell - 0.5;
    var i0 = Math.floor(gx), j0 = Math.floor(gy), fx = gx - i0, fy = gy - j0;
    var i1 = (i0 + 1 + NX) % NX, j1 = (j0 + 1 + NY) % NY;
    i0 = (i0 + NX) % NX; j0 = (j0 + NY) % NY;
    return (F[j0 * NX + i0] * (1 - fx) + F[j0 * NX + i1] * fx) * (1 - fy)
         + (F[j1 * NX + i0] * (1 - fx) + F[j1 * NX + i1] * fx) * fy;
  }
  var BUCKETS = 5;
  function draw() {
    var keep = look === 'trail';
    P.fill(ctx, W, H);
    var paths = [], hot = [], b;
    for (b = 0; b < BUCKETS; b++) { paths.push([]); hot.push([]); }
    /* 何もない格子の点は先に飛ばす（双線形補間で触れる 2×2 のどれかに濃さがあれば描く） */
    for (var j = 0; j < NY; j++) {
      var j1 = ((j + 1) % NY) * NX, r0 = j * NX;
      for (var i = 0; i < NX; i++) {
        var i1 = (i + 1) % NX, mx = 0;
        var c4 = [r0 + i, r0 + i1, j1 + i, j1 + i1];
        for (var q = 0; q < 4; q++) {
          var kk = c4[q], m = A[kk] > A0[kk] ? A[kk] : A0[kk];
          if (keep && T[kk] * 0.55 > m) m = T[kk] * 0.55;
          if (m > mx) mx = m;
        }
        live[r0 + i] = mx > 0.03 ? 1 : 0;
      }
    }
    for (var n = 0; n < dots.length; n += 2) {
      if (!live[dotCell[n >> 1]]) continue;
      var x = dots[n], y = dots[n + 1];
      var v = sampleA(x, y);
      if (keep) { var tv = sample(T, x, y) * 0.55; if (tv > v) v = tv; }
      if (v < 0.035) continue;
      b = Math.min(BUCKETS - 1, (v * BUCKETS) | 0);
      var r = 0.6 + Math.sqrt(v) * 1.6;
      (sample(heat, x, y) > 0.25 ? hot : paths)[b].push(x, y, r);
    }
    for (b = 0; b < BUCKETS; b++) {
      var alpha = 0.42 + 0.58 * (b + 1) / BUCKETS;
      fillDots(paths[b], P.rgba(P.line, alpha));
      fillDots(hot[b], P.rgba(P.accent, alpha));
    }
    if (look === 'halo') drawHalo();
  }
  function fillDots(list, style) {
    if (!list.length) return;
    ctx.fillStyle = style;
    ctx.beginPath();
    for (var i = 0; i < list.length; i += 3) {
      var rr = list[i + 2];
      if (rr < 1.05) { ctx.rect(list[i] - rr, list[i + 1] - rr, rr * 2, rr * 2); continue; }
      ctx.moveTo(list[i] + rr, list[i + 1]);
      ctx.arc(list[i], list[i + 1], rr, 0, Math.PI * 2);
    }
    ctx.fill();
  }
  /* HALO: 「増える」濃さの境目を細い線で引く。生きものの外側に薄い輪ができる */
  function drawHalo() {
    ctx.beginPath();
    var lv = MU * 0.55;
    for (var j = 0; j < NY; j++) {
      var j1 = (j + 1) % NY;
      for (var i = 0; i < NX; i++) {
        var i1 = (i + 1) % NX;
        var a = U[j * NX + i], b = U[j * NX + i1], c = U[j1 * NX + i1], d = U[j1 * NX + i];
        var idx = (a > lv ? 1 : 0) | (b > lv ? 2 : 0) | (c > lv ? 4 : 0) | (d > lv ? 8 : 0);
        if (idx === 0 || idx === 15) continue;
        var x0 = ox + (i + 0.5) * cell, y0 = oy + (j + 0.5) * cell, s = cell;
        var tx = x0 + s * (lv - a) / (b - a), rx = x0 + s, ry = y0 + s * (lv - b) / (c - b);
        var bx = x0 + s * (lv - d) / (c - d), by = y0 + s, lx = x0, ly = y0 + s * (lv - a) / (d - a);
        switch (idx) {
          case 1: case 14: ctx.moveTo(lx, ly); ctx.lineTo(tx, y0); break;
          case 2: case 13: ctx.moveTo(tx, y0); ctx.lineTo(rx, ry); break;
          case 3: case 12: ctx.moveTo(lx, ly); ctx.lineTo(rx, ry); break;
          case 4: case 11: ctx.moveTo(rx, ry); ctx.lineTo(bx, by); break;
          case 6: case 9: ctx.moveTo(tx, y0); ctx.lineTo(bx, by); break;
          case 7: case 8: ctx.moveTo(lx, ly); ctx.lineTo(bx, by); break;
          case 5: ctx.moveTo(lx, ly); ctx.lineTo(tx, y0); ctx.moveTo(rx, ry); ctx.lineTo(bx, by); break;
          case 10: ctx.moveTo(tx, y0); ctx.lineTo(rx, ry); ctx.moveTo(lx, ly); ctx.lineTo(bx, by); break;
        }
      }
    }
    ctx.strokeStyle = P.rgba(P.steel, 0.7);
    ctx.lineWidth = 0.9;
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, trailP = null;
  function dust(gx, gy, r) {
    for (var j = Math.floor(gy - r); j <= gy + r; j++) {
      for (var i = Math.floor(gx - r); i <= gx + r; i++) {
        var d = Math.hypot(i - gx, j - gy) / r;
        if (d >= 1) continue;
        var k = ((j % NY + NY) % NY) * NX + (i % NX + NX) % NX, v = 0.62 * (1 - d * d);
        if (v > A[k]) A[k] = v;
        if (v > T[k]) T[k] = v;
        heat[k] = 1;
      }
    }
  }
  function toGrid(e) { return { x: (e.clientX - ox) / cell, y: (e.clientY - oy) / cell, px: e.clientX, py: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = toGrid(e); trailP = last;
    placeOrb(last.x, last.y, Math.random() * Math.PI * 2, true);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = toGrid(e);
    /* なぞった跡には、すぐに点の筋が残る（多くはそのまま薄れて消え、ときどき形になって動き出す） */
    var dd = Math.hypot(p.px - trailP.px, p.py - trailP.py), n = Math.ceil(dd / 6);
    for (var i = 1; i <= n; i++) dust(trailP.x + (p.x - trailP.x) * i / n, trailP.y + (p.y - trailP.y) * i / n, 4);
    trailP = p;
    SFX.play('move', 160);
    if (Math.hypot(p.px - last.px, p.py - last.py) < cell * 40) return;
    placeOrb(p.x, p.y, Math.atan2(p.py - last.py, p.px - last.px), true);
    last = p;
    SFX.play('spawn', 300);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.getElementById('k-crowd').addEventListener('input', function () { crowd = this.value / 100; });
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-clear').addEventListener('click', function () {
    A.fill(0); T.fill(0); fade.fill(0); heat.fill(0);
    spawnFree(false); spawnFree(false);
    SFX.play('click');
  });
  pace = document.getElementById('k-pace').value / 100;
  crowd = document.getElementById('k-crowd').value / 100;

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0, checkT = 0, calm = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    /* 1 歩で 0.3 マスほど進む。歩みは遅くし、歩と歩のあいだは補間して滑らかに見せる */
    acc += dt * (8 + pace * 30);
    var n = 0;
    while (acc >= 1 && n < 3) { acc -= 1; step(); n++; }
    if (acc > 1) acc = 1;
    frac = acc;
    for (var k = 0; k < N; k++) if (heat[k] > 0) heat[k] *= 0.994;
    /* 数が減ったら空き地に 1 匹足す。増えすぎて画面が埋まりかけたら全体を少し薄める */
    checkT += dt;
    if (checkT > 0.5) {
      checkT = 0;
      var mass = 0;
      for (k = 0; k < N; k++) mass += A[k];
      var want = 2 + crowd * 6;
      calm += 0.5;
      if (mass < ORB_MASS * (want - 0.5) && calm > 3) { spawnFree(false); calm = 0; SFX.play('spawn', 4000); }
      dissolveBig();
    }
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
