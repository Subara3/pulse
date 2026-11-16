<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('ribbon');

$pageName = 'ribbon';
$pageTitle = 'RIBBON - PULSE';
$pageDesc = '棒の先につけた長いリボンが宙を舞い、ねじれながら小さな輪の連なりや蛇行、大きな輪、8の字を順に描いていく。押すといちばん近いリボンの先が指の下へ来て水色に光り、なぞるとリボンが指の描いた線をたどる。';
$pageCanonical = 'https://pulse.subara3.com/ribbon.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-length">LENGTH</label><input type="range" id="k-length" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-count">COUNT</label><input type="range" id="k-count" min="1" max="5" value="3"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, S = 400, ctx;

  /* リボン 1 本 = 棒の先（tip）につながった点の鎖。点は Verlet で動かす */
  var NP = 84;
  var ribbons = [];
  var speedK = 0.45, lenK = 0.5, count = 3;
  var clock = 0, figT = 0;
  var down = false, held = null, px = 0, py = 0;
  var SUB = 1 / 120;

  /* 棒の先が描く 4 つの形。重みを少しずつ入れ替えて、形から形へなめらかに移る */
  var FIGS = [
    { r: 0.075, f: 0.7, kind: 0 },   /* 小さな輪の連なり */
    { r: 0.09, f: 0.6, kind: 1 },  /* 蛇行 */
    { r: 0.24, f: 0.26, kind: 0 },  /* 大きな輪 */
    { r: 0.22, f: 0.22, kind: 2 }   /* 8 の字 */
  ];

  function makeRibbon(k) {
    var rb = {
      k: k, off: k * 2.1 + 0.7,
      x: new Float32Array(NP), y: new Float32Array(NP),
      ox: new Float32Array(NP), oy: new Float32Array(NP),
      tw: new Float32Array(NP),
      ph: new Float32Array(FIGS.length),
      tx: 0, ty: 0, ang: 0, rel: 9, glow: 0,
      path: [], pathT: 0
    };
    var a = autoTip(rb);
    rb.tx = a[0]; rb.ty = a[1];
    for (var i = 0; i < NP; i++) {
      rb.x[i] = rb.ox[i] = rb.tx;
      rb.y[i] = rb.oy[i] = rb.ty + i * segLen() * 0.3;
    }
    return rb;
  }

  function segLen() { return S * (0.6 + lenK * 1.2) / NP; }

  /* 今どの形をどれだけ描くか。1 つの形を 9 秒、その後 3 秒かけて次へ移る */
  function figWeights(rb) {
    var per = 12, t = figT / per + rb.k * 0.37, i = Math.floor(t) % FIGS.length, f = t - Math.floor(t);
    var w = [0, 0, 0, 0], m = f < 0.75 ? 0 : (f - 0.75) / 0.25;
    m = m * m * (3 - 2 * m);
    w[i] = 1 - m; w[(i + 1) % FIGS.length] = m;
    return w;
  }

  function autoTip(rb) {
    var o = rb.off;
    var bx = W * (0.5 + 0.3 * Math.sin(clock * 0.061 + o) + 0.04 * Math.sin(clock * 0.17 + o * 2));
    var by = H * (0.42 + 0.16 * Math.sin(clock * 0.083 + o * 1.7));
    var w = figWeights(rb), x = bx, y = by;
    for (var j = 0; j < FIGS.length; j++) {
      if (w[j] <= 0) continue;
      var F = FIGS[j], r = F.r * S, p = rb.ph[j], dx, dy;
      if (F.kind === 0) { dx = Math.cos(p) * r; dy = Math.sin(p) * r; }
      else if (F.kind === 1) { dx = Math.sin(p * 0.5) * r * 1.6; dy = Math.sin(p) * r; }
      else { dx = Math.sin(p) * r * 1.3; dy = Math.sin(p * 2) * r * 0.55; }
      x += dx * w[j]; y += dy * w[j];
    }
    return [x, y];
  }

  function resetAll() {
    ribbons.length = 0;
    for (var k = 0; k < count; k++) ribbons.push(makeRibbon(k));
    for (var s = 0; s < 600; s++) simulate(SUB * 2, false);   /* 開いたときから舞っているように、先に 10 秒ぶん回す */
  }

  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; S = Math.min(w, h * 1.25);
    resetAll();
  });

  /* ---------------------------------------------------------- 動き */
  function stepRibbon(rb, dt, live) {
    var sp = 0.35 + speedK * 1.3;
    for (var j = 0; j < FIGS.length; j++) rb.ph[j] += dt * sp * FIGS[j].f * Math.PI * 2;

    var tgt;
    if (rb === held) { tgt = [px, py]; rb.rel = 0; }
    else { tgt = autoTip(rb); rb.rel += dt; }
    var rate = rb === held ? 30 : Math.min(10, 1.5 + rb.rel * 5);
    var k = 1 - Math.exp(-rate * dt);
    var ntx = rb.tx + (tgt[0] - rb.tx) * k, nty = rb.ty + (tgt[1] - rb.ty) * k;

    /* 先の向きの変わり方をねじれとして根元に入れる。ねじれは鎖に沿って先へ流れていく */
    var vx = ntx - rb.tx, vy = nty - rb.ty;
    if (vx * vx + vy * vy > 0.01) {
      var a = Math.atan2(vy, vx), d = a - rb.ang;
      while (d > Math.PI) d -= Math.PI * 2;
      while (d < -Math.PI) d += Math.PI * 2;
      rb.tw[0] += d * 0.9;
      rb.ang = a;
    }
    rb.tw[0] += dt * 0.4 * Math.sin(clock * 0.3 + rb.off);
    rb.tx = ntx; rb.ty = nty;

    var X = rb.x, Y = rb.y, OX = rb.ox, OY = rb.oy;
    var g = S * 0.05 * dt * dt, damp = Math.pow(0.12, dt);   /* 空気が強く効く: 速さは 1 秒で 12% まで落ちる。重さは軽い */
    X[0] = OX[0] = rb.tx; Y[0] = OY[0] = rb.ty;
    for (var i = 1; i < NP; i++) {
      var nx = X[i] + (X[i] - OX[i]) * damp, ny = Y[i] + (Y[i] - OY[i]) * damp + g;
      OX[i] = X[i]; OY[i] = Y[i]; X[i] = nx; Y[i] = ny;
    }
    var L = segLen();
    for (var it = 0; it < 3; it++) {
      for (i = 1; i < NP; i++) {
        var ex = X[i] - X[i - 1], ey = Y[i] - Y[i - 1], dd = Math.sqrt(ex * ex + ey * ey) || 1e-6;
        var c = (dd - L) / dd;
        if (i === 1) { X[i] -= ex * c; Y[i] -= ey * c; }
        else { X[i] -= ex * c * 0.5; Y[i] -= ey * c * 0.5; X[i - 1] += ex * c * 0.5; Y[i - 1] += ey * c * 0.5; }
      }
    }
    /* 折れ目をならす: 隣の 2 点の中点へ少し寄せる。リボンにわずかな張りが出る */
    for (i = 2; i < NP - 1; i++) {
      X[i] += ((X[i - 1] + X[i + 1]) * 0.5 - X[i]) * 0.12;
      Y[i] += ((Y[i - 1] + Y[i + 1]) * 0.5 - Y[i]) * 0.12;
    }
    /* ねじれの移流: 1 点ずつ根元側の値に寄せる（先から順に。前の値を使うので波として流れる） */
    var T = rb.tw, q = Math.min(1, dt * 40);
    for (i = NP - 1; i > 0; i--) T[i] += (T[i - 1] - T[i]) * q;

    if (live) {
      rb.pathT += dt;
      if (rb.pathT > 1 / 30) { rb.pathT = 0; rb.path.push(rb.tx, rb.ty); if (rb.path.length > 240) rb.path.splice(0, 2); }
    }
    if (rb !== held) rb.glow = Math.max(0, rb.glow - dt * 0.5);
  }

  function simulate(dt, live) {
    clock += dt;
    figT += dt * (0.5 + speedK);
    for (var n = 0; n < ribbons.length; n++) stepRibbon(ribbons[n], dt, live);
  }

  /* 指のまわりの鎖を外へ押す（Verlet なので位置を動かせば速さもつく） */
  function push(x, y, R, f) {
    for (var n = 0; n < ribbons.length; n++) {
      var rb = ribbons[n];
      if (rb === held) continue;
      for (var i = 1; i < NP; i++) {
        var dx = rb.x[i] - x, dy = rb.y[i] - y, d = Math.sqrt(dx * dx + dy * dy);
        if (d < R && d > 0.1) {
          var m = (1 - d / R) * f;
          rb.x[i] += dx / d * m; rb.y[i] += dy / d * m;
        }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function drawRibbon(rb) {
    var X = rb.x, Y = rb.y, T = rb.tw, hw = Math.max(4, S * 0.011);
    var lx = [], ly = [], rx = [], ry = [], cs = [];
    for (var i = 0; i < NP; i++) {
      var a = i === 0 ? 0 : i - 1, b = i === NP - 1 ? NP - 1 : i + 1;
      var dx = X[b] - X[a], dy = Y[b] - Y[a], d = Math.sqrt(dx * dx + dy * dy) || 1;
      var c = Math.cos(T[i] + i * 0.06 + Math.sin(clock * 0.5 + rb.off + i * 0.04) * 1.2);
      var taper = i > NP - 6 ? (NP - i) / 6 : i < 3 ? 0.4 + i * 0.2 : 1;
      var w = hw * c * taper;
      lx.push(X[i] - dy / d * w); ly.push(Y[i] + dx / d * w);
      rx.push(X[i] + dy / d * w); ry.push(Y[i] - dx / d * w);
      cs.push(c);
    }
    var gl = rb.glow;
    for (i = 0; i < NP - 1; i++) {
      var cm = (cs[i] + cs[i + 1]) * 0.5, s = Math.abs(cm);
      var col = cm >= 0 ? P.mix(P.steel, P.line, 0.35 + 0.65 * s) : P.mix(P.deep, P.steel, 0.4 + 0.6 * s);
      if (gl > 0) col = P.mix(col, P.accent, gl * 0.55);
      var al = (cm >= 0 ? 0.22 + 0.45 * s : 0.4 + 0.3 * s) * (1 - i / NP * 0.35);
      ctx.fillStyle = P.rgba(col, al);
      ctx.beginPath();
      ctx.moveTo(lx[i], ly[i]); ctx.lineTo(lx[i + 1], ly[i + 1]);
      ctx.lineTo(rx[i + 1], ry[i + 1]); ctx.lineTo(rx[i], ry[i]);
      ctx.closePath();
      ctx.fill();
    }
    /* 縁の細線 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = gl > 0 ? P.rgba(P.mix(P.line, P.accent, gl), 0.85) : P.rgba(P.line, 0.75);
    ctx.beginPath();
    ctx.moveTo(lx[0], ly[0]);
    for (i = 1; i < NP; i++) ctx.lineTo(lx[i], ly[i]);
    ctx.moveTo(rx[0], ry[0]);
    for (i = 1; i < NP; i++) ctx.lineTo(rx[i], ry[i]);
    ctx.stroke();
    /* 棒 */
    ctx.strokeStyle = P.rgba(P.steel, 0.8);
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(rb.tx, rb.ty);
    ctx.lineTo(rb.tx + S * 0.04, rb.ty + S * 0.09);
    ctx.stroke();
    ctx.fillStyle = gl > 0 ? P.rgba(P.accent, 0.4 + gl * 0.6) : P.rgba(P.line, 0.9);
    ctx.beginPath();
    ctx.arc(rb.tx, rb.ty, 2.5, 0, Math.PI * 2);
    ctx.fill();
  }

  function drawPath(rb) {
    var p = rb.path, n = p.length / 2;
    if (n < 2) return;
    ctx.lineWidth = 1;
    for (var s = 0; s < 4; s++) {
      var a0 = Math.floor(n * s / 4), a1 = Math.min(n - 1, Math.floor(n * (s + 1) / 4));
      ctx.strokeStyle = P.rgba(P.steel, 0.08 + 0.1 * s);
      ctx.beginPath();
      ctx.moveTo(p[a0 * 2], p[a0 * 2 + 1]);
      for (var i = a0 + 1; i <= a1; i++) ctx.lineTo(p[i * 2], p[i * 2 + 1]);
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  function nearest(x, y) {
    var best = null, bd = Infinity;
    for (var n = 0; n < ribbons.length; n++) {
      var rb = ribbons[n];
      for (var i = 0; i < NP; i += 4) {
        var d = Math.hypot(rb.x[i] - x, rb.y[i] - y) + i * 0.4;
        if (d < bd) { bd = d; best = rb; }
      }
    }
    return best;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY;
    held = nearest(px, py);
    if (held) { held.glow = 1; held.path.length = 0; }
    push(px, py, S * 0.22, S * 0.06);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var d = Math.hypot(e.clientX - px, e.clientY - py);
    px = e.clientX; py = e.clientY;
    push(px, py, S * 0.12, S * 0.02);
    if (d > 3) SFX.play('move', 160);
  });
  function up() {
    if (held) held.rel = 0;
    down = false; held = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speedK = document.getElementById('k-speed').value / 100; }
  function readLen() { lenK = document.getElementById('k-length').value / 100; }
  function readCount() {
    var c = +document.getElementById('k-count').value;
    if (c === count) return;
    count = c;
    while (ribbons.length > count) ribbons.pop();
    while (ribbons.length < count) ribbons.push(makeRibbon(ribbons.length));
  }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-length').addEventListener('input', readLen);
  document.getElementById('k-count').addEventListener('input', function () { readCount(); SFX.play('toggle'); });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readLen(); readCount();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), lastFig = -1;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    var n = Math.max(1, Math.round(dt / SUB));
    for (var s = 0; s < n; s++) simulate(dt / n, true);

    var fi = Math.floor(figT / 12);
    if (fi !== lastFig) { if (lastFig >= 0) SFX.play('grow', 3000); lastFig = fi; }

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.8 });
    for (var r = 0; r < ribbons.length; r++) drawPath(ribbons[r]);
    for (r = 0; r < ribbons.length; r++) drawRibbon(ribbons[r]);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
