<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('weave');

$pageName = 'weave';
$pageTitle = 'WEAVE - PULSE';
$pageDesc = '縦の白い糸と横の鋼色の糸が1本ずつ上下を入れ替えて織られ、斜めの綾や菱の模様が画面いっぱいに並ぶ。模様は端から少しずつ別の織り方に入れ替わっていく。押すとまわりの糸が指のほうへ寄せられ、そこから新しい織り方が広がる。なぞると指の跡に沿って糸の上下が入れ替わる。';
$pageCanonical = 'https://pulse.subara3.com/weave.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 糸の交点を格子で持つ。交点ごとに「縦糸が上か」を 0..1 で持ち、規則が決めた側へ寄せる */
  var s, NC, NR, N, ox, oy, maxD;
  var bx, by, dx, dy, vx, vy, X, Y, up, glow, flip, pid;
  var pats = new Array(16), patN = 0, fronts = [];
  var speed = 0.4, size = 0.5;
  var t = 0, shift = 0, nextFront = 1.4;
  var hold = null;

  function tri(n, p) { var q = 2 * p, m = ((n % q) + q) % q; return m < p ? m : q - m; }
  function pick(a) { return a[Math.floor(Math.random() * a.length)]; }

  /* 織り方: 綾（斜め）と、それを折り返した山形・菱 */
  function newPat() {
    var m = pick([4, 4, 5, 6, 6, 8]);
    var k = Math.max(1, Math.min(m - 1, Math.round(m / 2 + (Math.random() - 0.5) * 2)));
    var per = [0, 0, 4, 6, 8, 8, 12];
    patN = (patN + 1) % pats.length;
    pats[patN] = { m: m, k: k, a: Math.random() < 0.75 ? 1 : 2, b: Math.random() < 0.5 ? 1 : -1, pc: pick(per), pr: pick(per) };
    return patN;
  }
  function target(i) {
    var p = pats[pid[i]], c = i % NC, r = (i / NC) | 0;
    var zc = p.pc ? tri(c, p.pc) : c, zr = p.pr ? tri(r, p.pr) : r;
    /* 1 段ずらす境目が、斜めに画面を渡っていく */
    var v = p.a * zc + p.b * zr + Math.floor(shift + c * 0.045 + r * 0.03);
    return (((v % p.m) + p.m) % p.m) < p.k ? 1 : 0;
  }

  function alloc() {
    var scale = Math.max(0.7, Math.min(1.2, Math.min(W, H) / 800));
    s = (24 - size * 12) * scale;
    NC = Math.ceil(W / s) + 4; NR = Math.ceil(H / s) + 4; N = NC * NR;
    ox = (W - (NC - 1) * s) / 2; oy = (H - (NR - 1) * s) / 2;
    maxD = Math.hypot(W, H) + s * 4;
    bx = new Float32Array(N); by = new Float32Array(N);
    dx = new Float32Array(N); dy = new Float32Array(N);
    vx = new Float32Array(N); vy = new Float32Array(N);
    X = new Float32Array(N); Y = new Float32Array(N);
    up = new Float32Array(N); glow = new Float32Array(N); flip = new Float32Array(N);
    pid = new Uint8Array(N);
    var p0 = newPat();
    fronts.length = 0;
    for (var i = 0; i < N; i++) {
      bx[i] = ox + (i % NC) * s; by[i] = oy + ((i / NC) | 0) * s;
      X[i] = bx[i]; Y[i] = by[i];
      pid[i] = p0;
    }
    for (var j = 0; j < N; j++) up[j] = target(j);
  }

  function launch(x, y, v) {
    fronts.push({ x: x, y: y, r: 0, v: v, id: newPat() });
  }

  /* 色は段階に分けて作り置き。糸 2 種 x 明るさ 6 段 x 水色 4 段 */
  var LV = 6, GV = 4, cols = [], bins = [];
  for (var T = 0; T < 2; T++) for (var g = 0; g < GV; g++) for (var l = 0; l < LV; l++) {
    var lv = (l + 0.5) / LV;
    var base = T === 0 ? P.ramp(0.34 + 0.62 * lv) : P.ramp(0.16 + 0.42 * lv);
    cols.push(P.rgba(P.mix(base, P.accent, g / (GV - 1) * 0.85)));
    bins.push([]);
  }
  var UNDER_WARP = P.rgba(P.mix(P.bg, P.steel, 0.42)), UNDER_WEFT = P.rgba(P.mix(P.bg, P.steel, 0.26));
  var OUTLINE = P.rgba(P.deep, 0.85);

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  function update(dt) {
    var sp = 0.35 + speed * 1.3;
    t += dt * sp;
    shift += dt * (0.08 + speed * 0.45);
    nextFront -= dt * sp;
    if (nextFront <= 0) {
      nextFront = 6 + Math.random() * 3;
      var side = Math.random();
      launch(side < 0.5 ? (side < 0.25 ? 0 : W) : Math.random() * W, side < 0.5 ? Math.random() * H : (side < 0.75 ? 0 : H), 120);
      SFX.play('grow', 2000);
    }
    for (var f = fronts.length - 1; f >= 0; f--) {
      var fr = fronts[f];
      fr.r += fr.v * dt * sp;
      if (fr.r > maxD) fronts.splice(f, 1);
    }
    var K = 30, C = 5, R2 = Math.pow(s * 5, 2), sw = s * 0.1;
    var ek = Math.min(1, dt * 7), gk = Math.exp(-dt * 1.1);
    for (var i = 0; i < N; i++) {
      for (var q = 0; q < fronts.length; q++) {
        var fq = fronts[q], ddx = bx[i] - fq.x, ddy = by[i] - fq.y;
        if (ddx * ddx + ddy * ddy < fq.r * fq.r) pid[i] = fq.id;
      }
      var ax = -K * dx[i] - C * vx[i], ay = -K * dy[i] - C * vy[i];
      if (hold) {
        var hx = hold.x - X[i], hy = hold.y - Y[i], gg = Math.exp(-(hx * hx + hy * hy) / R2);
        if (gg > 0.01) {
          ax += hx * gg * 28; ay += hy * gg * 28;
          if (gg > 0.3 && glow[i] < gg * 0.7) glow[i] = gg * 0.7;
        }
      }
      vx[i] += ax * dt; vy[i] += ay * dt;
      dx[i] += vx[i] * dt; dy[i] += vy[i] * dt;
      var c = i % NC, r = (i / NC) | 0;
      X[i] = bx[i] + dx[i] + sw * Math.sin(t * 0.8 + r * 0.23 + c * 0.05);
      Y[i] = by[i] + dy[i] + sw * Math.sin(t * 0.67 + c * 0.19 - r * 0.04);
      var tg = target(i);
      if (flip[i] > 0) { flip[i] -= dt; tg = 1 - tg; }
      up[i] += (tg - up[i]) * ek;
      glow[i] *= gk;
    }
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    var w = s * 0.54, c, r, i;
    ctx.lineCap = 'butt'; ctx.lineJoin = 'round';
    /* くぐる側: 糸全体を暗く敷く。上の糸のすき間から見える */
    ctx.lineWidth = w;
    ctx.strokeStyle = UNDER_WEFT;
    ctx.beginPath();
    for (r = 0; r < NR; r++) {
      i = r * NC; ctx.moveTo(X[i], Y[i]);
      for (c = 1; c < NC; c++) ctx.lineTo(X[i + c], Y[i + c]);
    }
    ctx.stroke();
    ctx.strokeStyle = UNDER_WARP;
    ctx.beginPath();
    for (c = 0; c < NC; c++) {
      ctx.moveTo(X[c], Y[c]);
      for (r = 1; r < NR; r++) ctx.lineTo(X[r * NC + c], Y[r * NC + c]);
    }
    ctx.stroke();

    /* 上を渡る側: 交点ごとに、前後の中点から中点までの 1 区間 */
    for (var b = 0; b < bins.length; b++) bins[b].length = 0;
    for (r = 1; r < NR - 1; r++) {
      for (c = 1; c < NC - 1; c++) {
        i = r * NC + c;
        var u = up[i], e = Math.abs(2 * u - 1);
        var light = 0.5 + 0.5 * Math.sin(t * 0.45 + c * 0.07 - r * 0.11);
        var L = Math.min(LV - 1, ((0.3 + 0.7 * light) * e * LV) | 0);
        var G = Math.min(GV - 1, (glow[i] * GV) | 0);
        bins[((u >= 0.5 ? 0 : 1) * GV + G) * LV + L].push(i);
      }
    }
    function seg(i, T) {
      var a = T === 0 ? i - NC : i - 1, z = T === 0 ? i + NC : i + 1;
      ctx.moveTo((X[a] + X[i]) * 0.5, (Y[a] + Y[i]) * 0.5);
      ctx.lineTo(X[i], Y[i]);
      ctx.lineTo((X[z] + X[i]) * 0.5, (Y[z] + Y[i]) * 0.5);
    }
    ctx.lineJoin = 'miter';
    ctx.lineWidth = w + 2.5;
    ctx.strokeStyle = OUTLINE;
    ctx.beginPath();
    for (b = 0; b < bins.length; b++) {
      var Tb = b < GV * LV ? 0 : 1, list = bins[b];
      for (var n = 0; n < list.length; n++) seg(list[n], Tb);
    }
    ctx.stroke();
    ctx.lineWidth = w;
    for (b = 0; b < bins.length; b++) {
      var lst = bins[b];
      if (!lst.length) continue;
      var Tc = b < GV * LV ? 0 : 1;
      ctx.strokeStyle = cols[b];
      ctx.beginPath();
      for (var m = 0; m < lst.length; m++) seg(lst[m], Tc);
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  function near(x, y, rad, fn) {
    var c0 = Math.max(0, Math.floor((x - rad - ox) / s) - 2), c1 = Math.min(NC - 1, Math.ceil((x + rad - ox) / s) + 2);
    var r0 = Math.max(0, Math.floor((y - rad - oy) / s) - 2), r1 = Math.min(NR - 1, Math.ceil((y + rad - oy) / s) + 2);
    for (var r = r0; r <= r1; r++) for (var c = c0; c <= c1; c++) {
      var i = r * NC + c, d = Math.hypot(X[i] - x, Y[i] - y);
      if (d < rad) fn(i, d / rad);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); hold = last;
    launch(last.x, last.y, 260);
    near(last.x, last.y, s * 4, function (i, k) { glow[i] = Math.max(glow[i], 1 - k * 0.6); });
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    hold = p;
    if (dist < 4) return;
    var mx = (p.x - last.x) / dist, my = (p.y - last.y) / dist, push = Math.min(dist, 40) * 6;
    var n = Math.ceil(dist / (s * 0.5));
    for (var j = 1; j <= n; j++) {
      var qx = last.x + (p.x - last.x) * j / n, qy = last.y + (p.y - last.y) * j / n;
      near(qx, qy, s * 1.4, function (i, k) {
        if (flip[i] < 4.5) flip[i] = 5;
        glow[i] = Math.max(glow[i], 1 - k * 0.3);
      });
      near(qx, qy, s * 3, function (i, k) {
        var f = (1 - k) / n;
        vx[i] += mx * push * f; vy[i] += my * push * f;
      });
    }
    last = p;
    SFX.play('move', 140);
  });
  function release() { down = false; hold = null; }
  canvas.addEventListener('pointerup', release);
  canvas.addEventListener('pointercancel', release);

  var kSpeed = document.getElementById('k-speed'), kSize = document.getElementById('k-size');
  kSpeed.addEventListener('input', function () { speed = this.value / 100; });
  kSize.addEventListener('change', function () { size = this.value / 100; alloc(); SFX.play('toggle'); });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = kSpeed.value / 100;
  if (Math.abs(kSize.value / 100 - size) > 0.001) { size = kSize.value / 100; alloc(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    update(dt);
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
