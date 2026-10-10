<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('dla');

$pageName = 'dla';
$pageTitle = 'DLA - PULSE';
$pageDesc = '小さな粒がでたらめに動き回り、円の縁や先に止まった粒にふれるとそこで止まる。止まった粒が積もって、中心へ向かう枝が伸びていく。押すとそこに粒が集まって小さな枝がすぐに育ち、なぞると指の跡に沿って枝の列ができる。';
$pageCanonical = 'https://pulse.subara3.com/dla.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-stick">STICK</label><input type="range" id="k-stick" min="0" max="100" value="30"></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;
  var C = 3;                              /* 1 マスの大きさ (px) */
  var CAP = 60000;
  var GX, GY, occ, R, cxg, cyg, B = 6, BX, BY, bocc;
  var px = new Int16Array(CAP), py = new Int16Array(CAP), par = new Int32Array(CAP),
      born = new Float32Array(CAP), mine = new Uint8Array(CAP), lin = new Uint8Array(CAP),
      jx = new Float32Array(CAP), jy = new Float32Array(CAP), np = 0;
  var WMAX = 5000;
  var wx = new Int16Array(WMAX), wy = new Int16Array(WMAX), wc = new Float32Array(WMAX), nw = 0;
  var flow = 0.5, stick = 0.2, T = 0, pace = 3, inner = 1e9, fade = 0, marks = [];

  function alloc() {
    GX = Math.ceil(W / C) + 1; GY = Math.ceil(H / C) + 1;
    occ = new Int32Array(GX * GY);
    BX = Math.ceil(GX / B) + 1; BY = Math.ceil(GY / B) + 1;
    bocc = new Uint8Array(BX * BY);
    cxg = (W / C / 2) | 0; cyg = (H / C / 2) | 0;
    R = Math.min(W, H) * 0.44 / C;
    reset(true);
  }

  function addP(x, y, parent, touched) {
    if (np >= CAP) return -1;
    px[np] = x; py[np] = y; par[np] = parent; born[np] = T; mine[np] = touched ? 1 : 0;
    jx[np] = (Math.random() - 0.5) * C * 0.7; jy[np] = (Math.random() - 0.5) * C * 0.7;
    /* 外輪から伸びた系統かどうか。触って置いた芯から育った枝は、終わりの判定に数えない */
    lin[np] = parent < 0 ? (touched ? 0 : 1) : lin[parent];
    occ[y * GX + x] = np + 1;
    bocc[((y / B) | 0) * BX + ((x / B) | 0)] = 1;
    var dx = x - cxg, dy = y - cyg, r = Math.sqrt(dx * dx + dy * dy);
    if (lin[np] && r < inner) inner = r;
    return np++;
  }

  function reset(pre) {
    occ.fill(0); bocc.fill(0); np = 0; inner = 1e9; fade = 0;
    /* 外側の輪。2 マスの厚みで閉じておく */
    var n = Math.ceil(R * Math.PI * 2 * 1.6);
    for (var k = 0; k < n; k++) {
      var a = k / n * Math.PI * 2;
      for (var t = 0; t < 2; t++) {
        var x = Math.round(cxg + Math.cos(a) * (R + t)), y = Math.round(cyg + Math.sin(a) * (R + t));
        if (x > 0 && y > 0 && x < GX - 1 && y < GY - 1 && !occ[y * GX + x]) addP(x, y, -1, false);
      }
    }
    for (k = 0; k < np; k++) born[k] = -1000;
    inner = 1e9;
    for (k = 0; k < np; k++) { var dx = px[k] - cxg, dy = py[k] - cyg; var r = Math.sqrt(dx * dx + dy * dy); if (r < inner) inner = r; }
    nw = 0;
    var want = target();
    for (k = 0; k < want; k++) {
      /* 最初は輪の中に散らしておき、すぐに積もりはじめるようにする */
      var rr = R * Math.sqrt(Math.random()) * 0.97, aa = Math.random() * Math.PI * 2;
      if (!pre) rr *= 0.1;
      spawnAt(cxg + Math.cos(aa) * rr, cyg + Math.sin(aa) * rr, 0);
    }
    if (pre) {
      var t0 = performance.now(), n0 = np;
      while (performance.now() - t0 < 700 && np - n0 < 3000) walk(2);
    }
  }

  function target() { return Math.round(500 + flow * 1400); }

  function spawnAt(x, y, cyan) {
    if (nw >= WMAX) return;
    var ix = Math.round(x), iy = Math.round(y);
    if (occ[iy * GX + ix]) return;
    wx[nw] = ix; wy[nw] = iy; wc[nw] = cyan; nw++;
  }
  function respawn(i) {
    var a = Math.random() * Math.PI * 2, r = Math.random() * 4;
    wx[i] = Math.round(cxg + Math.cos(a) * r); wy[i] = Math.round(cyg + Math.sin(a) * r); wc[i] = 0;
  }

  /* 酔歩: 1 マスずつでたらめに動き、8 近傍に粒があれば確率で止まる */
  /* まわりの区画が空なら、何歩ぶんかをまとめて大きく跳ぶ（見た目は同じで速い） */
  var stuck = 0, rot = 0;
  function walk(steps, part) {
    var Rl = (R + 1) * (R + 1);
    stuck = 0;
    var cnt = part == null ? nw : Math.max(1, Math.round(nw * part));
    for (var c = 0; c < cnt; c++) {
      var i = (rot + c) % nw;
      var x = wx[i], y = wy[i];
      for (var s = 0; s < steps; s++) {
        var bx = (x / B) | 0, by = (y / B) | 0, nx, ny;
        if (bx > 0 && by > 0 && bx < BX - 1 && by < BY - 1) {
          var bk = by * BX + bx;
          if (!(bocc[bk] | bocc[bk - 1] | bocc[bk + 1] | bocc[bk - BX] | bocc[bk + BX] | bocc[bk - BX - 1] | bocc[bk - BX + 1] | bocc[bk + BX - 1] | bocc[bk + BX + 1])) {
            var ja = Math.random() * 6.2832;
            nx = Math.round(x + Math.cos(ja) * (B - 1)); ny = Math.round(y + Math.sin(ja) * (B - 1));
            var jx = nx - cxg, jy = ny - cyg;
            if (jx * jx + jy * jy <= Rl) { x = nx; y = ny; }
            continue;
          }
        }
        var d = (Math.random() * 4) | 0;
        nx = x + (d === 0 ? 1 : d === 1 ? -1 : 0); ny = y + (d === 2 ? 1 : d === 3 ? -1 : 0);
        var dx = nx - cxg, dy = ny - cyg;
        if (dx * dx + dy * dy > Rl || nx < 1 || ny < 1 || nx >= GX - 1 || ny >= GY - 1) { continue; }
        var k = ny * GX + nx;
        if (occ[k]) continue;
        x = nx; y = ny;
        var o = occ[k - 1] || occ[k + 1] || occ[k - GX] || occ[k + GX] || occ[k - GX - 1] || occ[k - GX + 1] || occ[k + GX - 1] || occ[k + GX + 1];
        if (o && Math.random() < stick) {
          var id = addP(x, y, o - 1, wc[i] > 0);
          stuck++;
          if (id >= 0 && Math.random() < 0.004) SFX.play('tick', 2500);
          respawn(i); x = wx[i]; y = wy[i];
          break;
        }
      }
      wx[i] = x; wy[i] = y;
      if (wc[i] > 0) wc[i] -= 0.004;
    }
  }

  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  function toGrid(e) { return { x: e.clientX / C, y: e.clientY / C }; }
  function clampIn(p) {
    var dx = p.x - cxg, dy = p.y - cyg, r = Math.sqrt(dx * dx + dy * dy), m = R * 0.94;
    if (r > m) { p.x = cxg + dx / r * m; p.y = cyg + dy / r * m; }
    return p;
  }
  function pour(p, n) {
    for (var i = 0; i < n; i++) {
      var a = Math.random() * 6.2832, r = Math.random() * 5;
      spawnAt(p.x + Math.cos(a) * r, p.y + Math.sin(a) * r, 1);
    }
  }
  /* 押したところでは、その場で粒を集めて小さな枝をすぐに育てる */
  var jobs = [];
  function burst(gx, gy, n) {
    var ix = Math.round(gx), iy = Math.round(gy);
    if (ix < 2 || iy < 2 || ix >= GX - 2 || iy >= GY - 2) return;
    if (!occ[iy * GX + ix]) { var id0 = addP(ix, iy, -1, true); if (id0 >= 0) par[id0] = id0; }
    jobs.push({ x: ix, y: iy, left: n, r: 2 });
    if (jobs.length > 40) jobs.shift();
    /* 最初のひとかたまりはその場で育てて、押した瞬間に見えるようにする */
    for (var z = 0; z < 6; z++) runJobs(6);
  }
  function runJobs(budget) {
    var t0 = performance.now();
    for (var q = jobs.length - 1; q >= 0; q--) {
      var jb = jobs[q], per = Math.min(jb.left, 30);
      for (var m = 0; m < per; m++) {
        var stuckOne = false;
        for (var tries = 0; tries < 6 && !stuckOne; tries++) {
          var a = Math.random() * 6.2832, rr = jb.r + 3;
          var x = Math.round(jb.x + Math.cos(a) * rr), y = Math.round(jb.y + Math.sin(a) * rr);
          for (var st = 0; st < 260; st++) {
            /* 押したところへ少し引き寄せられながら歩く */
            var nx, ny;
            if (Math.random() < 0.38) {
              var ex = jb.x - x, ey = jb.y - y;
              if (Math.abs(ex) > Math.abs(ey)) { nx = x + (ex > 0 ? 1 : -1); ny = y; } else { nx = x; ny = y + (ey > 0 ? 1 : -1); }
            } else {
              var d = (Math.random() * 4) | 0;
              nx = x + (d === 0 ? 1 : d === 1 ? -1 : 0); ny = y + (d === 2 ? 1 : d === 3 ? -1 : 0);
            }
            var ddx = nx - jb.x, ddy = ny - jb.y;
            if (ddx * ddx + ddy * ddy > (rr + 10) * (rr + 10)) break;
            if (nx < 1 || ny < 1 || nx >= GX - 1 || ny >= GY - 1) break;
            var rcx = nx - cxg, rcy = ny - cyg;
            if (rcx * rcx + rcy * rcy > (R - 1) * (R - 1)) break;
            var k = ny * GX + nx;
            if (occ[k]) continue;
            x = nx; y = ny;
            var o = occ[k - 1] || occ[k + 1] || occ[k - GX] || occ[k + GX] || occ[k - GX - 1] || occ[k - GX + 1] || occ[k + GX - 1] || occ[k + GX + 1];
            if (o && mine[o - 1]) {
              addP(x, y, o - 1, true);
              var dr = Math.sqrt((x - jb.x) * (x - jb.x) + (y - jb.y) * (y - jb.y));
              if (dr > jb.r) jb.r = dr;
              stuckOne = true;
              break;
            }
          }
        }
        jb.left--;
      }
      if (jb.left <= 0) jobs.splice(q, 1);
      if (performance.now() - t0 > (budget || 7)) break;
    }
  }
  var down = false, last = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    var g = clampIn(toGrid(e));
    last = g;
    /* 触れたところに小さな芯を置き、粒をこぼす */
    var ix = Math.round(g.x), iy = Math.round(g.y);
    /* 中心の近くに芯を置くと、湧き出す粒を全部ひとり占めしてしまうので置かない */
    if (!occ[iy * GX + ix] && Math.hypot(g.x - cxg, g.y - cyg) > R * 0.32) {
      var nbr = -1;
      for (var k = 0; k < np && nbr < 0; k++) if (Math.abs(px[k] - ix) <= 1 && Math.abs(py[k] - iy) <= 1) nbr = k;
      var id0 = addP(ix, iy, nbr, true);
      if (nbr < 0 && id0 >= 0) par[id0] = id0;
    }
    burst(g.x, g.y, 230);
    pour(g, 30);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var g = clampIn(toGrid(e));
    if (Math.hypot(g.x - last.x, g.y - last.y) < 3) return;
    pour(g, 6);
    burst(g.x, g.y, 30);
    last = g;
    SFX.play('move', 180);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kFlow = document.getElementById('k-flow'), kStick = document.getElementById('k-stick');
  kFlow.addEventListener('input', function () { flow = this.value / 100; });
  kStick.addEventListener('input', function () { stick = 0.06 + Math.pow(this.value / 100, 1.5) * 0.94; });
  document.getElementById('k-clear').addEventListener('click', function () { fade = 0.0001; SFX.play('click'); });
  flow = kFlow.value / 100;
  stick = 0.06 + Math.pow(kStick.value / 100, 1.5) * 0.94;

  /* ---------------------------------------------------------- 描く */
  var NB = 7, paths = [];
  var LN = P.line, ST = P.steel, AC = P.accent;
  function draw() {
    P.fill(ctx, W, H);
    var vis = fade > 0 ? Math.max(0, 1 - fade) : 1;
    for (var b = 0; b <= NB; b++) paths[b] = new Path2D();
    var cyanPath = new Path2D(), h = C * 0.5;
    for (var k = 0; k < np; k++) {
      if (par[k] < 0 && !mine[k]) continue;
      var x1 = px[k] * C + h + jx[k], y1 = py[k] * C + h + jy[k];
      var age = T - born[k];
      if (mine[k] && age < 10) { cyanPath.rect(x1 - 2, y1 - 2, 4, 4); continue; }
      var q = 0.3 + 0.7 * Math.exp(-age / 40);
      var bi = Math.min(NB, Math.round(q * NB));
      var rr = 1.3 + q * 0.5;
      paths[bi].rect(x1 - rr, y1 - rr, rr * 2, rr * 2);
    }
    /* 古い粒ほど鋼色に沈み、伸びている先だけが白い */
    for (b = 0; b <= NB; b++) {
      var t = b / NB;
      ctx.fillStyle = P.rgba(P.mix(ST, LN, t), (0.5 + t * 0.45) * vis);
      ctx.fill(paths[b]);
    }
    ctx.fillStyle = P.rgba(AC, 0.9 * vis);
    ctx.fill(cyanPath);

    /* 外輪 */
    ctx.strokeStyle = P.rgba(ST, 0.55 * vis);
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(cxg * C + h, cyg * C + h, R * C + C, 0, Math.PI * 2);
    ctx.stroke();

    /* 漂っている粒。中心ほど濃い霞になる */
    ctx.fillStyle = P.rgba(LN, 0.5);
    ctx.beginPath();
    var cy = [];
    for (var i = 0; i < nw; i++) {
      if (wc[i] > 0) { cy.push(i); continue; }
      ctx.rect(wx[i] * C + h - 0.8, wy[i] * C + h - 0.8, 1.6, 1.6);
    }
    ctx.fill();
    if (cy.length) {
      ctx.fillStyle = P.rgba(AC, 0.75);
      ctx.beginPath();
      for (i = 0; i < cy.length; i++) ctx.rect(wx[cy[i]] * C + h - 0.9, wy[cy[i]] * C + h - 0.9, 1.8, 1.8);
      ctx.fill();
    }

    ctx.lineWidth = 1;
    for (i = marks.length - 1; i >= 0; i--) {
      var mk = marks[i];
      var a = 1 - mk.t / 1.4;
      if (a <= 0) { marks.splice(i, 1); continue; }
      ctx.strokeStyle = P.rgba(AC, a * 0.55);
      ctx.beginPath();
      ctx.arc(mk.x, mk.y, 4 + mk.t * 36, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    T += dt;
    for (var i = 0; i < marks.length; i++) marks[i].t += dt;
    if (fade > 0) {
      fade += dt / 5;
      if (fade >= 1) reset(false);
    } else {
      var want = target();
      while (nw < want) { respawn(nw); nw++; }
      if (nw > want) nw = Math.max(want, nw - 20);
      /* 積もる速さを一定に保つ。速すぎれば歩幅を減らし、遅ければ増やす */
      walk(Math.max(1, Math.round(pace)), Math.min(1, pace));
      rot = (rot + Math.max(1, Math.round(nw * Math.min(1, pace)))) % Math.max(1, nw);
      var want2 = (30 + flow * 110) * dt;
      pace *= stuck > want2 ? 0.93 : 1.04;
      if (pace < 0.03) pace = 0.03; else if (pace > 16) pace = 16;
      /* 指が中心近くまで届いたら、ゆっくり消して輪からやり直す */
      if (inner < R * 0.16 || np > CAP - 50) { fade = 0.0001; SFX.play('pop', 3000); }
    }
    runJobs();
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
