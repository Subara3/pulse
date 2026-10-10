<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('pendulumwave');

$pageName = 'pendulumwave';
$pageTitle = 'PENDULUMWAVE - PULSE';
$pageDesc = '長さが少しずつ違う振り子が一列に並んで揺れている。揃っていた動きがずれて波の形になり、一定時間ごとにまた揃う。押すと近くの振り子が指のほうへ引き寄せられ、離すと大きく揺れ出す。なぞると通り道の振り子が順にはじかれる。';
$pageCanonical = 'https://pulse.subara3.com/pendulumwave.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-swing">SWING</label><input type="range" id="k-swing" min="0" max="100" value="35"></div>
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
    pend.push({ n: n, x: -1 + 2 * i / (COUNT - 1), L: 0, trail: [], kicks: [], sx: 0, sy: 0, lastKick: 9, glow: 0, hold: 0, want: 0, held: 0 });
  }
  /* 長さは周期の 2 乗に比例。いちばん長いものを 1 にそろえる */
  var Tmax = CYCLE / BASE;
  pend.forEach(function (p) { var T = CYCLE / p.n; p.L = 0.42 + 0.58 * (T * T) / (Tmax * Tmax); });

  var pace = 0.45, swing = 0.35, t = 0, speed = 0.6;
  var ys = 1, yawBase = -0.35, yawAmp = 0.4;
  var yaw = -0.35, pitch = 0.38, yawT = -0.35, pitchT = 0.38, autoYaw = true;
  var gather = 0, gathering = false;

  /* ---------------------------------------------------------- 投影 */
  var f = 1, cx = 0, cy = 0, D = 4.2;
  function project(x, y, z) {
    var c = Math.cos(yaw), s = Math.sin(yaw);
    var x1 = c * x + s * z, z1 = -s * x + c * z;
    var cp = Math.cos(pitch), sp = Math.sin(pitch);
    var y1 = (y + 0.55) * ys;
    var y2 = cp * y1 - sp * z1, z2 = sp * y1 + cp * z1;
    var k = f / (D - z2);
    return [cx + x1 * k, cy - y2 * k, k];
  }

  function angle(p) {
    var amp = 0.18 + swing * 0.32;
    var a = amp * Math.cos(2 * Math.PI * p.n * t / CYCLE);
    a += (amp - a) * gather;
    /* 指で押された分の揺れ。その振り子の周期で揺れながら、数秒で元の列の動きに戻る */
    var w = 2 * Math.PI * p.n / CYCLE * speed;
    for (var q = 0; q < p.kicks.length; q++) {
      var kk = p.kicks[q];
      a += kk.K * Math.exp(-kk.age / 4) * (kk.s ? Math.sin(w * kk.age) : Math.cos(w * kk.age));
    }
    return a;
  }
  /* つかまれている振り子は、指にいちばん近づく角度まで引き寄せる（w はつかみの強さ） */
  function reachAngle(p, x, y) {
    var best = 0, bd = 1e9;
    for (var q = -40; q <= 40; q++) {
      var th = q / 40 * 1.35, s = project(p.x, -p.L * Math.cos(th), p.L * Math.sin(th));
      var d = (s[0] - x) * (s[0] - x) + (s[1] - y) * (s[1] - y);
      if (d < bd) { bd = d; best = th; }
    }
    return best;
  }
  function shownAngle(p) {
    var a0 = angle(p);
    if (p.hold > 0) p.held = a0 + (reachAngle(p, ptr.x, ptr.y) - a0) * p.hold;
    else p.held = a0;
    return p.held;
  }

  /* ---------------------------------------------------------- 描く */
  var FLOOR = -1.18;
  function draw(dt) {
    P.fill(ctx, W, H);
    ctx.lineCap = 'round';

    /* 床の方眼（遠近つき）。振り子の真下の影もここに落ちる */
    ctx.strokeStyle = P.rgba(P.steel, 0.26);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var gx = -1.4; gx <= 1.401; gx += 0.2) { var a = project(gx, FLOOR, -1), b = project(gx, FLOOR, 1); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); }
    for (var gz = -1; gz <= 1.001; gz += 0.2) { var c = project(-1.4, FLOOR, gz), d = project(1.4, FLOOR, gz); ctx.moveTo(c[0], c[1]); ctx.lineTo(d[0], d[1]); }
    ctx.stroke();

    /* 梁と 2 本の脚 */
    var b0 = project(-1.15, 0, 0), b1 = project(1.15, 0, 0);
    var f0 = project(-1.15, FLOOR, 0), f1 = project(1.15, FLOOR, 0);
    ctx.strokeStyle = P.rgba(P.line, 0.9); ctx.lineWidth = 2.2;
    ctx.beginPath(); ctx.moveTo(b0[0], b0[1]); ctx.lineTo(b1[0], b1[1]); ctx.stroke();
    ctx.strokeStyle = P.rgba(P.line, 0.4); ctx.lineWidth = 1.2;
    ctx.beginPath(); ctx.moveTo(b0[0], b0[1]); ctx.lineTo(f0[0], f0[1]); ctx.moveTo(b1[0], b1[1]); ctx.lineTo(f1[0], f1[1]); ctx.stroke();

    var bobs = [], shadows = [];
    for (var i = 0; i < COUNT; i++) {
      var p = pend[i], th = shownAngle(p);
      var by = -p.L * Math.cos(th), bz = p.L * Math.sin(th);
      var top = project(p.x, 0, 0), bob = project(p.x, by, bz), sh = project(p.x, FLOOR, bz);
      p.trail.push(bob[0], bob[1]);
      if (p.trail.length > 70) p.trail.splice(0, 2);
      p.sx = bob[0]; p.sy = bob[1]; p.tx = top[0]; p.ty = top[1];
      bobs.push({ top: top, bob: bob, z: bob[2], p: p });
      shadows.push(sh);
    }

    /* 影: 床に落ちた点を細い線でつなぐ。上から見た波の形がここに出る */
    ctx.strokeStyle = P.rgba(P.steel, 0.7); ctx.lineWidth = 1.2;
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
        ctx.strokeStyle = P.rgba(o.p.glow > 0.1 ? P.accent : P.steel, 0.7 * k / tr.length);
        ctx.lineWidth = 2.2;
        ctx.beginPath(); ctx.moveTo(tr[k - 2], tr[k - 1]); ctx.lineTo(tr[k], tr[k + 1]); ctx.stroke();
      }
      ctx.strokeStyle = o.p.glow > 0.1 ? P.rgba(P.accent, 0.5 + 0.45 * o.p.glow) : P.rgba(P.line, 0.6); ctx.lineWidth = 1.5;
      ctx.beginPath(); ctx.moveTo(o.top[0], o.top[1]); ctx.lineTo(o.bob[0], o.bob[1]); ctx.stroke();
      var r = Math.max(6, 0.046 * o.bob[2]);
      ctx.fillStyle = P.rgba(P.deep, 1);
      ctx.strokeStyle = o.p.glow > 0.1 ? P.rgba(P.accent, 0.95) : P.rgba(P.line, 0.95); ctx.lineWidth = 1.6;
      ctx.beginPath(); ctx.arc(o.bob[0], o.bob[1], r, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.9);
      ctx.beginPath(); ctx.arc(o.bob[0], o.bob[1], r * 0.62, 0, Math.PI * 2); ctx.fill();
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
  /* つかむ: 押した所に近い糸の振り子ほど強く指へ引き寄せられ、指についてくる。
     なぞると、通り道の近くの振り子が順につかまれては放される。放した位置からその振り子の周期で揺れ、数秒で列に戻る */
  var down = false, ptr = { x: 0, y: 0 }, sx0 = 0, sy0 = 0, dragged = false;
  function nearness(p, x, y) {
    var vx = p.sx - p.tx, vy = p.sy - p.ty, l2 = vx * vx + vy * vy || 1;
    var u = Math.max(0, Math.min(1, ((x - p.tx) * vx + (y - p.ty) * vy) / l2));
    return Math.hypot(x - (p.tx + vx * u), y - (p.ty + vy * u));
  }
  function letGo(p, pluck) {
    if (p.hold <= 0) return;
    var a0 = angle(p);
    var K = p.held - a0, minK = 0.9 * Math.min(1, p.hold * 1.3);
    /* 放すときは少なくとも 0.9 ラジアンほど振れるようにする（軽く触れただけでも大きく揺れ出す） */
    if (Math.abs(K) < minK) K = K < 0 ? -minK : (K > 0 ? minK : -minK);
    p.kicks.push({ K: K, age: 0 });
    /* なぞって通り過ぎた振り子は、はじかれて奥へ振れ出す */
    if (pluck) p.kicks.push({ K: -0.5 * Math.min(1, p.hold * 1.4), age: 0, s: 1 });
    while (p.kicks.length > 8) p.kicks.shift();
    p.hold = 0; p.want = 0;
  }
  function regrab(reach) {
    var any = false;
    pend.forEach(function (q) {
      var d = nearness(q, ptr.x, ptr.y), w = Math.exp(-(d / reach) * (d / reach));
      if (w > 0.12) { if (q.want < w) q.want = w; any = true; }
      else if (q.want > 0 && d > reach * 1.6) { letGo(q, true); }
    });
    return any;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; ptr.x = sx0 = e.clientX; ptr.y = sy0 = e.clientY; dragged = false;
    if (regrab(110)) SFX.play('collide');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    ptr.x = e.clientX; ptr.y = e.clientY;
    if (Math.abs(ptr.x - sx0) + Math.abs(ptr.y - sy0) > 12) dragged = true;
    if (regrab(70)) SFX.play('tick', 120);
  });
  function up() {
    if (!down) return;
    down = false;
    pend.forEach(function (q) { letGo(q, dragged); });
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

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
    /* 縦長の画面では、振り子を縦に伸ばし、正面寄りから近づいて見る（列が画面の幅いっぱいに出る） */
    var tall = h > w * 1.15;
    ys = tall ? Math.min(2.8, 1.25 * h / w) : 1;
    yawBase = tall ? -0.12 : -0.35; yawAmp = tall ? 0.12 : 0.4;
    pitchT = tall ? 0.26 : 0.38;
    f = tall ? w * 1.7 : Math.min(w * 1.35, h * 2.15);
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
      speed = 0.25 + pace * 0.75;
      t += dt * speed;
    }
    /* 列がそろうたびに、静かに 1 音 */
    var cyc = Math.floor(t / CYCLE * 2);
    if (cyc !== lastCycle) { lastCycle = cyc; SFX.play('ripple', 2000); }
    /* 触っていないあいだは、見る向きがゆっくり行き来する */
    pend.forEach(function (p) {
      p.lastKick += dt;
      /* つかみはすぐに効かせる（0.06 秒ほどで指に追いつく） */
      if (p.want > 0) p.hold += (p.want - p.hold) * (1 - Math.exp(-dt / 0.03));
      p.glow = 0;
      for (var g = 0; g < p.kicks.length; g++) p.glow = Math.max(p.glow, Math.exp(-p.kicks[g].age / 1.6));
      if (p.hold > 0.05) p.glow = 1;
      for (var q = p.kicks.length - 1; q >= 0; q--) { p.kicks[q].age += dt; if (p.kicks[q].age > 16) p.kicks.splice(q, 1); }
    });
    if (autoYaw) yawT += (yawBase + Math.sin(now / 1000 * 0.05) * yawAmp - yawT) * Math.min(1, dt * 0.5);
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
