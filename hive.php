<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('hive');

$pageName = 'hive';
$pageTitle = 'HIVE - PULSE';
$pageDesc = '上の枝から垂れた蜂の巣に、ミツバチが六角形の部屋を1つずつ足して、巣が下へ伸びていく。押すとそこに水色の部屋がまとめてでき、なぞると指の跡に沿って部屋が並ぶ。';
$pageCanonical = 'https://pulse.subara3.com/hive.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-bees">BEES</label><input type="range" id="k-bees" min="0" max="100" value="55"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 六角形の部屋は画面に固定した格子に並べる。巣はその上の「形」で、形の内側の部屋が建つ */
  var S, DX, DY, NC, NR, N;
  var cx, cy, build, tgt, fill, glow, age, keep, seed, comb;
  var combs = [];
  var Y0 = 0;
  var bees = [];
  var ripples = [];
  var speed = 0.45, beeAmt = 0.55;
  var HX = [], HY = [];
  for (var v = 0; v < 6; v++) { HX.push(Math.cos(-Math.PI / 2 + v * Math.PI / 3)); HY.push(Math.sin(-Math.PI / 2 + v * Math.PI / 3)); }

  function prof(c, x) {
    var u = (x - c.cx) / c.hw;
    if (u <= -1 || u >= 1) return -1;
    return Math.pow(1 - u * u, 0.38) * (1 + 0.07 * Math.sin(u * 6.3 + c.ph) + 0.04 * Math.sin(u * 13 + c.ph * 2));
  }

  function newComb(c, fresh) {
    c.L = fresh ? S * 1.6 : c.Lmax * (0.45 + Math.random() * 0.5);
    c.state = 0; c.timer = 0; c.alpha = 1;
    c.ph = Math.random() * 6.28;
    c.rate = 1 / (34 + Math.random() * 16);
  }

  function alloc() {
    S = Math.max(9, Math.min(14, Math.min(W, H) / 44));
    DX = S * Math.sqrt(3); DY = S * 1.5;
    NC = Math.ceil(W / DX) + 2; NR = Math.ceil(H / DY) + 2; N = NC * NR;
    cx = new Float32Array(N); cy = new Float32Array(N);
    build = new Float32Array(N); tgt = new Uint8Array(N); fill = new Float32Array(N);
    glow = new Float32Array(N); age = new Float32Array(N); keep = new Float32Array(N);
    seed = new Float32Array(N); comb = new Int8Array(N);
    Y0 = S * 2.2;
    for (var r = 0; r < NR; r++) {
      for (var c = 0; c < NC; c++) {
        var i = r * NC + c;
        cx[i] = DX * (c - 0.5 + 0.5 * (r & 1)); cy[i] = Y0 + DY * r;
        seed[i] = Math.random();
      }
    }
    var K = Math.max(2, Math.round(W / Math.max(230, H * 0.42)));
    combs = [];
    for (var k = 0; k < K; k++) {
      var cc = { cx: (k + 0.5) * W / K, hw: W / K * 0.5 - S * 1.3, Lmax: H - Y0 - S * 1.2 };
      newComb(cc, false);
      combs.push(cc);
    }
    for (var j = 0; j < N; j++) {
      var best = 0, bd = 1e9;
      for (var q = 0; q < K; q++) { var d = Math.abs(cx[j] - combs[q].cx); if (d < bd) { bd = d; best = q; } }
      comb[j] = best;
      if (inside(j)) { tgt[j] = 1; build[j] = 1; age[j] = Math.random() * 30 * (1 - (cy[j] - Y0) / H); }
    }
    ripples.length = 0;
    bees.length = 0; // 巣を作り直したら蜂も作り直す（古い巣の番号を持った蜂を残さない）
    makeBees();
  }

  function inside(i) {
    var c = combs[comb[i]], p = prof(c, cx[i]);
    return p > 0 && cy[i] - Y0 < c.L * p;
  }

  function cellAt(x, y) {
    var r = Math.round((y - Y0) / DY);
    if (r < 0 || r >= NR) return -1;
    var c = Math.round(x / DX + 0.5 - 0.5 * (r & 1));
    if (c < 0 || c >= NC) return -1;
    return r * NC + c;
  }

  /* ---------------------------------------------------------- ミツバチ */
  function beeCount() { return Math.round((20 + beeAmt * 340) * Math.min(1.6, Math.max(0.45, W * H / 1.4e6))); }
  function makeBees() {
    var n = beeCount();
    while (bees.length > n) bees.pop();
    while (bees.length < n) {
      var k = (Math.random() * combs.length) | 0, b = { k: k, x: 0, y: 0, a: Math.random() * 6.28, fly: 0, wait: Math.random() * 2, tx: 0, ty: 0, hot: 0 };
      pickTarget(b);
      b.x = b.tx; b.y = b.ty;
      bees.push(b);
    }
  }
  function pickTarget(b) {
    var c = combs[b.k];
    var u = (Math.random() * 2 - 1) * 0.92, x = c.cx + u * c.hw;
    var p = Math.max(0, prof(c, x)), edge = Y0 + c.L * p;
    b.tx = x;
    b.ty = Math.random() < 0.72 ? edge - Math.random() * S * 3 : Y0 + Math.random() * (edge - Y0);
    if (b.ty < Y0 - S * 0.5) b.ty = Y0;
  }
  function stepBees(dt, t) {
    for (var n = 0; n < bees.length; n++) {
      var b = bees[n];
      b.hot = Math.max(0, b.hot - dt * 0.5);
      if (down) {
        var dd = Math.hypot(b.x - px, b.y - py);
        if (dd < Math.min(W, H) * 0.32 && Math.random() < dt * 3) {
          b.tx = px + (Math.random() - 0.5) * S * 7; b.ty = py + (Math.random() - 0.5) * S * 7;
          b.fly = 1; b.wait = 0; b.hot = 1;
        }
      }
      var dx = b.tx - b.x, dy = b.ty - b.y, d = Math.hypot(dx, dy);
      if (d < 3) {
        b.fly = 0;
        b.wait -= dt;
        if (b.wait <= 0) {
          var dying = combs[b.k].state === 2;
          if (dying || Math.random() < 0.06) { b.k = (Math.random() * combs.length) | 0; b.fly = 1; }
          pickTarget(b);
          b.wait = 0.3 + Math.random() * 1.6;
          if (b.fly === 0 && Math.hypot(b.tx - b.x, b.ty - b.y) > S * 9) b.fly = 1;
        }
        b.a += Math.sin(t * 3 + n) * dt * 2;
        continue;
      }
      if (!b.fly && d > S * 8) b.fly = 1;
      var sp = b.fly ? 130 + (n % 7) * 8 : 18 + (n % 5) * 4;
      /* 目標の近くでは揺れを止めて減速する。そうしないと目標のまわりを回り続ける */
      var near = Math.min(1, d / (S * 3));
      var want = Math.atan2(dy, dx) + near * (b.fly ? Math.sin(t * 2.2 + n) * 0.5 : Math.sin(t * 4 + n * 1.7) * 0.7);
      var da = Math.atan2(Math.sin(want - b.a), Math.cos(want - b.a));
      b.a += da * Math.min(1, dt * (b.fly ? 6 : 9) / Math.max(0.15, near));
      var m = Math.min(d, sp * dt * (0.35 + 0.65 * near) * Math.max(0, Math.cos(da)));
      b.x += Math.cos(b.a) * m; b.y += Math.sin(b.a) * m;
    }
  }

  /* ---------------------------------------------------------- 巣 */
  var growAcc = 0;
  function stepCombs(dt) {
    var g = 0.25 + speed * 1.9;
    for (var k = 0; k < combs.length; k++) {
      var c = combs[k];
      if (c.state === 0) {
        /* 若い巣は速く伸びる。上が空いている時間を短くする */
        c.L += c.Lmax * c.rate * g * dt * (c.L < c.Lmax * 0.45 ? 2.2 : 1);
        if (c.L >= c.Lmax) { c.L = c.Lmax; c.state = 1; c.timer = 0; }
      } else if (c.state === 1) {
        c.timer += dt * g;
        /* 巣が一度に消えて画面が空かないように、薄れるのも若いのも同時に 1 枚まで */
        if (c.timer > 9 && calm(k)) { c.state = 2; c.timer = 0; }
      } else {
        c.timer += dt;
        c.alpha = Math.max(0, 1 - c.timer / 7);
        if (c.alpha <= 0) {
          for (var i = 0; i < N; i++) if (comb[i] === k && keep[i] <= 0) { build[i] = 0; tgt[i] = 0; fill[i] = 0; age[i] = 0; }
          newComb(c, true);
          for (var bi = 0; bi < bees.length; bi++) if (bees[bi].k === k) { pickTarget(bees[bi]); bees[bi].fly = 1; bees[bi].wait = 0; }
          SFX.play('grow', 3000);
        }
      }
    }
    growAcc += dt;
    if (growAcc > 4.5) { growAcc = 0; SFX.play('tick', 2500); }

    var fillRate = 0.035 * g;
    for (var j = 0; j < N; j++) {
      var cj = combs[comb[j]];
      var on = (cj.state !== 2 && inside(j)) || keep[j] > 0;
      if (keep[j] > 0) keep[j] -= dt;
      tgt[j] = on ? 1 : 0;
      if (on) {
        if (build[j] < 1) build[j] = Math.min(1, build[j] + dt * 1.7);
        if (build[j] >= 1) {
          age[j] += dt * g;
          if (age[j] > 7 && seed[j] < 0.82) fill[j] = Math.min(1, fill[j] + dt * fillRate * (0.5 + seed[j]));
        }
      } else if (cj.state !== 2 && build[j] > 0) {
        build[j] = Math.max(0, build[j] - dt * 0.8);
        if (build[j] <= 0) { fill[j] = 0; age[j] = 0; }
      }
      if (glow[j] > 0) glow[j] = Math.max(0, glow[j] - dt * 0.55);
    }
    for (var r = ripples.length - 1; r >= 0; r--) {
      var rp = ripples[r];
      rp.t += dt;
      if (rp.t > 1.3) { ripples.splice(r, 1); continue; }
      var front = rp.t * 300, wid = S * 1.6, amp = 1 - rp.t / 1.3;
      var r0 = Math.max(0, Math.floor((rp.y - front - wid - Y0) / DY)), r1 = Math.min(NR - 1, Math.ceil((rp.y + front + wid - Y0) / DY));
      for (var rr = r0; rr <= r1; rr++) {
        for (var cc = 0; cc < NC; cc++) {
          var q = rr * NC + cc;
          if (build[q] <= 0) continue;
          var e = Math.abs(Math.hypot(cx[q] - rp.x, cy[q] - rp.y) - front);
          if (e < wid) glow[q] = Math.max(glow[q], amp * (1 - e / wid) * 0.9);
        }
      }
    }
  }

  function calm(k) {
    for (var q = 0; q < combs.length; q++) {
      if (q === k) continue;
      if (combs[q].state === 2 || (combs[q].state === 0 && combs[q].L < combs[q].Lmax * 0.4)) return false;
    }
    return true;
  }

  /* 指のまわりに部屋を建てる。外側ほど少し遅れて建つ */
  function raise(x, y, rad, life) {
    var r0 = Math.max(0, Math.floor((y - rad - Y0) / DY)), r1 = Math.min(NR - 1, Math.ceil((y + rad - Y0) / DY));
    for (var r = r0; r <= r1; r++) {
      for (var c = 0; c < NC; c++) {
        var i = r * NC + c, d = Math.hypot(cx[i] - x, cy[i] - y);
        if (d > rad) continue;
        if (combs[comb[i]].state === 2) continue;
        if (build[i] <= 0) build[i] = -d / rad * 0.35;
        keep[i] = Math.max(keep[i], life * (1 - d / rad * 0.4));
        glow[i] = Math.max(glow[i], 1 - d / rad * 0.5);
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  var NB = 8;
  var wallB = [], innerB = [], fillB = [], capB = [], glowB = [];
  function hex(p, x, y, r) {
    p.moveTo(x + HX[0] * r, y + HY[0] * r);
    for (var v = 1; v < 6; v++) p.lineTo(x + HX[v] * r, y + HY[v] * r);
    p.closePath();
  }
  /* 建ちきって静かな部屋と地は別の canvas に数フレームおきに描き、毎フレームは動いている部屋とミツバチだけを描く */
  var layer = document.createElement('canvas'), lctx = layer.getContext('2d'), DPR = 1, frameNo = 0;
  function sizeLayer() {
    layer.width = Math.round(W * DPR); layer.height = Math.round(H * DPR);
    lctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    frameNo = 0;
  }
  function drawCells(g, settled) {
    var k;
    for (k = 0; k < NB; k++) { wallB[k] = new Path2D(); innerB[k] = new Path2D(); fillB[k] = new Path2D(); glowB[k] = new Path2D(); capB[k] = new Path2D(); }
    for (var i = 0; i < N; i++) {
      var b = build[i];
      if (b <= 0) continue;
      var gl = glow[i];
      if (settled !== (b >= 1 && gl <= 0.04)) continue;
      var ca = keep[i] > 0 ? 1 : combs[comb[i]].alpha;
      if (ca <= 0.01) continue;
      var e = 1 - (1 - b) * (1 - b);
      var R = S * 0.9 * e * (1 + 0.16 * gl);
      var x0 = cx[i], y0 = cy[i];
      var wa = Math.min(NB - 1, Math.floor(ca * (0.45 + 0.55 * e) * NB * 0.999));
      hex(wallB[wa], x0, y0, R);
      var f = fill[i];
      if (f > 0.97) hex(capB[Math.min(NB - 1, Math.floor(ca * NB * 0.999))], x0, y0, R * 0.8);
      else {
        hex(innerB[wa], x0, y0, R * (0.55 + 0.15 * f));
        if (f > 0.05) hex(fillB[Math.min(NB - 1, Math.floor(f * ca * NB * 0.999))], x0, y0, R * (0.3 + 0.48 * f));
      }
      if (gl > 0.04) hex(glowB[Math.min(NB - 1, Math.floor(gl * NB * 0.999))], x0, y0, R);
    }
    g.lineWidth = 1;
    for (k = 0; k < NB; k++) {
      var a = (k + 1) / NB;
      g.fillStyle = P.rgba(P.ramp(0.62), a * 0.32); g.fill(fillB[k]);
      g.fillStyle = P.rgba(P.line, a * 0.2); g.fill(capB[k]);
      g.strokeStyle = P.rgba(P.line, a * 0.35); g.stroke(capB[k]);
      g.strokeStyle = P.rgba(P.steel, a * 0.4); g.stroke(innerB[k]);
      g.strokeStyle = P.rgba(P.line, a * 0.62); g.stroke(wallB[k]);
    }
    if (settled) return;
    g.lineWidth = 1.4;
    for (k = 0; k < NB; k++) {
      var ga = (k + 1) / NB;
      g.fillStyle = P.rgba(P.accent, ga * 0.16); g.fill(glowB[k]);
      g.strokeStyle = P.rgba(P.accent, ga * 0.85); g.stroke(glowB[k]);
    }
  }
  function drawLayer() {
    P.fill(lctx, W, H, 1);
    P.grid(lctx, W, H, { step: 32, major: 4, alpha: 0.6 });
    /* 枝 */
    lctx.strokeStyle = P.rgba(P.steel, 0.75);
    lctx.lineWidth = 2;
    lctx.beginPath();
    lctx.moveTo(0, Y0 - S * 1.25);
    for (var x = 0; x <= W; x += 24) lctx.lineTo(x, Y0 - S * 1.25 + Math.sin(x * 0.006 + 1.3) * S * 0.25);
    lctx.stroke();
    drawCells(lctx, true);
  }
  function draw(t) {
    if (frameNo++ % 6 === 0) drawLayer();
    ctx.drawImage(layer, 0, 0, W, H);
    drawCells(ctx, false);

    /* ミツバチ */
    var sc = S / 12;
    var bodyW = new Path2D(), bodyC = new Path2D(), wings = new Path2D();
    for (var n = 0; n < bees.length; n++) {
      var be = bees[n], co = Math.cos(be.a), si = Math.sin(be.a);
      var pth = be.hot > 0.3 ? bodyC : bodyW;
      pth.moveTo(be.x + co * 3.6 * sc, be.y + si * 3.6 * sc);
      pth.ellipse(be.x, be.y, 3.6 * sc, 1.9 * sc, be.a, 0, 6.2832);
      var wl = be.fly ? 3.2 : 2.6, sw = be.fly ? 0.5 + 0.25 * Math.sin(t * 9 + n) : 0.35;
      for (var sd = -1; sd <= 1; sd += 2) {
        var wa2 = be.a + Math.PI + sd * (Math.PI / 2 - sw);
        var wx = be.x + Math.cos(wa2) * wl * sc * 0.8, wy = be.y + Math.sin(wa2) * wl * sc * 0.8;
        wings.moveTo(wx + Math.cos(wa2) * wl * sc, wy + Math.sin(wa2) * wl * sc);
        wings.ellipse(wx, wy, wl * sc, 1.3 * sc, wa2, 0, 6.2832);
      }
    }
    ctx.fillStyle = P.rgba(P.steel, 0.28); ctx.fill(wings);
    ctx.lineWidth = 0.8;
    ctx.strokeStyle = P.rgba(P.line, 0.35); ctx.stroke(wings);
    ctx.fillStyle = P.rgba(P.line, 0.92); ctx.fill(bodyW);
    ctx.fillStyle = P.rgba(P.accent, 0.95); ctx.fill(bodyC);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  var down = false, px = 0, py = 0, lx = 0, ly = 0, holdAcc = 0;
  ctx = P.fit(canvas, function (w, h, dpr) { W = w; H = h; DPR = dpr || 1; alloc(); sizeLayer(); });

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = lx = e.clientX; py = ly = e.clientY; holdAcc = 0;
    raise(px, py, S * 5.2, 14);
    ripples.push({ x: px, y: py, t: 0 });
    SFX.play('spawn', 120);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    px = e.clientX; py = e.clientY;
    var dist = Math.hypot(px - lx, py - ly);
    if (dist < S) return;
    var n = Math.ceil(dist / S);
    for (var i = 1; i <= n; i++) raise(lx + (px - lx) * i / n, ly + (py - ly) * i / n, S * 2.4, 11);
    lx = px; ly = py;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = this.value / 100; });
  document.getElementById('k-bees').addEventListener('input', function () { beeAmt = this.value / 100; makeBees(); });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = document.getElementById('k-speed').value / 100;
  beeAmt = document.getElementById('k-bees').value / 100;
  makeBees();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), T = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now; T += dt;
    if (down) {
      holdAcc += dt;
      if (holdAcc > 0.25) { holdAcc = 0; raise(px, py, S * 3, 10); }
    }
    stepCombs(dt);
    stepBees(dt, T);
    draw(T);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
