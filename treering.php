<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('treering');

$pageName = 'treering';
$pageTitle = 'TREE RING - PULSE';
$pageDesc = '丸太の断面に年輪が外側へ1本ずつ増え、よく育った年は広く、育たなかった年は狭い縞になって、ときどき枝の節のまわりで年輪がふくらむ。押すとそこに水色の節ができて外側の年輪が盛り上がり、なぞると年輪が指の動いた向きに引きずられる。';
$pageCanonical = 'https://pulse.subara3.com/treering.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-knots">KNOTS</label><input type="range" id="k-knots" min="0" max="100" value="45"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 年輪は中心から見た角度ごとの半径（M 本）で持つ。
     1 本の幅は今の半径に比例させ、画面は外周が一定の大きさに見えるよう縮め続ける。
     こうすると年輪は外で生まれて内へ流れ、いつまでも同じ密度に見える。 */
  var M = 180, TAU = Math.PI * 2;
  var COS = new Float32Array(M), SIN = new Float32Array(M);
  for (var a0 = 0; a0 < M; a0++) { COS[a0] = Math.cos(a0 / M * TAU); SIN[a0] = Math.sin(a0 / M * TAU); }

  var rings = [];                 /* { r: Float32Array, clim } 内側から外側へ */
  var from = null, to = null, cur = new Float32Array(M), prog = 0, toClim = 1;
  var clim = 1, lean = 0.14, leanA = Math.random() * TAU;
  var harm = [];
  for (var hk = 2; hk <= 6; hk++) harm.push({ k: hk, amp: 0.015 + Math.random() * 0.025, ph: Math.random() * TAU });
  for (var hw = 9; hw <= 15; hw += 2) harm.push({ k: hw, amp: 0.004 + Math.random() * 0.005, ph: Math.random() * TAU });
  var knots = [];                 /* { th, r0, rEnd, s, glow, push } */
  var scars = [];                 /* なぞった跡。世界座標に置き、指の向きに年輪を引きずる */
  var barkN = new Float32Array(M), barkF = new Float32Array(M);
  var rays = [];
  var sc = 1, CX = 0, CY = 0, Rscr = 300;
  var speed = 0.45, knotAmt = 0.45;
  var ringCount = 0, knotTimer = 4;

  /* 押しのけの場は粗い格子で計算し、点ごとには双線形で拾う */
  var GX = 0, GY = 0, GS = 1, DXg = null, DYg = null;
  var down = false, px = 0, py = 0, pAmt = 0, lastX = 0, lastY = 0, dragAcc = 0;

  function angDiff(a, b) { var d = a - b; return d - TAU * Math.round(d / TAU); }
  function rAt(r, th) { return r[Math.round(((th % TAU) + TAU) % TAU / TAU * M) % M]; }
  function mean(r) { var s = 0; for (var i = 0; i < M; i++) s += r[i]; return s / M; }

  function smoothNoise(out, amp) {
    var t = new Float32Array(M);
    for (var i = 0; i < M; i++) t[i] = Math.random() * 2 - 1;
    for (var pass = 0; pass < 3; pass++) {
      for (var j = 0; j < M; j++) out[j] = (t[(j + M - 1) % M] + t[j] * 2 + t[(j + 1) % M]) / 4;
      t.set(out);
    }
    for (var k = 0; k < M; k++) out[k] *= amp;
  }

  /* 次の 1 本: 前の年輪から、年ごとの太り方（clim）と偏り・揺らぎ・節のふくらみで外へ広げる */
  function nextRing(prev) {
    clim += (Math.random() - 0.5) * 0.55 + (1 - clim) * 0.15;
    if (Math.random() < 0.08) clim = 0.45 + Math.random() * 0.25;   /* ときどき細い年 */
    clim = Math.max(0.4, Math.min(1.7, clim));
    leanA += (Math.random() - 0.5) * 0.35;
    for (var h = 0; h < harm.length; h++) harm[h].ph += (Math.random() - 0.5) * 0.12;
    var R = mean(prev), w = R * 0.034 * clim;
    var out = new Float32Array(M);
    for (var i = 0; i < M; i++) {
      var th = i / M * TAU;
      var g = 1 + lean * Math.cos(th - leanA);
      for (var k = 0; k < harm.length; k++) g += harm[k].amp * Math.cos(harm[k].k * th + harm[k].ph);
      for (var n = 0; n < knots.length; n++) {
        var kn = knots[n];
        if (kn.s < 0.06 || kn.r0 > prev[i] * 1.02) continue;
        var d = angDiff(th, kn.th) / 0.14;
        g += kn.s * 2.4 * Math.exp(-d * d);
      }
      out[i] = prev[i] + w * Math.max(0.2, g);
    }
    for (var m = 0; m < knots.length; m++) {
      var q = knots[m];
      if (q.s >= 0.06 && q.r0 <= R * 1.02) { q.s *= 0.88; if (q.s < 0.06) q.rEnd = R; }
    }
    toClim = clim;
    return out;
  }

  function pushRing(r, c) {
    rings.push({ r: r, clim: c });
    if (rings.length > 240) rings.shift();
  }

  function seed() {
    rings.length = 0; knots.length = 0; scars.length = 0;
    var r = new Float32Array(M);
    for (var i = 0; i < M; i++) r[i] = 1;
    pushRing(r, 1);
    for (var n = 0; n < 150; n++) {
      if (n === 95 || n === 125) { var t0 = Math.random() * TAU; addKnot(t0, rAt(r, t0) * 0.97, 0.8, 0); }
      r = nextRing(r); pushRing(r, toClim);
    }
    from = r; to = nextRing(from); prog = 0;
    smoothNoise(barkN, 1); smoothNoise(barkF, 1);
    rays.length = 0;
    for (var k = 0; k < 34; k++) rays.push({ th: Math.random() * TAU, f: 0.15 + Math.random() * 0.5 });
  }

  function addKnot(th, r0, s, glow) {
    knots.push({ th: th, r0: r0, rEnd: 0, s: s, glow: glow, push: glow > 0 ? 1 : 0 });
    if (knots.length > 40) knots.shift();
  }

  /* 押した節より外の年輪を、節の向きに少しずつ持ち上げる（0.4 秒ほどで出し切る） */
  function applyPush(kn, amt) {
    var list = rings.map(function (o) { return o.r; });
    list.push(to);   /* from は rings の最後と同じ配列 */
    for (var k = 0; k < list.length; k++) {
      var r = list[k], R = mean(r);
      if (R < kn.r0 * 0.97) continue;
      var f = Math.min(1, (R - kn.r0 * 0.97) / (kn.r0 * 0.12 + 1e-6));
      for (var i = 0; i < M; i++) {
        var d = angDiff(i / M * TAU, kn.th) / 0.13;
        if (d > 3 || d < -3) continue;
        r[i] += R * 0.05 * f * amt * Math.exp(-d * d);
      }
    }
  }

  function resizeGrid() {
    GS = Math.max(W, H) / 46;
    GX = Math.ceil(W / GS) + 2; GY = Math.ceil(H / GS) + 2;
    DXg = new Float32Array(GX * GY); DYg = new Float32Array(GX * GY);
  }

  function field() {
    DXg.fill(0); DYg.fill(0);
    if (pAmt > 0.001) {
      var sg = 70 + 0.06 * Math.min(W, H), A = 0.75 * pAmt, lim = sg * 2.6;
      bump(px - lim, py - lim, px + lim, py + lim, function (x, y, k) {
        var dx = x - px, dy = y - py, e = A * Math.exp(-(dx * dx + dy * dy) / (sg * sg));
        DXg[k] += dx * e; DYg[k] += dy * e;
      });
    }
    for (var n = 0; n < scars.length; n++) {
      var s = scars[n], sx = CX + s.x * sc, sy = CY + s.y * sc, ss = 56, amp = 14 * s.life;
      var vx = s.vx * amp, vy = s.vy * amp;
      bump(sx - ss * 2.6, sy - ss * 2.6, sx + ss * 2.6, sy + ss * 2.6, function (x, y, k) {
        var dx = x - sx, dy = y - sy, e = Math.exp(-(dx * dx + dy * dy) / (ss * ss));
        DXg[k] += vx * e; DYg[k] += vy * e;
      });
    }
    /* 重なった跡で押しのけが大きくなりすぎて年輪が折れ返らないよう、頭打ちにする */
    var L = 34;
    for (var q = 0; q < DXg.length; q++) {
      var mg = Math.hypot(DXg[q], DYg[q]);
      if (mg > 1) { var f = L / Math.sqrt(L * L + mg * mg); DXg[q] *= f; DYg[q] *= f; }
    }
  }
  function bump(x0, y0, x1, y1, fn) {
    var i0 = Math.max(0, Math.floor(x0 / GS)), i1 = Math.min(GX - 1, Math.ceil(x1 / GS));
    var j0 = Math.max(0, Math.floor(y0 / GS)), j1 = Math.min(GY - 1, Math.ceil(y1 / GS));
    for (var j = j0; j <= j1; j++) for (var i = i0; i <= i1; i++) fn(i * GS, j * GS, j * GX + i);
  }
  var OX = 0, OY = 0;
  function disp(x, y) {
    var gx = x / GS, gy = y / GS;
    if (gx < 0 || gy < 0 || gx >= GX - 1 || gy >= GY - 1) { OX = x; OY = y; return; }
    var i = gx | 0, j = gy | 0, fx = gx - i, fy = gy - j, k = j * GX + i;
    var a = DXg[k] + (DXg[k + 1] - DXg[k]) * fx, b = DXg[k + GX] + (DXg[k + GX + 1] - DXg[k + GX]) * fx;
    var c = DYg[k] + (DYg[k + 1] - DYg[k]) * fx, d = DYg[k + GX] + (DYg[k + GX + 1] - DYg[k + GX]) * fx;
    OX = x + a + (b - a) * fy; OY = y + c + (d - c) * fy;
  }

  function ringPath(r, mul, stepN) {
    ctx.beginPath();
    for (var i = 0; i < M; i += stepN) {
      var rr = r[i] * mul * sc;
      disp(CX + COS[i] * rr, CY + SIN[i] * rr);
      if (i === 0) ctx.moveTo(OX, OY); else ctx.lineTo(OX, OY);
    }
    ctx.closePath();
  }

  var mid = new Float32Array(M), bark = new Float32Array(M);

  function draw() {
    var Rc = mean(cur);
    sc = Rscr / Rc;
    ctx.fillStyle = P.rgba(P.deep, 1);
    ctx.fillRect(0, 0, W, H);
    P.grid(ctx, W, H, { alpha: 0.8 });

    /* 樹皮の外形と木口の面 */
    for (var i = 0; i < M; i++) bark[i] = cur[i] * (1.05 + 0.016 * barkN[i] + 0.012 * Math.abs(barkF[(i * 7) % M]));
    ringPath(bark, 1, 1);
    ctx.fillStyle = P.rgba(P.bg, 1);
    ctx.fill();
    ctx.strokeStyle = P.rgba(P.steel, 0.55);
    ctx.lineWidth = 1.2;
    ctx.stroke();

    /* 放射の筋（細く薄い） */
    ctx.strokeStyle = P.rgba(P.steel, 0.09);
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    for (var q = 0; q < rays.length; q++) {
      var ry = rays[q], ii = Math.round(ry.th / TAU * M) % M, rOut = cur[ii] * sc;
      for (var s = 0; s <= 10; s++) {
        var rr = rOut * (ry.f + (1 - ry.f) * s / 10);
        disp(CX + COS[ii] * rr, CY + SIN[ii] * rr);
        if (s === 0) ctx.moveTo(OX, OY); else ctx.lineTo(OX, OY);
      }
    }
    ctx.stroke();

    /* 年輪。間隔が詰まった内側ほど薄くする */
    var prevMean = 0, prevR = null;
    var dpxMin = 1.2;
    for (var k = 0; k < rings.length; k++) {
      var rg = rings[k], rm = mean(rg.r);
      var spacing = (rm - prevMean) * sc, scr = rm * sc;
      var pr = prevR;
      prevMean = rm; prevR = rg.r;
      if (scr < 4 || spacing < dpxMin) continue;
      var al = Math.min(1, (spacing - dpxMin) / 3.5);
      var stepN = scr < 60 ? 4 : scr < 200 ? 2 : 1;
      var narrow = Math.max(0, Math.min(1, (1.3 - rg.clim) / 0.9));
      if (pr && spacing > 8) {
        for (var m2 = 0; m2 < M; m2++) mid[m2] = pr[m2] + (rg.r[m2] - pr[m2]) * 0.62;
        ringPath(mid, 1, stepN);
        ctx.strokeStyle = P.rgba(P.steel, 0.22 * al);
        ctx.lineWidth = 0.7;
        ctx.stroke();
      }
      ringPath(rg.r, 1, stepN);
      ctx.strokeStyle = P.rgba(P.line, (0.32 + 0.4 * narrow) * al);
      ctx.lineWidth = 0.7 + 0.9 * narrow;
      ctx.stroke();
    }
    /* いま太っている外周（形成層） */
    ringPath(cur, 1, 1);
    ctx.strokeStyle = P.rgba(P.line, 0.85);
    ctx.lineWidth = 1.3;
    ctx.stroke();

    /* 樹皮の割れ目 */
    ctx.strokeStyle = P.rgba(P.steel, 0.4);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var b = 0; b < M; b += 3) {
      var f2 = barkF[b];
      if (f2 < -0.05) continue;
      var r1 = cur[b] * sc * 1.008, r2 = bark[b] * sc * (0.99 - 0.02 * f2);
      disp(CX + COS[b] * r1, CY + SIN[b] * r1); ctx.moveTo(OX, OY);
      disp(CX + COS[b] * r2, CY + SIN[b] * r2); ctx.lineTo(OX, OY);
    }
    ctx.stroke();

    /* 節 */
    for (var n = 0; n < knots.length; n++) drawKnot(knots[n], Rc);

    /* 触っているところだけ、年輪をシアンでなぞり直す */
    if (pAmt > 0.02) {
      var cr = 80 + 0.05 * Math.min(W, H);
      ctx.save();
      ctx.beginPath(); ctx.arc(px, py, cr, 0, TAU); ctx.clip();
      var g = ctx.createRadialGradient(px, py, 0, px, py, cr);
      g.addColorStop(0, P.rgba(P.accent, 0.12 * pAmt)); g.addColorStop(1, P.rgba(P.accent, 0));
      ctx.fillStyle = g; ctx.fillRect(px - cr, py - cr, cr * 2, cr * 2);
      var dc = Math.hypot(px - CX, py - CY);
      prevMean = 0;
      for (var k2 = 0; k2 < rings.length; k2++) {
        var rm2 = mean(rings[k2].r), sp2 = (rm2 - prevMean) * sc;
        prevMean = rm2;
        if (sp2 < 2.5 || Math.abs(rm2 * sc - dc) > cr * 1.6) continue;
        ringPath(rings[k2].r, 1, 1);
        ctx.strokeStyle = P.rgba(P.accent, 0.7 * pAmt);
        ctx.lineWidth = 1.1;
        ctx.stroke();
      }
      ctx.restore();
    }
  }

  function drawKnot(kn, Rc) {
    var ii = Math.round(((kn.th % TAU) + TAU) % TAU / TAU * M) % M;
    var r0 = kn.r0 * sc;
    if (r0 < 6) return;
    var rEnd = (kn.s >= 0.06 ? cur[ii] : kn.rEnd) * sc;
    var size = Math.max(2, Math.min(11, kn.r0 * 0.04 * sc));
    var col = kn.glow > 0.01 ? P.mix(P.line, P.accent, kn.glow) : P.line;
    var ux = Math.cos(kn.th), uy = Math.sin(kn.th);
    /* 枝の通り道: 節から外へ細くなる筋 */
    if (rEnd > r0 + 2) {
      ctx.beginPath();
      var steps = 10;
      for (var s = 0; s <= steps; s++) {
        var rr = r0 + (rEnd - r0) * s / steps, wdt = size * (1 - 0.7 * s / steps);
        disp(CX + ux * rr - uy * wdt, CY + uy * rr + ux * wdt);
        if (s === 0) ctx.moveTo(OX, OY); else ctx.lineTo(OX, OY);
      }
      for (var s2 = steps; s2 >= 0; s2--) {
        var rr2 = r0 + (rEnd - r0) * s2 / steps, wd2 = size * (1 - 0.7 * s2 / steps);
        disp(CX + ux * rr2 + uy * wd2, CY + uy * rr2 - ux * wd2);
        ctx.lineTo(OX, OY);
      }
      ctx.closePath();
      ctx.fillStyle = P.rgba(P.deep, 0.75);
      ctx.fill();
      ctx.strokeStyle = P.rgba(col, 0.35 + 0.4 * kn.glow);
      ctx.lineWidth = 0.8;
      ctx.stroke();
    }
    /* 節の芯: 外へ長い小さな楕円の重なり */
    disp(CX + ux * r0, CY + uy * r0);
    ctx.save();
    ctx.translate(OX, OY);
    ctx.rotate(kn.th);
    for (var e = 3; e >= 1; e--) {
      ctx.beginPath();
      ctx.ellipse(0, 0, size * (0.6 + e * 0.55), size * (0.4 + e * 0.38), 0, 0, TAU);
      if (e === 3) { ctx.fillStyle = P.rgba(P.deep, 0.9); ctx.fill(); }
      ctx.strokeStyle = P.rgba(col, (0.35 + 0.15 * e) * (0.6 + 0.4 * kn.glow));
      ctx.lineWidth = 0.9;
      ctx.stroke();
    }
    ctx.fillStyle = P.rgba(col, 0.8);
    ctx.beginPath(); ctx.arc(0, 0, Math.max(1, size * 0.35), 0, TAU); ctx.fill();
    ctx.restore();
  }

  function renorm() {
    var Rc = mean(cur);
    if (Rc < 2000) return;
    var f = 1 / Rc;
    for (var k = 0; k < rings.length; k++) { var r = rings[k].r; for (var i = 0; i < M; i++) r[i] *= f; }
    for (var j = 0; j < M; j++) { from[j] *= f; to[j] *= f; cur[j] *= f; }
    for (var n = 0; n < knots.length; n++) { knots[n].r0 *= f; knots[n].rEnd *= f; }
    for (var s = 0; s < scars.length; s++) { scars[s].x *= f; scars[s].y *= f; }
  }

  function update(dt) {
    var mf = mean(from), mt = mean(to);
    prog += dt * (0.008 + speed * 0.04) * mf / Math.max(1e-6, mt - mf);
    while (prog >= 1) {
      prog -= 1;
      pushRing(to, toClim);
      from = to; to = nextRing(from);
      ringCount++;
      if (ringCount % 5 === 0) SFX.play('tick', 4000);
      mf = mean(from); mt = mean(to);
    }
    for (var i = 0; i < M; i++) cur[i] = from[i] + (to[i] - from[i]) * prog;
    renorm();

    knotTimer -= dt * (0.15 + knotAmt * 1.2) * (0.4 + speed * 1.3);
    if (knotTimer <= 0 && knotAmt > 0.01) {
      knotTimer = 6 + Math.random() * 5;
      var ta = Math.random() * TAU;
      addKnot(ta, rAt(cur, ta) * 0.97, 0.6 + Math.random() * 0.4, 0);
      SFX.play('grow', 3000);
    }
    for (var n = 0; n < knots.length; n++) {
      var kn = knots[n];
      kn.glow = Math.max(0, kn.glow - dt * 0.25);
      if (kn.push > 0) { var amt = Math.min(kn.push, dt * 2.6); kn.push -= amt; applyPush(kn, amt); }
    }
    for (var m = knots.length - 1; m >= 0; m--) if (knots[m].r0 * sc < 5) knots.splice(m, 1);

    pAmt += ((down ? 1 : 0) - pAmt) * Math.min(1, dt * (down ? 9 : 3));
    for (var s = scars.length - 1; s >= 0; s--) {
      scars[s].life -= dt * 0.2;
      if (scars[s].life <= 0) scars.splice(s, 1);
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  seed();
  for (var i0 = 0; i0 < M; i0++) cur[i0] = from[i0];
  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h; CX = W / 2; CY = H / 2;
    Rscr = 0.42 * Math.max(W, H);
    if (Rscr > Math.hypot(W, H) * 0.44) Rscr = Math.hypot(W, H) * 0.44;
    resizeGrid();
  });

  /* ---------------------------------------------------------- 操作 */
  function toWorld(x, y) { return { x: (x - CX) / sc, y: (y - CY) / sc }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = lastX = e.clientX; py = lastY = e.clientY; dragAcc = 0;
    var w = toWorld(px, py), th = Math.atan2(w.y, w.x), Rb = rAt(cur, th);
    var r0 = Math.max(Rb * 0.12, Math.min(Rb * 0.95, Math.hypot(w.x, w.y)));
    addKnot(th, r0, 1.1, 1);
    SFX.play('grow');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY, dx = x - lastX, dy = y - lastY, d = Math.hypot(dx, dy);
    px = x; py = y;
    if (d < 22) return;
    var w = toWorld(x, y);
    scars.push({ x: w.x, y: w.y, vx: dx / d, vy: dy / d, life: 1 });
    if (scars.length > 60) scars.shift();
    dragAcc += d;
    if (dragAcc > 110) {
      dragAcc = 0;
      var tw = Math.atan2(w.y, w.x), Rd = rAt(cur, tw), rr = Math.hypot(w.x, w.y);
      if (rr > Rd * 0.12 && rr < Rd * 0.95) addKnot(tw, rr, 0.45, 0.8);
    }
    lastX = x; lastY = y;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = this.value / 100; });
  document.getElementById('k-knots').addEventListener('input', function () { knotAmt = this.value / 100; });
  speed = document.getElementById('k-speed').value / 100;
  knotAmt = document.getElementById('k-knots').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    update(dt);
    field();
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
