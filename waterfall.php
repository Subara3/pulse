<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('waterfall');

$pageName = 'waterfall';
$pageTitle = 'WATERFALL - PULSE';
$pageDesc = '崖の縁から水が何本もの白い筋になって落ち、下の滝つぼで泡としぶきを上げる。水の多いところは少しずつ横へ移っていく。押すとそこに岩が置かれて水が左右に割れ、なぞると岩が指についてきて、割れた流れも一緒に動く。滝つぼを押すと水面に大きな波が立つ。';
$pageCanonical = 'https://pulse.subara3.com/waterfall.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="60"></div>
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DPR = 1, ctx;

  /* 落ちる水: 1 粒 = 速さの向きに伸ばした短い線 */
  var CAP = 9000, N = 0;
  var px = new Float32Array(CAP), py = new Float32Array(CAP);
  var pvx = new Float32Array(CAP), pvy = new Float32Array(CAP);
  var pw = new Float32Array(CAP), pc = new Float32Array(CAP);
  var pb = new Uint8Array(CAP);

  /* しぶき(0)・泡(1)・霧(2)。古いものから上書きする輪の配列 */
  var SCAP = 2400, sHead = 0;
  var sx = new Float32Array(SCAP), sy = new Float32Array(SCAP);
  var svx = new Float32Array(SCAP), svy = new Float32Array(SCAP);
  var sl = new Float32Array(SCAP), sl0 = new Float32Array(SCAP);
  var st = new Uint8Array(SCAP), sc = new Float32Array(SCAP);

  /* 滝つぼの水面（1 次元の波） */
  var M = 180, hw = new Float32Array(M), hv = new Float32Array(M);

  /* 指が起こす流れ（粗い格子） */
  var GS = 24, GX = 0, GY = 0, fu = null, fv = null;

  var LIP = 0, POOL = 0, G = 0, DRAG = 0.73, AMP = 0;
  var rocks = [], boulders = [], surges = [];
  var bg = null;
  var flow = 0.6, windK = 0.4, time = 0, windX = 0;
  var surgeT = 4, humT = 2;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  /* 縁から落ちる水の量。ゆっくり横へずれる 3 つの波を重ねる */
  function qAt(x) {
    var u = x / W;
    var q = 0.66 + 0.26 * Math.sin(u * 9.1 + time * 0.07)
                 + 0.2 * Math.sin(u * 17.3 - time * 0.11 + 1.3)
                 + 0.12 * Math.sin(u * 31.7 + time * 0.05 + 2.1);
    for (var i = 0; i < surges.length; i++) {
      var s = surges[i], d = (x - s.x) / s.r;
      q += s.a * Math.exp(-d * d);
    }
    if (u < 0.03) q *= u / 0.03;
    if (u > 0.97) q *= (1 - u) / 0.03;
    return q;
  }
  function smooth(a, b, v) { var t = (v - a) / (b - a); t = t < 0 ? 0 : t > 1 ? 1 : t; return t * t * (3 - 2 * t); }

  function spawnWater(i) {
    var x = 0, q = 0;
    for (var k = 0; k < 10; k++) {
      x = W * (0.01 + 0.98 * Math.random());
      q = smooth(0.32, 0.78, qAt(x));
      if (Math.random() < q) break;
    }
    /* 細い筋に揃えて出す。上のほうでは糸のように続き、下で散る */
    x = Math.round(x / 5) * 5 + (Math.random() - 0.5) * 1.2;
    px[i] = x; py[i] = LIP + Math.random() * 3;
    pvx[i] = rnd(-2, 2); pvy[i] = rnd(14, 30);
    pw[i] = (0.3 + 0.7 * Math.random()) * (0.45 + 0.55 * q);
    pc[i] = 0;
  }

  function spray(x, y, vx, vy, life, type, cyan) {
    var i = sHead; sHead = (sHead + 1) % SCAP;
    sx[i] = x; sy[i] = y; svx[i] = vx; svy[i] = vy;
    sl[i] = sl0[i] = life; st[i] = type; sc[i] = cyan || 0;
  }

  function surfAt(x) {
    var i = (x / W * (M - 1)) | 0;
    if (i < 0) i = 0; else if (i > M - 1) i = M - 1;
    return hw[i] * AMP;
  }

  function rockShape(r) {
    var pts = [], n = 10;
    for (var k = 0; k < n; k++) pts.push(0.84 + Math.random() * 0.3);
    return pts;
  }

  function setCount() {
    var base = Math.max(2600, Math.min(7000, W * H / 140));
    var nn = Math.min(CAP, Math.round(base * (0.3 + flow * 0.95)));
    for (var i = N; i < nn; i++) { spawnWater(i); py[i] = LIP + Math.random() * (POOL - LIP) * 0.9; pvy[i] = G / DRAG * 0.6; }
    N = nn;
  }

  /* 背景: 崖の地層と縁。サイズが変わったときだけ描く */
  function buildBg() {
    bg = document.createElement('canvas');
    bg.width = Math.round(W * DPR); bg.height = Math.round(H * DPR);
    var b = bg.getContext('2d');
    b.setTransform(DPR, 0, 0, DPR, 0, 0);
    P.fill(b, W, H, 1);
    P.grid(b, W, H, { step: 32, major: 4, alpha: 0.55 });
    /* 縁より上の台地 */
    b.fillStyle = P.rgba(P.deep, 0.7);
    b.fillRect(0, 0, W, LIP);
    b.strokeStyle = P.rgba(P.steel, 0.22);
    b.lineWidth = 1;
    for (var k = 1; k < 4; k++) {
      var yy = LIP * k / 4;
      b.beginPath();
      for (var x = 0; x <= W; x += 16) {
        var y = yy + Math.sin(x * 0.013 + k * 2) * 4 + Math.sin(x * 0.041 + k) * 1.5;
        if (x === 0) b.moveTo(x, y); else b.lineTo(x, y);
      }
      b.stroke();
    }
    /* 崖の地層 */
    var layers = 11;
    var lineY = [];
    for (var j = 0; j < layers; j++) {
      var y0 = LIP + (POOL - LIP) * (j + 0.6) / layers;
      var row = [];
      b.strokeStyle = P.rgba(P.steel, 0.16 + (j % 3 === 0 ? 0.07 : 0));
      b.beginPath();
      for (var x2 = 0; x2 <= W + 20; x2 += 20) {
        var y2 = y0 + Math.sin(x2 * 0.007 + j * 1.7) * 6 + (Math.random() - 0.5) * 3;
        row.push(y2);
        if (x2 === 0) b.moveTo(x2, y2); else b.lineTo(x2, y2);
      }
      b.stroke();
      lineY.push(row);
    }
    /* 地層のあいだの縦の割れ目 */
    b.strokeStyle = P.rgba(P.steel, 0.12);
    b.beginPath();
    for (var j2 = 0; j2 < layers - 1; j2++) {
      var cnt = Math.round(W / 70);
      for (var c = 0; c < cnt; c++) {
        var xi = Math.floor(Math.random() * (lineY[j2].length - 1));
        var xx = xi * 20 + 10;
        b.moveTo(xx, (lineY[j2][xi] + lineY[j2][xi + 1]) / 2);
        b.lineTo(xx + rnd(-4, 4), (lineY[j2 + 1][xi] + lineY[j2 + 1][xi + 1]) / 2);
      }
    }
    b.stroke();
    /* 縁の線 */
    b.strokeStyle = P.rgba(P.line, 0.45);
    b.lineWidth = 1.4;
    b.beginPath();
    for (var x3 = 0; x3 <= W; x3 += 10) {
      var y3 = LIP - 1 + Math.sin(x3 * 0.05) * 0.8 + (Math.random() - 0.5) * 1.2;
      if (x3 === 0) b.moveTo(x3, y3); else b.lineTo(x3, y3);
    }
    b.stroke();
    /* 滝つぼの暗がり */
    b.fillStyle = P.rgba(P.deep, 0.6);
    b.fillRect(0, POOL, W, H - POOL);
  }

  function setup() {
    LIP = Math.round(H * (W < H ? 0.12 : 0.13));
    POOL = Math.round(H * (W < H ? 0.8 : 0.82));
    G = H * 0.55;
    AMP = H * 0.012;
    GX = Math.ceil(W / GS) + 1; GY = Math.ceil(H / GS) + 1;
    fu = new Float32Array(GX * GY); fv = new Float32Array(GX * GY);
    hw.fill(0); hv.fill(0);
    sl.fill(0);
    rocks.length = 0; surges.length = 0;
    boulders = [];
    var nb = Math.max(1, Math.min(3, Math.round(W / 420)));
    for (var k = 0; k < nb; k++) {
      var r = Math.min(W, H) * rnd(0.045, 0.07);
      boulders.push({ x: W * (k + rnd(0.25, 0.75)) / nb, y: POOL - r * 0.15, r: r, shape: rockShape(), a: 1, fresh: 0 });
    }
    buildBg();
    N = 0;
    setCount();
    /* 開いたときにはもう流れ落ちているように、先に 2 秒ぶん回しておく */
    for (var s = 0; s < 120; s++) sim(1 / 60);
  }

  function hitRocks(i, list) {
    for (var k = 0; k < list.length; k++) {
      var R = list[k], r = R.r * R.a;
      if (r < 2) continue;
      var dx = px[i] - R.x, dy = py[i] - R.y, d2 = dx * dx + dy * dy;
      if (d2 >= r * r || d2 < 0.01) continue;
      var d = Math.sqrt(d2), nx = dx / d, ny = dy / d;
      px[i] = R.x + nx * r; py[i] = R.y + ny * r;
      var vn = pvx[i] * nx + pvy[i] * ny;
      if (vn < 0) {
        pvx[i] = (pvx[i] - 1.25 * vn * nx) * 0.9 + nx * 50;
        pvy[i] = (pvy[i] - 1.25 * vn * ny) * 0.9;
      }
      if (R.fresh > 0) {
        if (pc[i] < R.fresh) pc[i] = R.fresh;
        SFX.play('collide', 320);
      }
      if (Math.random() < 0.03) spray(px[i], py[i], nx * rnd(60, 160) + rnd(-30, 30), ny * rnd(60, 160) - rnd(40, 140), 1.6, 0, R.fresh);
    }
  }

  function sim(dt) {
    time += dt;
    windX = windK * H * 0.09 * (Math.sin(time * 0.11) + 0.5 * Math.sin(time * 0.27 + 1));

    var dk = Math.exp(-dt * 1.6), nG = GX * GY;
    for (var g = 0; g < nG; g++) { fu[g] *= dk; fv[g] *= dk; }

    for (var s = surges.length - 1; s >= 0; s--) {
      surges[s].t += dt;
      var sgt = surges[s].t, life = surges[s].life;
      surges[s].a = surges[s].peak * Math.min(1, sgt / 0.15) * Math.max(0, 1 - sgt / life);
      if (sgt > life) surges.splice(s, 1);
    }
    for (var r = rocks.length - 1; r >= 0; r--) {
      var R = rocks[r];
      if (R.held) { R.a = Math.min(1, R.a + dt * 6); R.fresh = 1; continue; }
      R.t += dt;
      R.fresh = Math.max(0, 1 - R.t / 2.5);
      R.a = R.t < 8 ? 1 : Math.max(0, 1 - (R.t - 8) / 2.5);
      if (R.a <= 0) rocks.splice(r, 1);
    }

    var span = POOL - LIP, cdec = dt * 0.7;
    for (var i = 0; i < N; i++) {
      var x = px[i], y = py[i];
      var prog = (y - LIP) / span;
      var gx = (x / GS) | 0, gy = (y / GS) | 0, u = 0, v = 0;
      if (gx >= 0 && gx < GX && gy >= 0 && gy < GY) { var gi = gy * GX + gx; u = fu[gi]; v = fv[gi]; }
      pvy[i] += (G - DRAG * pvy[i]) * dt + v * 2.2 * dt;
      pvx[i] += ((windX * prog + u) - pvx[i]) * 1.4 * dt + (Math.random() - 0.5) * 120 * prog * prog * dt;
      px[i] = x + pvx[i] * dt;
      py[i] = y + pvy[i] * dt;
      if (rocks.length) hitRocks(i, rocks);
      hitRocks(i, boulders);
      if (pc[i] > 0) pc[i] = Math.max(0, pc[i] - cdec);
      if (py[i] >= POOL + surfAt(px[i])) {
        var hi = (px[i] / W * (M - 1)) | 0;
        if (hi >= 0 && hi < M) hv[hi] += 0.006 * pw[i];
        var rr = Math.random();
        if (rr < 0.05) spray(px[i], POOL, rnd(-90, 90), -rnd(80, 240), 1.4, 0, pc[i]);
        else if (rr < 0.11) spray(px[i], POOL, rnd(-50, 50), rnd(1, 6), rnd(2, 4), 1, 0);
        else if (rr < 0.125) spray(px[i], POOL - rnd(0, 10), rnd(-10, 10), -rnd(15, 45), rnd(4, 7), 2, 0);
        spawnWater(i);
      } else if (px[i] < -30 || px[i] > W + 30 || py[i] < -60) spawnWater(i);
    }

    /* しぶき・泡・霧 */
    for (var j = 0; j < SCAP; j++) {
      if (sl[j] <= 0) continue;
      sl[j] -= dt;
      if (sc[j] > 0) sc[j] = Math.max(0, sc[j] - dt * 0.5);
      var t = st[j];
      var gxx = (sx[j] / GS) | 0, gyy = (sy[j] / GS) | 0, uu = 0, vv = 0;
      if (gxx >= 0 && gxx < GX && gyy >= 0 && gyy < GY) { uu = fu[gyy * GX + gxx]; vv = fv[gyy * GX + gxx]; }
      if (t === 0) {
        svy[j] += G * 0.9 * dt;
        svx[j] += (uu - svx[j] * 0.3) * dt;
        sx[j] += svx[j] * dt; sy[j] += svy[j] * dt;
        if (svy[j] > 0 && sy[j] > POOL + surfAt(sx[j])) {
          sl[j] = 0;
          if (Math.random() < 0.4) spray(sx[j], POOL, svx[j] * 0.3, rnd(1, 6), rnd(1.5, 3), 1, 0);
        }
      } else if (t === 1) {
        svx[j] *= Math.exp(-dt * 0.5);
        sx[j] += (svx[j] + uu * 0.3) * dt;
        sy[j] = POOL + surfAt(sx[j]) + svy[j];
      } else {
        svx[j] += ((windX * 0.6 + uu) - svx[j]) * 0.8 * dt;
        svy[j] += (vv * 0.6 - H * 0.04 - svy[j]) * 0.5 * dt;
        sx[j] += svx[j] * dt; sy[j] += svy[j] * dt;
      }
    }

    /* 水面の波 */
    var hl = hw[0], hc;
    for (var m = 1; m < M - 1; m++) {
      hc = hw[m];
      hv[m] = (hv[m] + (hl + hw[m + 1] - 2 * hc) * 0.22 - hc * 0.01) * 0.975;
      hl = hc;
    }
    for (var m2 = 1; m2 < M - 1; m2++) { hw[m2] += hv[m2]; if (hw[m2] > 1.2) hw[m2] = 1.2; else if (hw[m2] < -1.2) hw[m2] = -1.2; }
  }

  function drawRock(R, line) {
    if (R.a <= 0.01) return;
    var n = R.shape.length, r = R.r * (0.6 + 0.4 * R.a);
    ctx.beginPath();
    for (var k = 0; k <= n; k++) {
      var an = k / n * Math.PI * 2 + 0.3, rr = r * R.shape[k % n];
      var x = R.x + Math.cos(an) * rr, y = R.y + Math.sin(an) * rr * 0.92;
      if (k === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
    ctx.fillStyle = P.rgba(P.deep, 0.92 * R.a);
    ctx.fill();
    ctx.lineWidth = 1.3;
    ctx.strokeStyle = R.fresh > 0.02 ? P.rgba(P.mix(line, P.accent, R.fresh), (0.55 + 0.4 * R.fresh) * R.a) : P.rgba(line, 0.6 * R.a);
    ctx.stroke();
    /* 岩肌の筋 */
    ctx.strokeStyle = P.rgba(P.steel, 0.35 * R.a);
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(R.x - r * 0.45, R.y - r * 0.15); ctx.lineTo(R.x + r * 0.1, R.y - r * 0.4);
    ctx.moveTo(R.x - r * 0.2, R.y + r * 0.3); ctx.lineTo(R.x + r * 0.45, R.y + r * 0.05);
    ctx.stroke();
  }

  var NB = 6;
  function draw() {
    ctx.drawImage(bg, 0, 0, W, H);

    /* 落ちる水を明るさごとにまとめて描く */
    var tau = 0.075;
    for (var i = 0; i < N; i++) {
      if (pc[i] > 0.2) pb[i] = NB + (pc[i] > 0.6 ? 1 : 0);
      else { var b = (pw[i] * NB) | 0; pb[i] = b >= NB ? NB - 1 : b; }
    }
    ctx.lineWidth = 1;
    ctx.lineCap = 'butt';
    for (var k = 0; k < NB + 2; k++) {
      if (k < NB) ctx.strokeStyle = P.rgba(P.line, 0.07 + k * 0.085);
      else ctx.strokeStyle = P.rgba(P.accent, k === NB ? 0.45 : 0.8);
      ctx.beginPath();
      for (var j = 0; j < N; j++) {
        if (pb[j] !== k) continue;
        var x = px[j], y = py[j];
        ctx.moveTo(x - pvx[j] * tau, y - pvy[j] * tau - 1.5);
        ctx.lineTo(x, y);
      }
      ctx.stroke();
    }

    for (var bI = 0; bI < boulders.length; bI++) drawRock(boulders[bI], P.steel);

    /* 滝つぼの水面と、その下の波の線 */
    var step = W / (M - 1);
    for (var L = 0; L < 6; L++) {
      var gap = L === 0 ? 0 : H * 0.016 * L * (1 + 0.4 * L);
      var yb = POOL + gap;
      if (yb > H + 4) break;
      var amp = AMP * (1 - L * 0.12);
      ctx.strokeStyle = L === 0 ? P.rgba(P.line, 0.7) : P.rgba(P.steel, 0.5 - L * 0.07);
      ctx.lineWidth = L === 0 ? 1.4 : 1;
      ctx.beginPath();
      for (var m = 0; m < M; m++) {
        var mm = Math.min(M - 1, Math.max(0, m + ((L * 7) % 5) - 2));
        var yy = yb + hw[mm] * amp + (L ? Math.sin(m * 0.4 + time * 0.8 + L) * L * 0.6 : 0);
        if (m === 0) ctx.moveTo(0, yy); else ctx.lineTo(m * step, yy);
      }
      ctx.stroke();
    }

    for (var r = 0; r < rocks.length; r++) drawRock(rocks[r], P.line);

    /* しぶき・泡・霧 */
    var passes = [[0, P.line, 0.5], [0, P.accent, 0.8], [1, P.line, 0.6], [2, P.line, 0.1]];
    for (var p = 0; p < passes.length; p++) {
      var type = passes[p][0], wantC = p === 1;
      ctx.beginPath();
      for (var s = 0; s < SCAP; s++) {
        if (sl[s] <= 0 || st[s] !== type) continue;
        if (type === 0 && (sc[s] > 0.25) !== wantC) continue;
        if (type === 0) {
          ctx.moveTo(sx[s] - svx[s] * 0.025, sy[s] - svy[s] * 0.025);
          ctx.lineTo(sx[s], sy[s]);
        } else {
          var sz = type === 1 ? 1.3 : 2.2;
          var fade = Math.min(1, sl[s] / sl0[s] * 2);
          if (fade < 0.35 && (s & 1)) continue;
          ctx.rect(sx[s] - sz / 2, sy[s] - sz / 2, sz, sz);
        }
      }
      if (type === 0) { ctx.strokeStyle = P.rgba(passes[p][1], passes[p][2]); ctx.stroke(); }
      else { ctx.fillStyle = P.rgba(passes[p][1], passes[p][2]); ctx.fill(); }
    }
  }

  /* 状態をすべて宣言してから呼ぶ */
  ctx = P.fit(canvas, function (w, h, dpr) { W = w; H = h; DPR = dpr || 1; setup(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastT0 = 0, held = null, inPool = false;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }

  function pushField(x, y, R, fx, fy, radial) {
    var c0 = Math.max(0, ((x - R) / GS) | 0), c1 = Math.min(GX - 1, ((x + R) / GS) | 0);
    var r0 = Math.max(0, ((y - R) / GS) | 0), r1 = Math.min(GY - 1, ((y + R) / GS) | 0);
    for (var j = r0; j <= r1; j++) {
      for (var i = c0; i <= c1; i++) {
        var cx = (i + 0.5) * GS - x, cy = (j + 0.5) * GS - y, d = Math.sqrt(cx * cx + cy * cy);
        if (d >= R) continue;
        var f = 1 - d / R; f *= f;
        var k = j * GX + i;
        if (radial) { fu[k] += cx / (d + 1) * radial * f; fv[k] += cy / (d + 1) * radial * f; }
        else { fu[k] += fx * f; fv[k] += fy * f; }
      }
    }
  }

  function poolHit(x, power) {
    var c = x / W * (M - 1);
    for (var m = Math.max(1, Math.floor(c - 5)); m < Math.min(M - 1, Math.ceil(c + 5)); m++) {
      var d = (m - c) / 4;
      hv[m] += power * Math.exp(-d * d);
    }
  }

  function addSurge(x, peak, life) {
    surges.push({ x: x, r: Math.max(40, W * 0.06), a: 0, peak: peak, t: 0, life: life });
    if (surges.length > 6) surges.shift();
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); lastT0 = performance.now();
    canvas.setPointerCapture(e.pointerId);
    inPool = last.y > POOL - 6;
    if (inPool) {
      poolHit(last.x, 0.55);
      for (var k = 0; k < 40; k++) spray(last.x + rnd(-14, 14), POOL - 2, rnd(-120, 120), -rnd(120, 360), 1.6, 0, 1);
      for (var f = 0; f < 24; f++) spray(last.x, POOL, rnd(-90, 90), rnd(1, 7), rnd(2.5, 4.5), 1, 0);
      SFX.play('ripple');
      return;
    }
    var r = Math.max(18, Math.min(W, H) * 0.045);
    held = { x: last.x, y: Math.max(LIP + r * 0.6, last.y), r: r, shape: rockShape(), a: 0.4, fresh: 1, held: true, t: 0 };
    rocks.push(held);
    if (rocks.length > 5) rocks.shift();
    pushField(last.x, last.y, r * 3, 0, 0, 220);
    for (var s = 0; s < 26; s++) {
      var an = Math.random() * Math.PI * 2, sp = rnd(60, 200);
      spray(held.x + Math.cos(an) * r, held.y + Math.sin(an) * r, Math.cos(an) * sp, Math.sin(an) * sp - 60, 1.4, 0, 1);
    }
    addSurge(last.x, 0.8, 4);
    SFX.play('spawn');
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now();
    var dtm = Math.max(0.008, (now - lastT0) / 1000);
    var vx = (p.x - last.x) / dtm, vy = (p.y - last.y) / dtm;
    var sp = Math.hypot(vx, vy);
    if (sp > 1500) { vx *= 1500 / sp; vy *= 1500 / sp; }
    if (inPool) {
      if (Math.abs(p.x - last.x) > 6) {
        poolHit(p.x, 0.12);
        spray(p.x, POOL - 2, rnd(-60, 60), -rnd(80, 200), 1.4, 0, 1);
        SFX.play('move', 140);
      }
    } else if (held) {
      held.x = p.x; held.y = Math.max(LIP + held.r * 0.6, p.y);
      pushField(p.x, p.y, held.r * 3, vx * 0.15, vy * 0.15, 0);
      if (sp > 60) SFX.play('move', 140);
    }
    last = p; lastT0 = now;
  });
  function up() {
    down = false;
    if (held) { held.held = false; held.t = 0; held = null; }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kFlow = document.getElementById('k-flow'), kWind = document.getElementById('k-wind');
  kFlow.addEventListener('input', function () { flow = this.value / 100; setCount(); });
  kWind.addEventListener('input', function () { windK = this.value / 100; });
  /* 共有リンクから開いたとき、復元された値を拾う */
  flow = kFlow.value / 100; windK = kWind.value / 100;
  setCount();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    /* ときどき縁のどこかで水がふえる */
    surgeT -= dt;
    if (surgeT <= 0) { surgeT = rnd(5, 9); addSurge(W * rnd(0.1, 0.9), rnd(0.25, 0.45), rnd(6, 10)); }
    humT -= dt;
    if (humT <= 0) { humT = rnd(3, 6); SFX.play('ripple', 3000); }
    var n = Math.max(1, Math.ceil(dt / 0.0175));
    for (var s = 0; s < n; s++) sim(dt / n);
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
