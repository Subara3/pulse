<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('gradient');

$pageName = 'gradient';
$pageTitle = 'GRADIENT - PULSE';
$pageDesc = '等高線の地図の上に粒が落ち、いちばん急な下り坂の方向へ転がって、谷底を行き過ぎたり戻ったりしながら止まる。地形はゆっくり変わるので、止まった粒もまた動き出す。押したところがくぼみ、近くの粒が集まる。';
$pageCanonical = 'https://pulse.subara3.com/gradient.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-roll">ROLL</label><input type="range" id="k-roll" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-drag">DRAG</label><input type="range" id="k-drag" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" class="active" data-land="hills">HILLS</button><button type="button" data-land="ripple">RIPPLE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 地形: ガウスの山と谷の足し合わせ。中心はゆっくり漂う */
  var G = [], wells = [], land = 'hills';
  var roll = 0.45, drag = 0.35, clock = 0;

  function makeLand() {
    G = [];
    var count = land === 'hills' ? 11 : 7, base = Math.min(W, H);
    for (var i = 0; i < count; i++) {
      G.push({
        x0: W * (0.08 + Math.random() * 0.84), y0: H * (0.08 + Math.random() * 0.84),
        ax: 30 + Math.random() * 70, ay: 30 + Math.random() * 70,
        fx: 0.012 + Math.random() * 0.025, fy: 0.012 + Math.random() * 0.025, ph: Math.random() * 7,
        a: (i % 3 === 0 ? 1 : -1) * (0.6 + Math.random() * 0.8),
        s: base * (0.1 + Math.random() * 0.14), x: 0, y: 0
      });
    }
    place();
  }
  function place() {
    for (var i = 0; i < G.length; i++) {
      var g = G[i];
      g.x = g.x0 + Math.sin(clock * g.fx * 6.283 + g.ph) * g.ax;
      g.y = g.y0 + Math.cos(clock * g.fy * 6.283 + g.ph * 1.3) * g.ay;
    }
  }
  /* 高さと傾き */
  var out = [0, 0, 0];
  function height(x, y, grad) {
    var h = 0, gx = 0, gy = 0, i, g, dx, dy, s2, e;
    for (i = 0; i < G.length; i++) {
      g = G[i]; dx = x - g.x; dy = y - g.y; s2 = g.s * g.s;
      e = g.a * Math.exp(-(dx * dx + dy * dy) / (2 * s2));
      h += e;
      if (grad) { gx -= e * dx / s2; gy -= e * dy / s2; }
    }
    if (land === 'ripple') {
      /* 同心円のうねりを足して、谷をいくつも並べる */
      var cx = W * 0.5, cy = H * 0.5, rx = x - cx, ry = y - cy, r = Math.sqrt(rx * rx + ry * ry) + 1e-3, k = 0.028;
      var amp = 0.32 * Math.exp(-r / (Math.min(W, H) * 0.7));
      h += amp * Math.cos(r * k - clock * 0.15);
      if (grad) {
        var dr = -amp * k * Math.sin(r * k - clock * 0.15) - amp / (Math.min(W, H) * 0.7) * Math.cos(r * k - clock * 0.15);
        gx += dr * rx / r; gy += dr * ry / r;
      }
    }
    for (i = 0; i < wells.length; i++) {
      g = wells[i]; dx = x - g.x; dy = y - g.y; s2 = g.s * g.s;
      e = -g.d * Math.exp(-(dx * dx + dy * dy) / (2 * s2));
      h += e;
      if (grad) { gx -= e * dx / s2; gy -= e * dy / s2; }
    }
    out[0] = h; out[1] = gx; out[2] = gy;
    return h;
  }

  /* ------------------------------------------------ 等高線（マーチングスクエア） */
  var CELL = 12, CX, CY, HF, segs = [], hmin = -1, hmax = 1;
  function contours() {
    CX = Math.ceil(W / CELL) + 1; CY = Math.ceil(H / CELL) + 1;
    if (!HF || HF.length !== CX * CY) HF = new Float32Array(CX * CY);
    var i, j, lo = 1e9, hi = -1e9;
    for (j = 0; j < CY; j++) for (i = 0; i < CX; i++) {
      var v = height(i * CELL, j * CELL, false);
      HF[j * CX + i] = v;
      if (v < lo) lo = v; if (v > hi) hi = v;
    }
    /* 刻みはゆっくり追いかけて、線の本数が急に変わらないようにする */
    hmin += (lo - hmin) * 0.2; hmax += (hi - hmax) * 0.2;
    var step = (hmax - hmin) / 22;
    segs = [];
    var L0 = Math.ceil(hmin / step), L1 = Math.floor(hmax / step);
    for (var L = L0; L <= L1; L++) {
      var lv = L * step, list = [];
      for (j = 0; j < CY - 1; j++) for (i = 0; i < CX - 1; i++) {
        var a = HF[j * CX + i], b = HF[j * CX + i + 1], c = HF[(j + 1) * CX + i + 1], d = HF[(j + 1) * CX + i];
        var code = (a > lv ? 8 : 0) | (b > lv ? 4 : 0) | (c > lv ? 2 : 0) | (d > lv ? 1 : 0);
        if (code === 0 || code === 15) continue;
        var x = i * CELL, y = j * CELL;
        var top = [x + CELL * (lv - a) / (b - a), y], right = [x + CELL, y + CELL * (lv - b) / (c - b)];
        var bot = [x + CELL * (lv - d) / (c - d), y + CELL], left = [x, y + CELL * (lv - a) / (d - a)];
        switch (code) {
          case 1: case 14: list.push(left, bot); break;
          case 2: case 13: list.push(bot, right); break;
          case 3: case 12: list.push(left, right); break;
          case 4: case 11: list.push(top, right); break;
          case 6: case 9: list.push(top, bot); break;
          case 7: case 8: list.push(left, top); break;
          case 5: list.push(left, top, bot, right); break;
          case 10: list.push(top, right, left, bot); break;
        }
      }
      segs.push({ L: L, pts: list });
    }
  }

  /* ------------------------------------------------ 粒 */
  var B = [], TRAIL = 30;
  function drop(b) {
    b.x = Math.random() * W; b.y = Math.random() * H; b.vx = 0; b.vy = 0;
    b.still = 0; b.a = 0; b.out = false; b.tr = []; b.life = 14 + Math.random() * 22;
  }
  function seed() {
    B = [];
    var n = Math.round(Math.min(220, W * H / 6000));
    for (var i = 0; i < n; i++) { var b = {}; drop(b); b.a = Math.random(); b.life *= Math.random(); B.push(b); }
  }
  function moveBeads(dt) {
    var push = 9000 + roll * 26000, fr = 0.4 + drag * 3.2;
    for (var i = 0; i < B.length; i++) {
      var b = B[i];
      /* 坂を下る力と、転がり抵抗。勢いがあるので谷底を行き過ぎては戻る */
      height(b.x, b.y, true);
      b.vx += (-out[1] * push - b.vx * fr) * dt;
      b.vy += (-out[2] * push - b.vy * fr) * dt;
      var sp = Math.hypot(b.vx, b.vy);
      if (sp > 260) { b.vx *= 260 / sp; b.vy *= 260 / sp; }
      b.x += b.vx * dt; b.y += b.vy * dt;
      b.tr.push(b.x, b.y);
      if (b.tr.length > TRAIL * 2) b.tr.splice(0, 2);
      b.still = sp < 6 ? b.still + dt : 0;
      b.life -= dt;
      if (b.out) { b.a -= dt * 0.7; if (b.a <= 0) drop(b); }
      else {
        b.a = Math.min(1, b.a + dt * 0.8);
        if (b.life <= 0 || b.x < -40 || b.y < -40 || b.x > W + 40 || b.y > H + 40) b.out = true;
      }
    }
  }

  /* ------------------------------------------------ 描く */
  function inWell(x, y) {
    var k = 0;
    for (var i = 0; i < wells.length; i++) {
      var w = wells[i], d = Math.hypot(x - w.x, y - w.y) / (w.s * 1.6);
      if (d < 1) k = Math.max(k, (1 - d) * Math.min(1, w.d / 0.6));
    }
    return k;
  }
  function draw() {
    P.fill(ctx, W, H);
    var i, j, s;
    ctx.lineWidth = 1;
    /* 5 本ごとに白い計曲線、間は鋼色の細い線。くぼみの中だけシアン */
    for (s = 0; s < segs.length; s++) {
      var S = segs[s], major = S.L % 5 === 0, pts = S.pts, hot = [];
      ctx.strokeStyle = major ? P.rgba(P.line, 0.42) : P.rgba(P.steel, 0.4);
      ctx.beginPath();
      for (j = 0; j < pts.length; j += 2) {
        var p = pts[j], q = pts[j + 1];
        if (wells.length && inWell(p[0], p[1]) > 0.15) { hot.push(p, q); continue; }
        ctx.moveTo(p[0], p[1]); ctx.lineTo(q[0], q[1]);
      }
      ctx.stroke();
      if (hot.length) {
        ctx.strokeStyle = P.rgba(P.accent, 0.55);
        ctx.beginPath();
        for (j = 0; j < hot.length; j += 2) { ctx.moveTo(hot[j][0], hot[j][1]); ctx.lineTo(hot[j + 1][0], hot[j + 1][1]); }
        ctx.stroke();
      }
    }
    /* 転がった跡: 古いほど薄い */
    ctx.lineCap = 'round';
    for (var k = 0; k < 4; k++) {
      ctx.strokeStyle = P.rgba(P.line, 0.06 + k * 0.08);
      ctx.lineWidth = 1 + k * 0.25;
      ctx.beginPath();
      for (i = 0; i < B.length; i++) {
        var tr = B[i].tr, n = tr.length / 2;
        if (n < 2 || B[i].a < 0.3) continue;
        var a0 = Math.floor(n * k / 4), a1 = Math.min(n - 1, Math.floor(n * (k + 1) / 4));
        ctx.moveTo(tr[a0 * 2], tr[a0 * 2 + 1]);
        for (j = a0 + 1; j <= a1; j++) ctx.lineTo(tr[j * 2], tr[j * 2 + 1]);
      }
      ctx.stroke();
    }
    /* 粒 */
    for (var g = 0; g < 3; g++) {
      ctx.fillStyle = P.rgba(P.line, 0.3 + g * 0.33);
      ctx.beginPath();
      for (i = 0; i < B.length; i++) {
        var b = B[i];
        if (Math.min(2, (b.a * 3) | 0) !== g) continue;
        ctx.moveTo(b.x + 2.4, b.y); ctx.arc(b.x, b.y, 2.4, 0, Math.PI * 2);
      }
      ctx.fill();
    }
  }

  /* ------------------------------------------------ 操作 */
  var held = null;
  canvas.addEventListener('pointerdown', function (e) {
    held = { x: e.clientX, y: e.clientY, d: 0.15, s: Math.min(W, H) * 0.07, hold: true };
    wells.push(held);
    if (wells.length > 6) wells.shift();
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    held.x += (e.clientX - held.x) * 0.5; held.y += (e.clientY - held.y) * 0.5;
    SFX.play('move', 220);
  });
  function up() { if (held) held.hold = false; held = null; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kr = document.getElementById('k-roll'), kd = document.getElementById('k-drag');
  kr.addEventListener('input', function () { roll = this.value / 100; });
  kd.addEventListener('input', function () { drag = this.value / 100; });
  roll = kr.value / 100; drag = kd.value / 100;
  document.querySelectorAll('[data-land]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-land]').forEach(function (o) { o.classList.toggle('active', o === b); });
      land = b.dataset.land;
      makeLand();
      SFX.play('toggle');
    });
  });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; makeLand(); seed(); contours(); });

  /* ------------------------------------------------ 回す */
  var lastT = performance.now(), tick = 0, chime = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now; clock += dt; tick++;
    place();
    for (var i = wells.length - 1; i >= 0; i--) {
      var w = wells[i];
      if (w.hold) { w.d = Math.min(1.6, w.d + dt * 0.9); w.s = Math.min(Math.min(W, H) * 0.12, w.s + dt * 12); }
      else { w.d -= dt * 0.12; if (w.d <= 0) wells.splice(i, 1); }
    }
    if (tick % 2 === 0) contours();
    moveBeads(dt);
    draw();
    chime += dt;
    if (chime > 6) { chime = 0; SFX.play('tick', 3000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
