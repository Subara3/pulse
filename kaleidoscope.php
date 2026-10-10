<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('kaleidoscope');

$pageName = 'kaleidoscope';
$pageTitle = 'KALEIDOSCOPE - PULSE';
$pageDesc = '回る筒の中でガラス片が転がり、合わせ鏡に映った対称な模様が少しずつ変わっていく。押したところに大きな片が入ってまわりの片を押しのけ、なぞると片が指についてきて、跡に小さな片が落ちる。';
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
  var M = 6, spin = 0.5, gAng = Math.PI / 2, gSpin = 0, tubeA = 0, simT = 0;
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
    var pc = {
      type: type, pts: pts, s: s, rad: type === 'rod' ? s * 0.5 : s * 0.85,
      x: x, y: y, vx: 0, vy: 0, a: Math.random() * 6.283, w: 0,
      fill: tone < 0.4 ? 0 : tone < 0.75 ? 1 : 2,
      mine: mine ? 1 : 0,
      hr: Math.sqrt(Math.random()) * 0.75, ha: Math.random() * 6.283, hp: Math.random() * 6.283, hs: 0.6 + Math.random() * 0.8
    };
    /* 置いた場所をそのまま居場所の出発点にする */
    pc.hp = -simT * 0.11 * pc.hs + (Math.random() < 0.5 ? 0 : Math.PI);
    pc.hr = Math.min(0.85, Math.hypot(x, y));
    pc.ha = Math.atan2(y, x) - tubeA * pc.hs - 0.5 * Math.sin(simT * 0.07 * pc.hs + pc.hp * 1.7);
    return pc;
  }
  function seed() {
    pieces = [];
    for (var i = 0; i < 19; i++) {
      var a = Math.random() * 6.283, r = Math.sqrt(Math.random()) * 0.8;
      pieces.push(makePiece(Math.cos(a) * r, Math.sin(a) * r, false));
    }
  }

  function step(dt) {
    /* 片はそれぞれ筒の中に「居場所」を持ち、居場所は中央から縁までゆっくり行き来する。
       筒が回ると居場所も回るので、片は中央を横切りながら回り続け、どこかに積もりきらない */
    var om = spin + gSpin;
    gSpin *= Math.exp(-dt * 1.2);
    tubeA += om * dt * 0.5; simT += dt;
    var drag = Math.exp(-dt * 1.3), i, j, p, q;
    for (i = 0; i < pieces.length; i++) {
      p = pieces[i];
      var hr = Math.max(0.04, Math.min(0.88, p.hr + 0.3 * Math.sin(simT * 0.11 * p.hs + p.hp)));
      var ha = p.ha + tubeA * p.hs + 0.5 * Math.sin(simT * 0.07 * p.hs + p.hp * 1.7);
      var tx = Math.cos(ha) * hr, ty = Math.sin(ha) * hr;
      p.vx = (p.vx + (tx - p.x) * 1.1 * dt) * drag; p.vy = (p.vy + (ty - p.y) * 1.1 * dt) * drag;
      p.w += ((p.hs - 1) * 1.2 - p.w) * dt * 0.5;
      p.x += p.vx * dt; p.y += p.vy * dt;
      p.a += p.w * dt; p.w *= Math.exp(-dt * 1.2);
      if (p.mine > 0) p.mine = Math.max(0, p.mine - dt / 1.6);
      if (p.hl > 0) p.hl = Math.max(0, p.hl - dt * 0.8);
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
      var hl = p.hl || 0;
      if (p.type !== 'rod' && p.type !== 'ring' && (p.fill || hl > 0.05)) {
        ctx.fillStyle = hl > 0.05 ? P.rgba(LN, 0.07 + hl * 0.28) : FILLS[p.fill]; ctx.fill();
      }
      var col = p.mine > 0 ? P.mix(LN, P.accent, p.mine * 0.8) : LN;
      ctx.strokeStyle = P.rgba(col, (p.type === 'ring' ? 0.5 : 0.62) + hl * 0.38);
      ctx.lineWidth = (p.type === 'rod' ? 1.4 : 1.1) + hl * 1.2;
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
  var down = false, last = null, moved = 0, lastT0 = 0, dropAcc = 0;
  function trim(max) {
    while (pieces.length > max) {
      var idx = 0;
      for (var i = 0; i < pieces.length; i++) if (!pieces[i].mine) { idx = i; break; }
      pieces.splice(idx, 1);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; moved = 0; last = { x: e.clientX, y: e.clientY }; lastT0 = performance.now();
    /* 触れた場所（筒の中の位置）に大きめの片を置き、まわりの片を押しのけて光らせる */
    var x = (e.clientX - W / 2) / RS, y = (e.clientY - H / 2) / RS, r = Math.hypot(x, y);
    if (r > 0.85) { x *= 0.85 / r; y *= 0.85 / r; }
    var np = makePiece(x, y, true);
    np.s *= 1.5; np.rad *= 1.5; np.hl = 1;
    for (var k = 0; k < np.pts.length; k++) { np.pts[k][0] *= 1.5; np.pts[k][1] *= 1.5; }
    pieces.push(np);
    trim(30);
    var i;
    for (i = 0; i < pieces.length; i++) {
      var q = pieces[i], dx = q.x - x, dy = q.y - y, d = Math.hypot(dx, dy) + 1e-3;
      if (q === np || d > 0.4) continue;
      q.vx += dx / d * 0.8 * (1 - d / 0.4); q.vy += dy / d * 0.8 * (1 - d / 0.4); q.w += (Math.random() - 0.5) * 5;
      q.hl = Math.max(q.hl || 0, 1 - d / 0.4);
    }
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var cx = W / 2, cy = H / 2;
    /* 中心のまわりを回すようにドラッグした分だけ、筒が回る */
    var a0 = Math.atan2(last.y - cy, last.x - cx), a1 = Math.atan2(e.clientY - cy, e.clientX - cx);
    var da = Math.atan2(Math.sin(a1 - a0), Math.cos(a1 - a0));
    var lin = (e.clientX - last.x) / Math.max(W, H) * 4;
    gSpin = Math.max(-2, Math.min(2, gSpin + (da + lin) * 0.8));
    /* 指のそばの片は、指の動きにそのままついてくる */
    var now = performance.now(), mdt = Math.max(0.008, (now - lastT0) / 1000); lastT0 = now;
    var px0 = (e.clientX - W / 2) / RS, py0 = (e.clientY - H / 2) / RS;
    var mvx = (e.clientX - last.x) / RS / mdt, mvy = (e.clientY - last.y) / RS / mdt;
    for (var i = 0; i < pieces.length; i++) {
      var q = pieces[i], d = Math.hypot(q.x - px0, q.y - py0);
      if (d > 0.22) continue;
      var k = 1 - d / 0.22;
      q.x += (e.clientX - last.x) / RS * k; q.y += (e.clientY - last.y) / RS * k;
      q.vx = q.vx * (1 - k) + mvx * 0.5 * k; q.vy = q.vy * (1 - k) + mvy * 0.5 * k;
      q.w += (Math.random() - 0.5) * 2 * k; q.hl = Math.max(q.hl || 0, k);
    }
    /* なぞった跡に小さな片を落としていく */
    dropAcc += Math.hypot(e.clientX - last.x, e.clientY - last.y);
    if (dropAcc > 30 && Math.hypot(px0, py0) < 0.9) {
      dropAcc = 0;
      var sp = makePiece(px0, py0, false);
      sp.vx = mvx * 0.15; sp.vy = mvy * 0.15; sp.hl = 1;
      pieces.push(sp);
      trim(34);
    }
    moved += Math.hypot(e.clientX - last.x, e.clientY - last.y);
    last = { x: e.clientX, y: e.clientY };
    SFX.play('move', 220);
  });
  function up(e) {
    if (!down) return;
    down = false;
    if (moved >= 10) SFX.play('collide', 300);
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function spinFrom(v) { return 0.15 + v / 100 * 0.7; }
  document.getElementById('k-mirrors').addEventListener('input', function () { M = Math.max(3, Math.min(12, +this.value | 0)); SFX.play('tick', 120); });
  document.getElementById('k-spin').addEventListener('input', function () { spin = spinFrom(+this.value); });
  document.getElementById('k-shake').addEventListener('click', function () {
    for (var i = 0; i < pieces.length; i++) { var a = Math.random() * 6.283; pieces[i].vx += Math.cos(a) * 0.9; pieces[i].vy += Math.sin(a) * 0.9; pieces[i].w += (Math.random() - 0.5) * 6; }
    SFX.play('click');
  });
  M = +document.getElementById('k-mirrors').value | 0;
  spin = spinFrom(+document.getElementById('k-spin').value);

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; RS = Math.min(w, h) * 0.58; /* 縦横比によらず、短い辺の円を中心に模様が埋まる */ });
  seed();
  for (var w0 = 0; w0 < 90; w0++) step(1 / 30);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), tumbleWait = 0, trimWait = 2.5;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    step(dt);
    /* 足した片は時間をかけて減らし、もとの数（約 16）に戻す */
    trimWait -= dt;
    if (trimWait <= 0) { trimWait = 2.5; if (pieces.length > 19 && !down) pieces.shift(); }
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
