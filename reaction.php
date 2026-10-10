<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('reaction');

$pageName = 'reaction';
$pageTitle = 'REACTION - PULSE';
$pageDesc = '白い線で描いた模様が、枝分かれしたり丸い粒に分かれたりしながら、少しずつ形を変えていく。触ったところは模様が消えて種が置かれ、そこから新しい模様が広がる。';
$pageCanonical = 'https://pulse.subara3.com/reaction.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-form="coral">CORAL</button><button type="button" data-form="cells">CELLS</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var tide = { x: 0, y: 0, r: 0 }, clock = 0;
  var ctx;

  /* 2 つの物質が混ざり合い、片方がもう片方を食べて増える（グレイ・スコット）。
     粗い格子で解き、物質 B の濃さの等高線だけを細い線で引く */
  var NX, NY, A, B, A2, B2, heat, cx, cy;
  var FORMS = {
    coral: { f: 0.0545, k: 0.0620 },
    cells: { f: 0.0367, k: 0.0649 }
  };
  var form = 'coral', F = FORMS.coral.f, K = FORMS.coral.k, pace = 0.5;

  function alloc() {
    NX = Math.max(110, Math.min(230, Math.round(W / 6)));
    NY = Math.max(70, Math.round(NX * H / W));
    var n = NX * NY;
    A = new Float32Array(n).fill(1); B = new Float32Array(n);
    A2 = new Float32Array(n); B2 = new Float32Array(n);
    heat = new Float32Array(n);
    cx = new Float32Array(16); cy = new Float32Array(16);
    for (var i = 0; i < 14; i++) seed(Math.random() * NX, Math.random() * NY, 2 + Math.random() * 3, false);
    for (var s = 0; s < 420; s++) step();
  }

  function seed(gx, gy, r, touched) {
    for (var j = Math.floor(gy - r); j <= Math.ceil(gy + r); j++) {
      for (var i = Math.floor(gx - r); i <= Math.ceil(gx + r); i++) {
        var d = Math.hypot(i - gx, j - gy) / r;
        if (d >= 1) continue;
        var ii = (i % NX + NX) % NX, jj = (j % NY + NY) % NY, k = jj * NX + ii;
        var w = 0.5 + 0.5 * Math.cos(d * Math.PI);
        B[k] = Math.max(B[k], 0.9 * w);
        A[k] = Math.min(A[k], 1 - 0.6 * w);
        if (touched) heat[k] = Math.max(heat[k], w);
      }
    }
  }

  /* 触った所は小さく空けてから種を置く。空いた穴の中で新しい模様が育つのが見える */
  function clearHole(gx, gy, r) {
    for (var j = Math.floor(gy - r); j <= Math.ceil(gy + r); j++) {
      for (var i = Math.floor(gx - r); i <= Math.ceil(gx + r); i++) {
        var d = Math.hypot(i - gx, j - gy) / r;
        if (d >= 1) continue;
        var k = ((j % NY + NY) % NY) * NX + (i % NX + NX) % NX;
        var keep = d * d * d;
        B[k] *= keep; A[k] = 1 - (1 - A[k]) * keep;
        heat[k] = Math.max(heat[k], 1 - d * d);
      }
    }
  }

  /* 引き潮: 見えない大きな円がゆっくり動き、中の模様を少しずつ薄める。空いた所へ模様がまた伸びていく */
  function step() {
    var tr2 = tide.r * tide.r;
    for (var j = 0; j < NY; j++) {
      var up = ((j + NY - 1) % NY) * NX, dn = ((j + 1) % NY) * NX, row = j * NX;
      var dy = j - tide.y, dy2 = dy * dy;
      for (var i = 0; i < NX; i++) {
        var l = i === 0 ? NX - 1 : i - 1, r = i === NX - 1 ? 0 : i + 1;
        var k = row + i, a = A[k], b = B[k];
        var la = (A[row + l] + A[row + r] + A[up + i] + A[dn + i]) * 0.2
               + (A[up + l] + A[up + r] + A[dn + l] + A[dn + r]) * 0.05 - a;
        var lb = (B[row + l] + B[row + r] + B[up + i] + B[dn + i]) * 0.2
               + (B[up + l] + B[up + r] + B[dn + l] + B[dn + r]) * 0.05 - b;
        var abb = a * b * b;
        var na = a + la - abb + F * (1 - a);
        var nb = b + 0.5 * lb + abb - (K + F) * b;
        var dx = i - tide.x;
        if (dx * dx + dy2 < tr2) nb *= 0.9975;
        A2[k] = na < 0 ? 0 : na > 1 ? 1 : na;
        B2[k] = nb < 0 ? 0 : nb > 1 ? 1 : nb;
      }
    }
    var t = A; A = A2; A2 = t; t = B; B = B2; B2 = t;
  }

  /* ---------------------------------------------------------- 等高線 */
  var LEVELS = [0.09, 0.2, 0.32];
  function contour(level, path, hot, useHot) {
    var sx = W / NX, sy = H / NY;
    for (var j = 0; j < NY - 1; j++) {
      var r0 = j * NX, r1 = r0 + NX;
      for (var i = 0; i < NX - 1; i++) {
        var a = B[r0 + i], b = B[r0 + i + 1], c = B[r1 + i + 1], d = B[r1 + i];
        var idx = (a > level ? 1 : 0) | (b > level ? 2 : 0) | (c > level ? 4 : 0) | (d > level ? 8 : 0);
        if (idx === 0 || idx === 15) continue;
        var target = path;
        if (useHot) {
          if (heat[r0 + i] < 0.08) continue;
          target = hot;
        } else if (hot && heat[r0 + i] >= 0.08) continue;
        var x0 = (i + 0.5) * sx, y0 = (j + 0.5) * sy;
        /* 各辺の交点（上・右・下・左） */
        var tx = x0 + sx * (level - a) / (b - a), ty = y0;
        var rx = x0 + sx, ry = y0 + sy * (level - b) / (c - b);
        var bx = x0 + sx * (level - d) / (c - d), by = y0 + sy;
        var lx = x0, ly = y0 + sy * (level - a) / (d - a);
        switch (idx) {
          case 1: case 14: seg(target, lx, ly, tx, ty); break;
          case 2: case 13: seg(target, tx, ty, rx, ry); break;
          case 3: case 12: seg(target, lx, ly, rx, ry); break;
          case 4: case 11: seg(target, rx, ry, bx, by); break;
          case 6: case 9: seg(target, tx, ty, bx, by); break;
          case 7: case 8: seg(target, lx, ly, bx, by); break;
          case 5: seg(target, lx, ly, tx, ty); seg(target, rx, ry, bx, by); break;
          case 10: seg(target, tx, ty, rx, ry); seg(target, lx, ly, bx, by); break;
        }
      }
    }
  }
  function seg(c, x1, y1, x2, y2) { c.moveTo(x1, y1); c.lineTo(x2, y2); }

  function draw() {
    P.fill(ctx, W, H);
    ctx.lineCap = 'round';
    var styles = [
      { c: P.steel, a: 0.55, w: 0.8 },
      { c: P.line, a: 0.9, w: 1.25 },
      { c: P.line, a: 0.38, w: 0.8 }
    ];
    for (var n = 0; n < LEVELS.length; n++) {
      ctx.beginPath();
      contour(LEVELS[n], ctx, null, false);
      ctx.strokeStyle = P.rgba(styles[n].c, styles[n].a);
      ctx.lineWidth = styles[n].w;
      ctx.stroke();
    }
    /* 触ったところから育った線だけシアンで、ゆっくり白へ戻る */
    ctx.beginPath();
    contour(LEVELS[1], null, ctx, true);
    ctx.strokeStyle = P.rgba(P.accent, 0.9);
    ctx.lineWidth = 1.3;
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  function grid(e) { return { x: e.clientX / W * NX, y: e.clientY / H * NY, px: e.clientX, py: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = grid(e);
    clearHole(last.x, last.y, 7);
    seed(last.x, last.y, 2.6, true);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = grid(e), dist = Math.hypot(p.px - last.px, p.py - last.py);
    if (dist < 16) return;
    var n = Math.ceil(dist / 16);
    for (var i = 1; i <= n; i++) seed(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 1.8, true);
    last = p;
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.querySelectorAll('[data-form]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-form]').forEach(function (o) { o.classList.toggle('active', o === b); });
      form = b.dataset.form;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-clear').addEventListener('click', function () {
    A.fill(1); B.fill(0); heat.fill(0);
    for (var i = 0; i < 5; i++) seed(Math.random() * NX, Math.random() * NY, 3, false);
    SFX.play('click');
  });
  pace = document.getElementById('k-pace').value / 100;

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), mass = 0, massT = 0, autoT = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;
    /* 規則の値は選んだ形へ数秒かけて寄せる。急に切り替えない */
    var goal = FORMS[form], e = 1 - Math.exp(-dt * 0.6);
    F += (goal.f - F) * e; K += (goal.k - K) * e;
    tide.x = NX * (0.5 + 0.42 * Math.sin(clock * 0.031));
    tide.y = NY * (0.5 + 0.40 * Math.sin(clock * 0.047 + 1.3));
    tide.r = Math.min(NX, NY) * 0.2;
    var steps = Math.round(4 + pace * 14);
    for (var s = 0; s < steps; s++) step();
    for (var k = 0; k < heat.length; k++) if (heat[k] > 0) heat[k] *= 0.995;
    /* 模様が消えかけたら、どこかに種を落とす */
    massT += dt;
    if (massT > 1) {
      massT = 0; mass = 0;
      for (var m = 0; m < B.length; m += 7) mass += B[m];
      if (mass < B.length / 7 * 0.02) seed(Math.random() * NX, Math.random() * NY, 3, false);
    }
    autoT += dt;
    if (autoT > 9) { autoT = 0; seed(Math.random() * NX, Math.random() * NY, 2.4, false); }
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
