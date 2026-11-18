<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('fallen');

$pageName = 'fallen';
$pageTitle = 'FALLEN - PULSE';
$pageDesc = '落ち葉が1枚ずつ舞い落ちて下の葉を隠しながら地面を覆い、古い葉は暗く沈んで少しずつ土に還る。押すとまわりの葉が水色に光って舞い上がり、回りながら近くに落ち直し、なぞると葉が指の向きに掃き寄せられて地面の見える道ができる。';
$pageCanonical = 'https://pulse.subara3.com/fallen.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-fall">FALL</label><input type="range" id="k-fall" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-kind="mix">MIX</button><button type="button" data-kind="maple">MAPLE</button><button type="button" data-kind="ginkgo">GINKGO</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DPR = 1, ctx;
  var TAU = Math.PI * 2;

  /* ---------------------------------------------------------- 葉の形（局所座標、先端が +x） */
  var SHAPES = [];   /* { kind, body: Path2D, vein: Path2D, k: 大きさの補正 } */
  function makeOval() {
    var body = new Path2D(), vein = new Path2D(), w = 0.36 + Math.random() * 0.1, N = 48;
    function half(t) { return w * Math.pow(0.5 - 0.5 * Math.cos(t), 0.35) * 1.25; }
    for (var i = 0; i <= N; i++) {
      var t = i / N * TAU;
      var x = Math.cos(t), y = half(t) * Math.sin(t) * (1 + 0.035 * Math.sin(t * 26));
      if (i === 0) body.moveTo(x, y); else body.lineTo(x, y);
    }
    body.closePath();
    vein.moveTo(-1.32, 0.06); vein.lineTo(0.94, 0);
    for (var j = 1; j <= 4; j++) {
      var u = -0.8 + j * 0.36, t2 = Math.acos(Math.max(-1, Math.min(1, u + 0.3))), h = half(t2) * Math.sin(t2) * 0.82;
      vein.moveTo(u, 0); vein.lineTo(u + 0.3, h);
      vein.moveTo(u, 0); vein.lineTo(u + 0.3, -h);
    }
    return { kind: 'oval', body: body, vein: vein, k: 1 };
  }
  function makeMaple() {
    var body = new Path2D(), vein = new Path2D(), N = 140, sharp = 0.7 + Math.random() * 0.5;
    for (var i = 0; i <= N; i++) {
      var t = i / N * TAU;
      /* 先の 3 枚の裂片は大きく、付け根側の 2 枚は小さい。縁に細かいぎざぎざ */
      var lobe = Math.pow(0.5 + 0.5 * Math.cos(5 * t), sharp) * (0.55 + 0.45 * Math.cos(t * 0.5) * Math.cos(t * 0.5));
      var r = (0.38 + 0.68 * lobe) * (1 + 0.05 * Math.abs(Math.sin(t * 22)) * lobe);
      var x = r * Math.cos(t), y = r * Math.sin(t);
      if (i === 0) body.moveTo(x, y); else body.lineTo(x, y);
    }
    body.closePath();
    vein.moveTo(-0.4, 0); vein.lineTo(-0.95, 0.05);
    for (var j = 0; j < 5; j++) {
      var a = j * TAU / 5;
      vein.moveTo(-0.15, 0); vein.lineTo(Math.cos(a) * 0.85, Math.sin(a) * 0.85);
    }
    return { kind: 'maple', body: body, vein: vein, k: 1.05 };
  }
  function makeGinkgo() {
    var body = new Path2D(), vein = new Path2D(), A = 0.85 + Math.random() * 0.2, R = 1.6, N = 60;
    var ox = -0.9;
    body.moveTo(ox, 0);
    for (var i = 0; i <= N; i++) {
      var a = -A + 2 * A * i / N;
      var r = R * (1 - 0.22 * Math.exp(-Math.pow(a / 0.1, 2))) * (1 + 0.025 * Math.sin(a * 30));
      body.lineTo(ox + r * Math.cos(a), r * Math.sin(a));
    }
    body.closePath();
    vein.moveTo(ox, 0); vein.lineTo(ox - 0.5, 0.04);
    for (var v = -A + 0.12; v < A - 0.05; v += 0.16) {
      vein.moveTo(ox, 0); vein.lineTo(ox + R * 0.9 * Math.cos(v), R * 0.9 * Math.sin(v));
    }
    return { kind: 'ginkgo', body: body, vein: vein, k: 0.85 };
  }
  for (var si = 0; si < 4; si++) { SHAPES.push(makeOval()); SHAPES.push(makeMaple()); SHAPES.push(makeGinkgo()); }

  /* ---------------------------------------------------------- 状態 */
  var pile, pctx, base, bctx;           /* pile = 土の層 + 積もった葉。base = 土に還りかけた古い葉 */
  var still = [];                        /* 積もって止まった葉（古い順） */
  var moving = [];                       /* 舞っている葉・掃かれて滑っている葉 */
  var CAP = 460;
  var unit = 1, meanArea = 900;
  var fall = 0.45, wind = 0.4, kind = 'mix';
  var windX = 0, windY = 0, windT = Math.random() * 100;
  var dirty = true, fadeAcc = 0, spawnAcc = 0, gustAcc = 0, gustNext = 5;

  function pickShape() {
    var want = kind;
    if (want === 'mix') { var r = Math.random(); want = r < 0.45 ? 'oval' : r < 0.8 ? 'maple' : 'ginkgo'; }
    var list = SHAPES.filter(function (s) { return s.kind === want; });
    return list[(Math.random() * list.length) | 0];
  }
  function newLeaf(x, y) {
    var sh = pickShape();
    return {
      x: x, y: y, a: Math.random() * TAU, s: unit * (15 + Math.random() * 17) * sh.k, sh: sh,
      tone: Math.random(), fy: Math.random() < 0.5 ? 1 : -1,
      z: 0, vz: 0, vx: 0, vy: 0, va: 0, ph: Math.random() * TAU, fl: Math.random() * TAU, glow: 0, fly: false
    };
  }

  /* 葉を 1 枚描く。dim = 0（底の古い葉）〜1（いちばん上） */
  function drawLeaf(c, L, dim, sx, ox, oy, fy, shadow) {
    var s = L.s * sx, ca = Math.cos(L.a) * s, sa = Math.sin(L.a) * s;
    c.setTransform(DPR * ca, DPR * sa, -DPR * sa * fy, DPR * ca * fy, DPR * (L.x + ox), DPR * (L.y + oy));
    if (shadow) {
      c.fillStyle = P.rgba(P.deep, shadow);
      c.fill(L.sh.body);
      return;
    }
    var col = P.ramp(0.07 + dim * 0.17 + L.tone * 0.07);
    if (L.glow > 0) col = P.mix(col, P.rampAccent(0.45), Math.min(1, L.glow) * 0.5);
    c.fillStyle = P.rgba(col, 1);
    c.fill(L.sh.body);
    var lw = 1 / s * Math.min(1.4, 0.6 + 0.04 * L.s);
    c.lineWidth = lw;
    var edge = L.glow > 0.05 ? P.mix(P.line, P.accent, Math.min(1, L.glow)) : P.line;
    c.strokeStyle = P.rgba(edge, 0.18 + dim * 0.62);
    c.stroke(L.sh.body);
    c.lineWidth = lw * 0.8;
    c.strokeStyle = P.rgba(edge, 0.08 + dim * 0.32);
    c.stroke(L.sh.vein);
  }

  function rankDim(i, n) { return n > 1 ? Math.pow(i / (n - 1), 0.8) : 1; }

  function bake(L) { drawLeaf(bctx, L, 0, 1, 0, 0, L.fy, 0); }

  function rebuild() {
    pctx.setTransform(1, 0, 0, 1, 0, 0);
    pctx.clearRect(0, 0, pile.width, pile.height);
    pctx.drawImage(base, 0, 0);
    for (var i = 0; i < still.length; i++) drawLeaf(pctx, still[i], rankDim(i, still.length), 1, 0, 0, still[i].fy, 0);
    dirty = false;
  }

  function land(L) {
    L.fly = false; L.z = 0; L.vx = L.vy = L.vz = L.va = 0; L.glow = 0;
    still.push(L);
    while (still.length > CAP) bake(still.shift());
    if (!dirty) drawLeaf(pctx, L, 1, 1, 0, 0, L.fy, 0);
  }

  function init() {
    var cw = Math.round(W * DPR), ch = Math.round(H * DPR);
    pile = document.createElement('canvas'); pile.width = cw; pile.height = ch; pctx = pile.getContext('2d');
    base = document.createElement('canvas'); base.width = cw; base.height = ch; bctx = base.getContext('2d');
    unit = Math.max(0.8, Math.min(1.5, Math.sqrt(W * H) / 900));
    meanArea = 1.15 * Math.pow(unit * 23, 2);
    still = []; moving = [];
    /* 最初から地面の 8 割ほどを葉が覆っている。下の方は暗く沈んだ古い葉 */
    var n = Math.round(1.7 * W * H / meanArea);
    var under = Math.max(0, n - CAP);
    for (var i = 0; i < n; i++) {
      var L = newLeaf(Math.random() * (W + 40) - 20, Math.random() * (H + 40) - 20);
      if (i < under) bake(L); else still.push(L);
    }
    /* 底の層はあらかじめ少し土に還しておく */
    bctx.setTransform(1, 0, 0, 1, 0, 0);
    bctx.globalCompositeOperation = 'destination-out';
    bctx.fillStyle = P.rgba(P.deep, 0.35);
    bctx.fillRect(0, 0, base.width, base.height);
    bctx.globalCompositeOperation = 'source-over';
    /* 空中にも何枚か */
    for (var k = 0; k < 10; k++) {
      var F = newLeaf(Math.random() * W, Math.random() * H);
      F.fly = true; F.z = 0.2 + Math.random() * 0.8; F.vz = -0.3;
      moving.push(F);
    }
    dirty = true;
  }

  /* 地面（底の層）を丸く掃き出す */
  function clearBase(x, y, r, a) {
    bctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    bctx.globalCompositeOperation = 'destination-out';
    var g = bctx.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, P.rgba(P.deep, a));
    g.addColorStop(0.6, P.rgba(P.deep, a * 0.7));
    g.addColorStop(1, P.rgba(P.deep, 0));
    bctx.fillStyle = g;
    bctx.fillRect(x - r, y - r, r * 2, r * 2);
    bctx.globalCompositeOperation = 'source-over';
    dirty = true;
  }

  /* ---------------------------------------------------------- 状態をすべて宣言してから呼ぶ */
  ctx = P.fit(canvas, function (w, h, dpr) { W = w; H = h; DPR = dpr || 1; init(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastT = 0;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }

  /* 押す: まわりの葉が舞い上がり、回りながらまわりへ落ち直す */
  function toss(x, y) {
    var R = 95 * unit, keep = [];
    for (var i = 0; i < still.length; i++) {
      var L = still[i], dx = L.x - x, dy = L.y - y, d = Math.hypot(dx, dy);
      if (d < R) {
        var f = 1 - d / R, ang = Math.atan2(dy, dx) + (Math.random() - 0.5) * 0.6;
        var sp = (60 + 160 * f) * unit;
        L.fly = true; L.z = 0.02; L.vz = 0.7 + f * 0.9 + Math.random() * 0.3;
        L.vx = Math.cos(ang) * sp; L.vy = Math.sin(ang) * sp;
        L.va = (Math.random() - 0.5) * 8; L.glow = 1.2;
        moving.push(L);
      } else keep.push(L);
    }
    still = keep;
    /* 動いている葉も外へ押す */
    for (var j = 0; j < moving.length; j++) {
      var M = moving[j], mx = M.x - x, my = M.y - y, md = Math.hypot(mx, my);
      if (md < R * 1.3 && md > 0) { M.vx += mx / md * 120 * unit; M.vy += my / md * 120 * unit; M.glow = Math.max(M.glow, 0.8); }
    }
    clearBase(x, y, R * 0.9, 0.85);
    dirty = true;
  }

  /* なぞる: 指のまわりの葉が指の向きに掃き寄せられる */
  function sweep(x, y, vx, vy) {
    var R = 42 * unit, keep = [], hit = false;
    var sp = Math.hypot(vx, vy) || 1;
    var nx = -vy / sp, ny = vx / sp;
    for (var i = 0; i < still.length; i++) {
      var L = still[i], dx = L.x - x, dy = L.y - y, d = Math.hypot(dx, dy);
      if (d < R) {
        var side = (dx * nx + dy * ny) >= 0 ? 1 : -1;
        L.vx = vx * 0.55 + nx * side * 110 * unit; L.vy = vy * 0.55 + ny * side * 110 * unit;
        L.va = (Math.random() - 0.5) * 5; L.glow = 0.7;
        moving.push(L); hit = true;
      } else keep.push(L);
    }
    if (hit) still = keep;
    for (var j = 0; j < moving.length; j++) {
      var M = moving[j];
      if (M.fly) continue;
      var mx = M.x - x, my = M.y - y, md = Math.hypot(mx, my);
      if (md < R && md > 0) {
        var sd = (mx * nx + my * ny) >= 0 ? 1 : -1;
        M.vx = vx * 0.55 + nx * sd * 110 * unit; M.vy = vy * 0.55 + ny * sd * 110 * unit; M.glow = Math.max(M.glow, 0.6);
      }
    }
    clearBase(x, y, R * 1.1, 0.5);
    dirty = true;
  }

  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); lastT = performance.now();
    toss(last.x, last.y);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now();
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 6) return;
    var dt = Math.max(0.008, (now - lastT) / 1000);
    var vx = (p.x - last.x) / dt, vy = (p.y - last.y) / dt, vs = Math.hypot(vx, vy), cap = 900 * unit;
    if (vs > cap) { vx *= cap / vs; vy *= cap / vs; }
    var n = Math.ceil(dist / 16);
    for (var i = 1; i <= n; i++) sweep(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, vx, vy);
    last = p; lastT = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-fall').addEventListener('input', function () { fall = this.value / 100; });
  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.querySelectorAll('[data-kind]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-kind]').forEach(function (o) { o.classList.toggle('active', o === b); });
      kind = b.dataset.kind;
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  fall = document.getElementById('k-fall').value / 100;
  wind = document.getElementById('k-wind').value / 100;
  var act = document.querySelector('[data-kind].active');
  if (act) kind = act.dataset.kind;

  /* ---------------------------------------------------------- 風の一吹き: 上の葉が何枚か滑る */
  function gust() {
    var gx = Math.random() * W, gy = Math.random() * H, R = 140 * unit, keep = [], cnt = 0;
    var wl = Math.hypot(windX, windY) || 1, ux = windX / wl, uy = windY / wl;
    var from = Math.max(0, still.length - 220);
    for (var i = 0; i < still.length; i++) {
      var L = still[i];
      if (i >= from && cnt < 14 && Math.hypot(L.x - gx, L.y - gy) < R && Math.random() < 0.6) {
        var sp = (40 + Math.random() * 70) * unit * (0.4 + wind);
        L.vx = ux * sp; L.vy = uy * sp; L.va = (Math.random() - 0.5) * 2.5;
        moving.push(L); cnt++;
      } else keep.push(L);
    }
    if (cnt) { still = keep; dirty = true; }
  }

  /* ---------------------------------------------------------- 回す */
  var prevT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - prevT) / 1000));   /* 最初の数フレームは負になることがある */
    prevT = now;

    /* 風はゆっくり向きと強さを変える */
    windT += dt * 0.07;
    var wa = Math.sin(windT) * 1.3 + Math.sin(windT * 2.3 + 1) * 0.5;
    var wm = (10 + 60 * wind) * unit * (0.6 + 0.4 * Math.sin(windT * 3.1));
    windX = Math.cos(wa) * wm; windY = Math.sin(wa) * wm * 0.6 + 8 * unit;

    /* 新しい葉が舞い落ちる */
    spawnAcc += dt * (0.25 + 2.2 * fall) * W * H / meanArea / 110;
    while (spawnAcc > 1) {
      spawnAcc -= 1;
      var F = newLeaf(Math.random() * (W + 80) - 40, Math.random() * (H + 80) - 40);
      F.fly = true; F.z = 1; F.vz = -0.28 - Math.random() * 0.12;
      moving.push(F);
    }
    gustAcc += dt;
    if (gustAcc > gustNext) { gustAcc = 0; gustNext = 5 + Math.random() * 7 - wind * 3; gust(); }

    /* 古い層は少しずつ土に還る */
    fadeAcc += dt;
    if (fadeAcc > 1) {
      fadeAcc = 0;
      bctx.setTransform(1, 0, 0, 1, 0, 0);
      bctx.globalCompositeOperation = 'destination-out';
      bctx.fillStyle = P.rgba(P.deep, 0.018);
      bctx.fillRect(0, 0, base.width, base.height);
      bctx.globalCompositeOperation = 'source-over';
      dirty = true;
    }

    /* 動いている葉 */
    for (var i = moving.length - 1; i >= 0; i--) {
      var L = moving[i];
      L.glow = Math.max(0, L.glow - dt * 0.45);
      if (L.fly) {
        L.vz = Math.max(L.vz - 1.6 * dt, -0.32);
        L.z += L.vz * dt;
        var drag = Math.exp(-1.4 * dt);
        L.vx *= drag; L.vy *= drag;
        L.ph += dt * (1.6 + L.tone);
        L.fl += dt * (2 + 5 * L.z) * (L.tone < 0.5 ? 1 : -1);
        var sway = 38 * unit * Math.min(1, L.z * 2);
        L.x += (L.vx + windX * (0.4 + L.z) + Math.cos(L.ph) * sway) * dt;
        L.y += (L.vy + windY * (0.4 + L.z) + Math.sin(L.ph * 0.7) * sway * 0.5) * dt;
        L.a += (L.va * Math.exp(-0.4 * (1 - L.z)) + Math.sin(L.ph) * 0.9) * dt;
        if (L.z <= 0) {
          L.fy = Math.cos(L.fl) >= 0 ? 1 : -1;
          moving.splice(i, 1);
          land(L);
          if (Math.random() < 0.15) SFX.play('tick', 2600);
        }
      } else {
        var fr = Math.exp(-3.2 * dt);
        L.vx *= fr; L.vy *= fr; L.va *= fr;
        L.x += L.vx * dt; L.y += L.vy * dt; L.a += L.va * dt;
        if (Math.hypot(L.vx, L.vy) < 5 * unit) { moving.splice(i, 1); land(L); }
      }
    }

    if (dirty) rebuild();

    /* 描く */
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.9 });
    ctx.drawImage(pile, 0, 0, W, H);
    /* 影 → 滑る葉 → 舞う葉（低いものから） */
    for (var a = 0; a < moving.length; a++) {
      var A = moving[a];
      if (A.fly && A.z > 0.01) drawLeaf(ctx, A, 1, 1, A.z * 38 * unit, A.z * 52 * unit, 1, 0.45 * (1 - A.z * 0.5));
    }
    for (var b = 0; b < moving.length; b++) if (!moving[b].fly) drawLeaf(ctx, moving[b], 1, 1, 0, 0, moving[b].fy, 0);
    moving.sort(function (p, q) { return p.fly === q.fly ? p.z - q.z : (p.fly ? 1 : -1); });
    for (var c = 0; c < moving.length; c++) {
      var B = moving[c];
      if (!B.fly) continue;
      var fyv = Math.cos(B.fl), zz = Math.min(1, B.z * 4);
      var sgn = fyv >= 0 ? 1 : -1;
      var fy = sgn + (fyv - sgn) * zz;
      if (Math.abs(fy) < 0.12) fy = fy < 0 ? -0.12 : 0.12;
      drawLeaf(ctx, B, 1, 1 + 0.75 * B.z, 0, 0, fy, 0);
    }
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
