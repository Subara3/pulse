<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fluidveil');

$pageName = 'fluidveil';
$pageTitle = 'FLUID VEIL - PULSE';
$pageDesc = '流れに乗った白い細い線が画面を動き、あちこちで渦を巻いてはほどけていく。指でなぞるとその向きに流れが起き、触れた線はしばらく水色に光りながら運ばれる。';
$pageCanonical = 'https://pulse.subara3.com/fluidveil.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-curl">CURL</label><input type="range" id="k-curl" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-still">STILL</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /* 流れ（速度・圧力）は粗い格子で解く。見せるのは、その流れに乗って運ばれる細い糸 */
  var NX, NY, u, v, u2, v2, pr, dv, wabs;
  var NP, px, py, hx, hy, age, life, glow, K = 18, hh = 0;
  var flowAmt = 0.45, curlAmt = 0.5;
  /* かき混ぜ役: 見えない櫂が 3 本、ゆっくり画面を歩き回って流れを押す */
  var paddles = [
    { ph: 0.0, fx: 0.031, fy: 0.047, dir: 1 },
    { ph: 2.4, fx: 0.043, fy: 0.029, dir: -1 },
    { ph: 4.1, fx: 0.023, fy: 0.037, dir: 1 }
  ];
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  function alloc() {
    NX = Math.max(64, Math.min(120, Math.round(W / 11)));
    NY = Math.max(40, Math.round(NX * H / W));
    var n = NX * NY;
    u = new Float32Array(n); v = new Float32Array(n);
    u2 = new Float32Array(n); v2 = new Float32Array(n);
    pr = new Float32Array(n); dv = new Float32Array(n);
    wabs = new Float32Array(n);
    NP = Math.max(3000, Math.min(11000, Math.round(W * H / 95)));
    px = new Float32Array(NP); py = new Float32Array(NP);
    hx = new Float32Array(NP * K); hy = new Float32Array(NP * K);
    age = new Float32Array(NP); life = new Float32Array(NP);
    glow = new Float32Array(NP);
    for (var i = 0; i < NP; i++) { respawn(i); age[i] = Math.random() * life[i]; }
    /* 開いた瞬間から流れているように、少し先まで回しておく */
    for (var s = 0; s < 150; s++) simulate(1 / 60, s / 60);
  }

  function respawn(i) {
    px[i] = Math.random() * W;
    py[i] = Math.random() * H;
    for (var s = 0; s < K; s++) { hx[i * K + s] = px[i]; hy[i * K + s] = py[i]; }
    age[i] = 0;
    life[i] = 4 + Math.random() * 7;
    glow[i] = 0;
  }

  /* 格子の (cx, cy) に、なめらかな丸い押しを足す */
  function push(cx, cy, r, ax, ay) {
    var r2 = r * r;
    for (var j = Math.max(1, Math.floor(cy - r)); j < Math.min(NY - 1, Math.ceil(cy + r)); j++) {
      for (var i = Math.max(1, Math.floor(cx - r)); i < Math.min(NX - 1, Math.ceil(cx + r)); i++) {
        var dx = i - cx, dy = j - cy, q = (dx * dx + dy * dy) / r2;
        if (q < 1) { var w = (1 - q) * (1 - q), k = j * NX + i; u[k] += ax * w; v[k] += ay * w; }
      }
    }
  }
  /* 渦の形に押す（中心のまわりを回す） */
  function twist(cx, cy, r, amt) {
    var r2 = r * r;
    for (var j = Math.max(1, Math.floor(cy - r)); j < Math.min(NY - 1, Math.ceil(cy + r)); j++) {
      for (var i = Math.max(1, Math.floor(cx - r)); i < Math.min(NX - 1, Math.ceil(cx + r)); i++) {
        var dx = i - cx, dy = j - cy, q = (dx * dx + dy * dy) / r2;
        if (q < 1) { var w = (1 - q) * amt / r, k = j * NX + i; u[k] -= dy * w; v[k] += dx * w; }
      }
    }
  }

  /* 粗い格子の速度を、好きな位置で拾う */
  var sU = 0, sV = 0;
  function vel(x, y) {
    if (x < 0) x = 0; else if (x > NX - 1.001) x = NX - 1.001;
    if (y < 0) y = 0; else if (y > NY - 1.001) y = NY - 1.001;
    var i0 = x | 0, j0 = y | 0, fx = x - i0, fy = y - j0, b = j0 * NX + i0;
    var w00 = (1 - fx) * (1 - fy), w10 = fx * (1 - fy), w01 = (1 - fx) * fy, w11 = fx * fy;
    sU = u[b] * w00 + u[b + 1] * w10 + u[b + NX] * w01 + u[b + NX + 1] * w11;
    sV = v[b] * w00 + v[b + 1] * w10 + v[b + NX] * w01 + v[b + NX + 1] * w11;
  }

  /* 半ラグランジュ移流: 1 歩前にいた場所の速度を拾ってくる */
  function advect(dt) {
    var maxX = NX - 1.501, maxY = NY - 1.501;
    for (var j = 1; j < NY - 1; j++) {
      for (var i = 1; i < NX - 1; i++) {
        var k = j * NX + i;
        var x = i - dt * u[k], y = j - dt * v[k];
        if (x < 0.5) x = 0.5; else if (x > maxX) x = maxX;
        if (y < 0.5) y = 0.5; else if (y > maxY) y = maxY;
        var i0 = x | 0, j0 = y | 0, fx = x - i0, fy = y - j0, b = j0 * NX + i0;
        u2[k] = (u[b] * (1 - fx) + u[b + 1] * fx) * (1 - fy) + (u[b + NX] * (1 - fx) + u[b + NX + 1] * fx) * fy;
        v2[k] = (v[b] * (1 - fx) + v[b + 1] * fx) * (1 - fy) + (v[b + NX] * (1 - fx) + v[b + NX + 1] * fx) * fy;
      }
    }
    var t = u; u = u2; u2 = t; t = v; v = v2; v2 = t;
  }

  function edges() {
    for (var i = 0; i < NX; i++) { u[i] = v[i] = u[(NY - 1) * NX + i] = v[(NY - 1) * NX + i] = 0; }
    for (var j = 0; j < NY; j++) { u[j * NX] = v[j * NX] = u[j * NX + NX - 1] = v[j * NX + NX - 1] = 0; }
  }

  /* 圧力を解いて、湧き出しと吸い込みを取り除く（前の圧力から解き始める） */
  function project() {
    var j, i, k;
    for (j = 1; j < NY - 1; j++) for (i = 1; i < NX - 1; i++) {
      k = j * NX + i;
      dv[k] = -0.5 * (u[k + 1] - u[k - 1] + v[k + NX] - v[k - NX]);
    }
    for (var it = 0; it < 24; it++) {
      for (j = 1; j < NY - 1; j++) for (i = 1; i < NX - 1; i++) {
        k = j * NX + i;
        pr[k] = (dv[k] + pr[k - 1] + pr[k + 1] + pr[k - NX] + pr[k + NX]) * 0.25;
      }
    }
    for (j = 1; j < NY - 1; j++) for (i = 1; i < NX - 1; i++) {
      k = j * NX + i;
      u[k] -= 0.5 * (pr[k + 1] - pr[k - 1]);
      v[k] -= 0.5 * (pr[k + NX] - pr[k - NX]);
    }
    edges();
  }

  /* 渦を少しだけ強める。巻き込みが細かく残る */
  function confine(dt) {
    var eps = 1 + curlAmt * 22, j, i, k;
    for (j = 1; j < NY - 1; j++) for (i = 1; i < NX - 1; i++) {
      k = j * NX + i;
      dv[k] = 0.5 * (v[k + 1] - v[k - 1] - u[k + NX] + u[k - NX]);
      wabs[k] = Math.abs(dv[k]);
    }
    for (j = 2; j < NY - 2; j++) for (i = 2; i < NX - 2; i++) {
      k = j * NX + i;
      var gx = 0.5 * (wabs[k + 1] - wabs[k - 1]), gy = 0.5 * (wabs[k + NX] - wabs[k - NX]);
      var len = Math.sqrt(gx * gx + gy * gy) + 1e-5;
      u[k] += dt * eps * (gy / len) * dv[k];
      v[k] -= dt * eps * (gx / len) * dv[k];
    }
  }

  function stir(dt, t) {
    var s = 0.25 + flowAmt * 1.5;
    for (var n = 0; n < paddles.length; n++) {
      var pd = paddles[n];
      var x = NX * (0.5 + 0.36 * Math.sin(t * pd.fx * 2 + pd.ph));
      var y = NY * (0.5 + 0.34 * Math.sin(t * pd.fy * 2 + pd.ph * 1.7));
      var ang = t * 0.11 * pd.dir + pd.ph;
      push(x, y, NY * 0.13, dt * 34 * s * Math.cos(ang), dt * 34 * s * Math.sin(ang));
      twist(x, y, NY * 0.2, dt * 7 * s * pd.dir * Math.sin(t * 0.07 + pd.ph));
    }
  }

  function simulate(dt, t) {
    stir(dt, t);
    confine(dt);
    project();
    advect(dt);
    var fr = 1 - dt * 0.28;
    for (var k = 0; k < u.length; k++) { u[k] *= fr; v[k] *= fr; }
    project();
    moveThreads(dt);
  }

  function moveThreads(dt) {
    var sx = NX / W, sy = NY / H;
    hh = (hh + 1) % K;
    for (var i = 0; i < NP; i++) {
      age[i] += dt;
      if (age[i] > life[i] || px[i] < 0 || px[i] > W || py[i] < 0 || py[i] > H) { respawn(i); continue; }
      /* 中点法で 1 歩進める（渦の中で外へ飛ばされにくい） */
      vel(px[i] * sx, py[i] * sy);
      var mx = px[i] + sU / sx * dt * 0.5, my = py[i] + sV / sy * dt * 0.5;
      vel(mx * sx, my * sy);
      px[i] += sU / sx * dt; py[i] += sV / sy * dt;
      hx[i * K + hh] = px[i]; hy[i * K + hh] = py[i];
      if (glow[i] > 0) glow[i] = Math.max(0, glow[i] - dt * 0.4);
    }
  }

  /* 糸を描く。1 本ずつ、通ってきた跡を折れ線にする。速いところほど明るく、古い跡ほど薄く */
  var BUCKETS = 5, TIERS = 3, paths = [], cyanPath = [];
  function trail(k, from, to) {
    var base = k * K, idx = (hh - from + K) % K;
    ctx.moveTo(hx[base + idx], hy[base + idx]);
    for (var s = from + 1; s <= to; s++) {
      idx = (hh - s + K) % K;
      ctx.lineTo(hx[base + idx], hy[base + idx]);
    }
  }
  function draw() {
    P.fill(ctx, W, H);
    var b, t, n, k, per = (K - 1) / TIERS;
    for (b = 0; b < BUCKETS; b++) paths[b] = [];
    cyanPath.length = 0;
    var oldest = (hh + 1) % K;
    for (var i = 0; i < NP; i++) {
      var dx = hx[i * K + hh] - hx[i * K + oldest], dy = hy[i * K + hh] - hy[i * K + oldest];
      var sp = Math.sqrt(dx * dx + dy * dy) / (K - 1) * 60;
      var fade = Math.min(1, age[i] / 0.8, (life[i] - age[i]) / 1.2);
      var a = Math.min(1, sp / 150) * fade;
      if (glow[i] > 0.1 && fade > 0.2) { cyanPath.push(i); continue; }
      /* 淀んだところは描かない。暗い地が残って、流れの帯だけが浮かぶ */
      if (a < 0.12) continue;
      paths[Math.min(BUCKETS - 1, ((a - 0.12) / 0.88 * BUCKETS) | 0)].push(i);
    }
    ctx.lineWidth = 1;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    for (b = 0; b < BUCKETS; b++) {
      var list = paths[b];
      if (!list.length) continue;
      for (t = 0; t < TIERS; t++) {
        ctx.strokeStyle = P.rgba(P.line, (0.08 + 0.4 * (b + 0.5) / BUCKETS) * (1 - t / TIERS));
        ctx.beginPath();
        for (n = 0; n < list.length; n++) trail(list[n], Math.round(t * per), Math.round((t + 1) * per));
        ctx.stroke();
      }
    }
    if (cyanPath.length) {
      for (t = 0; t < TIERS; t++) {
        ctx.strokeStyle = P.rgba(P.accent, 0.5 * (1 - t / TIERS));
        ctx.beginPath();
        for (n = 0; n < cyanPath.length; n++) trail(cyanPath[n], Math.round(t * per), Math.round((t + 1) * per));
        ctx.stroke();
      }
    }
  }

  /* 触れた糸に印を付ける（しばらくシアンで光って、白に戻る） */
  function mark(x, y, r) {
    var r2 = r * r;
    for (var i = 0; i < NP; i++) {
      var dx = px[i] - x, dy = py[i] - y;
      if (dx * dx + dy * dy < r2) glow[i] = 1;
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastTime = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; lastTime = performance.now();
    var cx = last.x / W * NX, cy = last.y / H * NY;
    twist(cx, cy, NY * 0.12, 40 * (Math.random() < 0.5 ? -1 : 1));
    mark(last.x, last.y, 46);
    SFX.play('spawn', 200);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY }, now = performance.now();
    var dx = p.x - last.x, dy = p.y - last.y, dist = Math.hypot(dx, dy);
    if (dist < 3) return;
    var dts = Math.max(0.008, (now - lastTime) / 1000);
    var vx = dx / W * NX / dts, vy = dy / H * NY / dts;
    var sp = Math.hypot(vx, vy), lim = 40;
    if (sp > lim) { vx *= lim / sp; vy *= lim / sp; }
    var n = Math.ceil(dist / 10);
    for (var i = 1; i <= n; i++) {
      var x = last.x + dx * i / n, y = last.y + dy * i / n;
      push(x / W * NX, y / H * NY, 3.2, vx * 0.3 / n, vy * 0.3 / n);
    }
    mark(p.x, p.y, 38);
    last = p; lastTime = now;
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-flow').addEventListener('input', function () { flowAmt = this.value / 100; });
  document.getElementById('k-curl').addEventListener('input', function () { curlAmt = this.value / 100; });
  document.getElementById('k-still').addEventListener('click', function () {
    u.fill(0); v.fill(0); pr.fill(0);
    SFX.play('click');
  });
  flowAmt = document.getElementById('k-flow').value / 100;
  curlAmt = document.getElementById('k-curl').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), clock = 2.5;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;
    simulate(Math.min(dt, 1 / 30), clock);
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
