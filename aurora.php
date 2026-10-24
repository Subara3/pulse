<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('aurora');

$pageName = 'aurora';
$pageTitle = 'AURORA - PULSE';
$pageDesc = '夜空にオーロラが3本かかり、縦の細い光の筋を並べた帯がゆっくり波打ちながら、ところどころで折れ重なる。下の湖には、その光が逆さに映る。押すと近くの帯がそこへ引き寄せられて水色に光り、明るいところが帯に沿って左右へ走る。なぞると帯が指についてきて、ひだが寄る。';
$pageCanonical = 'https://pulse.subara3.com/aurora.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-fold">FOLD</label><input type="range" id="k-fold" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-glow">GLOW</label><input type="range" id="k-glow" min="0" max="100" value="60"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;
  var TAU = Math.PI * 2;

  /* 幕は 3 枚。奥ほど低く、短く、暗い */
  var SPEC = [
    { base: 0.58, hgt: 0.52, amp: 1.0,  lw: 1.3, k1: 2.1, k2: 3.7, w1: 0.32, w2: 0.21, lean: 0.10, ph: 0.0 },
    { base: 0.75, hgt: 0.36, amp: 0.85, lw: 1.1, k1: 2.7, k2: 4.6, w1: 0.26, w2: 0.34, lean: 0.07, ph: 2.1 },
    { base: 0.90, hgt: 0.22, amp: 0.72, lw: 1.0, k1: 3.3, k2: 5.3, w1: 0.38, w2: 0.24, lean: 0.05, ph: 4.2 }
  ];
  var curtains = [], pulses = [];
  var hy = 0, dpr = 1;
  var sky, aur, actx, fg;
  var t = 0, speed = 1, fold = 1, glow = 1;
  var LEV = 12;
  var bucketW = [], bucketA = [];
  for (var q = 0; q <= LEV; q++) { bucketW.push([]); bucketA.push([]); }

  function makeCurtain(sp) {
    var n = Math.round(Math.max(220, Math.min(600, W / 2.2)));
    var c = { sp: sp, n: n,
      x: new Float32Array(n), y: new Float32Array(n),
      dx: new Float32Array(n), dy: new Float32Array(n),
      vx: new Float32Array(n), vy: new Float32Array(n),
      e: new Float32Array(n), r: new Float32Array(n), rp: new Float32Array(n), rr: new Float32Array(n) };
    for (var i = 0; i < n; i++) {
      c.r[i] = Math.random();
      c.rp[i] = Math.random() * TAU;
      c.rr[i] = 0.3 + Math.random() * 0.7;
    }
    return c;
  }

  /* 空・星・山・湖は動かないので、サイズが変わったときだけ描いておく */
  function layer(h) {
    var cv = document.createElement('canvas');
    cv.width = Math.round(W * dpr); cv.height = Math.round(h * dpr);
    var c = cv.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { cv: cv, c: c };
  }

  function ridge(x) {
    var u = x / W;
    return hy - H * (0.035 + 0.03 * Math.sin(u * 7.1 + 1.3) * Math.sin(u * 2.3 + 0.4)
      + 0.012 * Math.sin(u * 23 + 2) + 0.006 * Math.sin(u * 57));
  }

  function build() {
    hy = Math.round(H * 0.78);
    sky = layer(H);
    var g = sky.c.createLinearGradient(0, 0, 0, hy);
    g.addColorStop(0, P.hex(P.deep));
    g.addColorStop(1, P.hex(P.bg));
    sky.c.fillStyle = g;
    sky.c.fillRect(0, 0, W, H);
    var ns = Math.round(W * hy / 1800);
    for (var i = 0; i < ns; i++) {
      var a = 0.15 + Math.pow(Math.random(), 3) * 0.5;
      sky.c.fillStyle = P.rgba(Math.random() < 0.3 ? P.line : P.steel, a);
      sky.c.beginPath();
      sky.c.arc(Math.random() * W, Math.random() * hy, 0.4 + Math.random() * 0.8, 0, TAU);
      sky.c.fill();
    }

    aur = layer(hy);
    actx = aur.c;

    fg = layer(H);
    var f = fg.c, x;
    /* 湖: 映り込みを少し沈める */
    var lg = f.createLinearGradient(0, hy, 0, H);
    lg.addColorStop(0, P.rgba(P.bg, 0.2));
    lg.addColorStop(1, P.rgba(P.deep, 0.85));
    f.fillStyle = lg;
    f.fillRect(0, hy, W, H - hy);
    f.strokeStyle = P.rgba(P.deep, 0.5);
    f.lineWidth = 1;
    f.beginPath();
    for (var yy = hy + 3; yy < H; yy += 3 + (yy - hy) * 0.06) {
      f.moveTo(0, yy + 0.5); f.lineTo(W, yy + 0.5);
    }
    f.stroke();
    /* 山と、その逆さの影 */
    f.fillStyle = P.hex(P.deep);
    f.beginPath(); f.moveTo(0, hy);
    for (x = 0; x <= W; x += 4) f.lineTo(x, ridge(x));
    f.lineTo(W, hy); f.closePath(); f.fill();
    f.fillStyle = P.rgba(P.deep, 0.8);
    f.beginPath(); f.moveTo(0, hy);
    for (x = 0; x <= W; x += 4) f.lineTo(x, 2 * hy - ridge(x));
    f.lineTo(W, hy); f.closePath(); f.fill();
    f.strokeStyle = P.rgba(P.steel, 0.45);
    f.beginPath();
    for (x = 0; x <= W; x += 4) { if (x) f.lineTo(x, ridge(x)); else f.moveTo(x, ridge(x)); }
    f.stroke();
    f.strokeStyle = P.rgba(P.steel, 0.25);
    f.beginPath(); f.moveTo(0, hy + 0.5); f.lineTo(W, hy + 0.5); f.stroke();

    curtains = SPEC.map(makeCurtain);
    pulses.length = 0;
  }

  /* 幕の形: 横に折れる波を 2 本重ねる。振れ幅が大きいと幕が自分の上に折り返す */
  function shape(c, tt) {
    var sp = c.sp, n = c.n, span = W * 1.2, x0s = -W * 0.1;
    var F = fold * (0.65 + 0.35 * Math.sin(0.043 * tt + sp.ph));
    var Ax = F * 1.8 * span / (TAU * sp.k1);
    var by = hy * sp.base;
    for (var i = 0; i < n; i++) {
      var s = i / (n - 1);
      var x0 = x0s + s * span + Ax * (0.7 * Math.sin(TAU * sp.k1 * s - sp.w1 * tt + sp.ph)
        + 0.3 * (sp.k1 / sp.k2) * Math.sin(TAU * sp.k2 * s + sp.w2 * tt));
      var y0 = by + hy * 0.08 * Math.sin(TAU * 1.3 * s + 0.2 * tt + sp.ph)
        + hy * 0.025 * Math.sin(TAU * 3.1 * s - 0.31 * tt);
      c.x[i] = x0 + c.dx[i];
      c.y[i] = y0 + c.dy[i];
    }
  }

  /* 押し出された分はばねで戻り、隣へ伝わって幕の上を波として走る */
  var down = false, px = 0, py = 0, pvx = 0, pvy = 0;
  function physics(c, h) {
    var n = c.n, K = 6, C = 3, Q = 200, G = 34;
    var Rx = 0.09 * (W + H) / 2 + 40, Ry = 0.35 * hy;
    for (var i = 0; i < n; i++) {
      var l = i > 0 ? i - 1 : i, r = i < n - 1 ? i + 1 : i;
      var ax = -K * c.dx[i] - C * c.vx[i] + Q * (c.dx[l] + c.dx[r] - 2 * c.dx[i]);
      var ay = -K * c.dy[i] - C * c.vy[i] + Q * (c.dy[l] + c.dy[r] - 2 * c.dy[i]);
      if (down) {
        var ex = px - c.x[i], ey = py - c.y[i];
        var w = Math.exp(-ex * ex / (Rx * Rx) - ey * ey / (Ry * Ry));
        if (w > 0.01) {
          ax += G * w * ex + w * pvx * 0.8;
          ay += G * w * ey + w * pvy * 0.8;
          if (w * 0.95 > c.e[i]) c.e[i] = w * 0.95;
        }
      }
      c.vx[i] += ax * h; c.vy[i] += ay * h;
    }
    for (var j = 0; j < n; j++) { c.dx[j] += c.vx[j] * h; c.dy[j] += c.vy[j] * h; }
  }

  function poke(x, y, kick) {
    var Rx = 0.09 * (W + H) / 2 + 40, Ry = 0.35 * hy;
    for (var k = 0; k < curtains.length; k++) {
      var c = curtains[k], best = -1, bw = 0;
      for (var i = 0; i < c.n; i++) {
        var ex = x - c.x[i], ey = y - c.y[i];
        var w = Math.exp(-ex * ex / (Rx * Rx) - ey * ey / (Ry * Ry));
        if (w < 0.01) continue;
        c.vx[i] += ex * w * kick; c.vy[i] += ey * w * kick;
        if (w > c.e[i]) c.e[i] = w;
        if (w > bw) { bw = w; best = i; }
      }
      if (best >= 0 && bw > 0.15) {
        var s = best / (c.n - 1);
        pulses.push({ c: k, s: s, dir: 1, t: 0, amp: 0.9 * bw, acc: true });
        pulses.push({ c: k, s: s, dir: -1, t: 0, amp: 0.9 * bw, acc: true });
      }
    }
  }

  function flushBuckets(color, buckets, lw) {
    actx.lineWidth = lw;
    for (var q = 1; q <= LEV; q++) {
      var b = buckets[q];
      if (!b.length) continue;
      actx.strokeStyle = P.rgba(color, q / LEV * 0.85);
      actx.beginPath();
      for (var m = 0; m < b.length; m += 4) { actx.moveTo(b[m], b[m + 1]); actx.lineTo(b[m + 2], b[m + 3]); }
      actx.stroke();
      b.length = 0;
    }
  }

  var SEG = [0, 0.07, 0.32, 1], SEGA = [1, 0.5, 0.2];
  function drawCurtain(k, c, dt) {
    var sp = c.sp, n = c.n;
    var I = sp.amp * (0.55 + 0.45 * Math.sin(0.05 * t + sp.ph * 1.5)) * glow;
    var Lh = hy * sp.hgt, decay = Math.exp(-0.7 * dt);
    for (var i = 0; i < n; i++) {
      var s = i / (n - 1);
      var env = Math.min(1, s / 0.08, (1 - s) / 0.08);
      var band = 0.5 + 0.5 * Math.sin(TAU * 1.7 * s - 0.5 * t + sp.ph) * Math.sin(TAU * 0.6 * s + 0.17 * t);
      var stri = 0.55 + 0.45 * Math.sin(c.rp[i] + t * c.rr[i]);
      var pb = 0;
      for (var p = 0; p < pulses.length; p++) {
        var u = pulses[p];
        if (u.c !== k) continue;
        var d = (s - u.s) / 0.035, g = Math.exp(-d * d) * u.amp * (1 - u.t / 5);
        pb += g;
        if (u.acc && g * 0.8 > c.e[i]) c.e[i] = g * 0.8;
      }
      var e = c.e[i];
      c.e[i] = e * decay;
      var b = (I * env * stri * (0.2 + 0.8 * band * band) + pb * 0.7 + e * 0.6) * 1.5;
      if (b < 0.02) continue;
      var L = Lh * (0.45 + 0.55 * (0.5 + 0.5 * Math.sin(TAU * 0.9 * s + 0.23 * t + sp.ph))) * (0.85 + 0.3 * c.r[i]) * (1 + e * 0.25);
      var x = c.x[i], y = c.y[i], lx = sp.lean * L;
      var bk = e > 0.22 ? bucketA : bucketW;
      for (var sg = 0; sg < 3; sg++) {
        var a = b * SEGA[sg];
        var lv = Math.min(LEV, Math.round(a * LEV));
        if (lv < 1) continue;
        var f0 = SEG[sg], f1 = SEG[sg + 1];
        bk[lv].push(x + lx * f0, y - L * f0, x + lx * f1, y - L * f1);
      }
    }
    flushBuckets(P.line, bucketW, sp.lw);
    flushBuckets(P.accent, bucketA, sp.lw);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h, r) { W = w; H = h; dpr = r || 1; build(); });

  /* ---------------------------------------------------------- 操作 */
  /* 湖を触ったら、映っている空の位置を触ったことにする */
  function pos(e) {
    var y = e.clientY;
    if (y > hy) y = 2 * hy - y;
    return { x: e.clientX, y: y };
  }
  var lastP = null, lastPT = 0;
  canvas.addEventListener('pointerdown', function (e) {
    var p = pos(e);
    down = true; px = p.x; py = p.y; pvx = pvy = 0;
    lastP = p; lastPT = performance.now();
    poke(p.x, p.y, 3.5);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now(), ddt = Math.max(0.008, (now - lastPT) / 1000);
    pvx = pvx * 0.5 + (p.x - lastP.x) / ddt * 0.5;
    pvy = pvy * 0.5 + (p.y - lastP.y) / ddt * 0.5;
    px = p.x; py = p.y; lastP = p; lastPT = now;
    SFX.play('move', 160);
  });
  function up() { down = false; pvx = pvy = 0; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speed = 0.3 + document.getElementById('k-speed').value / 100 * 1.75; }
  function readFold() { fold = 0.3 + document.getElementById('k-fold').value / 100 * 1.2; }
  function readGlow() { glow = 0.4 + document.getElementById('k-glow').value / 100 * 1.0; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-fold').addEventListener('input', readFold);
  document.getElementById('k-glow').addEventListener('input', readGlow);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readFold(); readGlow();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), nextPulse = 1.5;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    t += dt * speed;
    pvx *= Math.exp(-6 * dt); pvy *= Math.exp(-6 * dt);

    nextPulse -= dt * speed;
    if (nextPulse < 0) {
      nextPulse = 2.5 + Math.random() * 3;
      pulses.push({ c: (Math.random() * 3) | 0, s: 0.1 + Math.random() * 0.8, dir: Math.random() < 0.5 ? 1 : -1, t: 0, amp: 0.45 + Math.random() * 0.3, acc: false });
    }
    for (var p = pulses.length - 1; p >= 0; p--) {
      var u = pulses[p];
      u.t += dt * speed; u.s += u.dir * 0.1 * dt * speed;
      if (u.t > 5) pulses.splice(p, 1);
    }

    actx.clearRect(0, 0, W, hy);
    for (var k = curtains.length - 1; k >= 0; k--) {
      var c = curtains[k];
      shape(c, t);
      physics(c, dt * 0.5); physics(c, dt * 0.5);
      drawCurtain(k, c, dt);
    }

    ctx.drawImage(sky.cv, 0, 0, W, H);
    ctx.drawImage(aur.cv, 0, 0, W, hy);
    /* 湖の映り込み: 水平線で上下を返す */
    var rh = H - hy;
    ctx.save();
    ctx.globalAlpha = 0.45;
    ctx.translate(0, 2 * hy);
    ctx.scale(1, -1);
    ctx.drawImage(aur.cv, 0, (hy - rh) * dpr, W * dpr, rh * dpr, 0, hy - rh, W, rh);
    ctx.restore();
    ctx.drawImage(fg.cv, 0, 0, W, H);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
