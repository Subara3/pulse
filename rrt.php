<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('rrt');

$pageName = 'rrt';
$pageTitle = 'RRT - PULSE';
$pageDesc = '1つの点から白い直線の枝が四方へ伸び、丸い岩をよけながら画面を埋めていく。枝が水色の点に届くと、根元からそこまでの道が光り、次の木がその点から伸びはじめる。押すと枝がそこへ向かって一斉に伸び、なぞると指を追いかける。';
$pageCanonical = 'https://pulse.subara3.com/rrt.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-stride">STRIDE</label><input type="range" id="k-stride" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-rocks">ROCKS</label><input type="range" id="k-rocks" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 木は世代ごとに持つ。古い世代は薄れて消え、新しい世代がその上に伸びる */
  var MAXN = 7000;
  var gen = null, fading = [];
  var goal = { x: 0, y: 0 };
  var speed = 0.4, stride = 0.45, rocks = 0.5;
  var down = false, last = null, clock = 0;
  var pathIdx = new Int32Array(MAXN), pathLen = 0, travel = 0;

  function unit() { return Math.min(W, H); }
  function stepLen() { return Math.max(8, unit() * (0.014 + stride * 0.024)); }

  function inRock(rs, x, y, pad) {
    for (var i = 0; i < rs.length; i++) {
      var r = rs[i];
      if (Math.hypot(x - r.x, y - r.y) < r.r + pad) return true;
    }
    return false;
  }
  /* 線分が岩に触れるか */
  function hits(rs, ax, ay, bx, by) {
    var dx = bx - ax, dy = by - ay, L = dx * dx + dy * dy || 1;
    for (var i = 0; i < rs.length; i++) {
      var r = rs[i];
      var t = ((r.x - ax) * dx + (r.y - ay) * dy) / L;
      t = t < 0 ? 0 : t > 1 ? 1 : t;
      var px = ax + dx * t - r.x, py = ay + dy * t - r.y;
      if (px * px + py * py < (r.r + 3) * (r.r + 3)) return true;
    }
    return false;
  }

  function newGen(rx, ry) {
    var u = unit(), s = stepLen();
    var g = {
      x: new Float32Array(MAXN), y: new Float32Array(MAXN),
      par: new Int32Array(MAXN), cost: new Float32Array(MAXN), born: new Float32Array(MAXN),
      n: 1, s: s, rocks: [], alpha: 1, reached: false, reachT: 0,
      cap: Math.min(MAXN, Math.round(W * H / (s * s) * 0.75))
    };
    var nr = Math.round(rocks * 9), tries = 0;
    while (g.rocks.length < nr && tries++ < 200) {
      var rr = u * (0.05 + Math.random() * 0.07);
      var ox = rr + Math.random() * (W - rr * 2), oy = rr + Math.random() * (H - rr * 2);
      if (Math.hypot(ox - rx, oy - ry) < rr + s * 3) continue;
      if (inRock(g.rocks, ox, oy, rr + s * 2)) continue;
      g.rocks.push({ x: ox, y: oy, r: rr });
    }
    g.x[0] = rx; g.y[0] = ry; g.par[0] = -1; g.cost[0] = 0; g.born[0] = clock;
    gen = g;
    /* 次に目指す点: 根から離れた、岩の外の場所 */
    for (var k = 0; k < 60; k++) {
      var gx = W * (0.08 + Math.random() * 0.84), gy = H * (0.08 + Math.random() * 0.84);
      if (Math.hypot(gx - rx, gy - ry) > u * 0.45 && !inRock(g.rocks, gx, gy, s)) { goal.x = gx; goal.y = gy; break; }
    }
    pathLen = 0; travel = 0;
  }

  /* 1 本伸ばす。近い枝の中から、根からの道がいちばん短くなる親を選び、まわりの枝も付け替える（RRT*） */
  function grow(sx, sy) {
    var g = gen;
    if (g.n >= MAXN) return;
    var x = g.x, y = g.y, n = g.n, best = 0, bd = 1e18, i;
    for (i = 0; i < n; i++) {
      var dx = x[i] - sx, dy = y[i] - sy, d = dx * dx + dy * dy;
      if (d < bd) { bd = d; best = i; }
    }
    bd = Math.sqrt(bd);
    if (bd < 1) return;
    var k = Math.min(1, g.s / bd);
    var nx = x[best] + (sx - x[best]) * k, ny = y[best] + (sy - y[best]) * k;
    if (nx < 2 || ny < 2 || nx > W - 2 || ny > H - 2) return;
    if (hits(g.rocks, x[best], y[best], nx, ny)) return;
    var R = g.s * 2.2, R2 = R * R, par = best;
    var c = g.cost[best] + Math.hypot(nx - x[best], ny - y[best]);
    var nb = [];
    for (i = 0; i < n; i++) {
      var ex = x[i] - nx, ey = y[i] - ny, e2 = ex * ex + ey * ey;
      if (e2 > R2) continue;
      if (e2 < g.s * g.s * 0.06) return;   /* すでに枝がある場所には足さない */
      nb.push(i);
      var ci = g.cost[i] + Math.sqrt(e2);
      if (ci < c && !hits(g.rocks, x[i], y[i], nx, ny)) { c = ci; par = i; }
    }
    x[n] = nx; y[n] = ny; g.par[n] = par; g.cost[n] = c; g.born[n] = clock;
    g.n = n + 1;
    for (var j = 0; j < nb.length; j++) {
      var m = nb[j];
      if (m === par || m === 0) continue;
      var cm = c + Math.hypot(x[m] - nx, y[m] - ny);
      if (cm < g.cost[m] && !hits(g.rocks, x[m], y[m], nx, ny)) { g.par[m] = n; g.cost[m] = cm; }
    }
  }

  function sampleFree() {
    var g = gen, m = g.s * 0.5;
    if (Math.random() < 0.08) return [goal.x + (Math.random() - 0.5) * g.s, goal.y + (Math.random() - 0.5) * g.s];
    return [m + Math.random() * (W - m * 2), m + Math.random() * (H - m * 2)];
  }
  /* 指のまわりへ向けて伸ばす */
  function sampleNear(px, py, rad) {
    var a = Math.random() * Math.PI * 2, r = rad * Math.sqrt(Math.random());
    return [px + Math.cos(a) * r, py + Math.sin(a) * r];
  }
  function burst(px, py, count, rad) {
    for (var i = 0; i < count; i++) {
      var p = Math.random() < 0.55 ? [px, py] : sampleNear(px, py, rad);
      grow(p[0], p[1]);
    }
  }

  /* 目指す点にいちばん短い道で着く枝を探し、根までの道を拾う */
  function findPath() {
    var g = gen, R = g.s * 1.6, R2 = R * R, best = -1, bc = 1e18;
    for (var i = 0; i < g.n; i++) {
      var dx = g.x[i] - goal.x, dy = g.y[i] - goal.y, d2 = dx * dx + dy * dy;
      if (d2 > R2) continue;
      var c = g.cost[i] + Math.sqrt(d2);
      if (c < bc) { bc = c; best = i; }
    }
    pathLen = 0;
    if (best < 0) return false;
    var k = best;
    while (k >= 0 && pathLen < g.n) { pathIdx[pathLen++] = k; k = g.par[k]; }
    return true;
  }

  function resetAll() {
    fading.length = 0;
    newGen(W * (0.2 + Math.random() * 0.6), H * (0.2 + Math.random() * 0.6));
    /* 開いた瞬間から木が見えるよう、先に育てておく */
    for (var i = 0; i < gen.cap * 0.35; i++) { var p = sampleFree(); grow(p[0], p[1]); }
  }

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; resetAll(); });

  /* ---------------------------------------------------------- 描く */
  function drawRocks(g, a) {
    ctx.setLineDash([4, 5]);
    ctx.lineWidth = 1;
    for (var i = 0; i < g.rocks.length; i++) {
      var r = g.rocks[i];
      ctx.fillStyle = P.rgba(P.deep, 0.55 * a);
      ctx.strokeStyle = P.rgba(P.steel, 0.55 * a);
      ctx.beginPath(); ctx.arc(r.x, r.y, r.r, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
    }
    ctx.setLineDash([]);
  }
  function drawTree(g, a) {
    var x = g.x, y = g.y, par = g.par, i, p, age;
    ctx.lineWidth = 1;
    /* 落ち着いた枝 */
    ctx.strokeStyle = P.rgba(P.line, 0.34 * a);
    ctx.beginPath();
    for (i = 1; i < g.n; i++) {
      if (clock - g.born[i] < 0.8) continue;
      p = par[i]; ctx.moveTo(x[p], y[p]); ctx.lineTo(x[i], y[i]);
    }
    ctx.stroke();
    /* 伸びたばかりの枝は明るく */
    ctx.strokeStyle = P.rgba(P.line, 0.85 * a);
    ctx.beginPath();
    for (i = 1; i < g.n; i++) {
      age = clock - g.born[i];
      if (age >= 0.8) continue;
      p = par[i]; ctx.moveTo(x[p], y[p]); ctx.lineTo(x[i], y[i]);
    }
    ctx.stroke();
    ctx.fillStyle = P.rgba(P.line, 0.9 * a);
    for (i = 1; i < g.n; i++) {
      age = clock - g.born[i];
      if (age < 0.5) ctx.fillRect(x[i] - 1, y[i] - 1, 2, 2);
    }
  }
  function drawPath(g) {
    if (pathLen < 2) return;
    var x = g.x, y = g.y, i, k;
    ctx.lineJoin = 'round';
    ctx.strokeStyle = P.rgba(P.accent, 0.18);
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.moveTo(goal.x, goal.y);
    for (i = 0; i < pathLen; i++) { k = pathIdx[i]; ctx.lineTo(x[k], y[k]); }
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.accent, 0.8);
    ctx.lineWidth = 1.6;
    ctx.stroke();
    /* 根から目指す点へ、光の粒が道をたどる */
    var t = (travel % 1), at = Math.floor(t * (pathLen - 1)), f = t * (pathLen - 1) - at;
    var a0 = pathIdx[pathLen - 1 - at], a1 = pathIdx[Math.max(0, pathLen - 2 - at)];
    var lx = x[a0] + (x[a1] - x[a0]) * f, ly = y[a0] + (y[a1] - y[a0]) * f;
    ctx.fillStyle = P.rgba(P.line, 0.95);
    ctx.beginPath(); ctx.arc(lx, ly, 2.4, 0, Math.PI * 2); ctx.fill();
  }
  function drawMarks(g) {
    var rx = g.x[0], ry = g.y[0];
    ctx.strokeStyle = P.rgba(P.line, 0.8);
    ctx.lineWidth = 1.2;
    ctx.beginPath(); ctx.arc(rx, ry, 5, 0, Math.PI * 2); ctx.stroke();
    ctx.fillStyle = P.rgba(P.line, 0.95);
    ctx.beginPath(); ctx.arc(rx, ry, 2, 0, Math.PI * 2); ctx.fill();
    var pulse = 0.5 + 0.5 * Math.sin(clock * 1.6);
    ctx.strokeStyle = P.rgba(P.accent, 0.35 + 0.3 * pulse);
    ctx.beginPath(); ctx.arc(goal.x, goal.y, 8 + pulse * 3, 0, Math.PI * 2); ctx.stroke();
    ctx.fillStyle = P.rgba(P.accent, 0.95);
    ctx.beginPath(); ctx.arc(goal.x, goal.y, 3, 0, Math.PI * 2); ctx.fill();
  }

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e);
    goal.x = last.x; goal.y = last.y;
    gen.reached = false;
    burst(last.x, last.y, 160, unit() * 0.12);   /* 押した瞬間に、枝がそこへ向けて一斉に伸びる */
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e);
    if (Math.hypot(p.x - last.x, p.y - last.y) < 4) return;
    last = p; goal.x = p.x; goal.y = p.y;
    burst(p.x, p.y, 30, unit() * 0.06);
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readCtl() {
    speed = document.getElementById('k-speed').value / 100;
    stride = document.getElementById('k-stride').value / 100;
    rocks = document.getElementById('k-rocks').value / 100;
  }
  document.getElementById('k-speed').addEventListener('input', readCtl);
  document.getElementById('k-stride').addEventListener('input', readCtl);
  document.getElementById('k-rocks').addEventListener('input', readCtl);
  readCtl();   /* 共有リンクから開いたとき、復元された値を拾う */
  resetAll();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now; clock += dt;
    var g = gen;

    acc += dt * (90 + speed * 600);
    var lim = down ? MAXN : g.cap;
    while (acc > 1) {
      acc -= 1;
      if (g.n < lim) { var p = sampleFree(); grow(p[0], p[1]); }
    }
    if (down) burst(goal.x, goal.y, 6, unit() * 0.05);

    var ok = findPath();
    if (ok && !g.reached) { g.reached = true; g.reachT = clock; travel = 0; SFX.play('ripple'); }
    if (!ok) g.reached = false;
    if (ok) travel += dt * Math.min(0.6, 260 / Math.max(1, pathLen * g.s));

    /* 着いてしばらくたつか、木が画面を埋めたら、目指した点から次の木を伸ばす */
    if (!down && ((g.reached && clock - g.reachT > 5) || g.n >= g.cap)) {
      g.alpha = 1;
      fading.push(g);
      if (fading.length > 2) fading.shift();
      newGen(goal.x, goal.y);
      SFX.play('grow');
    }

    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.7 });
    for (var i = fading.length - 1; i >= 0; i--) {
      var f = fading[i];
      f.alpha -= dt / 3.5;
      if (f.alpha <= 0) { fading.splice(i, 1); continue; }
      drawRocks(f, f.alpha); drawTree(f, f.alpha * 0.8);
    }
    var fin = Math.min(1, (clock - gen.born[0]) / 1.2);
    drawRocks(gen, fin);
    drawTree(gen, 1);
    drawPath(gen);
    drawMarks(gen);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
