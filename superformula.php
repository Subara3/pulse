<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('superformula');

$pageName = 'superformula';
$pageTitle = 'SUPERFORMULA - PULSE';
$pageDesc = '大きさの違う閉じた曲線が何十本も重なり、円、星形、花びら形、角の丸い四角へと、外側から順に形を変えていく。ドラッグすると角のとがり方とふくらみ方が変わり、タップすると次の形に移る。';
$pageCanonical = 'https://pulse.subara3.com/superformula.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-twist">TWIST</label><input type="range" id="k-twist" min="0" max="100" value="35"></div>
  <div class="ctl-row"><label for="k-layers">LAYERS</label><input type="range" id="k-layers" min="0" max="100" value="60"></div>
  <div class="ctl-btns"><button type="button" class="active" data-cycle="on">DRIFT</button><button type="button" data-cycle="off">HOLD</button></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * r(θ) = ( |cos(mθ/4)|^n2 + |sin(mθ/4)|^n3 )^(-1/n1)
   * 形の切り替えは毎フレームの値を履歴に残し、内側の層ほど少し前の値で描く。
   * 外側で変わった形が、時間差で内側へ伝わっていく。
   */
  var SHAPES = [
    [6, 1, 7, 8], [5, 2, 7, 7], [4, 12, 15, 15], [8, 0.9, 1.6, 1.6], [3, 4.5, 10, 10],
    [7, 0.6, 1.2, 1.2], [4, 1.2, 0.8, 0.8], [5, 0.4, 0.4, 0.4], [10, 3, 8, 8], [6, 60, 55, 30],
    [2, 1.5, 5, 6], [9, 1.4, 4, 3]
  ];
  var HIST = 200, hist = [], head = -1;
  var cur = 0, nxt = 1, morph = 1, hold = 0, cycle = true;
  var twist = 0.35, layers = 0.6;
  var bend = { x: 0, y: 0, tx: 0, ty: 0, lit: 0 };
  var pts = new Float32Array(722 * 2);
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; });

  for (var i = 0; i < HIST; i++) hist.push([6, 1, 7, 8, 0]);

  function ease(t) { return t * t * (3 - 2 * t); }
  function params(out) {
    var e = ease(morph), a = SHAPES[cur], b = SHAPES[nxt];
    /* n は対数で補間すると、とがった形と丸い形の間がなめらかに移る */
    out[0] = a[0] + (b[0] - a[0]) * e;
    for (var k = 1; k < 4; k++) out[k] = Math.exp(Math.log(a[k]) * (1 - e) + Math.log(b[k]) * e);
    /* 指の位置: 横は角のとがり方（n2, n3）、縦はふくらみ方（n1） */
    out[2] *= Math.exp(bend.x * 1.6); out[3] *= Math.exp(bend.x * 1.6);
    out[1] *= Math.exp(-bend.y * 1.4);
    out[4] = bend.lit;
  }

  function shape(p, scale, rot, R) {
    var m = p[0], n1 = p[1], n2 = p[2], n3 = p[3], N = 360, max = 0, i;
    for (i = 0; i <= N; i++) {
      var th = i / N * Math.PI * 2;
      var c = Math.abs(Math.cos(m * th / 4)), s = Math.abs(Math.sin(m * th / 4));
      var r = Math.pow(Math.pow(c, n2) + Math.pow(s, n3), -1 / n1);
      if (!isFinite(r)) r = 0;
      pts[i * 2] = th; pts[i * 2 + 1] = r;
      if (r > max) max = r;
    }
    var k = max > 0 ? R * scale / max : 0;
    ctx.moveTo(W / 2 + Math.cos(rot) * pts[1] * k, H / 2 + Math.sin(rot) * pts[1] * k);
    for (i = 1; i <= N; i++) {
      var a = pts[i * 2] + rot, rr = pts[i * 2 + 1] * k;
      ctx.lineTo(W / 2 + Math.cos(a) * rr, H / 2 + Math.sin(a) * rr);
    }
  }

  var clock = 0;
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.55 });
    var R = Math.min(W, H) * 0.43;
    /* 中心の十字と外周の円（図面の基準線） */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.steel, 0.28);
    ctx.beginPath();
    ctx.moveTo(W / 2 - R * 1.12, H / 2); ctx.lineTo(W / 2 + R * 1.12, H / 2);
    ctx.moveTo(W / 2, H / 2 - R * 1.12); ctx.lineTo(W / 2, H / 2 + R * 1.12);
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.steel, 0.18);
    ctx.beginPath(); ctx.arc(W / 2, H / 2, R * 1.04, 0, Math.PI * 2); ctx.stroke();

    var L = Math.round(14 + layers * 30), lag = Math.max(1, Math.floor((HIST - 1) / L));
    var base = clock * 0.035;
    for (var n = L - 1; n >= 0; n--) {
      var p = hist[(head - n * lag + HIST * 4) % HIST];
      var u = n / (L - 1);
      var scale = 1 - u * 0.9;
      var rot = base + u * twist * Math.PI * 0.9;
      var a = 0.12 + 0.6 * Math.pow(1 - u, 1.6);
      /* 指で曲げた変化が伝わっている層だけ、シアンを混ぜる */
      var col = p[4] > 0.02 ? P.mix(P.line, P.accent, Math.min(1, p[4])) : P.line;
      ctx.strokeStyle = P.rgba(col, n === 0 ? 0.92 : a);
      ctx.lineWidth = n === 0 ? 1.5 : 1;
      ctx.beginPath();
      shape(p, scale, rot, R);
      ctx.closePath();
      ctx.stroke();
    }
  }

  function next() {
    var prevCur = cur;
    if (morph < 1) cur = nxt;
    var n;
    do { n = Math.floor(Math.random() * SHAPES.length); } while (n === cur || n === prevCur);
    nxt = n; morph = 0; hold = 0;
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, sx = 0, sy = 0, moved = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; sx = e.clientX; sy = e.clientY; moved = 0;
    bend.tx = (e.clientX / W - 0.5) * 2; bend.ty = (e.clientY / H - 0.5) * 2;
    SFX.play('tick', 200);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    moved += Math.abs(e.movementX || 0) + Math.abs(e.movementY || 0);
    bend.tx = (e.clientX / W - 0.5) * 2; bend.ty = (e.clientY / H - 0.5) * 2;
    SFX.play('move', 200);
  });
  function up(e) {
    if (!down) return;
    down = false;
    bend.tx = 0; bend.ty = 0;
    /* その場で離したら、次の形へ */
    if (e && Math.hypot(e.clientX - sx, e.clientY - sy) < 6) { next(); SFX.play('grow', 300); }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; bend.tx = 0; bend.ty = 0; });

  document.getElementById('k-twist').addEventListener('input', function () { twist = this.value / 100; });
  document.getElementById('k-layers').addEventListener('input', function () { layers = this.value / 100; });
  document.querySelectorAll('[data-cycle]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-cycle]').forEach(function (o) { o.classList.toggle('active', o === b); });
      cycle = b.dataset.cycle === 'on';
      SFX.play('toggle');
    });
  });
  document.getElementById('k-next').addEventListener('click', function () { next(); SFX.play('click'); });
  twist = document.getElementById('k-twist').value / 100;
  layers = document.getElementById('k-layers').value / 100;
  cycle = !!document.querySelector('[data-cycle="on"].active');

  /* ---------------------------------------------------------- 回す */
  /* 開いた瞬間から層がそろって見えるように、履歴を埋めておく */
  morph = 0;
  for (i = 0; i < HIST; i++) { morph = Math.min(1, morph + 1 / 120); head = (head + 1) % HIST; params(hist[head]); }
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;
    if (morph < 1) morph = Math.min(1, morph + dt / 4.5);
    else {
      hold += dt;
      if (cycle && hold > 6) { cur = nxt; next(); }
    }
    var k = Math.min(1, dt * 4);
    bend.x += (bend.tx - bend.x) * k; bend.y += (bend.ty - bend.y) * k;
    bend.lit = down ? Math.min(1, bend.lit + dt * 4) : Math.max(0, bend.lit - dt * 1.5);
    head = (head + 1) % HIST;
    params(hist[head]);
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
