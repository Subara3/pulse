<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('collatz');

$pageName = 'collatz';
$pageTitle = 'COLLATZ - PULSE';
$pageDesc = '偶数なら半分に、奇数なら3倍して1を足す。これを繰り返すとどの数も1になり、その道筋を束ねると海藻のような枝になる。触ると近くの枝の束が指のほうへ寄り、触れた枝から根元までの道が水色に光る。';
$pageCanonical = 'https://pulse.subara3.com/collatz.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-sway">SWAY</label><input type="range" id="k-sway" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-grow">REGROW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; });

  /* ---------------------------------------------------------- 木を組む
     1 から n までの道を逆向きにたどると、どの道も 1 を根にした 1 本の木に合流する。 */
  var NMAX = 4500;
  var index = new Map();
  var val = [], par = [], dep = [];
  function addNode(v, p) {
    var i = val.length;
    val.push(v); par.push(p); dep.push(p < 0 ? 0 : dep[p] + 1);
    index.set(v, i);
    return i;
  }
  addNode(1, -1);
  for (var n = 2; n <= NMAX; n++) {
    if (index.has(n)) continue;
    var chain = [], v = n;
    while (!index.has(v)) { chain.push(v); v = (v % 2 === 0) ? v / 2 : 3 * v + 1; }
    var p = index.get(v);
    for (var c = chain.length - 1; c >= 0; c--) p = addNode(chain[c], p);
  }
  var M = val.length;
  var parent = Int32Array.from(par), depth = Int32Array.from(dep);
  var odd = new Uint8Array(M);
  for (var i = 0; i < M; i++) odd[i] = val[i] % 2;
  /* どれだけの数がその枝を通るか（太さと明るさに使う） */
  var weight = new Float32Array(M), kids = new Int32Array(M);
  for (var i2 = M - 1; i2 > 0; i2--) { weight[i2] += 1; weight[parent[i2]] += weight[i2]; kids[parent[i2]]++; }
  weight[0] += 1;
  var maxDepth = 0;
  for (var i3 = 0; i3 < M; i3++) if (depth[i3] > maxDepth) maxDepth = depth[i3];
  var level = new Uint8Array(M);
  for (var i4 = 0; i4 < M; i4++) level[i4] = Math.min(5, Math.floor(Math.log(weight[i4]) / Math.log(4.2)));

  var X = new Float32Array(M), Y = new Float32Array(M), A = new Float32Array(M);
  var lit = new Float32Array(M);

  /* ---------------------------------------------------------- 形 */
  var t = 0, sway = 0.45, reveal = 6, bend = 0, bendT = 0;
  function layout() {
    /* 偶数で少し右へ、奇数で大きく左へ曲がる。曲がり方が時間でわずかに揺れる */
    var s = sway;
    var ea = 0.150 + s * 0.0005 * Math.sin(t * 0.09) + bend;
    var oa = -0.272 + s * 0.0008 * Math.sin(t * 0.06 + 1.7) + bend * 0.5;
    var ripple = s * 0.0004;
    X[0] = 0; Y[0] = 0; A[0] = -Math.PI / 2 + 0.55;   /* 枝の重みが画面の中ほどに来る向き */
    for (var i = 1; i < M; i++) {
      var p = parent[i];
      if (depth[i] > reveal + 1) { X[i] = X[p]; Y[i] = Y[p]; A[i] = A[p]; continue; }
      /* 根元から先へ、波がゆっくり伝わるように揺らす */
      var a = A[p] + (odd[i] ? oa : ea) + ripple * Math.sin(t * 0.35 - depth[i] * 0.09);
      var len = 1;
      var g = reveal - depth[i] + 1;           /* 伸びかけの枝 */
      if (g < 1) len = Math.max(0, g);
      A[i] = a;
      X[i] = X[p] + Math.cos(a) * len;
      Y[i] = Y[p] + Math.sin(a) * len;
    }
  }

  /* 画面に収める（ゆっくり追う） */
  var view = { cx: 0, cy: 0, s: 4, init: false };
  function fitView(dt) {
    var x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
    for (var i = 0; i < M; i += 3) {
      if (depth[i] > reveal) continue;
      if (X[i] < x0) x0 = X[i]; if (X[i] > x1) x1 = X[i];
      if (Y[i] < y0) y0 = Y[i]; if (Y[i] > y1) y1 = Y[i];
    }
    var s = Math.min(W * 0.86 / Math.max(10, x1 - x0), H * 0.84 / Math.max(10, y1 - y0));
    var cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    var k = view.init ? Math.min(1, dt * 0.8) : 1;
    view.init = true;
    view.s += (s - view.s) * k; view.cx += (cx - view.cx) * k; view.cy += (cy - view.cy) * k;
  }
  /* 画面上の位置。指のまわりの枝は押しのけられる（水をかき分けるように） */
  var SX = new Float32Array(M), SY = new Float32Array(M), NEAR = new Float32Array(M);
  function project() {
    var R2 = 130 * 130;
    for (var i = 0; i < M; i++) {
      var x = W / 2 + (X[i] - view.cx) * view.s, y = H / 2 + (Y[i] - view.cy) * view.s, nr = 0;
      if (pe > 0.01) {
        var dx = ptr.x - x, dy = ptr.y - y, d2 = dx * dx + dy * dy;
        if (d2 < R2 * 5) {
          /* 指のまわりの枝の束が指のほうへ寄せられる */
          var e = Math.exp(-d2 / R2);
          x += dx * 0.45 * e * pe; y += dy * 0.45 * e * pe;
          nr = Math.exp(-d2 / (R2 * 1.8)) * pe;
        }
      }
      SX[i] = x; SY[i] = y; NEAR[i] = nr;
    }
  }
  function sx(i) { return SX[i]; }
  function sy(i) { return SY[i]; }

  var LN = P.line, AC = P.accent, ST = P.steel;
  var LW = [1.2, 1.3, 1.5, 1.8, 2.2, 2.8];
  var LA = [0.38, 0.46, 0.58, 0.7, 0.84, 0.95];
  function draw() {
    var paths = [];
    for (var q = 0; q < 6; q++) paths.push(new Path2D());
    var tips = new Path2D(), glowP = new Path2D(), anyLit = false, nearP = new Path2D(), anyNear = false;
    var dr = 0.9;
    for (var i = 1; i < M; i++) {
      if (depth[i] > reveal + 1) continue;
      var p = parent[i];
      var x = sx(i), y = sy(i), px = sx(p), py = sy(p);
      var path = paths[level[i]];
      path.moveTo(px, py); path.lineTo(x, y);
      if (kids[i] === 0 && depth[i] <= reveal) { tips.moveTo(x + dr, y); tips.arc(x, y, dr, 0, Math.PI * 2); }
      if (lit[i] > 0.03) { glowP.moveTo(px, py); glowP.lineTo(x, y); anyLit = true; }
      if (NEAR[i] > 0.08) { nearP.moveTo(px, py); nearP.lineTo(x, y); anyNear = true; }
    }
    ctx.lineCap = 'round';
    for (var q2 = 0; q2 < 6; q2++) {
      ctx.lineWidth = LW[q2];
      ctx.strokeStyle = P.rgba(q2 < 2 ? P.mix(ST, LN, 0.55 + q2 * 0.2) : LN, LA[q2]);
      ctx.stroke(paths[q2]);
    }
    ctx.fillStyle = P.rgba(LN, 0.55);
    ctx.fill(tips);
    /* 押しのけられた枝は白く太る */
    if (anyNear) {
      ctx.lineWidth = 2.6;
      ctx.strokeStyle = P.rgba(LN, 0.95 * Math.min(1, pe * 1.5));
      ctx.stroke(nearP);
    }
    /* 触れた枝から根元までの道 */
    if (anyLit) {
      ctx.lineWidth = 12;
      ctx.strokeStyle = P.rgba(AC, 0.22 * litLevel);
      ctx.stroke(glowP);
      ctx.lineWidth = 4;
      ctx.strokeStyle = P.rgba(P.mix(AC, LN, 0.25), litLevel);
      ctx.stroke(glowP);
    }
    ctx.lineCap = 'butt';
  }

  /* ---------------------------------------------------------- 操作 */
  var hold = false, ptr = { x: -999, y: -999 }, litLevel = 0, litFrom = -1, pe = 0;
  function nearest(x, y) {
    var best = -1, bd = 90 * 90;
    for (var i = 1; i < M; i++) {
      if (depth[i] > reveal) continue;
      var dx = sx(i) - x, dy = sy(i) - y, d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }
  function light(i) {
    if (i < 0) return;
    if (i !== litFrom) SFX.play('tick', 120);
    litFrom = i;
    lit.fill(0);
    for (var k = i; k > 0; k = parent[k]) lit[k] = 1;
    litLevel = 1;
  }
  canvas.addEventListener('pointerdown', function (e) {
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY; pe = 1;
    light(nearest(ptr.x, ptr.y));
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    ptr.x = e.clientX; ptr.y = e.clientY;
    if (!hold) return;
    light(nearest(ptr.x, ptr.y));
    /* 触ったまま横へ動かすと、水の流れのように枝がそちらへなびく */
    bendT = (ptr.x / W - 0.5) * 0.008;
  });
  function up() { hold = false; bendT = 0; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var swayEl = document.getElementById('k-sway');
  function readSway() { sway = swayEl.value / 100 * 2; }
  swayEl.addEventListener('input', readSway);
  readSway();
  document.getElementById('k-grow').addEventListener('click', function () {
    reveal = 4; SFX.play('click');
  });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  reveal = maxDepth + 2;   /* 開いたときは育ちきった姿。REGROW で根元から育て直す */
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    t += dt;
    if (reveal < maxDepth + 2) {
      reveal += dt * (10 + reveal * 0.06);
      chime += dt;
      if (chime > 3) { chime = 0; SFX.play('grow', 2500); }
    }
    bend += (bendT - bend) * Math.min(1, dt * 1.2);
    if (!hold) litLevel = Math.max(0, litLevel - dt * 0.6);
    pe = hold ? 1 : pe * Math.exp(-dt * 1.6);
    layout();
    fitView(dt);
    project();
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.6 });
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
