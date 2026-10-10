<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('string');

$pageName = 'string';
$pageTitle = 'STRING ART - PULSE';
$pageDesc = '円に並べた釘のあいだに糸を張っていて、糸が重なって濃くなったところに曲線が見える。糸の掛け方が少しずつ変わり、曲線の形も変わっていく。指でなぞると、横切った糸がはじかれて震える。';
$pageCanonical = 'https://pulse.subara3.com/string.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-nails">NAILS</label><input type="range" id="k-nails" min="0" max="100" value="55"></div>
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" id="k-hold">HOLD</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, cx = 0, cy = 0, R = 1;

  /* 釘 i から釘 i×k へ糸を張る。k が 2 ならハート形、3 なら 2 枚の花びら */
  var NMAX = 400, nails = 200, k = 2, kdir = 1, pace = 0.4, hold = false, rot = -Math.PI / 2, T = 0;
  var amp = new Float32Array(NMAX), ph = new Float32Array(NMAX), hot = new Float32Array(NMAX);
  var AX = new Float32Array(NMAX), AY = new Float32Array(NMAX), BX = new Float32Array(NMAX), BY = new Float32Array(NMAX);

  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; cx = W / 2; cy = H / 2; R = Math.min(W, H) * 0.42; });

  function layout() {
    var tau = Math.PI * 2;
    for (var i = 0; i < nails; i++) {
      var a = i / nails * tau + rot, b = i * k / nails * tau + rot;
      AX[i] = cx + Math.cos(a) * R; AY[i] = cy + Math.sin(a) * R;
      BX[i] = cx + Math.cos(b) * R; BY[i] = cy + Math.sin(b) * R;
    }
  }

  /* ---------------------------------------------------------- 操作 */
  /* なぞった線と交わった糸だけをはじく */
  function cross(x1, y1, x2, y2, i) {
    var ax = AX[i], ay = AY[i], bx = BX[i], by = BY[i];
    var d = (x2 - x1) * (by - ay) - (y2 - y1) * (bx - ax);
    if (Math.abs(d) < 1e-6) return false;
    var t = ((ax - x1) * (by - ay) - (ay - y1) * (bx - ax)) / d;
    var u = ((ax - x1) * (y2 - y1) - (ay - y1) * (x2 - x1)) / d;
    return t >= 0 && t <= 1 && u > 0.04 && u < 0.96;
  }
  function pluck(i, s) {
    amp[i] = Math.min(16, Math.max(amp[i], s));
    ph[i] = 0; hot[i] = 1;
  }
  var down = false, last = null, moved = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0;
    last = { x: e.clientX, y: e.clientY };
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down && e.pointerType !== 'mouse') return;
    var p = { x: e.clientX, y: e.clientY };
    if (!last) { last = p; return; }
    var sp = Math.hypot(p.x - last.x, p.y - last.y);
    if (sp < 2) return;
    moved += sp;
    var hits = 0;
    for (var i = 0; i < nails; i++) {
      if (cross(last.x, last.y, p.x, p.y, i)) { pluck(i, Math.min(16, 3 + sp * (down ? 0.5 : 0.2))); hits++; }
    }
    if (hits) SFX.play('tick', 120);
    last = p;
  });
  function up(e) {
    /* その場で離したら、近くを通る糸をまとめて軽くはじく */
    if (down && moved < 6 && last) {
      var n = 0;
      for (var i = 0; i < nails; i++) {
        var ax = AX[i], ay = AY[i], bx = BX[i] - ax, by = BY[i] - ay, l2 = bx * bx + by * by + 1e-6;
        var t = ((last.x - ax) * bx + (last.y - ay) * by) / l2;
        t = t < 0 ? 0 : t > 1 ? 1 : t;
        var dx = ax + bx * t - last.x, dy = ay + by * t - last.y, dd = Math.sqrt(dx * dx + dy * dy);
        if (dd < 46) { pluck(i, 9 * (1 - dd / 46) + 2); n++; }
      }
      SFX.play(n ? 'collide' : 'tick');
    }
    down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; });
  canvas.addEventListener('pointerleave', function () { if (!down) last = null; });

  var kNails = document.getElementById('k-nails'), kPace = document.getElementById('k-pace'), kHold = document.getElementById('k-hold');
  function setNails(v) { nails = Math.round(80 + Math.pow(v / 100, 1.2) * 280); }
  kNails.addEventListener('input', function () { setNails(+this.value); });
  kPace.addEventListener('input', function () { pace = this.value / 100; });
  kHold.addEventListener('click', function () { hold = !hold; kHold.classList.toggle('active', hold); SFX.play('toggle'); });
  setNails(+kNails.value);
  pace = kPace.value / 100;
  hold = kHold.classList.contains('active');

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    hold = kHold.classList.contains('active');
    if (!hold) {
      /* 整数の近くではゆっくり、あいだは少し速く。形がはっきりする所に長く留まる */
      var s = Math.sin(Math.PI * k);
      k += kdir * dt * (0.004 + pace * 0.03) * (0.25 + 3 * s * s);
      if (k > 30) kdir = -1; else if (k < 2) kdir = 1;
    }
    rot += dt * 0.006;
    layout();

    P.fill(ctx, W, H);

    /* 震えていない糸はまとめて 1 本の道で描く */
    var still = new Path2D(), live = [];
    for (var i = 0; i < nails; i++) {
      if (amp[i] > 0.15) { live.push(i); continue; }
      amp[i] = 0;
      still.moveTo(AX[i], AY[i]); still.lineTo(BX[i], BY[i]);
    }
    var dens = Math.min(1, 200 / nails);
    ctx.lineWidth = 0.7;
    ctx.strokeStyle = P.rgba(P.line, 0.12 + dens * 0.12);
    ctx.stroke(still);

    /* はじいた糸: 中ほどがふくらんで震え、だんだん収まる */
    for (var q = 0; q < live.length; q++) {
      i = live[q];
      ph[i] += dt;
      amp[i] *= Math.pow(0.28, dt);
      hot[i] = Math.max(0, hot[i] - dt * 1.1);
      var ax = AX[i], ay = AY[i], bx = BX[i], by = BY[i];
      var dx = bx - ax, dy = by - ay, l = Math.sqrt(dx * dx + dy * dy) + 1e-6;
      var off = amp[i] * Math.sin(ph[i] * 11) * 2;
      var mx = (ax + bx) / 2 - dy / l * off, my = (ay + by) / 2 + dx / l * off;
      ctx.strokeStyle = P.rgba(P.mix(P.line, P.accent, hot[i] * 0.8), 0.22 + hot[i] * 0.4);
      ctx.lineWidth = 0.8 + hot[i] * 0.5;
      ctx.beginPath();
      ctx.moveTo(ax, ay);
      ctx.quadraticCurveTo(mx, my, bx, by);
      ctx.stroke();
    }

    /* 釘 */
    ctx.fillStyle = P.rgba(P.line, 0.7);
    ctx.beginPath();
    var tau = Math.PI * 2, nr = nails > 240 ? 0.9 : 1.2;
    for (i = 0; i < nails; i++) {
      var a = i / nails * tau + rot, x = cx + Math.cos(a) * (R + 3), y = cy + Math.sin(a) * (R + 3);
      ctx.moveTo(x + nr, y);
      ctx.arc(x, y, nr, 0, tau);
    }
    ctx.fill();
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(cx, cy, R + 9, 0, tau);
    ctx.stroke();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
