<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('magnetpendulum');

$pageName = 'magnetpendulum';
$pageTitle = 'MAGNETPENDULUM - PULSE';
$pageDesc = '振り子のおもりが3つの磁石のあいだを揺れ、最後にどれか1つの上で止まる。背景は、どこから放すとどの磁石で止まるかを濃さで塗り分けた図になっている。押さえているあいだは、そこから放したときの道筋と、同じ磁石で止まる場所が明るく示され、放すと振り子が動き出す。';
$pageCanonical = 'https://pulse.subara3.com/magnetpendulum.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drag">DRAG</label><input type="range" id="k-drag" min="0" max="100" value="18"></div>
  <div class="ctl-btns"><button type="button" class="active" data-n="3">THREE</button><button type="button" data-n="4">FOUR</button><button type="button" data-n="5">FIVE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, SC = 1, CELL = 3;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; SC = Math.min(w, h) / 3.4; alloc(); });

  /* 振り子の先: 中心へ戻るばね + 空気の抵抗 + 磁石の引力（少し上に吊った点として） */
  var K = 0.5, HH = 0.27, drag = 0.2;
  var nMag = 3, mx = [], my = [], spin = 0, rad = 1;
  function placeMagnets() {
    mx.length = 0; my.length = 0;
    for (var i = 0; i < nMag; i++) {
      var a = spin + i / nMag * Math.PI * 2 - Math.PI / 2;
      mx.push(Math.cos(a) * rad); my.push(Math.sin(a) * rad);
    }
  }
  function accel(x, y, vx, vy, out) {
    var ax = -K * x - drag * vx, ay = -K * y - drag * vy;
    for (var i = 0; i < nMag; i++) {
      var dx = mx[i] - x, dy = my[i] - y, r2 = dx * dx + dy * dy + HH * HH;
      var f = 1 / (r2 * Math.sqrt(r2));
      ax += dx * f; ay += dy * f;
    }
    out[0] = ax; out[1] = ay;
  }
  var A = [0, 0];
  /* どの磁石に落ちるかと、落ちるまでの長さ */
  function settle(x, y) {
    var vx = 0, vy = 0, dt = 0.045;
    for (var s = 0; s < 1400; s++) {
      accel(x, y, vx, vy, A);
      vx += A[0] * dt; vy += A[1] * dt;
      x += vx * dt; y += vy * dt;
      if ((s & 7) === 0 && vx * vx + vy * vy < 0.04) {
        for (var i = 0; i < nMag; i++) {
          var dx = mx[i] - x, dy = my[i] - y;
          if (dx * dx + dy * dy < 0.02) return i * 4096 + s;
        }
      }
    }
    var best = 0, bd = 1e9;
    for (var j = 0; j < nMag; j++) {
      var d = (mx[j] - x) * (mx[j] - x) + (my[j] - y) * (my[j] - y);
      if (d < bd) { bd = d; best = j; }
    }
    return best * 4096 + 1400;
  }

  /* ---------------------------------------------------------- 地図 */
  var NX, NY, label, steps, order, scan = 0, img, buf, bctx;
  function alloc() {
    NX = Math.ceil(W / CELL); NY = Math.ceil(H / CELL);
    label = new Int16Array(NX * NY).fill(-1);
    steps = new Uint16Array(NX * NY);
    img = new ImageData(NX, NY);
    var d = img.data;
    for (var k = 0; k < NX * NY; k++) { d[k * 4] = P.bg[0]; d[k * 4 + 1] = P.bg[1]; d[k * 4 + 2] = P.bg[2]; d[k * 4 + 3] = 255; }
    buf = document.createElement('canvas'); buf.width = NX; buf.height = NY;
    bctx = buf.getContext('2d');
    makeOrder();
  }
  /* 中心から外へ、輪が広がるように塗り直していく */
  function makeOrder() {
    var n = NX * NY, dist = new Float32Array(n);
    order = new Uint32Array(n);
    for (var k = 0; k < n; k++) {
      var i = k % NX, j = (k / NX) | 0;
      dist[k] = Math.hypot(i - NX / 2, j - NY / 2) + Math.random() * 1.5;
      order[k] = k;
    }
    order.sort(function (a, b) { return dist[a] - dist[b]; });
    scan = 0;
  }
  function rescan() { scan = 0; }

  var TONE = [0.03, 0.09, 0.15, 0.06, 0.12];
  function paint(k) {
    var i = k % NX, j = (k / NX) | 0, L = label[k];
    var t = TONE[L];
    /* 落ちるのに時間がかかった所ほど少し明るい。境目の入り組んだ所が浮かぶ */
    var s = steps[k] / 1400;
    var v = t + Math.min(1, s * 2.5) * 0.06;
    /* 隣と行き先が違う所は線として明るくする */
    var edge = 0;
    if (i > 0 && label[k - 1] >= 0 && label[k - 1] !== L) edge++;
    if (j > 0 && label[k - NX] >= 0 && label[k - NX] !== L) edge++;
    if (i < NX - 1 && label[k + 1] >= 0 && label[k + 1] !== L) edge++;
    if (j < NY - 1 && label[k + NX] >= 0 && label[k + NX] !== L) edge++;
    if (edge) v = Math.min(0.6, 0.27 + edge * 0.05);
    /* 画面の外側ほど地に沈める */
    var rx = (i - NX / 2) / (NX / 2), ry = (j - NY / 2) / (NY / 2), rr = Math.sqrt(rx * rx * 0.8 + ry * ry * 0.8);
    var f = rr < 0.4 ? 1 : Math.max(0.22, 1 - (rr - 0.4) * 1.25);
    v *= f;
    var c = P.ramp(v), o = k * 4, d = img.data;
    d[o] = c[0]; d[o + 1] = c[1]; d[o + 2] = c[2];
  }
  function work(budget) {
    var t0 = performance.now(), n = NX * NY, cx = NX / 2, cy = NY / 2, done = 0;
    while (scan < n && performance.now() - t0 < budget) {
      for (var q = 0; q < 24 && scan < n; q++, scan++) {
        var k = order[scan];
        var x = ((k % NX) + 0.5 - cx) * CELL / SC, y = (((k / NX) | 0) + 0.5 - cy) * CELL / SC;
        var r = settle(x, y);
        label[k] = (r / 4096) | 0; steps[k] = r % 4096;
        paint(k);
        /* 隣の線も塗り直す */
        if (k > 0) { if (label[k - 1] >= 0) paint(k - 1); }
        if (k >= NX && label[k - NX] >= 0) paint(k - NX);
        done++;
      }
    }
    return done;
  }

  /* ---------------------------------------------------------- 振り子 */
  var bobs = [], rings = [];
  function addBob(x, y, mine) {
    if (bobs.length > 5) bobs.shift();
    var b = { x: x, y: y, vx: 0, vy: 0, tx: new Float32Array(220), ty: new Float32Array(220), n: 0, head: 0, mine: mine, held: false, done: -1, life: 1 };
    bobs.push(b);
    return b;
  }
  function stepBob(b, h) {
    accel(b.x, b.y, b.vx, b.vy, A);
    b.vx += A[0] * h; b.vy += A[1] * h;
    b.x += b.vx * h; b.y += b.vy * h;
  }
  function record(b) {
    b.tx[b.head] = b.x; b.ty[b.head] = b.y;
    b.head = (b.head + 1) % 220; if (b.n < 220) b.n++;
  }
  function sxp(x) { return W / 2 + x * SC; }
  function syp(y) { return H / 2 + y * SC; }

  function drawBob(b) {
    var col = b.mine ? P.accent : P.line;
    ctx.lineWidth = b.mine ? 1.4 : 1.1;
    for (var s = 1; s < b.n; s++) {
      var i0 = (b.head - b.n + s - 1 + 440) % 220, i1 = (i0 + 1) % 220;
      var a = s / b.n;
      if ((s & 3) === 1 || s === 1) {
        if (s > 1) ctx.stroke();
        ctx.strokeStyle = P.rgba(col, a * a * 0.8 * b.life);
        ctx.beginPath();
        ctx.moveTo(sxp(b.tx[i0]), syp(b.ty[i0]));
      }
      ctx.lineTo(sxp(b.tx[i1]), syp(b.ty[i1]));
    }
    ctx.stroke();
    ctx.fillStyle = P.rgba(col, 0.95 * b.life);
    ctx.beginPath(); ctx.arc(sxp(b.x), syp(b.y), b.mine ? 4 : 3.2, 0, Math.PI * 2); ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  var held = null;
  function world(e) { return [(e.clientX - W / 2) / SC, (e.clientY - H / 2) / SC]; }

  /* 押さえている所から放したら、どの磁石へ行くか: その磁石に行く場所を地図の上で明るくする */
  var hlLabel = -1, hlA = 0, hlImg = null, hlBuf = null, hlCtx = null, hlX = 0, hlY = 0;
  function buildHighlight(L, fx, fy) {
    if (!hlImg || hlImg.width !== NX || hlImg.height !== NY) {
      hlImg = new ImageData(NX, NY);
      hlBuf = document.createElement('canvas'); hlBuf.width = NX; hlBuf.height = NY;
      hlCtx = hlBuf.getContext('2d');
    }
    var d = hlImg.data, cx = fx / CELL, cy = fy / CELL, R = 170 / CELL;
    var LN = P.line, AC = P.accent;
    for (var k = 0, n = NX * NY; k < n; k++) {
      var o = k * 4;
      if (label[k] !== L) { d[o + 3] = 0; continue; }
      var i = k % NX, j = (k / NX) | 0, dd = Math.hypot(i - cx, j - cy) / R;
      var near = dd < 1 ? (1 - dd) * (1 - dd) : 0;
      d[o] = LN[0] + (AC[0] - LN[0]) * near; d[o + 1] = LN[1] + (AC[1] - LN[1]) * near; d[o + 2] = LN[2] + (AC[2] - LN[2]) * near;
      d[o + 3] = 70 + near * 110;
    }
    hlCtx.putImageData(hlImg, 0, 0);
    hlLabel = L;
  }
  function labelAt(x, y) {
    var i = Math.floor(x / CELL), j = Math.floor(y / CELL);
    if (i < 0 || j < 0 || i >= NX || j >= NY) return -1;
    var L = label[j * NX + i];
    if (L < 0) { var w = [(x - W / 2) / SC, (y - H / 2) / SC]; L = (settle(w[0], w[1]) / 4096) | 0; }
    return L;
  }
  /* 押さえている所から放したときの道筋を先に計算しておく */
  var preview = new Float32Array(2 * 700), previewN = 0;
  function predict(x, y) {
    var vx = 0, vy = 0, dt = 0.03;
    previewN = 0;
    for (var s2 = 0; s2 < 700; s2++) {
      accel(x, y, vx, vy, A);
      vx += A[0] * dt; vy += A[1] * dt; x += vx * dt; y += vy * dt;
      preview[previewN * 2] = x; preview[previewN * 2 + 1] = y; previewN++;
      if (vx * vx + vy * vy < 0.002 && s2 > 60) break;
    }
  }
  function touchAt(e) {
    var L = labelAt(e.clientX, e.clientY);
    if (L >= 0 && (L !== hlLabel || Math.hypot(e.clientX - hlX, e.clientY - hlY) > 24)) {
      buildHighlight(L, e.clientX, e.clientY); hlX = e.clientX; hlY = e.clientY;
    }
    var w = world(e);
    predict(w[0], w[1]);
  }
  canvas.addEventListener('pointerdown', function (e) {
    var w = world(e);
    held = addBob(w[0], w[1], true);
    held.held = true;
    touchAt(e);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!held) return;
    var w = world(e);
    held.x = w[0]; held.y = w[1]; held.n = 0;
    touchAt(e);
    SFX.play('move', 260);
  });
  function up() {
    if (held) { held.held = false; held.vx = (Math.random() - 0.5) * 0.02; held.vy = (Math.random() - 0.5) * 0.02; held = null; }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kDrag = document.getElementById('k-drag'), dragTimer = 0;
  function readDrag() { drag = 0.1 + kDrag.value / 100 * 0.32; }
  kDrag.addEventListener('input', function () {
    readDrag();
    clearTimeout(dragTimer);
    dragTimer = setTimeout(rescan, 250);
  });
  readDrag();
  var nBtns = document.querySelectorAll('[data-n]');
  function setN(n, sound) {
    nMag = n;
    nBtns.forEach(function (o) { o.classList.toggle('active', +o.dataset.n === n); });
    placeMagnets(); rescan();
    if (sound) SFX.play('toggle');
  }
  nBtns.forEach(function (b) { b.addEventListener('click', function () { setN(+b.dataset.n, true); }); });
  placeMagnets();
  setTimeout(function () {
    var a = document.querySelector('[data-n].active');
    if (a && +a.dataset.n !== nMag) setN(+a.dataset.n, false);
  }, 400);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), autoT = 0.3, turnT = 50;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;

    work(scan < 2000 ? 14 : 8);
    if (scan >= NX * NY && !window.__mpDone) window.__mpDone = performance.now();
    bctx.putImageData(img, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'low';
    ctx.drawImage(buf, 0, 0, W, H);
    /* 押さえているあいだは行き先の地図を明るく、放すとゆっくり戻す */
    hlA = held ? Math.min(1, hlA + dt * 10) : Math.max(0, hlA - dt * 0.9);
    if (hlA > 0 && hlBuf) {
      ctx.globalAlpha = hlA;
      ctx.drawImage(hlBuf, 0, 0, W, H);
      ctx.globalAlpha = 1;
    }
    if (held && previewN > 1) {
      ctx.strokeStyle = P.rgba(P.accent, 0.75);
      ctx.lineWidth = 1.3;
      ctx.beginPath();
      ctx.moveTo(sxp(preview[0]), syp(preview[1]));
      for (var pv = 1; pv < previewN; pv++) ctx.lineTo(sxp(preview[pv * 2]), syp(preview[pv * 2 + 1]));
      ctx.stroke();
    }

    /* しばらくすると磁石の並びが少し回り、地図が中心から描き変わる */
    if (scan >= NX * NY) {
      turnT -= dt;
      if (turnT <= 0) {
        spin += (0.4 + Math.random() * 0.8) * (Math.random() < 0.5 ? -1 : 1);
        rad = 0.85 + Math.random() * 0.35;
        placeMagnets(); rescan();
        turnT = 45;
      }
    }

    autoT -= dt;
    if (autoT <= 0) {
      var free = 0;
      for (var q = 0; q < bobs.length; q++) if (!bobs[q].mine) free++;
      if (free < 3) {
        var ang = Math.random() * Math.PI * 2, rr = 0.9 + Math.random() * 0.9;
        addBob(Math.cos(ang) * rr * W / H * 0.85, Math.sin(ang) * rr, false);
      }
      autoT = 4 + Math.random() * 3;
    }

    var sim = dt * 3.2, sub = 6, h = sim / sub;
    for (var n = bobs.length - 1; n >= 0; n--) {
      var b = bobs[n];
      if (!b.held) {
        for (var s = 0; s < sub; s++) stepBob(b, h);
        if (b.done < 0 && b.vx * b.vx + b.vy * b.vy < 0.002) {
          for (var m = 0; m < nMag; m++) {
            if ((mx[m] - b.x) * (mx[m] - b.x) + (my[m] - b.y) * (my[m] - b.y) < 0.03) {
              b.done = 0;
              rings.push({ m: m, t: 0, mine: b.mine });
              if (b.mine) SFX.play('pop'); else SFX.play('collide', 3000);
            }
          }
        }
        if (b.done >= 0) { b.done += dt; b.life = Math.max(0, 1 - b.done / 3); }
      }
      record(b);
      if (b.life <= 0) { bobs.splice(n, 1); continue; }
      drawBob(b);
    }

    /* 磁石 */
    for (var i = 0; i < nMag; i++) {
      var X = sxp(mx[i]), Y = syp(my[i]);
      ctx.fillStyle = P.rgba(P.deep, 0.9);
      ctx.beginPath(); ctx.arc(X, Y, 7, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = P.rgba(P.line, 0.85); ctx.lineWidth = 1.2;
      ctx.beginPath(); ctx.arc(X, Y, 7, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.9);
      ctx.beginPath(); ctx.arc(X, Y, 2, 0, Math.PI * 2); ctx.fill();
      if (hlA > 0.01 && i === hlLabel) {
        ctx.strokeStyle = P.rgba(P.accent, 0.8 * hlA); ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.arc(X, Y, 13, 0, Math.PI * 2); ctx.stroke();
      }
    }
    for (var r = rings.length - 1; r >= 0; r--) {
      var rg = rings[r]; rg.t += dt;
      var al = 1 - rg.t / 1.8;
      if (al <= 0) { rings.splice(r, 1); continue; }
      ctx.strokeStyle = P.rgba(rg.mine ? P.accent : P.line, al * 0.6);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(sxp(mx[rg.m]), syp(my[rg.m]), 9 + rg.t * 26, 0, Math.PI * 2); ctx.stroke();
    }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
