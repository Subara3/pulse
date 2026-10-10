<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fourier');

$pageName = 'fourier';
$pageTitle = 'FOURIER - PULSE';
$pageDesc = '大小の円が円の上で回り、先端の点が花のような図形を描いては次の形へ移っていく。押すと図形がその点へ引き寄せられて輪郭が光り、指で線を描くと円の列がその線をなぞり直す。';
$pageCanonical = 'https://pulse.subara3.com/fourier.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="35"></div>
  <div class="ctl-row"><label for="k-circles">CIRCLES</label><input type="range" id="k-circles" min="0" max="100" value="70"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="arms">ARMS</button><button type="button" data-look="trace">TRACE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, CX = 0, CY = 0;
  /* 図形の大きさに合わせて、画面の高さの 2/3 ほどに収める（ゆっくり追う） */
  var view = { s: 300, cx: 0, cy: 0, init: false };
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; CX = w / 2; CY = h / 2; view.init = false; });
  function sx(x) { return CX + (x - view.cx) * view.s; }
  function sy(y) { return CY + (y - view.cy) * view.s; }
  /* 押したところへ線が引き寄せられる（離すとばねのように戻る） */
  var pk = { x: 0, y: 0, a: 0 }, wq = [0, 0];
  function wp(X, Y) {
    if (pk.a > 0.002) {
      var dx = pk.x - X, dy = pk.y - Y, e = Math.exp(-(dx * dx + dy * dy) / (210 * 210)) * 0.8 * pk.a;
      X += dx * e; Y += dy * e;
    }
    wq[0] = X; wq[1] = Y; return wq;
  }

  /* 係数は k = -K..K の複素数。re[k+K], im[k+K] */
  var K = 64, NK = 2 * K + 1;
  var curR = new Float64Array(NK), curI = new Float64Array(NK);
  var fromR = new Float64Array(NK), fromI = new Float64Array(NK);
  var toR = new Float64Array(NK), toI = new Float64Array(NK);
  var morph = 1, morphDur = 9;
  var hold = 0;                          /* 今の形をあと何秒保つか */
  var userShape = false;

  /* m 回対称の花: k ≡ 1 (mod m) の項だけを使う。位相をそろえて左右対称にする */
  var shapeNo = 0;
  var SYM = [5, 5, 3, 3, 7, 7, 4, 4, 6, 6];
  function flower(outR, outI) {
    outR.fill(0); outI.fill(0);
    var m = SYM[shapeNo % SYM.length];
    shapeNo++;
    var base = Math.random() * Math.PI * 2;
    function put(k, a, flip) {
      if (k < -K || k > K) return;
      /* 位相を基準角にそろえると、図形が鏡のように対称になる */
      var ph = base * (1 - k) + (flip ? Math.PI : 0);
      outR[k + K] += a * Math.cos(ph); outI[k + K] += a * Math.sin(ph);
    }
    put(1, 0.6, false);
    put(1 - m, 0.12 + Math.random() * 0.2, Math.random() < 0.5);
    put(1 + m, 0.03 + Math.random() * 0.09, Math.random() < 0.5);
    if (Math.random() < 0.6) put(1 - 2 * m, 0.02 + Math.random() * 0.05, Math.random() < 0.5);
  }
  /* 移り変わりが始まったら、それまでの軌跡は別に取り分けてはっきり薄れさせる */
  var ghost = [], ghostA = 0;
  function startMorph(tR, tI, dur) {
    fromR.set(curR); fromI.set(curI);
    toR.set(tR); toI.set(tI);
    morph = 0; morphDur = dur;
    ghost = trail; ghostA = 1; trail = [];
  }

  /* ---------------------------------------------------------- 円の列 */
  var order = [];
  for (var k0 = -K; k0 <= K; k0++) order.push(k0);
  var nCircles = 40, speed = 1 / 12, s = 0;
  function sortTerms() {
    order.sort(function (a, b) {
      if (a === 0) return -1; if (b === 0) return 1;
      return (curR[b + K] * curR[b + K] + curI[b + K] * curI[b + K]) - (curR[a + K] * curR[a + K] + curI[a + K] * curI[a + K]);
    });
  }
  function evalAt(ss, n) {
    var x = 0, y = 0;
    for (var i = 0; i < n; i++) {
      var k = order[i], r = curR[k + K], im = curI[k + K];
      var th = Math.PI * 2 * k * ss, c = Math.cos(th), sn = Math.sin(th);
      x += r * c - im * sn; y += r * sn + im * c;
    }
    return [x, y];
  }

  var trail = [], phase = 0, keep = 0.92;
  var LN = P.line, AC = P.accent, ST = P.steel;
  var look = 'arms';
  var draftA = 1;
  function fitTo(x0, x1, y0, y1) {
    var w = Math.max(0.05, x1 - x0), h = Math.max(0.05, y1 - y0);
    var tgt = Math.min(H * 0.68 / h, W * 0.84 / w);
    var cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    var k = view.init ? 0.02 : 1;
    view.init = true;
    view.s += (tgt - view.s) * k; view.cx += (cx - view.cx) * k; view.cy += (cy - view.cy) * k;
  }
  function strokeTrail(tr, mul) {
    var L = tr.length;
    if (L < 2) return;
    var B = 10;
    for (var b = 0; b < B; b++) {
      ctx.beginPath();
      var i0 = Math.floor(L * b / B), i1 = Math.min(L - 1, Math.floor(L * (b + 1) / B) + 1);
      for (var m = i0; m <= i1; m++) { var w = wp(sx(tr[m][0]), sy(tr[m][1])); if (m === i0) ctx.moveTo(w[0], w[1]); else ctx.lineTo(w[0], w[1]); }
      var a = Math.pow((b + 1) / B, 1.2);
      ctx.lineWidth = 1.6 + a * 1.6;
      ctx.strokeStyle = P.rgba(LN, (0.38 + 0.62 * a) * mul);
      ctx.stroke();
    }
  }
  function draw() {
    var n = 0;
    for (var i = 0; i < order.length && n < nCircles + 1; i++) {
      var k = order[i];
      if (k !== 0 && curR[k + K] * curR[k + K] + curI[k + K] * curI[k + K] < 1e-8) break;
      n++;
    }

    /* 薄い下書き: 今の係数が描く図形の全体。移り変わりの間は消す */
    var calm = morph < 1 ? 0 : 1;
    draftA += (calm - draftA) * 0.04;
    var bx0 = 1e9, bx1 = -1e9, by0 = 1e9, by1 = -1e9;
    var draft = new Path2D();
    for (var j = 0; j <= 360; j++) {
      var q = evalAt(j / 360, n);
      if (q[0] < bx0) bx0 = q[0]; if (q[0] > bx1) bx1 = q[0];
      if (q[1] < by0) by0 = q[1]; if (q[1] > by1) by1 = q[1];
      var w0 = wp(sx(q[0]), sy(q[1]));
      if (j === 0) draft.moveTo(w0[0], w0[1]); else draft.lineTo(w0[0], w0[1]);
    }
    fitTo(bx0, bx1, by0, by1);
    if (draftA > 0.02) {
      /* 図形の内側をごく淡く塗り、輪郭の下書きを添える */
      ctx.fillStyle = P.rgba(LN, 0.13 * draftA);
      ctx.fill(draft, 'evenodd');
      ctx.lineWidth = 1;
      ctx.strokeStyle = P.rgba(ST, 0.3 * draftA);
      ctx.stroke(draft);
    }
    /* つままれている間は、図形の輪郭が水色に光る */
    if (pk.a > 0.02) {
      ctx.lineWidth = 2.2;
      ctx.strokeStyle = P.rgba(AC, 0.7 * pk.a);
      ctx.stroke(draft);
    }

    /* 前の形の軌跡: 移り変わりの最初の 1 秒ほどで消える */
    if (ghostA > 0.01 && ghost.length > 1) strokeTrail(ghost, ghostA * 0.55);
    /* なぞった跡: 一周ぶん、古いほど薄く。これが主役 */
    strokeTrail(trail, 1);
    var L = trail.length;

    /* 円と腕: 細く薄く、先端の白い線を邪魔しない */
    var x = sx(curR[K]), y = sy(curI[K]), tipX = x, tipY = y, vs = view.s;
    if (look === 'arms') {
      var circ = new Path2D(), arm = new Path2D(), w1 = wp(x, y);
      arm.moveTo(w1[0], w1[1]);
      for (var c = 1; c < n; c++) {
        var kk = order[c], r = curR[kk + K], im = curI[kk + K];
        var rad = Math.hypot(r, im) * vs;
        w1 = wp(x, y);
        if (rad > 1.5) { circ.moveTo(w1[0] + rad, w1[1]); circ.arc(w1[0], w1[1], rad, 0, Math.PI * 2); }
        var th = Math.PI * 2 * kk * s, co = Math.cos(th), sn = Math.sin(th);
        x += (r * co - im * sn) * vs; y += (r * sn + im * co) * vs;
        w1 = wp(x, y);
        arm.lineTo(w1[0], w1[1]);
      }
      x = w1[0]; y = w1[1];
      ctx.lineWidth = 0.6;
      ctx.strokeStyle = P.rgba(ST, 0.17);
      ctx.stroke(circ);
      ctx.lineWidth = 0.8;
      ctx.strokeStyle = P.rgba(LN, 0.26);
      ctx.stroke(arm);
      tipX = x; tipY = y;
    } else if (L) { var w2 = wp(sx(trail[L - 1][0]), sy(trail[L - 1][1])); tipX = w2[0]; tipY = w2[1]; }

    /* 画面に一つだけの焦点: ペン先 */
    ctx.fillStyle = P.rgba(AC, 0.95);
    ctx.beginPath(); ctx.arc(tipX, tipY, 2.6, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = P.rgba(AC, 0.3);
    ctx.beginPath(); ctx.arc(tipX, tipY, 7, 0, Math.PI * 2); ctx.stroke();
    return n;
  }

  /* ---------------------------------------------------------- 描いた線を取り込む */
  var stroke = null, pkHold = false;
  function fromStroke(pts) {
    /* 弧長で 256 点に取り直し、閉じた線として DFT */
    var N = 256, len = [0];
    for (var i = 1; i < pts.length; i++) len.push(len[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]));
    var closeLen = Math.hypot(pts[0][0] - pts[pts.length - 1][0], pts[0][1] - pts[pts.length - 1][1]);
    var total = len[len.length - 1] + closeLen;
    var zx = new Float64Array(N), zy = new Float64Array(N), seg = 0;
    for (var n = 0; n < N; n++) {
      var d = total * n / N;
      if (d >= len[len.length - 1]) {
        var f = closeLen > 0 ? (d - len[len.length - 1]) / closeLen : 0, a = pts[pts.length - 1], b = pts[0];
        zx[n] = a[0] + (b[0] - a[0]) * f; zy[n] = a[1] + (b[1] - a[1]) * f;
      } else {
        while (len[seg + 1] < d) seg++;
        var f2 = (d - len[seg]) / Math.max(1e-6, len[seg + 1] - len[seg]);
        zx[n] = pts[seg][0] + (pts[seg + 1][0] - pts[seg][0]) * f2;
        zy[n] = pts[seg][1] + (pts[seg + 1][1] - pts[seg][1]) * f2;
      }
      zx[n] = (zx[n] - CX) / view.s + view.cx; zy[n] = (zy[n] - CY) / view.s + view.cy;
    }
    var R = new Float64Array(NK), I = new Float64Array(NK);
    for (var k = -K; k <= K; k++) {
      var sr = 0, si = 0;
      for (var m = 0; m < N; m++) {
        var th = -Math.PI * 2 * k * m / N, c = Math.cos(th), s2 = Math.sin(th);
        sr += zx[m] * c - zy[m] * s2; si += zx[m] * s2 + zy[m] * c;
      }
      R[k + K] = sr / N; I[k + K] = si / N;
    }
    return [R, I];
  }

  canvas.addEventListener('pointerdown', function (e) {
    stroke = [[e.clientX, e.clientY]];
    pk.x = e.clientX; pk.y = e.clientY; pk.a = Math.max(pk.a, 0.5); pkHold = true;
    SFX.play('tick');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!stroke) return;
    var l = stroke[stroke.length - 1];
    if (Math.hypot(e.clientX - l[0], e.clientY - l[1]) < 3) return;
    stroke.push([e.clientX, e.clientY]);
    /* 線を描き始めたら、引き寄せはやめる */
    if (stroke.length > 6) pkHold = false;
    SFX.play('move', 160);
  });
  function up() {
    if (!stroke) return;
    var pts = stroke; stroke = null; pkHold = false;
    var span = 0;
    for (var i = 1; i < pts.length; i++) span += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
    if (pts.length > 8 && span > 80) {
      /* 閉じていない線は、行って戻る一筆として扱う（端と端を直線で結ばない） */
      var gap = Math.hypot(pts[0][0] - pts[pts.length - 1][0], pts[0][1] - pts[pts.length - 1][1]);
      if (gap > span * 0.2) pts = pts.concat(pts.slice(1, -1).reverse());
      var c = fromStroke(pts);
      startMorph(c[0], c[1], 0.9);
      trail = []; ghostA = 0.6; s = 0;
      userShape = true; hold = 2 / speed;   /* 描いた線は 2 周なぞってから花に戻る */
      SFX.play('spawn');
    } else {
      /* 軽く触っただけなら、次の花へ移る */
      flower(toR, toI); startMorph(toR, toI, 5);
      userShape = false; hold = 2 / speed;
      SFX.play('ripple');
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var speedEl = document.getElementById('k-speed'), circEl = document.getElementById('k-circles');
  function readSpeed() { speed = 1 / (26 - speedEl.value / 100 * 20); }
  function readCirc() { nCircles = Math.round(3 + Math.pow(circEl.value / 100, 1.6) * 60); }
  speedEl.addEventListener('input', readSpeed);
  circEl.addEventListener('input', readCirc);
  readSpeed(); readCirc();
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 回す */
  flower(curR, curI);
  hold = 1.5 / speed;
  /* 最初の 1 周の半分は描いた状態で始める */
  sortTerms();
  (function () {
    var n = Math.min(order.length, nCircles + 1);
    for (var i = 0; i < 300; i++) {
      var q = evalAt(i / 600, n);
      trail.push([q[0], q[1], i / 600]);
    }
    s = 0.5; phase = 0.5;
  })();
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    s = (s + dt * speed) % 1;
    pk.a = pkHold ? Math.min(1, pk.a + dt * 7) : pk.a * Math.exp(-dt * 1.5);
    phase += dt * speed;

    if (morph < 1) {
      morph = Math.min(1, morph + dt / morphDur);
      var e = morph * morph * (3 - 2 * morph);
      for (var i = 0; i < NK; i++) { curR[i] = fromR[i] + (toR[i] - fromR[i]) * e; curI[i] = fromI[i] + (toI[i] - fromI[i]) * e; }
    } else {
      hold -= dt;
      if (hold <= 0) {
        flower(toR, toI); startMorph(toR, toI, userShape ? 6 : 10);
        userShape = false; hold = 1.6 / speed;
        SFX.play('grow', 3000);
      }
    }
    sortTerms();

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.7 });
    var n = Math.min(order.length, nCircles + 1);
    var q = evalAt(s, n);
    trail.push([q[0], q[1], phase]);
    /* 移り変わりの間は短い尾だけ。落ち着いたら一周ぶんまで伸びる */
    keep += ((morph < 1 ? 0.3 : 0.92) - keep) * Math.min(1, dt * 0.8);
    while (trail.length > 2 && phase - trail[0][2] > keep) trail.shift();
    ghostA = Math.max(0, ghostA - dt / 1.1);
    if (ghostA <= 0) ghost = [];
    draw();

    if (stroke && stroke.length > 1) {
      ctx.lineWidth = 1.4;
      ctx.strokeStyle = P.rgba(AC, 0.85);
      ctx.beginPath();
      ctx.moveTo(stroke[0][0], stroke[0][1]);
      for (var j = 1; j < stroke.length; j++) ctx.lineTo(stroke[j][0], stroke[j][1]);
      ctx.stroke();
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
