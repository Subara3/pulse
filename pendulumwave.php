<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pendulumwave');

$pageName = 'pendulumwave';
$pageTitle = 'PENDULUMWAVE - PULSE';
$pageDesc = '長さが少しずつ違う振り子が一列に並んで揺れている。揃っていた動きがずれて波の形になり、一定時間ごとにまた揃う。横になぞると見る角度が変わり、軽く触ると全部を同じ位置から放し直す。';
$pageCanonical = 'https://pulse.subara3.com/pendulumwave.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-swing">SWING</label><input type="range" id="k-swing" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-release">RELEASE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 振り子の波。i 番目の振り子は、決めた時間 CYCLE のあいだにちょうど BASE + i 回往復する長さにしてある。
     だから CYCLE ごとに全部がそろい、そのあいだで位相のずれが波や交差の模様を作る */
  var COUNT = 18, BASE = 50, CYCLE = 60;
  var pend = [];
  for (var i = 0; i < COUNT; i++) {
    var n = BASE + i, T = CYCLE / n;
    pend.push({ n: n, x: -1 + 2 * i / (COUNT - 1), L: 0, trail: [] });
  }
  /* 長さは周期の 2 乗に比例。いちばん長いものを 1 にそろえる */
  var Tmax = CYCLE / BASE;
  pend.forEach(function (p) { var T = CYCLE / p.n; p.L = 0.42 + 0.58 * (T * T) / (Tmax * Tmax); });

  var pace = 0.45, swing = 0.5, t = 0;
  var yaw = -0.35, pitch = 0.38, yawT = -0.35, pitchT = 0.38, autoYaw = true;
  var gather = 0, gathering = false;

  /* ---------------------------------------------------------- 投影 */
  var f = 1, cx = 0, cy = 0, D = 4.2;
  function project(x, y, z) {
    var c = Math.cos(yaw), s = Math.sin(yaw);
    var x1 = c * x + s * z, z1 = -s * x + c * z;
    var cp = Math.cos(pitch), sp = Math.sin(pitch);
    var y1 = y + 0.5;
    var y2 = cp * y1 - sp * z1, z2 = sp * y1 + cp * z1;
    var k = f / (D - z2);
    return [cx + x1 * k, cy - y2 * k, k];
  }

  function angle(p) {
    var amp = 0.18 + swing * 0.32;
    var a = amp * Math.cos(2 * Math.PI * p.n * t / CYCLE);
    return a + (amp - a) * gather;
  }

  /* ---------------------------------------------------------- 描く */
  var FLOOR = -1.18;
  function draw(dt) {
    P.fill(ctx, W, H);
    ctx.lineCap = 'round';

    /* 床の方眼（遠近つき）。振り子の真下の影もここに落ちる */
    ctx.strokeStyle = P.rgba(P.steel, 0.16);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var gx = -1.4; gx <= 1.401; gx += 0.2) { var a = project(gx, FLOOR, -1), b = project(gx, FLOOR, 1); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); }
    for (var gz = -1; gz <= 1.001; gz += 0.2) { var c = project(-1.4, FLOOR, gz), d = project(1.4, FLOOR, gz); ctx.moveTo(c[0], c[1]); ctx.lineTo(d[0], d[1]); }
    ctx.stroke();

    /* 梁と 2 本の脚 */
    var b0 = project(-1.15, 0, 0), b1 = project(1.15, 0, 0);
    var f0 = project(-1.15, FLOOR, 0), f1 = project(1.15, FLOOR, 0);
    ctx.strokeStyle = P.rgba(P.line, 0.85); ctx.lineWidth = 1.4;
    ctx.beginPath(); ctx.moveTo(b0[0], b0[1]); ctx.lineTo(b1[0], b1[1]); ctx.stroke();
    ctx.strokeStyle = P.rgba(P.line, 0.3); ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(b0[0], b0[1]); ctx.lineTo(f0[0], f0[1]); ctx.moveTo(b1[0], b1[1]); ctx.lineTo(f1[0], f1[1]); ctx.stroke();

    var bobs = [], shadows = [];
    for (var i = 0; i < COUNT; i++) {
      var p = pend[i], th = angle(p);
      var by = -p.L * Math.cos(th), bz = p.L * Math.sin(th);
      var top = project(p.x, 0, 0), bob = project(p.x, by, bz), sh = project(p.x, FLOOR, bz);
      p.trail.push(bob[0], bob[1]);
      if (p.trail.length > 44) p.trail.splice(0, 2);
      bobs.push({ top: top, bob: bob, z: bob[2], p: p });
      shadows.push(sh);
    }

    /* 影: 床に落ちた点を細い線でつなぐ。上から見た波の形がここに出る */
    ctx.strokeStyle = P.rgba(P.steel, 0.5); ctx.lineWidth = 1;
    ctx.beginPath();
    shadows.forEach(function (s, k) { if (k) ctx.lineTo(s[0], s[1]); else ctx.moveTo(s[0], s[1]); });
    ctx.stroke();
    ctx.fillStyle = P.rgba(P.steel, 0.6);
    ctx.beginPath();
    shadows.forEach(function (s) { ctx.moveTo(s[0] + 1.8, s[1]); ctx.arc(s[0], s[1], 1.8, 0, Math.PI * 2); });
    ctx.fill();

    /* 奥から順に描く */
    bobs.sort(function (u, v) { return u.z - v.z; });
    bobs.forEach(function (o) {
      var tr = o.p.trail;
      for (var k = 2; k < tr.length; k += 2) {
        ctx.strokeStyle = P.rgba(P.steel, 0.5 * k / tr.length);
        ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(tr[k - 2], tr[k - 1]); ctx.lineTo(tr[k], tr[k + 1]); ctx.stroke();
      }
      ctx.strokeStyle = P.rgba(P.line, 0.42); ctx.lineWidth = 0.8;
      ctx.beginPath(); ctx.moveTo(o.top[0], o.top[1]); ctx.lineTo(o.bob[0], o.bob[1]); ctx.stroke();
      var r = Math.max(3, 0.022 * o.bob[2]);
      ctx.fillStyle = P.rgba(P.deep, 1);
      ctx.strokeStyle = P.rgba(P.line, 0.95); ctx.lineWidth = 1.2;
      ctx.beginPath(); ctx.arc(o.bob[0], o.bob[1], r, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.9);
      ctx.beginPath(); ctx.arc(o.bob[0], o.bob[1], r * 0.38, 0, Math.PI * 2); ctx.fill();
    });

    /* 放し直すときは、シアンの細い棒が手前から全部の玉を押さえてそろえる */
    if (gather > 0.01) {
      var amp = 0.18 + swing * 0.32, pts = [];
      for (var q = 0; q < COUNT; q++) {
        var pp = pend[q];
        pts.push(project(pp.x, -pp.L * Math.cos(amp), pp.L * Math.sin(amp) + 0.06));
      }
      ctx.strokeStyle = P.rgba(P.accent, 0.8 * gather); ctx.lineWidth = 1.2;
      ctx.beginPath();
      pts.forEach(function (s, k) { if (k) ctx.lineTo(s[0], s[1]); else ctx.moveTo(s[0], s[1]); });
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, sx = 0, sy = 0, lx = 0, ly = 0, moved = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; sx = lx = e.clientX; sy = ly = e.clientY; moved = 0;
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var dx = e.clientX - lx, dy = e.clientY - ly;
    lx = e.clientX; ly = e.clientY;
    moved += Math.abs(dx) + Math.abs(dy);
    if (moved < 6) return;
    autoYaw = false;
    yawT = Math.max(-1.2, Math.min(1.2, yawT + dx * 0.0025));
    pitchT = Math.max(0.1, Math.min(0.95, pitchT + dy * 0.003));
    SFX.play('move', 220);
  });
  function up() {
    if (down && moved < 6) release();
    down = false;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', function () { down = false; });

  function release() {
    if (gathering) return;
    gathering = true;
    SFX.play('grow');
  }

  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.getElementById('k-swing').addEventListener('input', function () { swing = this.value / 100; });
  document.getElementById('k-release').addEventListener('click', release);
  pace = document.getElementById('k-pace').value / 100;
  swing = document.getElementById('k-swing').value / 100;

  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    f = Math.min(w * 1.2, h * 1.9);
    cx = w / 2; cy = h * 0.5;
    pend.forEach(function (p) { p.trail.length = 0; });
  });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), idle = 0, lastCycle = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    if (gathering) {
      gather = Math.min(1, gather + dt / 1.1);
      if (gather >= 1) { t = 0; gather = 0; gathering = false; lastCycle = 0; pend.forEach(function (p) { p.trail.length = 0; }); }
    } else {
      t += dt * (0.25 + pace * 0.75);
    }
    /* 列がそろうたびに、静かに 1 音 */
    var cyc = Math.floor(t / CYCLE * 2);
    if (cyc !== lastCycle) { lastCycle = cyc; SFX.play('ripple', 2000); }
    /* 触っていないあいだは、見る向きがゆっくり行き来する */
    if (!down) idle += dt;
    if (idle > 8) autoYaw = true;
    if (autoYaw) yawT += (-0.35 + Math.sin(now / 1000 * 0.05) * 0.4 - yawT) * Math.min(1, dt * 0.5);
    if (down) idle = 0;
    var e = 1 - Math.exp(-dt * 3);
    yaw += (yawT - yaw) * e; pitch += (pitchT - pitch) * e;
    draw(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
