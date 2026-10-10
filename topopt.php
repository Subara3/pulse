<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('topopt');

$pageName = 'topopt';
$pageTitle = 'TOPOPT - PULSE';
$pageDesc = '四角い板に重さがかかると、力のかからない部分から材料が削られ、橋の骨組みのような形が残る。重りの位置がゆっくり動くと、骨組みの形も変わる。触ったところへ重りを移せる。';
$pageCanonical = 'https://pulse.subara3.com/topopt.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-mass">MASS</label><input type="range" id="k-mass" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" class="active" data-span="bridge">BRIDGE</button><button type="button" data-span="arm">ARM</button></div>
  <div class="ctl-btns"><button type="button" id="k-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* ------------------------------------------------ 有限要素（4 節点の正方形要素・平面応力） */
  var nu = 0.3, E0 = 1, Emin = 1e-3, PEN = 3;
  var kk = [0.5 - nu / 6, 0.125 + nu / 8, -0.25 - nu / 12, -0.125 + 3 * nu / 8, -0.25 + nu / 12, -0.125 - nu / 8, nu / 6, 0.125 - 3 * nu / 8];
  var KI = [
    [0, 1, 2, 3, 4, 5, 6, 7], [1, 0, 7, 6, 5, 4, 3, 2], [2, 7, 0, 5, 6, 3, 4, 1], [3, 6, 5, 0, 7, 2, 1, 4],
    [4, 5, 6, 7, 0, 1, 2, 3], [5, 4, 3, 2, 1, 0, 7, 6], [6, 3, 4, 1, 2, 7, 0, 5], [7, 2, 1, 4, 3, 6, 5, 0]
  ];
  var KE = new Float64Array(64);
  for (var i0 = 0; i0 < 8; i0++) for (var j0 = 0; j0 < 8; j0++) KE[i0 * 8 + j0] = kk[KI[i0][j0]] / (1 - nu * nu);

  var NX, NY, NE, ND, EDOF, X, XN, DC, CE, EE, U, F, FIX, R, Z, Pp, Q, DIAG, FW, FI;
  var span = 'bridge', mass = 0.45, vol = 1, volT = 0.4;

  function build() {
    var aw = W * 0.84, ah = H * 0.66, asp = Math.max(0.7, Math.min(2.6, aw / ah));
    NY = Math.round(Math.sqrt(2600 / asp)); NX = Math.round(NY * asp);
    NE = NX * NY; ND = 2 * (NX + 1) * (NY + 1);
    EDOF = new Int32Array(NE * 8);
    for (var ex = 0; ex < NX; ex++) for (var ey = 0; ey < NY; ey++) {
      var e = ey * NX + ex, n1 = (NY + 1) * ex + ey, n2 = (NY + 1) * (ex + 1) + ey;
      /* 左下・右下・右上・左上の順（上の行ほど ey が小さい） */
      var d = [2 * (n1 + 1), 2 * (n1 + 1) + 1, 2 * (n2 + 1), 2 * (n2 + 1) + 1, 2 * n2, 2 * n2 + 1, 2 * n1, 2 * n1 + 1];
      for (var k = 0; k < 8; k++) EDOF[e * 8 + k] = d[k];
    }
    X = new Float64Array(NE).fill(1); XN = new Float64Array(NE);
    DC = new Float64Array(NE); CE = new Float64Array(NE); EE = new Float64Array(NE);
    U = new Float64Array(ND); F = new Float64Array(ND); FIX = new Uint8Array(ND);
    R = new Float64Array(ND); Z = new Float64Array(ND); Pp = new Float64Array(ND); Q = new Float64Array(ND); DIAG = new Float64Array(ND);
    /* 感度フィルタ: 半径 2 要素の重み */
    var rmin = 2.1, list = [], wts = [];
    FI = []; FW = [];
    for (ey = 0; ey < NY; ey++) for (ex = 0; ex < NX; ex++) {
      var idx = [], w = [];
      for (var j = Math.max(0, ey - 2); j <= Math.min(NY - 1, ey + 2); j++)
        for (var i = Math.max(0, ex - 2); i <= Math.min(NX - 1, ex + 2); i++) {
          var r = rmin - Math.hypot(i - ex, j - ey);
          if (r > 0) { idx.push(j * NX + i); w.push(r); }
        }
      FI.push(idx); FW.push(w);
    }
    supports();
    vol = 1;
    loadX = span === 'bridge' ? NX / 2 : NX; loadY = span === 'bridge' ? NY : NY / 2;
    SHADE = null;
  }
  function node(ix, iy) { return (NY + 1) * ix + iy; }
  function supports() {
    FIX.fill(0);
    if (span === 'bridge') {
      /* 下の両端をピンで留める */
      for (var k = 0; k <= 1; k++) {
        var a = node(k, NY), b = node(NX - k, NY);
        FIX[2 * a] = FIX[2 * a + 1] = 1; FIX[2 * b] = FIX[2 * b + 1] = 1;
      }
    } else {
      /* 左の壁にがっちり固定 */
      for (var iy = 0; iy <= NY; iy++) { var n = node(0, iy); FIX[2 * n] = FIX[2 * n + 1] = 1; }
    }
    U.fill(0);
  }
  /* 重りは 4 節点に分けて置き、動きをなめらかにする */
  var loadX = 0, loadY = 0;
  function setLoad() {
    F.fill(0);
    var ix, d;
    /* 橋: 道路の重さが下の縁に均等にかかる */
    if (span === 'bridge') {
      for (ix = 0; ix <= NX; ix++) { d = 2 * node(ix, NY) + 1; if (!FIX[d]) F[d] -= 1.6 / NX; }
    }
    var x = Math.max(0, Math.min(NX, loadX)), y = Math.max(0, Math.min(NY, loadY));
    ix = Math.min(NX - 1, Math.floor(x));
    var iy = Math.min(NY - 1, Math.floor(y)), fx = x - ix, fy = y - iy;
    var w = [[ix, iy, (1 - fx) * (1 - fy)], [ix + 1, iy, fx * (1 - fy)], [ix, iy + 1, (1 - fx) * fy], [ix + 1, iy + 1, fx * fy]];
    var pw = span === 'bridge' ? 0.7 : 1;
    for (var k = 0; k < 4; k++) { d = 2 * node(w[k][0], w[k][1]) + 1; if (!FIX[d]) F[d] -= w[k][2] * pw; }
  }
  var ue = new Float64Array(8);
  function matvec(x, y) {
    y.fill(0);
    for (var e = 0; e < NE; e++) {
      var o = e * 8, Ee = EE[e];
      for (var k = 0; k < 8; k++) ue[k] = x[EDOF[o + k]];
      for (var i = 0; i < 8; i++) {
        var s = 0, ro = i * 8;
        for (var j = 0; j < 8; j++) s += KE[ro + j] * ue[j];
        y[EDOF[o + i]] += Ee * s;
      }
    }
    for (var d = 0; d < ND; d++) if (FIX[d]) y[d] = x[d];
  }
  /* 前の答えから始める共役勾配法。毎フレーム少しずつ解き進める */
  function solve(iters) {
    var d, e;
    DIAG.fill(0);
    for (e = 0; e < NE; e++) for (var k = 0; k < 8; k++) DIAG[EDOF[e * 8 + k]] += EE[e] * KE[0];
    for (d = 0; d < ND; d++) { if (FIX[d]) { DIAG[d] = 1; U[d] = 0; } }
    matvec(U, Q);
    var rz = 0;
    for (d = 0; d < ND; d++) { R[d] = (FIX[d] ? 0 : F[d]) - Q[d]; Z[d] = R[d] / DIAG[d]; Pp[d] = Z[d]; rz += R[d] * Z[d]; }
    for (var it = 0; it < iters; it++) {
      matvec(Pp, Q);
      var pq = 0;
      for (d = 0; d < ND; d++) pq += Pp[d] * Q[d];
      if (pq <= 0) break;
      var al = rz / pq, rz2 = 0;
      for (d = 0; d < ND; d++) { U[d] += al * Pp[d]; R[d] -= al * Q[d]; Z[d] = R[d] / DIAG[d]; rz2 += R[d] * Z[d]; }
      var be = rz2 / rz; rz = rz2;
      for (d = 0; d < ND; d++) Pp[d] = Z[d] + be * Pp[d];
      if (rz < 1e-14) break;
    }
  }
  /* 最適性規準法で密度を 1 歩更新する */
  function optimize() {
    var e, k;
    for (e = 0; e < NE; e++) EE[e] = Emin + Math.pow(X[e], PEN) * (E0 - Emin);
    setLoad();
    solve(24);
    for (e = 0; e < NE; e++) {
      var o = e * 8, s = 0;
      for (var i = 0; i < 8; i++) {
        var ui = U[EDOF[o + i]], t = 0;
        for (var j = 0; j < 8; j++) t += KE[i * 8 + j] * U[EDOF[o + j]];
        s += ui * t;
      }
      CE[e] = s;
      DC[e] = -PEN * Math.pow(X[e], PEN - 1) * (E0 - Emin) * s;
    }
    /* 感度をならして、市松模様を防ぐ */
    for (e = 0; e < NE; e++) {
      var idx = FI[e], w = FW[e], num = 0, den = 0;
      for (k = 0; k < idx.length; k++) { num += w[k] * X[idx[k]] * DC[idx[k]]; den += w[k]; }
      XN[e] = num / (den * Math.max(1e-3, X[e]));
    }
    for (e = 0; e < NE; e++) DC[e] = XN[e];
    var l1 = 0, l2 = 1e9, move = 0.06, target = Math.min(0.98, vol) * NE;
    while ((l2 - l1) / (l1 + l2) > 1e-3 && l2 > 1e-30) {
      var lm = 0.5 * (l1 + l2), sum = 0;
      for (e = 0; e < NE; e++) {
        var xn = X[e] * Math.sqrt(Math.max(0, -DC[e]) / lm);
        xn = Math.max(0, Math.max(X[e] - move, Math.min(1, Math.min(X[e] + move, xn))));
        XN[e] = xn; sum += xn;
      }
      if (sum > target) l1 = lm; else l2 = lm;
    }
    for (e = 0; e < NE; e++) X[e] = XN[e];
    /* 橋の道路（いちばん下の 1 段）は削らない */
    if (span === 'bridge') for (e = (NY - 1) * NX; e < NE; e++) X[e] = 1;
  }

  /* ------------------------------------------------ 描く */
  var SUB = 4, SW, SH, SHADE = null, simg, sbuf, sctx, OX, OY, ES;
  function layout() {
    ES = Math.min(W * 0.84 / NX, H * 0.66 / NY);
    OX = (W - ES * NX) / 2; OY = (H - ES * NY) / 2 - H * 0.02;
    SW = NX * SUB; SH = NY * SUB;
    SHADE = new Float32Array(SW * SH);
    simg = new ImageData(SW, SH);
    sbuf = document.createElement('canvas'); sbuf.width = SW; sbuf.height = SH;
    sctx = sbuf.getContext('2d');
  }
  var BG = P.bg, LN = P.line, ST = P.steel, DEEP = P.deep;
  function render() {
    if (!SHADE) layout();
    var i, j;
    /* 要素の中心で持っている密度を、細かい格子へなめらかに広げる */
    for (j = 0; j < SH; j++) {
      var gy = (j + 0.5) / SUB - 0.5, y0 = Math.max(0, Math.min(NY - 1, Math.floor(gy))), y1 = Math.min(NY - 1, y0 + 1), fy = Math.max(0, Math.min(1, gy - y0));
      for (i = 0; i < SW; i++) {
        var gx = (i + 0.5) / SUB - 0.5, x0 = Math.max(0, Math.min(NX - 1, Math.floor(gx))), x1 = Math.min(NX - 1, x0 + 1), fx = Math.max(0, Math.min(1, gx - x0));
        var a = X[y0 * NX + x0] * (1 - fx) + X[y0 * NX + x1] * fx, b = X[y1 * NX + x0] * (1 - fx) + X[y1 * NX + x1] * fx;
        SHADE[j * SW + i] = a * (1 - fy) + b * fy;
      }
    }
    var d = simg.data;
    for (j = 0; j < SH; j++) for (i = 0; i < SW; i++) {
      var k = j * SW + i, v = SHADE[k], o = k * 4;
      var gxx = (i > 0 && i < SW - 1) ? (SHADE[k + 1] - SHADE[k - 1]) * 0.5 : 0;
      var gyy = (j > 0 && j < SH - 1) ? (SHADE[k + SW] - SHADE[k - SW]) * 0.5 : 0;
      var g = Math.sqrt(gxx * gxx + gyy * gyy) + 1e-4;
      /* 材料は鋼色の面、輪郭は白い線、削れたところは藍に沈む */
      var fill = Math.max(0, Math.min(1, (v - 0.25) / 0.5));
      fill = fill * fill * (3 - 2 * fill) * 0.42;
      var r = BG[0] + (ST[0] - BG[0]) * fill, gg = BG[1] + (ST[1] - BG[1]) * fill, bb = BG[2] + (ST[2] - BG[2]) * fill;
      var dist = Math.abs(v - 0.5) / g;
      var line = Math.max(0, 1 - dist / 1.1) * 0.85;
      r += (LN[0] - r) * line; gg += (LN[1] - gg) * line; bb += (LN[2] - bb) * line;
      d[o] = r; d[o + 1] = gg; d[o + 2] = bb; d[o + 3] = 255;
    }
    sctx.putImageData(simg, 0, 0);

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.6 });
    /* 設計できる範囲を薄い枠で示す */
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.lineWidth = 1;
    ctx.setLineDash([4, 6]);
    ctx.strokeRect(OX - 0.5, OY - 0.5, ES * NX + 1, ES * NY + 1);
    ctx.setLineDash([]);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(sbuf, OX, OY, ES * NX, ES * NY);
    drawSupports();
    drawLoad();
  }
  function hatch(x, y, w, horizontal) {
    ctx.beginPath();
    if (horizontal) {
      ctx.moveTo(x - w, y); ctx.lineTo(x + w, y);
      for (var t = -w; t < w; t += 6) { ctx.moveTo(x + t, y); ctx.lineTo(x + t - 5, y + 6); }
    } else {
      ctx.moveTo(x, y - w); ctx.lineTo(x, y + w);
      for (var s = -w; s < w; s += 6) { ctx.moveTo(x, y + s); ctx.lineTo(x - 6, y + s + 5); }
    }
    ctx.stroke();
  }
  function drawSupports() {
    ctx.strokeStyle = P.rgba(P.line, 0.6);
    ctx.lineWidth = 1;
    if (span === 'bridge') {
      [OX + ES, OX + ES * (NX - 1)].forEach(function (x) {
        var y = OY + ES * NY;
        ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x - 9, y + 14); ctx.lineTo(x + 9, y + 14); ctx.closePath(); ctx.stroke();
        hatch(x, y + 16, 14, true);
      });
    } else {
      hatch(OX - 2, OY + ES * NY / 2, ES * NY / 2 + 6, false);
    }
  }
  function drawLoad() {
    var x = OX + loadX * ES, y = OY + loadY * ES;
    var len = 44;
    ctx.strokeStyle = P.rgba(P.accent, 0.9);
    ctx.fillStyle = P.rgba(P.accent, 0.9);
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(x, y - len); ctx.lineTo(x, y - 5); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x, y - 1); ctx.lineTo(x - 5, y - 10); ctx.lineTo(x + 5, y - 10); ctx.closePath(); ctx.fill();
    var g = ctx.createRadialGradient(x, y, 0, x, y, 22);
    g.addColorStop(0, P.rgba(P.accent, 0.25)); g.addColorStop(1, P.rgba(P.accent, 0));
    ctx.fillStyle = g;
    ctx.beginPath(); ctx.arc(x, y, 22, 0, Math.PI * 2); ctx.fill();
  }

  /* ------------------------------------------------ 操作 */
  var down = false, idle = 99, autoT = 0, wantX = 0, wantY = 0;
  function toGrid(e) {
    return [Math.max(0, Math.min(NX, (e.clientX - OX) / ES)), Math.max(0, Math.min(NY, (e.clientY - OY) / ES))];
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; idle = 0;
    var g = toGrid(e); wantX = g[0]; wantY = g[1];
    SFX.play('collide');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var g = toGrid(e); wantX = g[0]; wantY = g[1]; idle = 0;
    SFX.play('move', 240);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var km = document.getElementById('k-mass');
  km.addEventListener('input', function () { mass = this.value / 100; });
  mass = km.value / 100;
  document.querySelectorAll('[data-span]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-span]').forEach(function (o) { o.classList.toggle('active', o === b); });
      span = b.dataset.span;
      build(); idle = 99; autoT = 0;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-reset').addEventListener('click', function () { build(); autoT = 0; SFX.play('click'); });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ------------------------------------------------ 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    /* 材料の量は MASS で決まる。最初は板いっぱいから少しずつ削る */
    volT = 0.2 + mass * 0.4;
    vol += (volT - vol) * Math.min(1, dt * 0.45);
    idle += dt;
    if (idle > 7) {
      /* 放っておくと、重りがゆっくり行き来する */
      autoT += dt;
      var s = Math.sin(autoT * 2 * Math.PI / 46);
      if (span === 'bridge') { wantX = NX * (0.5 + 0.32 * s); wantY = NY; }
      else { wantX = NX; wantY = NY * (0.5 + 0.38 * s); }
    }
    loadX += (wantX - loadX) * Math.min(1, dt * (idle > 7 ? 0.8 : 4));
    loadY += (wantY - loadY) * Math.min(1, dt * (idle > 7 ? 0.8 : 4));
    optimize();
    render();
    chime += dt;
    if (chime > 8) { chime = 0; SFX.play('tick', 4000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
