<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('sashiko');

$pageName = 'sashiko';
$pageTitle = 'SASHIKO - PULSE';
$pageDesc = '藍の布に白い糸の短い縫い目が縦横に並び、段ごとに縫い目を1目ずらすかどうかで、つながった線が四角や十字の輪を描く。針が1段ずつ縫い直すたびに模様が組み変わり、押すとそこを通る縦と横の段が水色の糸で縫い直され、なぞると指が横切った段が次々に縫い直される。';
$pageCanonical = 'https://pulse.subara3.com/sashiko.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 格子の点 (c, r)。横の段 r の縫い目 (c→c+1) は (c + a[r]) が偶数のとき表に出る。
     縦の段 c の縫い目 (r→r+1) は (r + b[c]) が偶数のとき。段ごとの 1 ビットだけで模様が決まる */
  var s = 24, NC = 0, NR = 0, ox = 0, oy = 0;
  var a, b, ta, tb;                 /* いまの段のずれと、次に縫いたい段のずれ */
  var hOn, hP, hG, vOn, vP, vG;     /* 縫い目ごとの 表/裏・見えている長さ・光り */
  var needles = [];
  var speed = 0.45, size = 0.5;
  var time = 0, nextTheme = 4, nextNeedle = 0.3;
  var down = false, fx = 0, fy = 0, lastC = -1, lastR = -1;

  /* 段のずれの並べ方。中心で折り返して、左右・上下がそろった模様にする */
  function theme(n) {
    var out = new Uint8Array(n), mid = n >> 1, kind = Math.floor(Math.random() * 5);
    var p = 2 + Math.floor(Math.random() * 5), q = 1 + Math.floor(Math.random() * (p - 1));
    var phi = 1.618034 + Math.random() * 0.4, off = Math.random();
    var rnd = [];
    for (var i = 0; i < n; i++) rnd.push(Math.random() < 0.5 ? 1 : 0);
    for (i = 0; i < n; i++) {
      var k = Math.abs(i - mid), v;
      if (kind === 0) v = (k % p) < q ? 1 : 0;
      else if (kind === 1) v = Math.floor((k + 1 + off) * phi) - Math.floor((k + off) * phi) - 1;
      else if (kind === 2) { var t = k, c = 0; while (t) { c ^= t & 1; t >>= 1; } v = c; }
      else if (kind === 3) v = rnd[k];
      else v = (k * k) % p < q ? 1 : 0;
      out[i] = v & 1;
    }
    return out;
  }

  function alloc() {
    var scale = Math.max(0.75, Math.min(1.25, Math.min(W, H) / 760));
    s = (34 - size * 18) * scale;
    NC = Math.ceil(W / s) + 3; NR = Math.ceil(H / s) + 3;
    ox = (W - (NC - 1) * s) / 2; oy = (H - (NR - 1) * s) / 2;
    var n = NC * NR;
    hOn = new Uint8Array(n); hP = new Float32Array(n); hG = new Float32Array(n);
    vOn = new Uint8Array(n); vP = new Float32Array(n); vG = new Float32Array(n);
    a = theme(NR); b = theme(NC); ta = theme(NR); tb = theme(NC);
    for (var r = 0; r < NR; r++) for (var c = 0; c < NC; c++) {
      var i = r * NC + c;
      hOn[i] = ((c + a[r]) & 1) === 0 ? 1 : 0; hP[i] = hOn[i];
      vOn[i] = ((r + b[c]) & 1) === 0 ? 1 : 0; vP[i] = vOn[i];
    }
    needles.length = 0;
  }

  /* 針: 1 本の段を端から端へ（または途中から外へ）進み、通った縫い目をいまの a / b に合わせる */
  function launch(kind, line, from, dir, accent) {
    needles.push({ k: kind, l: line, pos: from, last: Math.floor(from) - dir, dir: dir, acc: accent, life: 0 });
  }

  function apply(nd, idx) {
    var i, on;
    if (nd.k === 0) {
      if (idx < 0 || idx >= NC - 1) return;
      i = nd.l * NC + idx; on = ((idx + a[nd.l]) & 1) === 0 ? 1 : 0;
      if (hOn[i] !== on) { hOn[i] = on; hP[i] = on ? 0 : hP[i]; }
      if (nd.acc) hG[i] = 1;
    } else {
      if (idx < 0 || idx >= NR - 1) return;
      i = idx * NC + nd.l; on = ((idx + b[nd.l]) & 1) === 0 ? 1 : 0;
      if (vOn[i] !== on) { vOn[i] = on; vP[i] = on ? 0 : vP[i]; }
      if (nd.acc) vG[i] = 1;
    }
  }

  function stepNeedles(dt) {
    var v = (3 + speed * 9) * dt;
    for (var n = needles.length - 1; n >= 0; n--) {
      var nd = needles[n];
      nd.life += dt;
      nd.pos += nd.dir * v * (nd.acc ? 2.6 : 1);
      var cur = Math.floor(nd.pos);
      while (nd.last !== cur) { nd.last += nd.dir; apply(nd, nd.last); }
      var len = nd.k === 0 ? NC : NR;
      if (nd.pos < -2 || nd.pos > len + 1) needles.splice(n, 1);
    }
  }

  /* 次に縫い直す段を選ぶ。ねらいの並びと違う段から */
  function autoNeedle() {
    var cand = [];
    for (var r = 0; r < NR; r++) if (a[r] !== ta[r]) cand.push(r);
    for (var c = 0; c < NC; c++) if (b[c] !== tb[c]) cand.push(NR + c);
    if (!cand.length) { nextTheme = Math.min(nextTheme, 1.5); return; }
    var pick = cand[Math.floor(Math.random() * cand.length)];
    var busy = needles.some(function (nd) { return (nd.k === 0 ? nd.l : NR + nd.l) === pick; });
    if (busy) return;
    var dir = Math.random() < 0.5 ? 1 : -1;
    if (pick < NR) { a[pick] = ta[pick]; launch(0, pick, dir > 0 ? -1 : NC - 1, dir, false); }
    else { var cc = pick - NR; b[cc] = tb[cc]; launch(1, cc, dir > 0 ? -1 : NR - 1, dir, false); }
    SFX.play('tick', 1800);
  }

  /* 指の通る段を裏返して、指の位置から両側へ縫い直す */
  function flipRow(r, c) {
    if (r < 0 || r >= NR) return;
    a[r] ^= 1; ta[r] = a[r];
    launch(0, r, c + 0.5, 1, true); launch(0, r, c + 0.49, -1, true);
  }
  function flipCol(c, r) {
    if (c < 0 || c >= NC) return;
    b[c] ^= 1; tb[c] = b[c];
    launch(1, c, r + 0.5, 1, true); launch(1, c, r + 0.49, -1, true);
  }

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 描く */
  var LN = P.line, AC = P.accent, ST = P.steel;
  function seg(path, x0, y0, x1, y1, p) {
    path.moveTo(x0, y0);
    path.lineTo(x0 + (x1 - x0) * p, y0 + (y1 - y0) * p);
  }

  function draw(dt) {
    P.fill(ctx, W, H, 1);
    var gap = s * 0.17, lw = Math.max(1.6, s * 0.1);
    /* 布の針穴 */
    ctx.fillStyle = P.rgba(ST, 0.28);
    for (var r = 0; r < NR; r++) for (var c = 0; c < NC; c++) ctx.fillRect(ox + c * s - 0.75, oy + r * s - 0.75, 1.5, 1.5);

    var grow = dt * 5, R = s * 3.2;
    var main = new Path2D(), lit = [];
    for (r = 0; r < NR; r++) {
      var y = oy + r * s;
      for (c = 0; c < NC; c++) {
        var i = r * NC + c, x = ox + c * s, p, g, d;
        if (down) {
          d = Math.hypot(x + s * 0.5 - fx, y - fy);
          if (d < R) hG[i] = Math.max(hG[i], (1 - d / R) * 0.8);
          d = Math.hypot(x - fx, y + s * 0.5 - fy);
          if (d < R) vG[i] = Math.max(vG[i], (1 - d / R) * 0.8);
        }
        if (c < NC - 1) {
          p = hP[i] = hOn[i] ? Math.min(1, hP[i] + grow) : Math.max(0, hP[i] - grow);
          g = hG[i] = Math.max(0, hG[i] - dt * 0.45);
          if (p > 0.01) {
            if (g > 0.04) lit.push(0, x + gap, y, x + s - gap, y, p, g);
            else seg(main, x + gap, y, x + s - gap, y, p);
          }
        }
        if (r < NR - 1) {
          p = vP[i] = vOn[i] ? Math.min(1, vP[i] + grow) : Math.max(0, vP[i] - grow);
          g = vG[i] = Math.max(0, vG[i] - dt * 0.45);
          if (p > 0.01) {
            if (g > 0.04) lit.push(1, x, y + gap, x, y + s - gap, p, g);
            else seg(main, x, y + gap, x, y + s - gap, p);
          }
        }
      }
    }
    ctx.lineCap = 'round';
    ctx.lineWidth = lw;
    ctx.strokeStyle = P.rgba(LN, 0.9);
    ctx.stroke(main);
    for (var k = 0; k < lit.length; k += 7) {
      var gg = lit[k + 6];
      ctx.strokeStyle = P.rgba(P.mix(LN, AC, Math.min(1, gg * 1.2)), 0.92);
      ctx.beginPath();
      ctx.moveTo(lit[k + 1], lit[k + 2]);
      ctx.lineTo(lit[k + 1] + (lit[k + 3] - lit[k + 1]) * lit[k + 5], lit[k + 2] + (lit[k + 4] - lit[k + 2]) * lit[k + 5]);
      ctx.stroke();
    }
    /* 針と、針の後ろに伸びる糸 */
    ctx.lineCap = 'butt';
    for (var n = 0; n < needles.length; n++) {
      var nd = needles[n];
      var hx, hy, ex, ey, tx, ty, L = s * 1.5;
      if (nd.k === 0) {
        hx = ox + nd.pos * s; hy = oy + nd.l * s;
        ex = hx - nd.dir * L; ey = hy; tx = ex - nd.dir * s * 1.2; ty = hy + s * 0.45;
      } else {
        hx = ox + nd.l * s; hy = oy + nd.pos * s;
        ex = hx; ey = hy - nd.dir * L; tx = hx + s * 0.45; ty = ey - nd.dir * s * 1.2;
      }
      var col = nd.acc ? AC : LN, fade = Math.min(1, nd.life * 4);
      ctx.strokeStyle = P.rgba(col, 0.85 * fade);
      ctx.lineWidth = 1.4;
      ctx.beginPath(); ctx.moveTo(hx, hy); ctx.lineTo(ex, ey); ctx.stroke();
      ctx.beginPath(); ctx.arc(ex, ey, 1.8, 0, Math.PI * 2); ctx.stroke();
      ctx.strokeStyle = P.rgba(col, 0.4 * fade);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(ex, ey); ctx.quadraticCurveTo(tx, ty, ex - (hx - ex) * 1.4, ey - (hy - ey) * 1.4); ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  function cellOf(x, y) { return { c: Math.round((x - ox) / s), r: Math.round((y - oy) / s) }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; fx = e.clientX; fy = e.clientY;
    var q = cellOf(fx, fy);
    flipRow(q.r, q.c); flipCol(q.c, q.r);
    lastC = q.c; lastR = q.r;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    fx = e.clientX; fy = e.clientY;
    var q = cellOf(fx, fy), moved = false;
    while (lastR !== q.r) { lastR += lastR < q.r ? 1 : -1; flipRow(lastR, q.c); moved = true; }
    while (lastC !== q.c) { lastC += lastC < q.c ? 1 : -1; flipCol(lastC, q.r); moved = true; }
    if (moved) SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kSize = document.getElementById('k-size');
  kSpeed.addEventListener('input', function () { speed = this.value / 100; });
  kSize.addEventListener('change', function () { size = this.value / 100; alloc(); SFX.play('toggle'); });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = kSpeed.value / 100;
  if (Math.abs(kSize.value / 100 - size) > 0.001) { size = kSize.value / 100; alloc(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    time += dt;
    nextTheme -= dt;
    if (nextTheme <= 0) {
      var ch = Math.random();
      if (ch < 0.7) ta = theme(NR);
      if (ch > 0.3) tb = theme(NC);
      nextTheme = 16 + Math.random() * 10;
    }
    nextNeedle -= dt;
    if (nextNeedle <= 0) {
      if (needles.length < 8) autoNeedle();
      nextNeedle = (0.9 - speed * 0.5) * (0.6 + Math.random() * 0.8);
    }
    stepNeedles(dt);
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
