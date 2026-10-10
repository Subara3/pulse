<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('karman');

$pageName = 'karman';
$pageTitle = 'KÁRMÁN - PULSE';
$pageDesc = '左から右へ流れる水の中に丸い柱が立っていて、その後ろで右回りと左回りの渦が交互に離れて流れていく。柱はつかんで動かせる。柱から離れたところを押すと、そこに小さな柱が立って後ろに渦ができる。';
$pageCanonical = 'https://pulse.subara3.com/karman.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-shape="circle">CIRCLE</button><button type="button" data-shape="plate">PLATE</button><button type="button" data-shape="pair">PAIR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * 流れは格子ボルツマン法（D2Q9）で解く。左から一様な流れが入り、右へ抜ける。上下はつながっている。
   * 見せるのは渦の強さの等高線だけ。右回りの渦は白、左回りの渦は鋼色の輪になって流れていく。
   */
  var CX = [0, 1, 0, -1, 0, 1, -1, -1, 1];
  var CY = [0, 0, 1, 0, -1, 1, 1, -1, -1];
  var WT = [4 / 9, 1 / 9, 1 / 9, 1 / 9, 1 / 9, 1 / 36, 1 / 36, 1 / 36, 1 / 36];
  var feq = new Float32Array(9);
  var NX, NY, N, f, g, solid, ux, uy, vort, sm, psi, cs;
  var U0 = 0.09, tau = 0.56, flowAmt = 0.5, shape = 'circle';
  var ob = { x: 0, y: 0, tx: 0, ty: 0, r: 0, held: false, lit: 0 };
  /* 柱から離れた所を押すと、そこに指先ほどの小さな柱が立つ */
  var fin = { on: false, x: 0, y: 0, r: 0, lit: 0 };
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  function alloc() {
    NX = Math.max(150, Math.min(230, Math.round(W / 6)));
    NY = Math.max(64, Math.round(NX * H / W));
    N = NX * NY;
    cs = W / NX;
    f = []; g = [];
    for (var q = 0; q < 9; q++) { f.push(new Float32Array(N)); g.push(new Float32Array(N)); }
    solid = new Uint8Array(N);
    ux = new Float32Array(N); uy = new Float32Array(N);
    vort = new Float32Array(N); sm = new Float32Array(N); psi = new Float32Array(N);
    ob.r = Math.max(6, Math.round(NY * 0.075));
    fin.r = Math.max(2.5, ob.r * 0.4);
    ob.x = ob.tx = NX * 0.2; ob.y = ob.ty = NY * 0.5;
    /* 柱のすぐ後ろに横向きの揺れを一つ置いておく。渦がちぎれ始めるまでの待ち時間が短くなる */
    for (var k = 0; k < N; k++) {
      var i = k % NX, j = (k / NX) | 0, dx = i - ob.x - ob.r * 2.5, dy = j - ob.y;
      var vy = 0.6 * U0 * Math.exp(-dx * dx / (ob.r * ob.r * 5) - dy * dy / (ob.r * ob.r * 2.5));
      equil(k, 1, U0, vy);
    }
    mask();
    /* 開いた瞬間から渦の列が見えるように、先に回しておく */
    for (var s = 0; s < 1100; s++) step();
    measure();
  }

  function equil(k, rho, vx, vy) {
    var uu = vx * vx + vy * vy;
    for (var q = 0; q < 9; q++) {
      var cu = CX[q] * vx + CY[q] * vy;
      f[q][k] = WT[q] * rho * (1 + 3 * cu + 4.5 * cu * cu - 1.5 * uu);
    }
  }

  function isSolid(i, j) {
    if (fin.on) {
      var fx = i - fin.x, fy = j - fin.y;
      if (fx * fx + fy * fy < fin.r * fin.r) return true;
    }
    var dx = i - ob.x, dy = j - ob.y, r = ob.r;
    if (dy > NY / 2) dy -= NY; else if (dy < -NY / 2) dy += NY;
    if (shape === 'plate') return Math.abs(dx) < 1.6 && Math.abs(dy) < r * 1.15;
    if (shape === 'pair') {
      var r2 = r * 0.68, s = r * 1.25;
      return dx * dx + (dy - s) * (dy - s) < r2 * r2 || dx * dx + (dy + s) * (dy + s) < r2 * r2;
    }
    return dx * dx + dy * dy < r * r;
  }

  /* 柱の場所を格子に写す。柱がどいたマスには、止まった水を入れる */
  function mask() {
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var k = j * NX + i, s = isSolid(i, j) ? 1 : 0;
        if (solid[k] && !s) equil(k, 1, 0, 0);
        if (s) { ux[k] = 0; uy[k] = 0; }
        solid[k] = s;
      }
    }
  }

  /* 1 歩: 隣から分布を引いてきて（柱なら跳ね返し）、その場で平衡へ緩める */
  function step() {
    var om = 1 / tau, q;
    for (q = 0; q < 9; q++) { var cu = CX[q] * U0; feq[q] = WT[q] * (1 + 3 * cu + 4.5 * cu * cu - 1.5 * U0 * U0); }
    var f0 = f[0], f1 = f[1], f2 = f[2], f3 = f[3], f4 = f[4], f5 = f[5], f6 = f[6], f7 = f[7], f8 = f[8];
    var g0 = g[0], g1 = g[1], g2 = g[2], g3 = g[3], g4 = g[4], g5 = g[5], g6 = g[6], g7 = g[7], g8 = g[8];
    var e1 = feq[1], e5 = feq[5], e8 = feq[8];
    for (var j = 0; j < NY; j++) {
      var jm = (j === 0 ? NY - 1 : j - 1) * NX, jp = (j === NY - 1 ? 0 : j + 1) * NX, jr = j * NX;
      for (var i = 0; i < NX; i++) {
        var k = jr + i;
        if (solid[k]) continue;
        var L = i > 0, R = i < NX - 1, a, b;
        /* 入口（左端）には一様な流れが入り、出口（右端）は素通し */
        var v0 = f0[k];
        var v1 = L ? (solid[a = jr + i - 1] ? f3[k] : f1[a]) : e1;
        var v2 = solid[b = jm + i] ? f4[k] : f2[b];
        var v3 = R ? (solid[a = jr + i + 1] ? f1[k] : f3[a]) : f3[k];
        var v4 = solid[b = jp + i] ? f2[k] : f4[b];
        var v5 = L ? (solid[a = jm + i - 1] ? f7[k] : f5[a]) : e5;
        var v6 = R ? (solid[a = jm + i + 1] ? f8[k] : f6[a]) : f6[k];
        var v7 = R ? (solid[a = jp + i + 1] ? f5[k] : f7[a]) : f7[k];
        var v8 = L ? (solid[a = jp + i - 1] ? f6[k] : f8[a]) : e8;
        var rho = v0 + v1 + v2 + v3 + v4 + v5 + v6 + v7 + v8;
        var vx = (v1 - v3 + v5 - v6 - v7 + v8) / rho, vy = (v2 - v4 + v5 + v6 - v7 - v8) / rho;
        var uu = 1.5 * (vx * vx + vy * vy), r9 = rho / 9, r36 = rho / 36, c;
        g0[k] = v0 + om * (4 * r9 * (1 - uu) - v0);
        c = vx;       g1[k] = v1 + om * (r9 * (1 + 3 * c + 4.5 * c * c - uu) - v1);
        c = vy;       g2[k] = v2 + om * (r9 * (1 + 3 * c + 4.5 * c * c - uu) - v2);
        c = -vx;      g3[k] = v3 + om * (r9 * (1 + 3 * c + 4.5 * c * c - uu) - v3);
        c = -vy;      g4[k] = v4 + om * (r9 * (1 + 3 * c + 4.5 * c * c - uu) - v4);
        c = vx + vy;  g5[k] = v5 + om * (r36 * (1 + 3 * c + 4.5 * c * c - uu) - v5);
        c = vy - vx;  g6[k] = v6 + om * (r36 * (1 + 3 * c + 4.5 * c * c - uu) - v6);
        c = -vx - vy; g7[k] = v7 + om * (r36 * (1 + 3 * c + 4.5 * c * c - uu) - v7);
        c = vx - vy;  g8[k] = v8 + om * (r36 * (1 + 3 * c + 4.5 * c * c - uu) - v8);
        ux[k] = vx; uy[k] = vy;
      }
    }
    var t = f; f = g; g = t;
  }

  /* 渦の強さを出して、少しならす */
  function measure() {
    var i, j, k;
    for (j = 0; j < NY; j++) {
      var jp = (j + 1) % NY, jm = (j - 1 + NY) % NY;
      for (i = 1; i < NX - 1; i++) {
        k = j * NX + i;
        vort[k] = solid[k] ? 0 : (uy[k + 1] - uy[k - 1]) - (ux[jp * NX + i] - ux[jm * NX + i]);
      }
      vort[j * NX] = vort[j * NX + NX - 1] = 0;
    }
    for (j = 0; j < NY; j++) {
      var a = ((j - 1 + NY) % NY) * NX, b = j * NX, c = ((j + 1) % NY) * NX;
      for (i = 1; i < NX - 1; i++) {
        sm[b + i] = (vort[a + i - 1] + vort[a + i] + vort[a + i + 1] + vort[b + i - 1] + vort[b + i] * 2 + vort[b + i + 1]
                   + vort[c + i - 1] + vort[c + i] + vort[c + i + 1]) * 0.1;
      }
    }
  }

  /* 流れ関数: 各列で ux を下から積み上げる。列ごとの総流量をそろえて、遠くの流線が水平に並ぶようにする */
  var psiMin = 0, psiMax = 0;
  function streamFn() {
    var i, j, k, ref = U0 * NY;
    psiMin = 0; psiMax = ref;
    for (i = 0; i < NX; i++) {
      var q = 0;
      for (j = 0; j < NY; j++) q += ux[j * NX + i];
      var sc = q > 1e-6 ? ref / q : 0, acc = 0;
      for (j = 0; j < NY; j++) {
        k = j * NX + i;
        psi[k] = acc;
        acc += ux[k] * sc;
      }
    }
  }

  /* マーチングスクエアで、ある高さの等高線を path に積む */
  function contour(F, level, i0) {
    for (var j = 0; j < NY - 1; j++) {
      var y0 = (j + 0.5) * cs, y1 = y0 + cs;
      for (var i = i0; i < NX - 2; i++) {
        var k = j * NX + i;
        var a = F[k] - level, b = F[k + 1] - level, c = F[k + NX + 1] - level, d = F[k + NX] - level;
        var idx = (a > 0 ? 1 : 0) | (b > 0 ? 2 : 0) | (c > 0 ? 4 : 0) | (d > 0 ? 8 : 0);
        if (idx === 0 || idx === 15) continue;
        var x0 = (i + 0.5) * cs, x1 = x0 + cs;
        var tx = x0 + cs * a / (a - b), bx = x0 + cs * d / (d - c);
        var ly = y0 + cs * a / (a - d), ry = y0 + cs * b / (b - c);
        switch (idx) {
          case 1: case 14: ctx.moveTo(x0, ly); ctx.lineTo(tx, y0); break;
          case 2: case 13: ctx.moveTo(tx, y0); ctx.lineTo(x1, ry); break;
          case 3: case 12: ctx.moveTo(x0, ly); ctx.lineTo(x1, ry); break;
          case 4: case 11: ctx.moveTo(x1, ry); ctx.lineTo(bx, y1); break;
          case 6: case 9: ctx.moveTo(tx, y0); ctx.lineTo(bx, y1); break;
          case 7: case 8: ctx.moveTo(x0, ly); ctx.lineTo(bx, y1); break;
          case 5: ctx.moveTo(x0, ly); ctx.lineTo(tx, y0); ctx.moveTo(x1, ry); ctx.lineTo(bx, y1); break;
          case 10: ctx.moveTo(tx, y0); ctx.lineTo(x1, ry); ctx.moveTo(x0, ly); ctx.lineTo(bx, y1); break;
        }
      }
    }
  }

  var LEVELS = 7;
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.55 });
    var base = U0 / ob.r * 0.16;
    ctx.lineWidth = 1;
    ctx.lineJoin = 'round';
    /* 流線（流れ関数の等高線）。一様な流れが柱をよけ、後ろでうねる様子を薄く引く */
    var gap = U0 * NY / 26, lo = Math.floor(psiMin / gap), hi = Math.ceil(psiMax / gap);
    ctx.strokeStyle = P.rgba(P.steel, 0.32);
    ctx.beginPath();
    for (var m = lo; m <= hi; m++) contour(psi, (m + 0.5) * gap, 1);
    ctx.stroke();
    for (var n = 1; n <= LEVELS; n++) {
      var lv = base * Math.pow(n, 1.6), a = 0.16 + 0.55 * n / LEVELS;
      ctx.strokeStyle = P.rgba(P.line, a);
      ctx.beginPath(); contour(sm, -lv, 12); ctx.stroke();
      ctx.strokeStyle = P.rgba(P.steel, a * 1.15);
      ctx.beginPath(); contour(sm, lv, 12); ctx.stroke();
    }
    drawBody();
  }

  /* 柱の輪郭。つかんでいる間と、動かした直後だけシアン */
  function drawBody() {
    var col = P.mix(P.line, P.accent, ob.lit), x = (ob.x + 0.5) * cs, y = (ob.y + 0.5) * cs, r = ob.r * cs;
    ctx.save();
    ctx.lineWidth = 1.4;
    ctx.strokeStyle = P.rgba(col, 0.9);
    ctx.fillStyle = P.rgba(P.deep, 0.9);
    ctx.beginPath();
    if (shape === 'plate') {
      ctx.rect(x - 1.6 * cs, y - r * 1.15, 3.2 * cs, r * 2.3);
    } else if (shape === 'pair') {
      ctx.arc(x, y - r * 1.25, r * 0.68, 0, Math.PI * 2);
      ctx.moveTo(x + r * 0.68, y + r * 1.25);
      ctx.arc(x, y + r * 1.25, r * 0.68, 0, Math.PI * 2);
    } else {
      ctx.arc(x, y, r, 0, Math.PI * 2);
    }
    ctx.fill(); ctx.stroke();
    /* 図面の中心線 */
    ctx.strokeStyle = P.rgba(col, 0.35);
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x - r * 1.6, y); ctx.lineTo(x + r * 1.6, y);
    ctx.moveTo(x, y - r * (shape === 'circle' ? 1.6 : 2.4)); ctx.lineTo(x, y + r * (shape === 'circle' ? 1.6 : 2.4));
    ctx.stroke();
    if (fin.lit > 0.01) {
      ctx.strokeStyle = P.rgba(P.accent, 0.85 * fin.lit);
      ctx.lineWidth = 1.4;
      ctx.beginPath();
      ctx.arc((fin.x + 0.5) * cs, (fin.y + 0.5) * cs, fin.r * cs, 0, Math.PI * 2);
      ctx.fill(); ctx.stroke();
    }
    ctx.restore();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false;
  function gridPos(e) { return { x: e.clientX / cs - 0.5, y: e.clientY / cs - 0.5 }; }
  function nearBody(p) {
    var dy = p.y - ob.y, reach = ob.r * (shape === 'circle' ? 1.8 : 2.6);
    return Math.abs(p.x - ob.x) < ob.r * 1.8 && Math.abs(dy) < reach;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    var p = gridPos(e);
    if (nearBody(p)) {
      ob.held = true;
      SFX.play('tick', 200);
    } else {
      fin.on = true; fin.x = p.x; fin.y = p.y;
      mask();
      SFX.play('collide', 200);
    }
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = gridPos(e);
    if (ob.held) {
      ob.tx = Math.max(ob.r + 4, Math.min(NX * 0.6, p.x)); ob.ty = p.y;
    } else if (fin.on) {
      fin.x += (p.x - fin.x) * 0.5; fin.y += (p.y - fin.y) * 0.5;
      fin.x = Math.max(fin.r + 2, Math.min(NX - fin.r - 2, fin.x));
      mask();
    }
    SFX.play('move', 220);
  });
  function up() {
    down = false; ob.held = false;
    if (fin.on) { fin.on = false; mask(); }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function setFlow(v) {
    flowAmt = v;
    U0 = 0.06 + v * 0.06;
    tau = 0.58 - v * 0.04;
  }
  document.getElementById('k-flow').addEventListener('input', function () { setFlow(this.value / 100); });
  document.querySelectorAll('[data-shape]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-shape]').forEach(function (o) { o.classList.toggle('active', o === b); });
      shape = b.dataset.shape;
      mask();
      ob.lit = 1;
      SFX.play('toggle');
    });
  });
  setFlow(document.getElementById('k-flow').value / 100);
  var act = document.querySelector('[data-shape].active');
  if (act && act.dataset.shape !== shape) { shape = act.dataset.shape; mask(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), clock = 0, acc = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;
    /* 触っていないときは、柱がゆっくり上下に漂う */
    if (!ob.held) ob.ty += (NY * (0.5 + 0.12 * Math.sin(clock * 0.09)) - ob.ty) * Math.min(1, dt * 0.15);
    var px = ob.x, py = ob.y;
    var k = Math.min(1, dt * (ob.held ? 6 : 1.5));
    ob.x += (ob.tx - ob.x) * k; ob.y += (ob.ty - ob.y) * k;
    var movedBy = Math.hypot(ob.x - px, ob.y - py);
    if (movedBy > 0.02) mask();
    fin.lit = fin.on ? Math.min(1, fin.lit + dt * 5) : Math.max(0, fin.lit - dt * 3);
    ob.lit = ob.held ? Math.min(1, ob.lit + dt * 4) : Math.max(0, ob.lit - dt * 0.8);
    /* 1 秒あたりの歩数を決めておき、画面の速さに左右されないようにする */
    acc += dt * 180;
    var n = 0;
    while (acc >= 1 && n < 8) { step(); acc -= 1; n++; }
    if (acc > 1) acc = 1;
    measure();
    streamFn();
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
