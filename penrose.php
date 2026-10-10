<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('penrose');

$pageName = 'penrose';
$pageTitle = 'PENROSE - PULSE';
$pageDesc = '太い菱形と細い菱形の2種類のタイルで敷き詰めた床に、画面がゆっくり近づいていく。近づくとタイルが細かいタイルに分かれるが、同じ並びがくり返し出てくることはない。触ると、触ったところから周りのタイルが順に光る。';
$pageCanonical = 'https://pulse.subara3.com/penrose.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-look="tiles">TILES</button><button type="button" data-look="lines">LINES</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, CX = 0, CY = 0, RV = 0, ctx;

  var PHI = (1 + Math.sqrt(5)) / 2;
  var roots = [];          /* 画面座標の三角形 [色, ax, ay, bx, by, cx, cy]。0 = 細い菱形の半分, 1 = 太い菱形の半分 */
  var pulses = [];         /* 触ったところから広がる輪 */
  var look = 'tiles', drift = 0.4;

  /* ロビンソン三角形を黄金比で切り分ける */
  function split(t, out) {
    var ax = t[1], ay = t[2], bx = t[3], by = t[4], cx = t[5], cy = t[6];
    if (t[0] === 0) {
      var px = ax + (bx - ax) / PHI, py = ay + (by - ay) / PHI;
      out.push([0, cx, cy, px, py, bx, by], [1, px, py, cx, cy, ax, ay]);
    } else {
      var qx = bx + (ax - bx) / PHI, qy = by + (ay - by) / PHI;
      var rx = bx + (cx - bx) / PHI, ry = by + (cy - by) / PHI;
      out.push([1, rx, ry, cx, cy, ax, ay], [1, qx, qy, rx, ry, bx, by], [0, rx, ry, qx, qy, ax, ay]);
    }
  }
  function edge(t) { return Math.hypot(t[3] - t[1], t[4] - t[2]); }
  /* 画面を覆う円（回転しても角が欠けない）の外にある三角形は捨てる */
  function outside(t) {
    var gx = (t[1] + t[3] + t[5]) / 3, gy = (t[2] + t[4] + t[6]) / 3;
    return Math.hypot(gx - CX, gy - CY) - edge(t) * 1.1 > RV;
  }

  var ROOT_MAX = 420;
  function seed() {
    CX = W / 2; CY = H / 2;
    RV = Math.hypot(W, H) * 0.5 + 40;
    /* 太陽形の 10 枚から始める。中心は画面の外にずらして、ありふれた場所から眺める */
    var R = RV * 3.2, ox = CX + RV * 0.73, oy = CY - RV * 0.41;
    roots = [];
    for (var i = 0; i < 10; i++) {
      var a1 = (2 * i - 1) * Math.PI / 10, a2 = (2 * i + 1) * Math.PI / 10;
      var b = [ox + Math.cos(a1) * R, oy + Math.sin(a1) * R], c = [ox + Math.cos(a2) * R, oy + Math.sin(a2) * R];
      if (i % 2 === 0) { var s = b; b = c; c = s; }
      roots.push([0, ox, oy, b[0], b[1], c[0], c[1]]);
    }
    for (var k = 0; k < 4; k++) settle();
    pulses.length = 0;
  }
  /* 大きくなりすぎた根を切り分け、見えなくなったものを捨てる */
  function settle() {
    var next = [];
    for (var i = 0; i < roots.length; i++) {
      var t = roots[i];
      if (edge(t) > ROOT_MAX) {
        var kids = [];
        split(t, kids);
        for (var j = 0; j < kids.length; j++) if (!outside(kids[j])) next.push(kids[j]);
      } else if (!outside(t)) next.push(t);
    }
    roots = next;
  }

  /* 画面の中心を軸に、ゆっくり寄りながら少し回る */
  function transform(s, rot) {
    var c = Math.cos(rot) * s, sn = Math.sin(rot) * s;
    function tx(x, y, out, i) {
      var dx = x - CX, dy = y - CY;
      out[i] = CX + dx * c - dy * sn;
      out[i + 1] = CY + dx * sn + dy * c;
    }
    for (var i = 0; i < roots.length; i++) {
      var t = roots[i];
      tx(t[1], t[2], t, 1); tx(t[3], t[4], t, 3); tx(t[5], t[6], t, 5);
    }
    for (var n = 0; n < pulses.length; n++) {
      var p = pulses[n], q = [0, 0];
      tx(p.x, p.y, q, 0); p.x = q[0]; p.y = q[1]; p.r *= s;
    }
  }

  /* ---------------------------------------------------------- 描く */
  function smooth(a, b, x) { var t = (x - a) / (b - a); t = t < 0 ? 0 : t > 1 ? 1 : t; return t * t * (3 - 2 * t); }
  var NB = 10;
  var lineB = [], fillB = [], glowB = [], hiB = [];
  function resetBuckets() {
    for (var i = 0; i < NB; i++) { lineB[i] = []; fillB[i] = []; glowB[i] = []; hiB[i] = []; }
  }
  /* 線の濃さ: 細かすぎる段は薄く、大きくなった段も薄れて、次の段と入れ替わる */
  function lineAlpha(e) { return smooth(30, 64, e) * (1 - smooth(90, 160, e)); }
  function fillAlpha(e) { return smooth(46, 74, e) * (1 - smooth(74, 120, e)); }
  function glowAt(gx, gy) {
    var g = 0;
    for (var n = 0; n < pulses.length; n++) {
      var p = pulses[n], d = Math.hypot(gx - p.x, gy - p.y) - p.r;
      /* 輪の内側はまるごと明るく、縁がいちばん明るい */
      var k = (d < 0 ? 0.5 + 0.5 * Math.exp(-d * d / (p.w * p.w)) : Math.exp(-d * d / (p.w * p.w))) * p.life;
      if (k > g) g = k;
    }
    return g;
  }
  function visit(t) {
    var e = edge(t);
    var gx = (t[1] + t[3] + t[5]) / 3, gy = (t[2] + t[4] + t[6]) / 3;
    var rad = e * 1.1;
    if (gx + rad < 0 || gx - rad > W || gy + rad < 0 || gy - rad > H) return;
    var la = lineAlpha(e);
    if (la > 0.02) {
      lineB[Math.min(NB - 1, (la * NB) | 0)].push(t);
      if (pulses.length) {
        var gl = glowAt(gx, gy) * la;
        if (gl > 0.05) hiB[Math.min(NB - 1, (gl * NB) | 0)].push(t);
      }
    }
    var fa = fillAlpha(e);
    if (fa > 0.02) {
      if (look === 'tiles' && t[0] === 1) fillB[Math.min(NB - 1, (fa * NB) | 0)].push(t);
      if (pulses.length) {
        var g = glowAt(gx, gy) * fa;
        if (g > 0.03) glowB[Math.min(NB - 1, (g * NB) | 0)].push(t);
      }
    }
    if (e / PHI >= 30) {
      var kids = [];
      split(t, kids);
      for (var i = 0; i < kids.length; i++) visit(kids[i]);
    }
  }
  function tri(t) { ctx.moveTo(t[1], t[2]); ctx.lineTo(t[3], t[4]); ctx.lineTo(t[5], t[6]); ctx.closePath(); }
  function legs(t) { ctx.moveTo(t[5], t[6]); ctx.lineTo(t[1], t[2]); ctx.lineTo(t[3], t[4]); }

  function draw() {
    P.fill(ctx, W, H);
    resetBuckets();
    for (var i = 0; i < roots.length; i++) visit(roots[i]);
    var b, j, list;
    /* 太い菱形だけをうっすら明るくして、床の地紋にする */
    for (b = 0; b < NB; b++) {
      list = fillB[b]; if (!list.length) continue;
      ctx.fillStyle = P.rgba(P.steel, (b + 0.5) / NB * 0.16);
      ctx.beginPath();
      for (j = 0; j < list.length; j++) tri(list[j]);
      ctx.fill();
    }
    for (b = 0; b < NB; b++) {
      list = glowB[b]; if (!list.length) continue;
      ctx.fillStyle = P.rgba(P.accent, (b + 0.5) / NB * 0.7);
      ctx.beginPath();
      for (j = 0; j < list.length; j++) tri(list[j]);
      ctx.fill();
    }
    ctx.lineWidth = 1.2;
    ctx.lineJoin = 'round';
    for (b = 0; b < NB; b++) {
      list = lineB[b]; if (!list.length) continue;
      ctx.strokeStyle = P.rgba(P.line, 0.08 + (b + 0.5) / NB * 0.55);
      ctx.beginPath();
      for (j = 0; j < list.length; j++) legs(list[j]);
      ctx.stroke();
    }
    /* 光の輪が通るタイルは、縁取りも白く太くなる */
    ctx.lineWidth = 1.8;
    for (b = 0; b < NB; b++) {
      list = hiB[b]; if (!list.length) continue;
      ctx.strokeStyle = P.rgba(P.line, (b + 0.5) / NB * 0.8);
      ctx.beginPath();
      for (j = 0; j < list.length; j++) legs(list[j]);
      ctx.stroke();
    }
    /* 端を少し沈めて、画面の真ん中に目が行くようにする */
    var vg = ctx.createRadialGradient(CX, CY, Math.min(W, H) * 0.35, CX, CY, RV);
    vg.addColorStop(0, P.rgba(P.deep, 0));
    vg.addColorStop(1, P.rgba(P.deep, 0.55));
    ctx.fillStyle = vg;
    ctx.fillRect(0, 0, W, H);
  }

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; seed(); });

  /* ---------------------------------------------------------- 操作 */
  function pulse(x, y, big) {
    pulses.push({ x: x, y: y, r: big ? 105 : 66, w: big ? 50 : 44, v: big ? 45 : 30, life: 1, decay: big ? 0.55 : 0.35 });
    if (pulses.length > 40) pulses.shift();
  }
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    pulse(last.x, last.y, true);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (Math.hypot(p.x - last.x, p.y - last.y) < 26) return;
    last = p;
    pulse(p.x, p.y, false);
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kd = document.getElementById('k-drift');
  kd.addEventListener('input', function () { drift = this.value / 100; });
  drift = kd.value / 100;
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    /* 黄金比ぶん寄るのに 10〜60 秒。寄りきると、1 段細かい床と入れ替わっている */
    var rate = Math.log(PHI) / (60 - drift * 50);
    transform(Math.exp(dt * rate), dt * (0.002 + drift * 0.008));
    for (var n = pulses.length - 1; n >= 0; n--) {
      var p = pulses[n];
      p.r += p.v * dt;
      p.life -= p.decay * dt;
      if (p.life <= 0) pulses.splice(n, 1);
    }
    settle();
    draw();
    chime += dt;
    if (chime > 9) { chime = 0; SFX.play('tick', 4000); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
