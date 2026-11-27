<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('skein');

$pageName = 'skein';
$pageTitle = 'SKEIN - PULSE';
$pageDesc = '雁の群れがいくつもV字に並んで空を渡り、先頭の鳥はしばらくすると列の後ろへ下がって、次の鳥が先頭になる。押すとまわりの鳥が水色になって列を離れ、指を先頭にしたV字に並び直し、なぞるとその列が指についてくる。';
$pageCanonical = 'https://pulse.subara3.com/skein.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-count">COUNT</label><input type="range" id="k-count" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;
  var TAU = Math.PI * 2;

  /* ---------------------------------------------------------- 状態 */
  var MAXB = 420;
  var bx = new Float32Array(MAXB), by = new Float32Array(MAXB);
  var bvx = new Float32Array(MAXB), bvy = new Float32Array(MAXB);
  var bh = new Float32Array(MAXB);     /* 向き */
  var bs = new Float32Array(MAXB);     /* 奥行き（大きさ） */
  var heat = new Float32Array(MAXB);   /* 触られた明るさ */
  var ph = new Float32Array(MAXB), fr = new Float32Array(MAXB);   /* 羽ばたき */
  var bSk = new Array(MAXB);
  var nB = 0, skeins = [], G = null;
  var gT = 0, gAng = 0;
  var minWH = 1, ws0 = 20, M = 100, PX = 1, PY = 1, base = 60, Rorb = 40;
  var speedK = 1, density = 0.5;
  var tc = null, tctx = null, fadeAcc = 0, mergeAcc = 0, sndT = 0;
  var ptr = { down: false, id: -1, x: 0, y: 0, vx: 0, vy: 0, lt: 0 };
  var VL = { x: 0, y: 0, vx: 0, vy: 0, th: 0, orb: 0 };

  speedK = 0.3 + document.getElementById('k-speed').value / 100 * 1.4;
  density = document.getElementById('k-count').value / 100;

  function wd(d, p) { return d - p * Math.round(d / p); }
  function wa(a) { return a - TAU * Math.round(a / TAU); }
  function rnd(a, b) { return a + Math.random() * (b - a); }

  function newSkein(depth, ang) {
    return { lead: -1, L: [], R: [], ang: ang, off: 0, depth: depth, cone: rnd(0.38, 0.6),
             timer: rnd(8, 22), retarget: rnd(6, 18), seed: rnd(0, 100), ptr: false };
  }
  function count(s) { return (s.lead >= 0 ? 1 : 0) + s.L.length + s.R.length; }

  /* V の腕の 1 つ前の鳥から見た、自分の場所 */
  function slot(s, px, py, h, side, i) {
    var sp = ws0 * s.depth * 1.15 * (1 + 0.05 * Math.sin(gT * 0.6 + i * 1.7));
    var cb = Math.cos(s.cone) * sp, sb = Math.sin(s.cone) * sp * side;
    var c = Math.cos(h), n = Math.sin(h);
    return [px - c * cb - n * sb, py - n * cb + c * sb];
  }

  function addBird(x, y, h, s) {
    var i = nB++;
    bx[i] = x; by[i] = y; bh[i] = h; bs[i] = s.depth; heat[i] = 0;
    var v = base * speedK * s.depth;
    bvx[i] = Math.cos(h) * v; bvy[i] = Math.sin(h) * v;
    ph[i] = Math.random() * TAU; fr[i] = rnd(1.4, 2.0) * TAU;
    bSk[i] = s;
    return i;
  }

  function build() {
    minWH = Math.min(W, H);
    ws0 = 0.03 * minWH + 9;
    M = ws0 * 2 + 0.04 * Math.max(W, H);
    PX = W + 2 * M; PY = H + 2 * M;
    base = 0.035 * Math.max(W, H) + 28;
    Rorb = 0.08 * minWH;
    nB = 0; skeins = []; G = null; ptr.down = false;
    gAng = Math.random() * TAU;
    var N = Math.min(MAXB, Math.round(Math.max(130, W * H / 4000) * (0.5 + density)));
    while (nB < N) {
      var n = Math.min(N - nB, 6 + Math.floor(Math.random() * Math.min(16, N / 6)));
      var s = newSkein(rnd(0.45, 1.25), gAng + rnd(-0.6, 0.6));
      s.off = s.ang - gAng;
      var lx = rnd(0, W), ly = rnd(0, H);
      s.lead = addBird(lx, ly, s.ang, s);
      var nL = Math.floor((n - 1) * rnd(0.3, 0.7));
      for (var k = 0; k < n - 1; k++) {
        var arm = k < nL ? s.L : s.R, side = k < nL ? 1 : -1;
        var p = arm.length ? arm[arm.length - 1] : s.lead;
        var q = slot(s, bx[p], by[p], s.ang, side, nB);
        arm.push(addBird(q[0], q[1], s.ang, s));
      }
      skeins.push(s);
    }
    tc = document.createElement('canvas');
    tc.width = Math.max(1, Math.round(W)); tc.height = Math.max(1, Math.round(H));
    tctx = tc.getContext('2d');
    for (var t = 0; t < 110; t++) step(1 / 30);
  }

  /* 列から外す。先頭なら長いほうの腕の先頭が繰り上がる */
  function detach(i) {
    var s = bSk[i], k;
    if (s.lead === i) {
      var arm = s.L.length >= s.R.length ? s.L : s.R;
      s.lead = arm.length ? arm.shift() : -1;
    } else if ((k = s.L.indexOf(i)) >= 0) s.L.splice(k, 1);
    else if ((k = s.R.indexOf(i)) >= 0) s.R.splice(k, 1);
  }
  function prune() {
    skeins = skeins.filter(function (s) { return s.ptr || count(s) > 0; });
  }

  function rotateLead(s) {
    if (s.L.length + s.R.length < 3) return;
    var old = s.lead;
    var A = s.L.length > s.R.length ? s.L : s.R.length > s.L.length ? s.R : (Math.random() < 0.5 ? s.L : s.R);
    var B = A === s.L ? s.R : s.L;
    s.lead = A.shift();
    B.push(old);
    if (gT - sndT > 3.5) { sndT = gT; SFX.play('tick'); }
  }

  /* 小さくなった列は、近くを同じ向きに飛ぶ列に加わる */
  function mergeSmall() {
    for (var a = 0; a < skeins.length; a++) {
      var A = skeins[a];
      if (A.ptr || count(A) >= 4 || A.lead < 0) continue;
      for (var b = 0; b < skeins.length; b++) {
        var B = skeins[b];
        if (b === a || B.ptr || B.lead < 0 || count(B) < count(A) || count(A) + count(B) > 30) continue;
        if (Math.abs(A.depth - B.depth) > 0.35 || Math.abs(wa(A.ang - B.ang)) > 1.3) continue;
        var dx = wd(bx[A.lead] - bx[B.lead], PX), dy = wd(by[A.lead] - by[B.lead], PY);
        if (dx * dx + dy * dy > Math.pow(0.32 * minWH, 2)) continue;
        var list = [A.lead].concat(A.L, A.R);
        for (var k = 0; k < list.length; k++) {
          (B.L.length <= B.R.length ? B.L : B.R).push(list[k]);
          bSk[list[k]] = B;
        }
        A.lead = -1; A.L = []; A.R = [];
        prune();
        return;
      }
    }
  }

  /* 画面の外に出きった列は、進む向きと直角にずらして入り直す（同じ所ばかり通らないように） */
  function reseat() {
    for (var a = 0; a < skeins.length; a++) {
      var s = skeins[a];
      if (s.ptr || s.lead < 0) continue;
      var list = [s.lead].concat(s.L, s.R), k, i, out = true;
      for (k = 0; k < list.length && out; k++) {
        i = list[k];
        if (bx[i] > -ws0 && bx[i] < W + ws0 && by[i] > -ws0 && by[i] < H + ws0) out = false;
      }
      if (!out) continue;
      var d = rnd(-0.5, 0.5) * (Math.abs(Math.sin(s.ang)) * PX + Math.abs(Math.cos(s.ang)) * PY);
      var sx = -Math.sin(s.ang) * d, sy = Math.cos(s.ang) * d;
      var ok = true;
      for (k = 0; k < list.length && ok; k++) {
        i = list[k];
        var nx = ((bx[i] + sx + M) % PX + PX) % PX - M, ny = ((by[i] + sy + M) % PY + PY) % PY - M;
        if (nx > -ws0 && nx < W + ws0 && ny > -ws0 && ny < H + ws0) ok = false;
      }
      if (!ok) continue;
      for (k = 0; k < list.length; k++) {
        i = list[k];
        bx[i] = ((bx[i] + sx + M) % PX + PX) % PX - M;
        by[i] = ((by[i] + sy + M) % PY + PY) % PY - M;
      }
    }
  }

  function follow(i, p, s, side, maxC, rate, dt) {
    var px, py, pvx, pvy, h;
    if (p < 0) { px = VL.x; py = VL.y; pvx = VL.vx; pvy = VL.vy; h = s.ang; }
    else { px = bx[p]; py = by[p]; pvx = bvx[p]; pvy = bvy[p]; h = bh[p]; }
    var q = slot(s, px, py, h, side, i);
    var ex = wd(q[0] - bx[i], PX) * 1.5, ey = wd(q[1] - by[i], PY) * 1.5;
    var m = Math.hypot(ex, ey);
    if (m > maxC) { ex *= maxC / m; ey *= maxC / m; }
    var k = Math.min(1, rate * dt);
    bvx[i] += (pvx + ex - bvx[i]) * k;
    bvy[i] += (pvy + ey - bvy[i]) * k;
  }

  var ox = new Float32Array(MAXB), oy = new Float32Array(MAXB);
  function step(dt) {
    gT += dt;
    gAng += dt * 0.03 * Math.sin(gT * 0.031 + 1.3);
    var v0 = base * speedK;

    /* 指が先頭の列: 指が止まっていると、そのまわりを小さく回る */
    if (G && ptr.down) {
      var pv = Math.hypot(ptr.vx, ptr.vy);
      var to = Rorb * Math.max(0, 1 - pv / (base * 1.2));
      VL.orb += (to - VL.orb) * Math.min(1, 2 * dt);
      VL.th += dt * 1.3;
      var tx = ptr.x + Math.cos(VL.th) * VL.orb, ty = ptr.y + Math.sin(VL.th) * VL.orb;
      var nvx = (tx - VL.x) * 10, nvy = (ty - VL.y) * 10, nm = Math.hypot(nvx, nvy), cap = 8 * base;
      if (nm > cap) { nvx *= cap / nm; nvy *= cap / nm; nm = cap; }
      VL.vx = nvx; VL.vy = nvy;
      VL.x += VL.vx * dt; VL.y += VL.vy * dt;
      if (nm > 6) G.ang += wa(Math.atan2(nvy, nvx) - G.ang) * Math.min(1, 6 * dt);
    }

    for (var a = 0; a < skeins.length; a++) {
      var s = skeins[a], i, j;
      var v = v0 * s.depth;
      if (!s.ptr) {
        s.retarget -= dt;
        if (s.retarget < 0) { s.retarget = rnd(8, 20); s.off = rnd(-0.6, 0.6); }
        var tang = gAng + s.off + 0.3 * Math.sin(gT * 0.11 + s.seed);
        var da = wa(tang - s.ang), mt = 0.14 * dt;
        s.ang += da > mt ? mt : da < -mt ? -mt : da;
        s.timer -= dt;
        if (s.timer < 0) { s.timer = rnd(12, 26); rotateLead(s); }
        i = s.lead;
        if (i >= 0) {
          var kk = Math.min(1, 2 * dt);
          bvx[i] += (Math.cos(s.ang) * v - bvx[i]) * kk;
          bvy[i] += (Math.sin(s.ang) * v - bvy[i]) * kk;
        }
      }
      var maxC = s.ptr ? 4 * base : 0.8 * v + 20, rate = s.ptr ? 6 : 4;
      for (j = 0; j < s.L.length; j++) follow(s.L[j], j ? s.L[j - 1] : s.lead, s, 1, maxC, rate, dt);
      for (j = 0; j < s.R.length; j++) follow(s.R[j], j ? s.R[j - 1] : s.lead, s, -1, maxC, rate, dt);
    }

    for (var b = 0; b < nB; b++) {
      ox[b] = bx[b]; oy[b] = by[b];
      bx[b] += bvx[b] * dt; by[b] += bvy[b] * dt;
      if (bx[b] < -M) bx[b] += PX; else if (bx[b] >= W + M) bx[b] -= PX;
      if (by[b] < -M) by[b] += PY; else if (by[b] >= H + M) by[b] -= PY;
      if (bvx[b] * bvx[b] + bvy[b] * bvy[b] > 4) bh[b] += wa(Math.atan2(bvy[b], bvx[b]) - bh[b]) * Math.min(1, 5 * dt);
      ph[b] += fr[b] * dt;
      var sk = bSk[b];
      if (sk.ptr) heat[b] += (1 - heat[b]) * Math.min(1, 4 * dt);
      else heat[b] *= Math.exp(-dt * 0.45);
      bs[b] += (sk.depth - bs[b]) * Math.min(1, 0.5 * dt);
    }

    /* 通った跡: 薄い線を別の板に描き、少しずつ消す */
    fadeAcc += dt;
    if (fadeAcc > 0.12) {
      fadeAcc = 0;
      tctx.globalCompositeOperation = 'destination-out';
      tctx.fillStyle = P.rgba(P.deep, 0.07);
      tctx.fillRect(0, 0, tc.width, tc.height);
      tctx.globalCompositeOperation = 'source-over';
    }
    tctx.lineWidth = 1;
    for (var pass = 0; pass < 2; pass++) {
      tctx.strokeStyle = pass ? P.rgba(P.accent, 0.3) : P.rgba(P.steel, 0.2);
      tctx.beginPath();
      for (b = 0; b < nB; b++) {
        if ((heat[b] > 0.35) !== !!pass) continue;
        if (Math.abs(bx[b] - ox[b]) > W * 0.5 || Math.abs(by[b] - oy[b]) > H * 0.5) continue;
        tctx.moveTo(ox[b], oy[b]); tctx.lineTo(bx[b], by[b]);
      }
      tctx.stroke();
    }

    mergeAcc += dt;
    if (mergeAcc > 2.5) { mergeAcc = 0; mergeSmall(); reseat(); }
  }

  /* 雁: 首を伸ばし、羽を広げては縮める */
  function drawBird(i) {
    var s = bs[i], w = ws0 * s;
    var x = bx[i], y = by[i];
    if (x < -w || x > W + w || y < -w || y > H + w) return;
    var c = Math.cos(bh[i]), n = Math.sin(bh[i]);
    var f = Math.sin(ph[i]), k = 0.7 + 0.3 * Math.cos(ph[i]);
    var t = Math.max(0, Math.min(1, (s - 0.4) / 0.75));
    var col = P.mix(P.steel, P.line, t);
    if (heat[i] > 0.01) col = P.mix(col, P.accent, Math.min(1, heat[i]));
    ctx.strokeStyle = P.rgba(col, 0.55 + 0.4 * t);
    ctx.lineWidth = 0.8 + 0.9 * s;
    ctx.beginPath();
    ctx.moveTo(x + c * 0.36 * w, y + n * 0.36 * w);
    ctx.lineTo(x - c * 0.14 * w, y - n * 0.14 * w);
    for (var side = -1; side <= 1; side += 2) {
      var ex = (0.01 + 0.03 * f) * w, ey = side * 0.27 * w;
      var tx = -(0.22 + 0.08 * f) * w, ty = side * 0.56 * w * k;
      ctx.moveTo(x + c * 0.04 * w, y + n * 0.04 * w);
      ctx.lineTo(x + c * ex - n * ey, y + n * ex + c * ey);
      ctx.lineTo(x + c * tx - n * ty, y + n * tx + c * ty);
    }
    ctx.stroke();
  }

  function render() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.7 });
    ctx.drawImage(tc, 0, 0, W, H);
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    var order = skeins.slice().sort(function (a, b) { return a.depth - b.depth; });
    for (var a = 0; a < order.length; a++) {
      var s = order[a];
      if (s.lead >= 0) drawBird(s.lead);
      for (var j = 0; j < s.L.length; j++) drawBird(s.L[j]);
      for (j = 0; j < s.R.length; j++) drawBird(s.R[j]);
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    if (ptr.down) return;
    ptr.down = true; ptr.id = e.pointerId;
    ptr.x = e.clientX; ptr.y = e.clientY; ptr.vx = ptr.vy = 0; ptr.lt = performance.now();
    canvas.setPointerCapture(e.pointerId);
    var R = 0.24 * minWH, list = [];
    for (var i = 0; i < nB; i++) list.push([Math.hypot(bx[i] - ptr.x, by[i] - ptr.y), i]);
    list.sort(function (a, b) { return a[0] - b[0]; });
    var grab = [];
    for (var k = 0; k < list.length && grab.length < 36; k++) {
      if (list[k][0] < R || (grab.length < 10 && list[k][0] < 0.7 * minWH)) grab.push(list[k][1]);
      else if (list[k][0] < 1.8 * R) {
        var o = list[k][1], d = list[k][0] || 1;
        heat[o] = Math.max(heat[o], 0.5);
        bvx[o] += (bx[o] - ptr.x) / d * base * 0.8; bvy[o] += (by[o] - ptr.y) / d * base * 0.8;
      }
    }
    var hx = 0, hy = 0;
    for (k = 0; k < grab.length; k++) { hx += Math.cos(bh[grab[k]]); hy += Math.sin(bh[grab[k]]); }
    G = newSkein(1, Math.atan2(hy, hx));
    G.ptr = true; G.cone = 0.55;
    VL.x = ptr.x; VL.y = ptr.y; VL.vx = VL.vy = 0; VL.orb = 0; VL.th = G.ang - Math.PI / 2;
    var c = Math.cos(G.ang), n = Math.sin(G.ang);
    for (k = 0; k < grab.length; k++) {
      var b = grab[k];
      detach(b);
      bSk[b] = G; heat[b] = 1;
      var dx = bx[b] - ptr.x, dy = by[b] - ptr.y, dd = Math.hypot(dx, dy) || 1;
      bvx[b] -= dx / dd * base * 0.8; bvy[b] -= dy / dd * base * 0.8;
      var left = (c * dy - n * dx) > 0;
      if (left && G.L.length > G.R.length + 1) left = false;
      if (!left && G.R.length > G.L.length + 1) left = true;
      (left ? G.L : G.R).push(b);
    }
    skeins.push(G);
    prune();
    SFX.play('spawn');
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!ptr.down || e.pointerId !== ptr.id) return;
    var now = performance.now(), dt = Math.max(0.008, (now - ptr.lt) / 1000);
    var x = e.clientX, y = e.clientY;
    var k = Math.min(1, dt * 12);
    ptr.vx += ((x - ptr.x) / dt - ptr.vx) * k;
    ptr.vy += ((y - ptr.y) / dt - ptr.vy) * k;
    ptr.x = x; ptr.y = y; ptr.lt = now;
    SFX.play('move', 150);
  });
  function up(e) {
    if (!ptr.down || (e && e.pointerId !== ptr.id)) return;
    ptr.down = false;
    if (G) {
      G.ptr = false;
      var A = G.L.length >= G.R.length ? G.L : G.R;
      G.lead = A.length ? A.shift() : -1;
      G.off = wa(G.ang - gAng);
      G.timer = rnd(12, 24);
      G = null;
      prune();
      SFX.play('ripple');
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speedK = 0.3 + this.value / 100 * 1.4; });
  document.getElementById('k-count').addEventListener('change', function () { density = this.value / 100; build(); SFX.play('click'); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    ptr.vx *= Math.exp(-dt * 5); ptr.vy *= Math.exp(-dt * 5);
    step(dt);
    render();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
