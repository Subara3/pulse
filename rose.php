<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rose');

$pageName = 'rose';
$pageTitle = 'ROSE - PULSE';
$pageDesc = '花びらの形の曲線の上を一定の角度ずつ進んだ点を、細い線で順に結んで図形を描く。しばらくすると次の図形に入れ替わる。押すとすぐ次の図形に移り、横になぞると角度が少しずれて線の並びが変わる。';
$pageCanonical = 'https://pulse.subara3.com/rose.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, cx = 0, cy = 0, R = 1;

  /* r = sin(nθ) の上で、角度 d 度ずつ跳んだ 361 個の点を順に結ぶ */
  var PAIRS = [[2, 39], [6, 71], [3, 47], [7, 19], [4, 31], [5, 97], [2, 29], [6, 59], [8, 13], [5, 121], [3, 83], [4, 67]];
  var pi = 0, n0 = 2, d0 = 39, n1 = 2, d1 = 39, tr = 1, hold = 0;
  var pace = 0.4, trail = 0.55, nudge = 0, rot = 0, pen = 0, T = 0;
  var XS = new Float32Array(361), YS = new Float32Array(361), XP = new Float32Array(361), YP = new Float32Array(361);

  function resize(w, h) {
    W = w; H = h; cx = W / 2; cy = H / 2; R = Math.min(W, H) * 0.43;
    P.fill(ctx0(), W, H);
  }
  var _ctx = null;
  function ctx0() { return _ctx || canvas.getContext('2d'); }

  function next() {
    pi = (pi + 1) % PAIRS.length;
    n0 = n1; d0 = d1; n1 = PAIRS[pi][0]; d1 = PAIRS[pi][1]; tr = 0; hold = 0;
  }

  function points(nn, dg, xs, ys) {
    var rad = Math.PI / 180, dd = dg * rad;
    for (var k = 0; k <= 360; k++) {
      var th = k * dd, r = Math.sin(nn * th) * R;
      xs[k] = cx + Math.cos(th + rot) * r;
      ys[k] = cy + Math.sin(th + rot) * r;
    }
  }
  function lace(xs, ys, a) {
    if (a <= 0.01) return;
    ctx.strokeStyle = P.rgba(P.line, 0.36 * a);
    ctx.lineWidth = 0.8;
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(xs[0], ys[0]);
    for (var k = 1; k <= 360; k++) ctx.lineTo(xs[k], ys[k]);
    ctx.stroke();
  }
  function petals(nn, a) {
    if (a <= 0.01) return;
    ctx.strokeStyle = P.rgba(P.steel, 0.24 * a);
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    var M = 720;
    for (var k = 0; k <= M; k++) {
      var th = k / M * Math.PI * 2, r = Math.sin(nn * th) * R;
      var x = cx + Math.cos(th + rot) * r, y = cy + Math.sin(th + rot) * r;
      if (k === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
    ctx.stroke();
  }

  var ctx = P.fit(canvas, function (w, h) { _ctx = canvas.getContext('2d'); resize(w, h); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, moved = 0, glow = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0; last = { x: e.clientX, y: e.clientY };
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - last.x, dy = e.clientY - last.y;
    moved += Math.abs(dx) + Math.abs(dy);
    last = { x: e.clientX, y: e.clientY };
    /* 横になぞると跳ぶ角度がずれる。離すとゆっくり戻る */
    nudge = Math.max(-0.9, Math.min(0.9, nudge + dx * 0.003));
    glow = 1;
    if (Math.abs(dx) > 1) SFX.play('move', 160);
  });
  function up() {
    if (down && moved < 6) { next(); glow = 1; SFX.play('spawn'); }
    down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; });

  var kPace = document.getElementById('k-pace'), kTrail = document.getElementById('k-trail');
  kPace.addEventListener('input', function () { pace = this.value / 100; });
  kTrail.addEventListener('input', function () { trail = this.value / 100; });
  document.getElementById('k-next').addEventListener('click', function () { next(); SFX.play('click'); });
  pace = kPace.value / 100;
  trail = kTrail.value / 100;

  /* ---------------------------------------------------------- 回す */
  function ease(t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    /* 形と形のあいだは、前の形を薄めながら次の形を濃くして入れ替える */
    if (tr < 1) tr = Math.min(1, tr + dt / (9 - pace * 6));
    else {
      hold += dt;
      if (hold > 24 - pace * 16) next();
    }
    var e = ease(tr);
    /* 止まって見えないよう、角度はごくわずかに揺れ続ける */
    var wob = Math.sin(T * 0.09) * 0.05;
    if (!down) nudge *= Math.pow(0.4, dt);
    rot += dt * 0.012;
    glow = Math.max(0, glow - dt * 0.6);
    points(n1, d1 + wob + nudge, XS, YS);
    points(n0, d0 + wob + nudge, XP, YP);

    /* 残像: 前の線を薄く残す */
    P.fill(ctx, W, H, 0.12 + (1 - trail) * 0.5);
    petals(n0, 1 - e); petals(n1, e);
    lace(XP, YP, 1 - e); lace(XS, YS, e);

    /* 線をたどる 1 点だけを、シアンで灯す */
    pen = (pen + dt * (14 + pace * 18)) % 360;
    var pk = Math.floor(pen), seg = 14;
    ctx.lineWidth = 1.4;
    for (var s = 0; s < seg; s++) {
      var a = pk - s, b = a + 1;
      if (a < 0) break;
      ctx.strokeStyle = P.rgba(P.accent, (1 - s / seg) * (0.55 + glow * 0.4));
      ctx.beginPath();
      ctx.moveTo(XS[a], YS[a]);
      var f = s === 0 ? pen - pk : 1;
      ctx.lineTo(XS[a] + (XS[b] - XS[a]) * f, YS[a] + (YS[b] - YS[a]) * f);
      ctx.stroke();
    }
    var fp = pen - pk, hx = XS[pk] + (XS[pk + 1] - XS[pk]) * fp, hy = YS[pk] + (YS[pk + 1] - YS[pk]) * fp;
    ctx.fillStyle = P.rgba(P.accent, 0.95);
    ctx.beginPath();
    ctx.arc(hx, hy, 2.2, 0, Math.PI * 2);
    ctx.fill();
    if (pk === 0 && fp < dt * 30) SFX.play('tick', 4000);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
