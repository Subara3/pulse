<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cloth');

$pageName = 'cloth';
$pageTitle = 'CLOTH - PULSE';
$pageDesc = '上の棒から布が垂れ下がり、風でゆっくりふくらんだりしぼんだりする。押すとその部分がへこみ、なぞると動かした向きに布が引っぱられる。';
$pageCanonical = 'https://pulse.subara3.com/cloth.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="20"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="silk">SILK</button><button type="button" data-look="net">NET</button></div>
  <div class="ctl-btns"><button type="button" id="k-reset">RESET</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, sized = false;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; if (sized) build(); sized = true; });

  /*
   * 点を格子に並べ、隣どうしの距離を保つ（Verlet 積分 + 距離拘束）。
   * 点は奥行き z も持つので、風で前後にふくらむ。投影して描き、面の向きで明るさを決める。
   */
  var CW = 0, CH = 0, NP = 0, px, py, pz, ox, oy, oz, pin, heat, sx, sy, br;
  var links = [], LA, LB, LR, NL = 0;
  var S = 10, top = 0, left = 0, FOC = 1100, RS = 1;

  /* 布の大きさは画面に合わせる。横長でも縦長でも、幅と高さの大半を使い、点の数はほぼ一定（約 1400） */
  function build() {
    var portrait = H > W;
    var bw = W * (portrait ? 0.82 : 0.62), bh = H * (portrait ? 0.62 : 0.72);
    S = Math.sqrt(bw * bh / 1400);
    CW = Math.max(12, Math.round(bw / S) + 1); CH = Math.max(10, Math.round(bh / S) + 1);
    S = Math.min(bw / (CW - 1), bh / (CH - 1));
    RS = Math.max(0.5, Math.min(1.2, Math.min(W, H) / 720));   // 指の効く範囲も画面に合わせる
    NP = CW * CH;
    px = new Float32Array(NP); py = new Float32Array(NP); pz = new Float32Array(NP);
    ox = new Float32Array(NP); oy = new Float32Array(NP); oz = new Float32Array(NP);
    pin = new Uint8Array(NP); heat = new Float32Array(NP);
    sx = new Float32Array(NP); sy = new Float32Array(NP); br = new Float32Array(NP);
    left = W / 2 - S * (CW - 1) / 2;
    top = H * (portrait ? 0.14 : 0.12);
    for (var j = 0; j < CH; j++) for (var i = 0; i < CW; i++) {
      var k = j * CW + i;
      px[k] = ox[k] = left + i * S;
      py[k] = oy[k] = top + j * S * 0.97;          // ほぼ垂れ下がった状態から始める
      pz[k] = oz[k] = (Math.random() - 0.5) * 2;
      pin[k] = j === 0 && (i % 5 === 0 || i === CW - 1) ? 1 : 0;
    }
    links = [];
    function L(a, b, r) { links.push(a, b, r); }
    for (var j2 = 0; j2 < CH; j2++) for (var i2 = 0; i2 < CW; i2++) {
      var k2 = j2 * CW + i2;
      if (i2 < CW - 1) L(k2, k2 + 1, S);
      if (j2 < CH - 1) L(k2, k2 + CW, S);
      if (i2 < CW - 2) L(k2, k2 + 2, S * 2);       // 曲げにくさ
      if (j2 < CH - 2) L(k2, k2 + CW * 2, S * 2);
    }
    NL = links.length / 3;
    LA = new Int32Array(NL); LB = new Int32Array(NL); LR = new Float32Array(NL);
    for (var n = 0; n < NL; n++) { LA[n] = links[n * 3]; LB[n] = links[n * 3 + 1]; LR[n] = links[n * 3 + 2]; }
  }

  var t = 0, wind = 0.45, look = 'silk';
  var touch = { on: false, x: 0, y: 0, dx: 0, dy: 0, s: 0 };

  function step(h) {
    t += h;
    var g = 700 * h * h, wz = wind * 1900 * h * h;
    var tr = 150 * RS;
    for (var k = 0; k < NP; k++) {
      if (pin[k]) continue;
      var x = px[k], y = py[k], z = pz[k];
      var vx = (x - ox[k]) * 0.984, vy = (y - oy[k]) * 0.984, vz = (z - oz[k]) * 0.98;
      ox[k] = x; oy[k] = y; oz[k] = z;
      var i = k % CW, j = (k / CW) | 0;
      /* 風: 横から斜めに渡っていく大きなうねり＋ときどきの突風 */
      var gust = 0.45 + 0.3 * Math.sin(t * 0.21) * Math.sin(t * 0.13 + 1.7);
      var w = (Math.sin(t * 0.9 - i * 0.16 + j * 0.05) + 0.6 * Math.sin(t * 1.55 - j * 0.21 + i * 0.07)) * gust;
      px[k] = x + vx;
      py[k] = y + vy + g;
      pz[k] = z + vz + wz * w;
      /* 指: 投影した位置の近くを奥へ押し込み、なぞった向きに少し引く */
      if (touch.s > 0.01) {
        var dx = sx[k] - touch.x, dy = sy[k] - touch.y, d2 = dx * dx + dy * dy;
        if (d2 < tr * tr) {
          var f = 1 - Math.sqrt(d2) / tr; f = f * f * touch.s;
          /* 押し込みは位置だけ動かし、勢いはほとんど残さない（暴れないように） */
          var want = 180 * RS * f;
          if (pz[k] < want) { var dz = (want - pz[k]) * 0.3; pz[k] += dz; oz[k] += dz * 0.8; }
        }
      }
    }
    for (var it = 0; it < 8; it++) {
      for (var n = 0; n < NL; n++) {
        var a = LA[n], b = LB[n];
        var ex = px[b] - px[a], ey = py[b] - py[a], ez = pz[b] - pz[a];
        var d = Math.sqrt(ex * ex + ey * ey + ez * ez) || 1e-6;
        var r = LR[n];
        if (r > S * 1.5 && d < r) continue;          // 曲げのばねは縮みには効かせない
        var df = (d - r) / d * 0.5;
        var pa = pin[a], pb = pin[b];
        if (pa && pb) continue;
        if (pa) df *= 2; else if (pb) df *= 2;
        if (!pa) { px[a] += ex * df; py[a] += ey * df; pz[a] += ez * df; }
        if (!pb) { px[b] -= ex * df; py[b] -= ey * df; pz[b] -= ez * df; }
      }
    }
  }

  /* 投影と明るさ（面の法線と斜め上からの光） */
  var Lx = -0.62, Ly = -0.34, Lz = 0.71;
  function project() {
    var cx = W / 2, cy = H * 0.42;
    for (var k = 0; k < NP; k++) {
      var s = FOC / (FOC + pz[k]);
      sx[k] = cx + (px[k] - cx) * s; sy[k] = cy + (py[k] - cy) * s;
    }
    for (var j = 0; j < CH; j++) for (var i = 0; i < CW; i++) {
      var k2 = j * CW + i;
      var kl = i > 0 ? k2 - 1 : k2, kr = i < CW - 1 ? k2 + 1 : k2;
      var ku = j > 0 ? k2 - CW : k2, kd = j < CH - 1 ? k2 + CW : k2;
      var ax = px[kr] - px[kl], ay = py[kr] - py[kl], az = pz[kr] - pz[kl];
      var bx = px[kd] - px[ku], by = py[kd] - py[ku], bz = pz[kd] - pz[ku];
      var nx = ay * bz - az * by, ny = az * bx - ax * bz, nz = ax * by - ay * bx;
      var nl = Math.sqrt(nx * nx + ny * ny + nz * nz) || 1;
      var dot = (nx * Lx + ny * Ly - nz * Lz) / nl;
      br[k2] = Math.abs(dot);
    }
  }

  var NB = 28, fillPaths = [], linePaths = [];
  function bl(A, k, k1, k2, k3, u, v) { return (A[k] * (1 - u) + A[k1] * u) * (1 - v) + (A[k2] * (1 - u) + A[k3] * u) * v; }
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.55 });
    project();
    var silk = look === 'silk';
    for (var b = 0; b < NB; b++) { fillPaths[b] = new Path2D(); linePaths[b] = new Path2D(); }
    function binOf(v) { return Math.min(NB - 1, (v * v * v * NB) | 0); }
    for (var j = 0; j < CH - 1; j++) for (var i = 0; i < CW - 1; i++) {
      var k = j * CW + i, k1 = k + 1, k2 = k + CW, k3 = k + CW + 1;
      if (silk) {
        /* 1 マスを 2×2 に割り、明るさを四隅から補間して段差を目立たなくする */
        for (var v2 = 0; v2 < 2; v2++) for (var u2 = 0; u2 < 2; u2++) {
          var u0 = u2 * 0.5, v0 = v2 * 0.5, u1 = u0 + 0.56, v1 = v0 + 0.56, uc = u0 + 0.25, vc = v0 + 0.25;
          var bb = (br[k] * (1 - uc) + br[k1] * uc) * (1 - vc) + (br[k2] * (1 - uc) + br[k3] * uc) * vc;
          var fp = fillPaths[binOf(bb)];
          fp.moveTo(bl(sx, k, k1, k2, k3, u0, v0), bl(sy, k, k1, k2, k3, u0, v0));
          fp.lineTo(bl(sx, k, k1, k2, k3, u1, v0), bl(sy, k, k1, k2, k3, u1, v0));
          fp.lineTo(bl(sx, k, k1, k2, k3, u1, v1), bl(sy, k, k1, k2, k3, u1, v1));
          fp.lineTo(bl(sx, k, k1, k2, k3, u0, v1), bl(sy, k, k1, k2, k3, u0, v1));
          fp.closePath();
        }
      }
      /* 横糸（上辺）と縦糸（左辺）。右端・下端は最後に足す */
      if (!silk || j % 2 === 0) { var lp = linePaths[binOf((br[k] + br[k1]) * 0.5)]; lp.moveTo(sx[k], sy[k]); lp.lineTo(sx[k1], sy[k1]); }
      if (!silk || i % 2 === 0) { var lq = linePaths[binOf((br[k] + br[k2]) * 0.5)]; lq.moveTo(sx[k], sy[k]); lq.lineTo(sx[k2], sy[k2]); }
      if (i === CW - 2) { var lr = linePaths[binOf((br[k1] + br[k3]) * 0.5)]; lr.moveTo(sx[k1], sy[k1]); lr.lineTo(sx[k3], sy[k3]); }
      if (j === CH - 2) { var ls = linePaths[binOf((br[k2] + br[k3]) * 0.5)]; ls.moveTo(sx[k2], sy[k2]); ls.lineTo(sx[k3], sy[k3]); }
    }
    if (silk) for (var b1 = 0; b1 < NB; b1++) {
      var fc = P.mix(P.bg, P.ramp(0.06 + (b1 + 0.5) / NB * 0.78), 0.62);
      ctx.fillStyle = P.rgba(fc, 1);
      ctx.fill(fillPaths[b1]);
    }
    ctx.lineWidth = silk ? 0.7 : 0.9;
    for (var b2 = 0; b2 < NB; b2++) {
      var f = (b2 + 0.5) / NB;
      ctx.strokeStyle = P.rgba(P.line, silk ? 0.07 + f * 0.3 : 0.16 + f * 0.62);
      ctx.stroke(linePaths[b2]);
    }
    /* 触られた糸 */
    var hp = new Path2D(), hp2 = new Path2D(), anyH = false;
    for (var hj = 0; hj < CH; hj++) for (var hi = 0; hi < CW; hi++) {
      var hk = hj * CW + hi, hv = heat[hk];
      if (hv < 0.04) continue;
      anyH = true;
      var P2 = hv > 0.45 ? hp : hp2;
      if (hi < CW - 1) { P2.moveTo(sx[hk], sy[hk]); P2.lineTo(sx[hk + 1], sy[hk + 1]); }
      if (hj < CH - 1) { P2.moveTo(sx[hk], sy[hk]); P2.lineTo(sx[hk + CW], sy[hk + CW]); }
    }
    if (anyH) {
      ctx.lineWidth = 1.4;
      ctx.strokeStyle = P.rgba(P.accent, 0.4); ctx.stroke(hp2);
      ctx.strokeStyle = P.rgba(P.accent, 0.85); ctx.stroke(hp);
    }
    /* 棒と留め具 */
    var y0 = sy[0];
    ctx.strokeStyle = P.rgba(P.line, 0.8);
    ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(sx[0] - S * 1.6, y0); ctx.lineTo(sx[CW - 1] + S * 1.6, y0); ctx.stroke();
    ctx.fillStyle = P.rgba(P.line, 0.9);
    for (var i2 = 0; i2 < CW; i2++) if (pin[i2]) { ctx.beginPath(); ctx.arc(sx[i2], sy[i2], 2.2, 0, Math.PI * 2); ctx.fill(); }
    /* 指の位置 */
    if (touch.s > 0.02) {
      ctx.strokeStyle = P.rgba(P.accent, 0.6 * touch.s);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(touch.x, touch.y, 10 + 8 * (1 - touch.s), 0, Math.PI * 2); ctx.stroke();
    }
  }

  function warm(x, y, k) {
    k = k || 1.2;
    for (var i = 0; i < NP; i++) {
      var dx = sx[i] - x, dy = sy[i] - y, d2 = dx * dx + dy * dy;
      var wr = 150 * RS; if (d2 < wr * wr) heat[i] = Math.min(1, heat[i] + (1 - Math.sqrt(d2) / wr) * k);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; touch.x = e.clientX; touch.y = e.clientY; touch.dx = touch.dy = 0;
    warm(e.clientX, e.clientY);
    canvas.setPointerCapture(e.pointerId);
    SFX.play('collide', 200);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    touch.dx = e.clientX - touch.x; touch.dy = e.clientY - touch.y;
    touch.x = e.clientX; touch.y = e.clientY;
    /* なぞると、指のまわりの布が指と一緒に引っぱられる（すぐについてくる） */
    var gr = 170 * RS;
    for (var k = 0; k < NP; k++) {
      if (pin[k]) continue;
      var ddx = sx[k] - touch.x, ddy = sy[k] - touch.y, d2 = ddx * ddx + ddy * ddy;
      if (d2 > gr * gr) continue;
      var f = 1 - Math.sqrt(d2) / gr; f *= f;
      heat[k] = Math.min(1, heat[k] + f * 1.5);
      var mx = touch.dx * f, my = touch.dy * f;
      px[k] += mx; py[k] += my; ox[k] += mx * 0.8; oy[k] += my * 0.8;
      var lift = -Math.min(18, Math.hypot(touch.dx, touch.dy)) * f; pz[k] += lift; oz[k] += lift * 0.7;   // つまんだところが手前に持ち上がる
    }
    if (Math.abs(touch.dx) + Math.abs(touch.dy) > 4) SFX.play('move', 180);
  });
  function up() { touch.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  wind = document.getElementById('k-wind').value / 100;
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-reset').addEventListener('click', function () { build(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  build();
  for (var w = 0; w < 40; w++) step(1 / 60);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    touch.s += ((touch.on ? 1 : 0) - touch.s) * Math.min(1, dt * (touch.on ? 12 : 2.5));
    var n = Math.max(1, Math.round(dt / (1 / 120)));
    for (var i = 0; i < n; i++) step(dt / n);
    touch.dx *= 0.5; touch.dy *= 0.5;
    var hd = Math.exp(-dt * 0.9);
    for (var q = 0; q < NP; q++) heat[q] *= hd;
    if (touch.on) warm(touch.x, touch.y, dt * 4);
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
