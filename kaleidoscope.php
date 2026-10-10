<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kaleidoscope');

$pageName = 'kaleidoscope';
$pageTitle = 'KALEIDOSCOPE - PULSE';
$pageDesc = '回る筒の中でガラス片が転がり、合わせ鏡に映った対称な模様が少しずつ変わっていく。ドラッグすると筒の回り方が変わり、タップするとその位置に片が1つ増える。';
$pageCanonical = 'https://pulse.subara3.com/kaleidoscope.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-mirrors">MIRRORS</label><input type="range" id="k-mirrors" min="3" max="12" step="1" value="6"></div>
  <div class="ctl-row"><label for="k-spin">SPIN</label><input type="range" id="k-spin" min="0" max="100" value="35"></div>
  <div class="ctl-btns"><button type="button" id="k-shake">SHAKE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var ctx = canvas.getContext('2d');
  var W = 0, H = 0, RS = 1;

  /* 筒の中（半径 1 の円）でガラス片が重力に引かれて転がる。
     見えるのは、その中身を合わせ鏡で 2M 回写した像 */
  var M = 6, spin = 0.5, gAng = Math.PI / 2, gSpin = 0;
  var pieces = [];
  var TYPES = ['tri', 'shard', 'sliver', 'bead', 'ring', 'tri', 'shard', 'bead'];

  function makePiece(x, y, mine) {
    var type = TYPES[(Math.random() * TYPES.length) | 0];
    var s = 0.08 + Math.random() * 0.12, pts = [];
    if (type === 'tri' || type === 'shard') {
      var n = type === 'tri' ? 3 : 4, a0 = Math.random() * 6.283;
      for (var k = 0; k < n; k++) {
        var a = a0 + k / n * 6.283 + (Math.random() - 0.5) * 0.9;
        var r = s * (0.6 + Math.random() * 0.5);
        pts.push([Math.cos(a) * r, Math.sin(a) * r]);
      }
    } else if (type === 'sliver') {
      var w = s * 0.18;
      pts = [[-s, -w], [s, -w * 0.3], [s * 0.9, w], [-s * 0.8, w * 0.6]];
    }
    if (type === 'bead') s *= 0.45;
    if (type === 'ring') s *= 0.55;
    var tone = Math.random();
    return {
      type: type, pts: pts, s: s, rad: type === 'rod' ? s * 0.5 : s * 0.85,
      x: x, y: y, vx: 0, vy: 0, a: Math.random() * 6.283, w: 0,
      fill: tone < 0.4 ? 0 : tone < 0.75 ? 1 : 2,
      mine: mine ? 1 : 0
    };
  }
  function seed() {
    pieces = [];
    for (var i = 0; i < 15; i++) {
      var a = Math.random() * 6.283, r = Math.sqrt(Math.random()) * 0.8;
      pieces.push(makePiece(Math.cos(a) * r, Math.sin(a) * r, false));
    }
  }

  function step(dt) {
    /* 筒は一定の速さで回り、壁に触れた片を持ち上げる。持ち上がった片は上から崩れて中央を横切る */
    var om = spin + gSpin;
    gSpin *= Math.exp(-dt * 1.2);
    var gx = Math.cos(gAng) * 0.8, gy = Math.sin(gAng) * 0.8;
    var drag = Math.exp(-dt * 1.6), i, j, p, q;
    for (i = 0; i < pieces.length; i++) {
      p = pieces[i];
      p.vx = (p.vx + gx * dt) * drag; p.vy = (p.vy + gy * dt) * drag;
      p.x += p.vx * dt; p.y += p.vy * dt;
      p.a += p.w * dt; p.w *= Math.exp(-dt * 1.2);
      if (p.mine > 0) p.mine = Math.max(0, p.mine - dt / 3);
    }
    /* 片どうし: 重なったら押し離し、こすれた分だけ回す */
    for (var it = 0; it < 2; it++) {
      for (i = 0; i < pieces.length; i++) {
        p = pieces[i];
        for (j = i + 1; j < pieces.length; j++) {
          q = pieces[j];
          var dx = q.x - p.x, dy = q.y - p.y, d = Math.sqrt(dx * dx + dy * dy) + 1e-6, m = p.rad + q.rad;
          if (d >= m) continue;
          var o = (m - d) * 0.5, nx = dx / d, ny = dy / d;
          p.x -= nx * o; p.y -= ny * o; q.x += nx * o; q.y += ny * o;
          var rv = (q.vx - p.vx) * nx + (q.vy - p.vy) * ny;
          if (rv < 0) { p.vx += nx * rv * 0.5; p.vy += ny * rv * 0.5; q.vx -= nx * rv * 0.5; q.vy -= ny * rv * 0.5; }
          var tv = (q.vx - p.vx) * -ny + (q.vy - p.vy) * nx;
          p.w -= tv * 2; q.w += tv * 2;
        }
        /* 筒の壁 */
        var r = Math.sqrt(p.x * p.x + p.y * p.y), lim = 1 - p.rad;
        if (r > lim) {
          var ux = p.x / r, uy = p.y / r;
          p.x = ux * lim; p.y = uy * lim;
          var vn = p.vx * ux + p.vy * uy;
          if (vn > 0) { p.vx -= ux * vn * 1.3; p.vy -= uy * vn * 1.3; }
          var vt = p.vx * -uy + p.vy * ux, wall = om * r, k = Math.min(1, dt * 10);
          p.vx += -uy * (wall - vt) * k; p.vy += ux * (wall - vt) * k;
          p.w += (wall - vt) * 3 * k;
        }
      }
    }
  }

  var LN = P.line, ST = P.steel;
  function tracePiece(p) {
    var S = RS, X = p.x * S, Y = p.y * S, c = Math.cos(p.a), s = Math.sin(p.a);
    if (p.type === 'bead' || p.type === 'ring') {
      ctx.moveTo(X + p.s * S, Y); ctx.arc(X, Y, p.s * S, 0, 6.283);
    } else if (p.type === 'rod') {
      ctx.moveTo(X - c * p.s * S, Y - s * p.s * S); ctx.lineTo(X + c * p.s * S, Y + s * p.s * S);
    } else {
      for (var k = 0; k < p.pts.length; k++) {
        var px = p.pts[k][0], py = p.pts[k][1];
        var qx = X + (px * c - py * s) * S, qy = Y + (px * s + py * c) * S;
        if (k === 0) ctx.moveTo(qx, qy); else ctx.lineTo(qx, qy);
      }
      ctx.closePath();
    }
  }
  var FILLS = [null, P.rgba(LN, 0.07), P.rgba(ST, 0.16)];
  function drawPieces() {
    for (var i = 0; i < pieces.length; i++) {
      var p = pieces[i];
      ctx.beginPath();
      tracePiece(p);
      if (p.fill && p.type !== 'rod' && p.type !== 'ring') { ctx.fillStyle = FILLS[p.fill]; ctx.fill(); }
      var col = p.mine > 0 ? P.mix(LN, P.accent, p.mine) : LN;
      ctx.strokeStyle = P.rgba(col, p.type === 'ring' ? 0.42 : 0.55);
      ctx.lineWidth = p.type === 'rod' ? 1.4 : 1;
      ctx.stroke();
    }
  }

  function draw() {
    P.fill(ctx, W, H);
    var cx = W / 2, cy = H / 2, k;
    /* 鏡の継ぎ目: ごく薄い放射線 */
    ctx.strokeStyle = P.rgba(ST, 0.1);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (k = 0; k < M * 2; k++) {
      var a = k / (M * 2) * Math.PI * 2;
      ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(a) * RS * 1.1, cy + Math.sin(a) * RS * 1.1);
    }
    ctx.stroke();
    ctx.lineJoin = 'round';
    for (k = 0; k < M; k++) {
      for (var m = 0; m < 2; m++) {
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(k / M * Math.PI * 2);
        if (m) ctx.scale(1, -1);
        drawPieces();
        ctx.restore();
      }
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, moved = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0; last = { x: e.clientX, y: e.clientY };
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var cx = W / 2, cy = H / 2;
    /* 中心のまわりを回すようにドラッグした分だけ、筒が回る */
    var a0 = Math.atan2(last.y - cy, last.x - cx), a1 = Math.atan2(e.clientY - cy, e.clientX - cx);
    var da = Math.atan2(Math.sin(a1 - a0), Math.cos(a1 - a0));
    var lin = (e.clientX - last.x) / Math.max(W, H) * 4;
    gSpin = Math.max(-2, Math.min(2, gSpin + (da + lin) * 2.5));
    moved += Math.hypot(e.clientX - last.x, e.clientY - last.y);
    last = { x: e.clientX, y: e.clientY };
    SFX.play('move', 220);
  });
  function up(e) {
    if (!down) return;
    down = false;
    if (moved < 10 && e.type === 'pointerup') {
      /* 触れた場所（筒の中の位置）に新しい片を置く */
      var x = (last.x - W / 2) / RS, y = (last.y - H / 2) / RS, r = Math.hypot(x, y);
      if (r > 0.85) { x *= 0.85 / r; y *= 0.85 / r; }
      pieces.push(makePiece(x, y, true));
      while (pieces.length > 24) {
        var idx = 0;
        for (var i = 0; i < pieces.length; i++) if (!pieces[i].mine) { idx = i; break; }
        pieces.splice(idx, 1);
      }
      SFX.play('spawn');
    } else {
      SFX.play('collide', 300);
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function spinFrom(v) { return 0.25 + v / 100 * 0.9; }
  document.getElementById('k-mirrors').addEventListener('input', function () { M = Math.max(3, Math.min(12, +this.value | 0)); SFX.play('tick', 120); });
  document.getElementById('k-spin').addEventListener('input', function () { spin = spinFrom(+this.value); });
  document.getElementById('k-shake').addEventListener('click', function () {
    for (var i = 0; i < pieces.length; i++) { var a = Math.random() * 6.283; pieces[i].vx += Math.cos(a) * 0.9; pieces[i].vy += Math.sin(a) * 0.9; pieces[i].w += (Math.random() - 0.5) * 6; }
    SFX.play('click');
  });
  M = +document.getElementById('k-mirrors').value | 0;
  spin = spinFrom(+document.getElementById('k-spin').value);

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; RS = Math.hypot(w, h) * 0.5 * 0.8; });
  seed();
  for (var w0 = 0; w0 < 90; w0++) step(1 / 30);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), tumbleWait = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    step(dt);
    /* 片が底に積もって動かなくなりかけたら、ときどき小さく転がす */
    tumbleWait -= dt;
    if (tumbleWait <= 0) {
      var p = pieces[(Math.random() * pieces.length) | 0];
      p.w += (Math.random() - 0.5) * 4; p.vx += (Math.random() - 0.5) * 0.3; p.vy += (Math.random() - 0.5) * 0.3;
      tumbleWait = 0.6 + Math.random();
    }
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
