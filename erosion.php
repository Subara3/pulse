<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('erosion');

$pageName = 'erosion';
$pageTitle = 'EROSION - PULSE';
$pageDesc = '島の地形を等高線で描いている。川が少しずつ谷を削り、上流へ枝分かれしながら伸びていく。押したりなぞったりするとそこに雨が降り、その水が通る川筋が海まで水色に光って、水の粒が谷を下っていく。';
$pageCanonical = 'https://pulse.subara3.com/erosion.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-rain">RAIN</label><input type="range" id="k-rain" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-lift">LIFT</label><input type="range" id="k-lift" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /* 粗い格子の地形。各マスの水はいちばん低い隣へ流れ、集まった水の量が多いほど川底を削る */
  var NX, NY, N, CS, h, up, jit, A, WA, wgt, boost, rec, order, seen, heapH, heapI, img, buf, bctx, BW, BH;
  var rain = 0.5, lift = 0.5, lo = 0, hi = 1, T = 0, upT = 0, drops = [];
  var NB8 = [], DIST8 = [];

  /* ---------------------------------------------------------- 雑音 */
  var seed = 1;
  function hash(i, j) {
    var n = (i * 374761393 + j * 668265263 + seed * 2147483647) | 0;
    n = (n ^ (n >>> 13)) * 1274126177 | 0;
    return ((n ^ (n >>> 16)) >>> 0) / 4294967296;
  }
  function vnoise(x, y) {
    var i = Math.floor(x), j = Math.floor(y), fx = x - i, fy = y - j;
    var u = fx * fx * (3 - 2 * fx), v = fy * fy * (3 - 2 * fy);
    var a = hash(i, j), b = hash(i + 1, j), c = hash(i, j + 1), d = hash(i + 1, j + 1);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
  }
  function fbm(x, y, oct) {
    var s = 0, amp = 0.5, f = 1;
    for (var o = 0; o < oct; o++) { s += vnoise(x * f, y * f) * amp; f *= 2.03; amp *= 0.5; }
    return s;
  }

  function alloc() {
    CS = Math.max(5, Math.min(W, H) / 108);
    NX = Math.ceil(W / CS) + 1; NY = Math.ceil(H / CS) + 1; N = NX * NY;
    h = new Float32Array(N); up = new Float32Array(N); jit = new Float32Array(N).fill(1); A = new Float32Array(N); WA = new Float32Array(N); wgt = new Float32Array(N);
    boost = new Float32Array(N); rec = new Int32Array(N); order = new Int32Array(N); seen = new Uint8Array(N);
    heapH = new Float32Array(N); heapI = new Int32Array(N);
    NB8 = [-1, 1, -NX, NX, -NX - 1, -NX + 1, NX - 1, NX + 1];
    DIST8 = [1, 1, 1, 1, 1.414, 1.414, 1.414, 1.414];
    BW = NX * 2; BH = NY * 2;
    img = new ImageData(BW, BH);
    buf = document.createElement('canvas');
    buf.width = BW; buf.height = BH;
    bctx = buf.getContext('2d');
    terrain();
  }

  var ox = 0, oy = 0;
  function upliftField() {
    /* 持ち上がり方の地図。ゆっくり流れていくので、尾根と谷も少しずつ形を変える */
    var asp = NX / NY, dxT = T * 0.004, dyT = T * 0.0027;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var x = i / NX * 2.4 * asp / 1.78, y = j / NY * 2.4;
        var dx = (i / NX - 0.5) * 2, dy = (j / NY - 0.5) * 2;
        var r = Math.sqrt(dx * dx * 0.62 + dy * dy * 0.95);
        /* 島の形: 岸の線は雑音でゆがめる。岸より外は持ち上がらず、海になる */
        var coast = 0.86 + (fbm(x * 0.9 + 21 + ox, y * 0.9 + 4 + oy, 3) - 0.5) * 0.55;
        var ed = (coast - r) / 0.3;
        ed = ed < 0 ? 0 : ed > 1 ? 1 : ed * ed * (3 - 2 * ed);
        var ee = Math.min(i, j, NX - 1 - i, NY - 1 - j) / 6;
        if (ee < 1) ed *= ee;
        up[j * NX + i] = ed * (0.25 + fbm(x + ox + dxT, y + oy + dyT, 3) * 1.25) * jit[j * NX + i];
      }
    }
  }

  function terrain() {
    seed = (Math.random() * 100000) | 0;
    ox = Math.random() * 40; oy = Math.random() * 40;
    var asp = NX / NY;
    for (var j = 0; j < NY; j++) {
      for (var i = 0; i < NX; i++) {
        var x = i / NX * 4 * asp / 1.78, y = j / NY * 4;
        h[j * NX + i] = fbm(x + 9, y + 3, 5) * 0.3 + Math.random() * 0.02;
        jit[j * NX + i] = 0.8 + Math.random() * 0.4;
      }
    }
    upliftField();
    for (var q = 0; q < N; q++) h[q] *= up[q] > 0 ? Math.min(1, up[q] * 3) : 0;
    /* 開いた瞬間から谷が刻まれているように、先に時間を進めておく */
    var t0 = performance.now();
    for (var n = 0; n < 300 && performance.now() - t0 < 450; n++) evolve(3.5);
    measure(true);
    shadeBuf();
  }

  /* 低い順に水をたどる（端は海）。窪地は埋めて湖にする */
  function flood() {
    var hn = 0, i, j, k, c, m = 0;
    seen.fill(0);
    function push(kk, hv) {
      var p = hn++;
      while (p > 0) { var q = (p - 1) >> 1; if (heapH[q] <= hv) break; heapH[p] = heapH[q]; heapI[p] = heapI[q]; p = q; }
      heapH[p] = hv; heapI[p] = kk;
    }
    function pop() {
      var top = heapI[0], lastH = heapH[--hn], lastI = heapI[hn], p = 0;
      while (true) {
        var l = p * 2 + 1; if (l >= hn) break;
        var r = l + 1, s = (r < hn && heapH[r] < heapH[l]) ? r : l;
        if (heapH[s] >= lastH) break;
        heapH[p] = heapH[s]; heapI[p] = heapI[s]; p = s;
      }
      heapH[p] = lastH; heapI[p] = lastI;
      return top;
    }
    for (i = 0; i < NX; i++) { k = i; h[k] = 0; seen[k] = 1; rec[k] = -1; push(k, 0); k = (NY - 1) * NX + i; h[k] = 0; seen[k] = 1; rec[k] = -1; push(k, 0); }
    for (j = 1; j < NY - 1; j++) { k = j * NX; h[k] = 0; seen[k] = 1; rec[k] = -1; push(k, 0); k = j * NX + NX - 1; h[k] = 0; seen[k] = 1; rec[k] = -1; push(k, 0); }
    while (hn > 0) {
      c = pop();
      order[m++] = c;
      var ci = c % NX, cj = (c / NX) | 0, hc = h[c];
      for (var d = 0; d < 8; d++) {
        var nk = c + NB8[d];
        var ni = ci + (d === 0 || d === 4 || d === 6 ? -1 : d === 1 || d === 5 || d === 7 ? 1 : 0);
        if (ni < 0 || ni >= NX || nk < 0 || nk >= N || seen[nk]) continue;
        seen[nk] = 1;
        if (h[nk] <= hc) h[nk] = hc + 2e-5 + Math.random() * 2e-5;
        rec[nk] = c;
        push(nk, h[nk]);
      }
    }
    return m;
  }

  function evolve(dt) {
    var m = flood(), i, k;
    /* 埋めたあとの地形で、いちばん急に下る隣を流れ先にする */
    for (i = 0; i < m; i++) {
      k = order[i];
      if (rec[k] < 0) continue;
      var ki = k % NX, best = rec[k], bs = 0, hk = h[k];
      if (ki === 0 || ki === NX - 1) continue;
      for (var dI = 0; dI < 8; dI++) {
        var nk = k + NB8[dI];
        if (nk < 0 || nk >= N) continue;
        var sl = (hk - h[nk]) / DIST8[dI];
        if (sl > bs) { bs = sl; best = nk; }
      }
      rec[k] = best;
    }
    /* 降らせた雨の分は別にも数えて、その水が通る川筋をシアンで描く */
    for (k = 0; k < N; k++) { WA[k] = boost[k] * 60; A[k] = 1 + WA[k]; }
    for (i = m - 1; i >= 0; i--) { k = order[i]; if (rec[k] >= 0) { A[rec[k]] += A[k]; WA[rec[k]] += WA[k]; } }
    var K = (0.014 + rain * 0.045) * dt, U = (0.006 + lift * 0.02) * dt;
    for (i = 0; i < m; i++) {
      k = order[i];
      var r = rec[k];
      if (r < 0) continue;
      var dd = (r - k === 1 || k - r === 1 || r - k === NX || k - r === NX) ? 1 : 1.414;
      var F = K * Math.sqrt(A[k]) / dd;
      h[k] = (h[k] + up[k] * U + F * h[r]) / (1 + F);
    }
    /* 斜面をわずかにならす */
    var D = 0.06 * dt;
    for (var j = 1; j < NY - 1; j++) {
      for (i = 1; i < NX - 1; i++) {
        k = j * NX + i;
        wgt[k] = (h[k - 1] + h[k + 1] + h[k - NX] + h[k + NX] - 4 * h[k]) * D;
      }
    }
    for (j = 1; j < NY - 1; j++) for (i = 1; i < NX - 1; i++) { k = j * NX + i; h[k] += wgt[k]; }
  }

  function measure(now) {
    var mx = 0;
    for (var k = 0; k < N; k += 3) if (h[k] > mx) mx = h[k];
    if (now) hi = mx; else hi += (mx - hi) * 0.03;
  }

  /* ---------------------------------------------------------- 塗る */
  var BG = P.bg, DEEP = P.deep, LN = P.line, ST = P.steel;
  var LOWC = P.mix(DEEP, BG, 0.6), HIC = P.mix(BG, ST, 0.3);
  function shadeBuf() {
    var d = img.data, span = Math.max(1e-5, hi), NC = 13, sc = NC / span;
    for (var by = 0; by < BH; by++) {
      var fy = by * 0.5 + 0.25;
      var j = fy | 0; if (j > NY - 2) j = NY - 2;
      var v = fy - j;
      for (var bx = 0; bx < BW; bx++) {
        var fx = bx * 0.5 + 0.25;
        var i = fx | 0; if (i > NX - 2) i = NX - 2;
        var u = fx - i, k = j * NX + i;
        var a = h[k], b = h[k + 1], c = h[k + NX], e = h[k + NX + 1];
        var hv = a + (b - a) * u + (c - a) * v + (a - b - c + e) * u * v;
        var gx = (b - a) * (1 - v) + (e - c) * v, gy = (c - a) * (1 - u) + (e - b) * u;
        var t = hv / span; if (t > 1) t = 1;
        var r = LOWC[0] + (HIC[0] - LOWC[0]) * t, g = LOWC[1] + (HIC[1] - LOWC[1]) * t, bl = LOWC[2] + (HIC[2] - LOWC[2]) * t;
        /* 北西から光を当てた陰影 */
        var lit = (-gx * 0.7 - gy * 0.7) * sc * 1.1;
        if (lit > 0) { lit = Math.min(1, lit) * 0.42; r += (ST[0] - r) * lit; g += (ST[1] - g) * lit; bl += (ST[2] - bl) * lit; }
        else { lit = Math.min(1, -lit) * 0.6; r += (DEEP[0] - r) * lit; g += (DEEP[1] - g) * lit; bl += (DEEP[2] - bl) * lit; }
        /* 等高線。線までの距離を画素で測って太さをそろえ、5 本ごとに少し濃く */
        var hn = hv * sc, f = hn - Math.floor(hn), dist = f < 0.5 ? f : 1 - f;
        var gm = Math.sqrt(gx * gx + gy * gy) * sc * 0.5 + 1e-5;
        var px = dist / gm, idx = Math.round(hn) % 4 === 0;
        var la = 1 - px / (idx ? 1.1 : 0.75);
        if (hv < span * 0.03) {
          /* 海: 等高線は引かず、地の藍に戻す。岸だけ白い線で縁取る */
          var sea = hv / (span * 0.03);
          r = BG[0] + (r - BG[0]) * sea * sea; g = BG[1] + (g - BG[1]) * sea * sea; bl = BG[2] + (bl - BG[2]) * sea * sea;
          var cd = (1 - sea) / (Math.sqrt(gx * gx + gy * gy) / (span * 0.03) * 0.5 + 1e-5);
          var ca = Math.max(0, 1 - cd / 1.4) * 0.42;
          r += (LN[0] - r) * ca; g += (LN[1] - g) * ca; bl += (LN[2] - bl) * ca;
        } else if (la > 0) {
          la *= idx ? 0.3 : 0.14;
          r += (LN[0] - r) * la; g += (LN[1] - g) * la; bl += (LN[2] - bl) * la;
        }
        var o = (by * BW + bx) * 4;
        d[o] = r; d[o + 1] = g; d[o + 2] = bl; d[o + 3] = 255;
      }
    }
    bctx.putImageData(img, 0, 0);
  }

  /* 川筋: 集まった水が多いマスだけ、流れ先まで白い線で結ぶ */
  var RCLS = 5;
  function rivers() {
    var thr = 18, paths = [], sea = hi * 0.06, wetPath = new Path2D();
    for (var c = 0; c < RCLS; c++) paths.push(new Path2D());
    for (var k = 0; k < N; k++) {
      var a = A[k], wa = WA[k], isWet = wa > 70 && wa > a * 0.3;
      if (!isWet && (a < thr || wa > a * 0.3)) continue;
      var r = rec[k];
      if (r < 0 || h[k] < sea) continue;
      var r2 = rec[r] >= 0 ? rec[r] : r;
      var x0 = (k % NX) * CS, y0 = ((k / NX) | 0) * CS;
      var x1 = (r % NX) * CS, y1 = ((r / NX) | 0) * CS;
      var x2 = (r2 % NX) * CS, y2 = ((r2 / NX) | 0) * CS;
      var cl = Math.max(0, Math.min(RCLS - 1, Math.floor(Math.log(a / thr) * 0.8)));
      var path = isWet ? wetPath : paths[cl];
      /* 2 つ先までの中点を結んで、ジグザグを丸める */
      path.moveTo((x0 + x1) * 0.5, (y0 + y1) * 0.5);
      path.quadraticCurveTo(x1, y1, (x1 + x2) * 0.5, (y1 + y2) * 0.5);
    }
    ctx.lineCap = 'round';
    for (c = 0; c < RCLS; c++) {
      ctx.strokeStyle = P.rgba(LN, 0.45 + c * 0.12);
      ctx.lineWidth = 0.7 + c * 0.45;
      ctx.stroke(paths[c]);
    }
    ctx.strokeStyle = P.rgba(P.accent, 0.9);
    ctx.lineWidth = 2.6;
    ctx.stroke(wetPath);
  }

  var ctx = P.fit(canvas, function (w, hh) { W = w; H = hh; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  function wet(x, y, amt) {
    var ci = Math.round(x / CS), cj = Math.round(y / CS), R = 9;
    for (var j = Math.max(1, cj - R); j <= Math.min(NY - 2, cj + R); j++) {
      for (var i = Math.max(1, ci - R); i <= Math.min(NX - 2, ci + R); i++) {
        var q = ((i - ci) * (i - ci) + (j - cj) * (j - cj)) / (R * R);
        if (q < 1) boost[j * NX + i] = Math.min(1.5, boost[j * NX + i] + (1 - q) * amt);
      }
    }
  }
  function pour(x, y, n, spread) {
    for (var i = 0; i < n; i++) {
      if (drops.length > 500) drops.shift();
      var a = Math.random() * 6.2832, r = Math.random() * spread;
      drops.push({ x: x + Math.cos(a) * r, y: y + Math.sin(a) * r, t: 0 });
    }
  }
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    wet(last.x, last.y, 1.4);
    evolve(0.05);
    pour(last.x, last.y, 50, 40);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < 8) return;
    wet(p.x, p.y, 0.6);
    pour(p.x, p.y, 5, 14);
    last = p;
    SFX.play('ripple', 220);
  });
  function upP() { down = false; }
  canvas.addEventListener('pointerup', upP);
  canvas.addEventListener('pointercancel', upP);

  var kRain = document.getElementById('k-rain'), kLift = document.getElementById('k-lift');
  kRain.addEventListener('input', function () { rain = this.value / 100; });
  kLift.addEventListener('input', function () { lift = this.value / 100; });
  document.getElementById('k-new').addEventListener('click', function () { drops.length = 0; boost.fill(0); terrain(); SFX.play('click'); });
  rain = kRain.value / 100;
  lift = kLift.value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), tick = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    upT += dt;
    if (upT > 1.5) { upT = 0; upliftField(); }
    tick++;
    if (tick % 3 !== 0) evolve(0.3);
    var bk = Math.pow(0.6, dt);
    for (var k = 0; k < N; k++) if (boost[k] > 0) boost[k] = boost[k] > 0.01 ? boost[k] * bk : 0;
    /* 地形はゆっくりしか変わらないので、陰影と等高線は 3 回に 1 回だけ塗り直す */
    if (tick % 3 === 0) { measure(false); shadeBuf(); }
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, NX * CS, NY * CS);
    rivers();

    /* 降らせた雨粒は、流れ先をたどって谷を下る */
    ctx.fillStyle = P.rgba(P.accent, 0.9);
    ctx.beginPath();
    for (var i = drops.length - 1; i >= 0; i--) {
      var d = drops[i];
      d.t += dt;
      var ci = Math.round(d.x / CS), cj = Math.round(d.y / CS);
      if (ci < 0 || cj < 0 || ci >= NX || cj >= NY || d.t > 9) { drops.splice(i, 1); continue; }
      var r = rec[cj * NX + ci];
      /* 海に出た粒は消える */
      if (r < 0 || (h[cj * NX + ci] < hi * 0.03 && d.t > 0.4)) { drops.splice(i, 1); continue; }
      var tx = (r % NX) * CS, ty = ((r / NX) | 0) * CS, dx = tx - d.x, dy = ty - d.y, dl = Math.sqrt(dx * dx + dy * dy) + 1e-6;
      var sp = 70 * dt;
      d.x += dx / dl * Math.min(sp, dl) + (Math.random() - 0.5) * 0.6;
      d.y += dy / dl * Math.min(sp, dl) + (Math.random() - 0.5) * 0.6;
      var rr = 2 * Math.min(1, (9 - d.t) / 2);
      ctx.moveTo(d.x + rr, d.y);
      ctx.arc(d.x, d.y, rr, 0, 6.2832);
    }
    ctx.fill();
    if (Math.random() < dt * 0.3) SFX.play('tick', 4000);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
