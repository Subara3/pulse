<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('mobile');

$pageName = 'mobile';
$pageTitle = 'MOBILE - PULSE';
$pageDesc = '天井から吊った細い棒の両端に円い板が下がり、それが何段も重なって、つり合ったままゆっくり回っている。押すとそこから風が吹いて近くの板が揺れて回り出し、なぞると指の動いた向きに板が流されて、上の棒までつられて回る。';
$pageCanonical = 'https://pulse.subara3.com/mobile.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-shape="disc">DISC</button><button type="button" data-shape="leaf">LEAF</button></div>
  <div class="ctl-btns"><button type="button" id="k-new">NEW</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* モビール: 棒の両端に「板」か「下の段のモビール」が下がる二分木。
     各棒は吊り糸のまわりに水平に回り、吊り点は振り子として左右・前後に揺れる。
     奥行き z は少し上から見下ろす角度で画面の y に足す */
  var TILT = 0.24, TR = 120;
  var nodes = [], leaves = [], root = null, leafCount = 0, maxLeaves = 16;
  var CX = 0, Y0 = 0, SP = 0, LB = 0, RB = 0, LMAX = 5, DECAY = 0.74;
  var windK = 0.45, trailK = 0.5, shape = 'disc';
  var t = 0, wx = 0, wz = 0, wAng = 0, frameN = 0;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function base(level) {
    return {
      level: level, phi: rnd(0, 6.283), phi0: rnd(0, 6.283), w: 0,
      ox: 0, oz: 0, vx: 0, vz: 0, g: 0,
      ph: rnd(0, 6.283), fq: rnd(0.05, 0.13), sw: rnd(0.6, 1.4), cd: rnd(0.4, 1),
      hx: 0, hy: 0, hz: 0, X: 0, Y: 0, Z: 0, th: 0
    };
  }
  function makeLeaf(level, r) {
    var n = base(level);
    n.leaf = true;
    n.r = r || RB * Math.max(0.55, 1.25 - 0.1 * level) * rnd(0.75, 1.1);
    n.m = n.r * n.r;
    n.s = SP * rnd(0.25, 0.6);
    n.k = rnd(0.2, 0.45);
    n.tr = new Float32Array(TR * 2); n.ti = 0; n.tn = 0;
    leafCount++;
    nodes.push(n); leaves.push(n);
    return n;
  }
  function gen(level) {
    if (level >= LMAX || leafCount >= maxLeaves - 1 || (level > 1 && Math.random() < 0.12)) return makeLeaf(level);
    var A = gen(level + 1), B;
    if (level >= LMAX - 1 || leafCount >= maxLeaves - 2 || (level > 0 && Math.random() < 0.5)) {
      /* 片側が 1 枚の板なら、下の段とつり合いすぎない大きさにする（支点が端に寄りすぎない） */
      var r = A.leaf ? 0 : Math.max(RB * 0.6, Math.min(RB * 1.45, Math.sqrt(A.m * rnd(0.35, 0.7))));
      B = makeLeaf(level + 1, r);
    } else {
      B = gen(level + 1);
    }
    if (Math.random() < 0.5) { var tmp = A; A = B; B = tmp; }
    var n = base(level);
    n.leaf = false;
    n.A = A; n.B = B;
    n.L = LB * Math.pow(DECAY, level) * rnd(0.85, 1.15);
    n.a = n.L * B.m / (A.m + B.m); n.b = n.L - n.a;   /* 支点: mA·a = mB·b */
    n.m = A.m + B.m;
    n.I = A.m * n.a * n.a + B.m * n.b * n.b;
    n.s = level === 0 ? Y0 : SP * rnd(0.8, 1.1);
    n.k = rnd(0.03, 0.08);
    nodes.push(n);
    return n;
  }
  function build() {
    nodes = []; leaves = []; leafCount = 0;
    var asp = H / W;
    LMAX = asp < 0.8 ? 4 : 5;
    maxLeaves = asp < 0.8 ? 16 : 13;
    RB = Math.min(W, H) * (asp < 1 ? 0.075 : 0.085);
    LB = W * (asp < 1 ? 0.48 : 0.5);
    DECAY = asp < 1 ? 0.72 : 0.8;
    CX = W * 0.5;
    Y0 = Math.max(H * 0.09, LB * TILT * 0.75);
    SP = (H * 0.86 - Y0 - RB * 2) / (LMAX + 0.4);
    var tries = 0;
    do { nodes = []; leaves = []; leafCount = 0; root = gen(0); tries++; } while (leafCount < 8 && tries < 20);
    /* 吊り糸の長さをまとめて伸び縮みさせ、いちばん下の板が画面の下近くに来るようにする */
    for (var it = 0; it < 3; it++) {
      var bot = bottom(root, 0);
      var k = (H * 0.88 - Y0) / Math.max(1, bot - Y0);
      k = Math.max(0.8, Math.min(1.8, k));
      for (var q = 0; q < nodes.length; q++) if (nodes[q] !== root) nodes[q].s *= k;
    }
    /* 吊った直後から少しずつ回っている */
    for (var i = 0; i < nodes.length; i++) nodes[i].w = rnd(-0.3, 0.3);
  }

  function bottom(n, y) {
    y += n.s;
    if (n.leaf) return y + n.r * (shape === 'leaf' ? 2.2 : 2);
    return Math.max(bottom(n.A, y), bottom(n.B, y));
  }

  /* ---------------------------------------------------------- 位置 */
  function place(n, x, y, z, thp) {
    n.hx = x; n.hy = y; n.hz = z;
    n.th = thp + n.phi;
    n.X = x + n.ox; n.Y = y + n.s; n.Z = z + n.oz;
    if (n.leaf) return;
    var c = Math.cos(n.th), s = Math.sin(n.th);
    place(n.A, n.X - n.a * c, n.Y, n.Z - n.a * s, n.th);
    place(n.B, n.X + n.b * c, n.Y, n.Z + n.b * s, n.th);
  }
  function sy(y, z) { return y + z * TILT; }
  /* 触る判定に使う画面上の点。板は中心、棒は支点 */
  function hitX(n) { return n.X; }
  function hitY(n) { return sy(n.Y, n.Z) + (n.leaf ? n.r : 0); }

  /* ---------------------------------------------------------- 動き */
  function step(dt) {
    t += dt;
    wAng = t * 0.045 + Math.sin(t * 0.11) * 1.3;
    var mag = windK * (55 + 40 * Math.sin(t * 0.19 + 1) * Math.sin(t * 0.067));
    wx = Math.cos(wAng) * mag; wz = Math.sin(wAng) * mag;
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i], al;
      if (n.leaf) {
        /* 板は風に面を向けすぎないよう少し回され、あとは自分の揺らぎ */
        al = -0.012 * mag * Math.sin(2 * (n.th - wAng)) + (0.12 + windK * 0.25) * Math.sin(t * n.fq * 3 + n.ph)
           - n.k * 0.15 * Math.sin(n.phi - n.phi0) - 0.7 * n.w;
      } else {
        var c = Math.cos(n.th), s = Math.sin(n.th);
        var tA = (s * wx - c * wz) * n.A.cd, tB = (-s * wx + c * wz) * n.B.cd;
        al = (n.a * n.A.m * tA + n.b * n.B.m * tB) / n.I * 0.35
           + (0.06 + windK * 0.16) * Math.sin(t * n.fq + n.ph)
           - n.k * Math.sin(n.phi - n.phi0) - 0.55 * n.w;
      }
      n.w += al * dt;
      if (n.w > 2) n.w = 2; else if (n.w < -2) n.w = -2;
      n.phi += n.w * dt;
      /* 吊り点の揺れ（振り子） */
      var om = 2.6 / Math.max(0.6, n.s / SP);
      n.vx += (wx * n.sw * 0.45 - om * n.ox - 1.1 * n.vx) * dt;
      n.vz += (wz * n.sw * 0.45 - om * n.oz - 1.1 * n.vz) * dt;
      n.ox += n.vx * dt; n.oz += n.vz * dt;
      var lim = SP * 0.6;
      if (n.ox > lim) { n.ox = lim; n.vx *= -0.3; } else if (n.ox < -lim) { n.ox = -lim; n.vx *= -0.3; }
      if (n.oz > lim) { n.oz = lim; n.vz *= -0.3; } else if (n.oz < -lim) { n.oz = -lim; n.vz *= -0.3; }
      n.g = Math.max(0, n.g - dt * 0.55);
    }
    place(root, CX, 0, 0, 0);
  }

  /* 画面の (px,py) のまわりに力をかける。follow=true はなぞり（指の速さに寄せる）、false は押した一吹き */
  function push(px, py, fx, fy, R, follow, gk) {
    var hit = 0;
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.leaf) continue;
      var c = Math.cos(n.th), s = Math.sin(n.th);
      for (var e = 0; e < 2; e++) {
        var ch = e ? n.B : n.A, arm = e ? n.b : n.a;
        var d = Math.hypot(hitX(ch) - px, hitY(ch) - py);
        if (d > R) continue;
        var f = 1 - d / R; f *= f;
        var tang = e ? (-s * fx + c * fy) : (s * fx - c * fy);
        var ar = Math.max(arm, RB);
        if (follow) n.w += (tang / ar - n.w) * 0.12 * f;
        else n.w += tang / ar * 0.5 * f;
        if (follow) { ch.vx += (fx * 0.6 - ch.vx) * 0.3 * f; ch.vz += (fy * 0.6 - ch.vz) * 0.3 * f; }
        else { ch.vx += fx * 0.5 * f; ch.vz += fy * 0.5 * f; }
        if (ch.leaf) ch.w += (e ? 1 : -1) * (fx + fy) * 0.012 * f;
        ch.g = Math.min(1, Math.max(ch.g, f * gk));
        n.g = Math.min(1, Math.max(n.g, f * gk * 0.7));
        hit += f;
      }
    }
    return hit;
  }

  /* ---------------------------------------------------------- 描く */
  var LN = P.line, ST = P.steel, AC = P.accent, DP = P.deep;
  function depth(z) { var v = 0.5 + z / (LB * 1.6); return v < 0 ? 0 : v > 1 ? 1 : v; }

  function drawWires() {
    ctx.lineCap = 'round';
    /* 天井の留め具 */
    ctx.strokeStyle = P.rgba(ST, 0.7); ctx.lineWidth = 1.2;
    ctx.beginPath(); ctx.moveTo(CX - 14, 1.5); ctx.lineTo(CX + 14, 1.5); ctx.stroke();
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      var dA = depth(n.Z), col = P.mix(LN, AC, n.g);
      /* 吊り糸 */
      ctx.strokeStyle = P.rgba(P.mix(ST, col, 0.5), 0.35 + 0.35 * dA);
      ctx.lineWidth = 0.8;
      ctx.beginPath(); ctx.moveTo(n.hx, sy(n.hy, n.hz)); ctx.lineTo(n.X, sy(n.Y, n.Z) - (n.leaf ? 0 : n.L * 0.035)); ctx.stroke();
      if (n.leaf) continue;
      /* 棒: 支点を少し持ち上げた弧 */
      var ax = n.A.hx, ay = sy(n.A.hy, n.A.hz), bx = n.B.hx, by = sy(n.B.hy, n.B.hz);
      ctx.strokeStyle = P.rgba(col, 0.55 + 0.4 * dA);
      ctx.lineWidth = 1.1 + 0.9 * dA + (LMAX - n.level) * 0.12;
      ctx.beginPath();
      ctx.moveTo(ax, ay);
      ctx.quadraticCurveTo(n.X, sy(n.Y, n.Z) - n.L * 0.07, bx, by);
      ctx.stroke();
      ctx.fillStyle = P.rgba(col, 0.8);
      ctx.beginPath(); ctx.arc(n.X, sy(n.Y, n.Z) - n.L * 0.035, 1.8, 0, 6.283); ctx.fill();
    }
  }

  function drawTrails() {
    if (trailK <= 0.01) return;
    var len = Math.round(20 + trailK * (TR - 20));
    ctx.lineWidth = 1;
    for (var i = 0; i < leaves.length; i++) {
      var n = leaves[i], m = Math.min(n.tn, len);
      if (m < 3) continue;
      var tr = n.tr;
      /* 古いほど薄く。4 区切りで濃さを変える */
      for (var seg = 0; seg < 4; seg++) {
        var s0 = Math.floor(m * seg / 4), s1 = Math.floor(m * (seg + 1) / 4);
        ctx.strokeStyle = P.rgba(P.mix(ST, AC, n.g * 0.6), (0.06 + 0.18 * seg / 3) * (0.4 + trailK));
        ctx.beginPath();
        for (var k = s0; k <= s1 && k < m; k++) {
          var j = ((n.ti - m + k) % TR + TR) % TR;
          if (k === s0) ctx.moveTo(tr[j * 2], tr[j * 2 + 1]); else ctx.lineTo(tr[j * 2], tr[j * 2 + 1]);
        }
        ctx.stroke();
      }
    }
  }

  function platePath(r, f) {
    ctx.beginPath();
    if (shape === 'leaf') {
      var hw = r * 0.78 * f + 0.6;
      ctx.moveTo(0, -r * 1.1);
      ctx.bezierCurveTo(hw * 1.3, -r * 0.5, hw * 1.3, r * 0.5, 0, r * 1.1);
      ctx.bezierCurveTo(-hw * 1.3, r * 0.5, -hw * 1.3, -r * 0.5, 0, -r * 1.1);
    } else {
      ctx.ellipse(0, 0, Math.max(0.8, r * f), r, 0, 0, 6.283);
    }
  }

  var order = [];
  function drawPlates() {
    order.length = 0;
    for (var i = 0; i < leaves.length; i++) order.push(leaves[i]);
    order.sort(function (p, q) { return p.Z - q.Z; });
    for (var o = 0; o < order.length; o++) {
      var n = order[o];
      var c = Math.cos(n.th), f = Math.abs(c), dA = depth(n.Z);
      var x = n.X, y = sy(n.Y, n.Z) + n.r;
      var col = P.mix(LN, AC, n.g);
      ctx.save();
      ctx.translate(x, y);
      platePath(n.r, f);
      /* 表は少し明るく、裏は暗く */
      var lit = c > 0 ? 0.3 + 0.35 * f : 0.12 + 0.18 * f;
      ctx.fillStyle = P.rgba(P.mix(DP, P.mix(ST, AC, n.g * 0.7), lit * (0.6 + 0.4 * dA)), 0.94);
      ctx.fill();
      ctx.strokeStyle = P.rgba(col, 0.5 + 0.5 * dA);
      ctx.lineWidth = 1.2 + n.g;
      ctx.stroke();
      /* 製図の補助線: 内側の輪と中心線 */
      ctx.strokeStyle = P.rgba(col, (0.12 + 0.18 * f) * (0.5 + 0.5 * dA));
      ctx.lineWidth = 0.8;
      ctx.save(); ctx.scale(0.62, 0.62); platePath(n.r, f); ctx.restore();
      ctx.stroke();
      ctx.beginPath(); ctx.moveTo(0, -n.r * (shape === 'leaf' ? 1.1 : 1)); ctx.lineTo(0, n.r * (shape === 'leaf' ? 1.1 : 1)); ctx.stroke();
      ctx.restore();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); place(root, CX, 0, 0, 0); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastMoveT = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    last = { x: e.clientX, y: e.clientY };
    lastMoveT = performance.now();
    var R = Math.min(W, H) * 0.3;
    /* 押した点から外向きの一吹き。近い板ほど強く */
    var hit = 0;
    for (var i = 0; i < 12; i++) {
      var a = i / 12 * 6.283;
      hit += push(last.x + Math.cos(a) * R * 0.25, last.y + Math.sin(a) * R * 0.25, Math.cos(a) * 340, Math.sin(a) * 340, R * 0.6, false, 1);
    }
    if (hit < 0.2) {
      /* 何もないところを押したら、いちばん近い板へ向けて風を送る */
      var best = null, bd = 1e9;
      for (var j = 0; j < leaves.length; j++) {
        var d = Math.hypot(hitX(leaves[j]) - last.x, hitY(leaves[j]) - last.y);
        if (d < bd) { bd = d; best = leaves[j]; }
      }
      if (best) {
        var dx = (hitX(best) - last.x) / bd, dy = (hitY(best) - last.y) / bd;
        push(hitX(best), hitY(best), dx * 300, dy * 300, R, false, 0.9);
      }
    }
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now();
    var p = { x: e.clientX, y: e.clientY };
    var dtm = Math.max(0.008, (now - lastMoveT) / 1000);
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 3) return;
    var vx = (p.x - last.x) / dtm, vy = (p.y - last.y) / dtm, v = Math.hypot(vx, vy);
    if (v > 900) { vx *= 900 / v; vy *= 900 / v; }
    push(p.x, p.y, vx, vy, Math.min(W, H) * 0.16, true, 1);
    last = p; lastMoveT = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { windK = this.value / 100; });
  document.getElementById('k-trail').addEventListener('input', function () { trailK = this.value / 100; });
  document.querySelectorAll('[data-shape]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-shape]').forEach(function (o) { o.classList.toggle('active', o === b); });
      shape = b.dataset.shape;
      SFX.play('toggle');
    });
  });
  document.getElementById('k-new').addEventListener('click', function () {
    build(); place(root, CX, 0, 0, 0);
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  windK = document.getElementById('k-wind').value / 100;
  trailK = document.getElementById('k-trail').value / 100;
  var act = document.querySelector('[data-shape].active');
  if (act) shape = act.dataset.shape;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    step(dt * 0.5); step(dt * 0.5);
    frameN++;
    if (frameN % 2 === 0) {
      for (var i = 0; i < leaves.length; i++) {
        var n = leaves[i];
        n.tr[n.ti * 2] = n.X; n.tr[n.ti * 2 + 1] = sy(n.Y, n.Z) + n.r;
        n.ti = (n.ti + 1) % TR; if (n.tn < TR) n.tn++;
      }
    }
    /* 風が強まったときだけ、ときどき 1 音 */
    chime += dt;
    if (chime > 5 && Math.hypot(wx, wz) > windK * 80) { chime = 0; SFX.play('tick'); }
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.8 });
    drawTrails();
    drawWires();
    drawPlates();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
