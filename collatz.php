<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('collatz');

$pageName = 'collatz';
$pageTitle = 'COLLATZ - PULSE';
$pageDesc = '偶数なら半分に、奇数なら3倍して1を足す。これを繰り返すと、どの数もやがて1になる。その道筋を束ねると海藻のような枝になって揺れ、触れた枝は根元までの道が光る。';
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
  var NMAX = 5200;
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
    var ea = 0.150 + s * 0.022 * Math.sin(t * 0.13) + bend;
    var oa = -0.272 + s * 0.035 * Math.sin(t * 0.083 + 1.7) + bend * 0.5;
    var ripple = s * 0.010;
    X[0] = 0; Y[0] = 0; A[0] = -Math.PI / 2;
    for (var i = 1; i < M; i++) {
      var p = parent[i];
      if (depth[i] > reveal + 1) { X[i] = X[p]; Y[i] = Y[p]; A[i] = A[p]; continue; }
      /* 根元から先へ、波がゆっくり伝わるように揺らす */
      var a = A[p] + (odd[i] ? oa : ea) + ripple * Math.sin(t * 0.7 - depth[i] * 0.09);
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
  function sx(i) { return W / 2 + (X[i] - view.cx) * view.s; }
  function sy(i) { return H / 2 + (Y[i] - view.cy) * view.s; }

  var LN = P.line, AC = P.accent, ST = P.steel;
  var LW = [0.6, 0.8, 1.05, 1.4, 1.9, 2.5];
  var LA = [0.22, 0.34, 0.5, 0.66, 0.82, 0.95];
  function draw() {
    var paths = [];
    for (var q = 0; q < 6; q++) paths.push(new Path2D());
    var tips = new Path2D(), glowP = new Path2D(), anyLit = false;
    var dr = 0.9;
    for (var i = 1; i < M; i++) {
      if (depth[i] > reveal + 1) continue;
      var p = parent[i];
      var x = sx(i), y = sy(i), px = sx(p), py = sy(p);
      var path = paths[level[i]];
      path.moveTo(px, py); path.lineTo(x, y);
      if (kids[i] === 0 && depth[i] <= reveal) { tips.moveTo(x + dr, y); tips.arc(x, y, dr, 0, Math.PI * 2); }
      if (lit[i] > 0.03) { glowP.moveTo(px, py); glowP.lineTo(x, y); anyLit = true; }
    }
    ctx.lineCap = 'round';
    for (var q2 = 0; q2 < 6; q2++) {
      ctx.lineWidth = LW[q2];
      ctx.strokeStyle = P.rgba(q2 < 2 ? P.mix(ST, LN, 0.55 + q2 * 0.2) : LN, LA[q2]);
      ctx.stroke(paths[q2]);
    }
    ctx.fillStyle = P.rgba(LN, 0.55);
    ctx.fill(tips);
    if (anyLit) {
      ctx.lineWidth = 1.6;
      ctx.strokeStyle = P.rgba(AC, 0.85 * litLevel);
      ctx.stroke(glowP);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var hold = false, ptr = { x: 0, y: 0 }, litLevel = 0, litFrom = -1;
  function nearest(x, y) {
    var best = -1, bd = 40 * 40;
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
    hold = true; ptr.x = e.clientX; ptr.y = e.clientY;
    light(nearest(ptr.x, ptr.y));
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    ptr.x = e.clientX; ptr.y = e.clientY;
    if (!hold) return;
    light(nearest(ptr.x, ptr.y));
    /* 触ったまま横へ動かすと、水の流れのように枝がそちらへなびく */
    bendT = (ptr.x / W - 0.5) * 0.02;
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
  reveal = maxDepth * 0.42;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    t += dt;
    if (reveal < maxDepth + 2) {
      reveal += dt * (6 + reveal * 0.04);
      chime += dt;
      if (chime > 3) { chime = 0; SFX.play('grow', 2500); }
    }
    bend += (bendT - bend) * Math.min(1, dt * 1.2);
    if (!hold) litLevel = Math.max(0, litLevel - dt * 0.3);
    layout();
    fitView(dt);
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
