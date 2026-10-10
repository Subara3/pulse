<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rose');

$pageName = 'rose';
$pageTitle = 'ROSE - PULSE';
$pageDesc = '花びらの形の曲線の上を一定の角度ずつ進んだ点を、細い線で順に結んで図形を描く。しばらくすると次の図形に入れ替わる。押すと指のまわりの線が外へ押しのけられ、なぞると指の通った跡に沿って線が曲がる。離すと元に戻る。';
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
  /* 指のまわりでは線が外へ押しのけられる。線は細かく刻んでから曲げる */
  var wells = [], RW = 140;
  function warp(x, y, out) {
    var ox = x, oy = y, wsum = 0;
    for (var i = 0; i < wells.length; i++) {
      var w = wells[i], dx = x - w.x, dy = y - w.y, d = Math.sqrt(dx * dx + dy * dy) + 1e-3;
      var q = d / RW, f = w.s * Math.exp(-q * q * 1.6);
      if (f < 0.002) continue;
      var push = f * RW * 0.9;
      ox += dx / d * push; oy += dy / d * push; wsum += f;
    }
    /* いくつ重なっても、押しのける量は 1 か所ぶんを超えない */
    if (wsum > 1) { ox = x + (ox - x) / wsum; oy = y + (oy - y) / wsum; }
    out[0] = ox; out[1] = oy; out[2] = wsum;
  }
  var wo = [0, 0, 0];
  function lace(xs, ys, a) {
    if (a <= 0.01) return;
    var plain = new Path2D(), lit = new Path2D(), SUB = wells.length ? 8 : 1;
    for (var k = 0; k < 360; k++) {
      var x0 = xs[k], y0 = ys[k], x1 = xs[k + 1], y1 = ys[k + 1];
      warp(x0, y0, wo);
      var px = wo[0], py = wo[1], pw = wo[2];
      for (var s = 1; s <= SUB; s++) {
        var t = s / SUB;
        warp(x0 + (x1 - x0) * t, y0 + (y1 - y0) * t, wo);
        var path = (pw + wo[2]) > 0.5 ? lit : plain;
        path.moveTo(px, py); path.lineTo(wo[0], wo[1]);
        px = wo[0]; py = wo[1]; pw = wo[2];
      }
    }
    ctx.lineJoin = 'round';
    ctx.strokeStyle = P.rgba(P.line, 0.62 * a);
    ctx.lineWidth = 1.15;
    ctx.stroke(plain);
    ctx.strokeStyle = P.rgba(P.accent, 0.8 * a);
    ctx.lineWidth = 1.5;
    ctx.stroke(lit);
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
  var well = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0; last = { x: e.clientX, y: e.clientY };
    well = { x: last.x, y: last.y, s: 0.35, held: true };
    wells.push(well);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - last.x, dy = e.clientY - last.y;
    moved += Math.abs(dx) + Math.abs(dy);
    last = { x: e.clientX, y: e.clientY };
    /* 押しのける場所が指についてくる。通った跡は少しのあいだ曲がったまま残る */
    if (well) {
      if (Math.hypot(last.x - well.x, last.y - well.y) > 40) {
        well.held = false;
        well = { x: last.x, y: last.y, s: 0.7, held: true };
        wells.push(well);
      } else { well.x = last.x; well.y = last.y; }
    }
    glow = 1;
    if (Math.abs(dx) > 1) SFX.play('move', 160);
  });
  function up() {
    if (well) well.held = false;
    well = null; down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

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
    var wob = 0;
    if (!down) nudge *= Math.pow(0.4, dt);
    rot += dt * 0.002;
    glow = Math.max(0, glow - dt * 0.6);
    /* 押しているあいだはすぐ深く押しのけ、離すとゆっくり戻る */
    for (var wi = wells.length - 1; wi >= 0; wi--) {
      var wl = wells[wi];
      if (wl.held) wl.s = Math.min(1, wl.s + dt * 6);
      else { wl.s -= dt * (down ? 1.4 : 0.8); if (wl.s <= 0) wells.splice(wi, 1); }
    }
    if (wells.length > 24) wells.splice(0, wells.length - 24);
    points(n1, d1 + wob + nudge, XS, YS);
    points(n0, d0 + wob + nudge, XP, YP);

    /* 残像: 前の線を薄く残す */
    P.fill(ctx, W, H, 0.12 + (1 - trail) * 0.5);
    petals(n0, 1 - e); petals(n1, e);
    lace(XP, YP, 1 - e); lace(XS, YS, e);

    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
