<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('perch');

$pageName = 'perch';
$pageTitle = 'PERCH - PULSE';
$pageDesc = '何本も張られた電線に小鳥がとまってとなりと間をあけて並び、ときどき何羽かが続けて飛び立っては、空を回って別の電線に降りる。押すとまわりの鳥が水色になって飛び立ち電線が揺れて、なぞると鳥が指を追って飛び、離した近くの電線に降りる。';
$pageCanonical = 'https://pulse.subara3.com/perch.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-birds">BIRDS</label><input type="range" id="k-birds" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="45"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 電線は横に細かく刻んだ 1 次元の弦。電柱の位置で止め、鳥の重みと羽ばたきで揺れる */
  var DX = 6, M = 0, SPAN = 400, X0 = 0, U = 1;
  var wires = [];          /* { y0, sag, u, v, load } */
  var poles = [];          /* x 座標 */
  var arms = [];           /* 腕木の y */
  var birds = [];
  var groups = [];
  var amt = 0.5, wind = 0.45;
  var time = 0, nextFlock = 2.2, nextHop = 0.8, idleFly = 0;
  var gid = 0;
  var press = null;        /* 押しているあいだの群れ */

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function restY(w, x) {
    var t = (x - X0) / SPAN;
    t = t - Math.floor(t);
    return w.y0 + w.sag * 4 * t * (1 - t);
  }
  function wireY(w, x) {
    var f = x / DX, i = Math.floor(f);
    if (i < 0) i = 0; if (i > M - 2) i = M - 2;
    var k = f - i; if (k < 0) k = 0; if (k > 1) k = 1;
    return restY(w, x) + w.u[i] * (1 - k) + w.u[i + 1] * k;
  }
  function idx(x) { return Math.max(0, Math.min(M - 1, Math.round(x / DX))); }
  function kick(w, x, imp, rad) {
    var c = x / DX, r = rad || 4;
    for (var i = Math.max(1, Math.floor(c - r)); i <= Math.min(M - 2, Math.ceil(c + r)); i++) {
      if (w.pin[i]) continue;
      var d = Math.abs(i - c) / r;
      if (d < 1) w.v[i] += imp * (Math.cos(d * Math.PI) + 1) * 0.5;
    }
  }

  function build() {
    U = Math.max(0.9, Math.min(1.6, Math.min(W, H) / 560));
    SPAN = Math.max(300, Math.min(720, W * 0.48));
    var nPole = Math.ceil(W / SPAN) + 2;
    X0 = (W - (nPole - 2) * SPAN) / 2 - SPAN * 0.18;
    poles = [];
    for (var p = -1; p < nPole; p++) poles.push(X0 + p * SPAN);
    M = Math.ceil(W / DX) + 2;
    /* 電線は 3 本ずつの束にして、束を縦に並べる */
    var nb = Math.max(2, Math.min(4, Math.round(H / 260)));
    var gap = 24 * U;
    wires = []; arms = [];
    for (var b = 0; b < nb; b++) {
      var cy = H * (0.17 + 0.7 * (nb === 1 ? 0.5 : b / (nb - 1)));
      arms.push(cy - gap * 1.6);
      for (var k = -1; k <= 1; k++) {
        var w = {
          y0: cy + k * gap - gap * 0.4, sag: SPAN * (0.055 + 0.01 * k + 0.008 * b),
          u: new Float32Array(M), v: new Float32Array(M), load: new Float32Array(M), pin: new Uint8Array(M),
          ph: Math.random() * 6.28
        };
        for (var q = 0; q < poles.length; q++) { var pi = Math.round(poles[q] / DX); if (pi >= 0 && pi < M) w.pin[pi] = 1; }
        w.pin[0] = 1; w.pin[M - 1] = 1;
        wires.push(w);
      }
    }
  }

  /* ---------------------------------------------------------- 鳥 */
  function newBird() {
    return {
      st: 'perch', w: 0, x: 0, gx: 0, y: 0, vx: 0, vy: 0,
      f: Math.random() < 0.5 ? -1 : 1, g: null, leave: -1, land: -1,
      tw: 0, tx: 0, glow: 0, ph: Math.random() * 6.28, flap: rnd(4.2, 5.6),
      gap: rnd(13, 21), bob: Math.random() * 6.28, next: rnd(1, 6), away: false
    };
  }
  function targetCount() {
    var area = Math.max(0.45, Math.min(1.4, W * H / (1280 * 800)));
    return Math.round((40 + amt * 280) * area);
  }

  /* 空いている場所を探す。近くの鳥と、そこへ降りる途中の鳥の両方から離れたところ */
  function freeSpot(nearX, nearY, spread, wireList) {
    var best = null, bs = -1;
    for (var n = 0; n < 14; n++) {
      var wi = wireList ? wireList[(Math.random() * wireList.length) | 0] : (Math.random() * wires.length) | 0;
      var x = nearX == null ? rnd(16, W - 16) : nearX + rnd(-spread, spread);
      x = Math.max(16, Math.min(W - 16, x));
      var md = 999;
      for (var i = 0; i < birds.length; i++) {
        var b = birds[i];
        if (b.st === 'perch' && b.w === wi) md = Math.min(md, Math.abs(b.x - x));
        else if (b.st === 'land' && b.tw === wi) md = Math.min(md, Math.abs(b.tx - x));
      }
      for (var q = 0; q < poles.length; q++) md = Math.min(md, Math.abs(poles[q] - x) + 4);
      var sc = Math.min(md, 26 * U);
      if (nearY != null) sc -= Math.abs(wires[wi].y0 - nearY) * 0.02;
      if (sc > bs) { bs = sc; best = { w: wi, x: x }; }
    }
    return best;
  }
  function wiresNear(y, k) {
    var list = wires.map(function (w, i) { return { i: i, d: Math.abs(w.y0 - y) }; });
    list.sort(function (a, b) { return a.d - b.d; });
    return list.slice(0, k).map(function (o) { return o.i; });
  }

  function placeAll() {
    birds = [];
    groups = [];
    var n = targetCount();
    /* はじめは何か所かにまとまってとまっている */
    var spots = [];
    for (var s = 0; s < 7; s++) spots.push({ x: rnd(0, W), w: (Math.random() * wires.length) | 0 });
    for (var i = 0; i < n; i++) {
      var b = newBird();
      var sp = spots[i % spots.length];
      b.w = Math.random() < 0.7 ? sp.w : (Math.random() * wires.length) | 0;
      b.x = b.gx = Math.max(10, Math.min(W - 10, sp.x + rnd(-1, 1) * rnd(20, 260)));
      birds.push(b);
    }
    for (var it = 0; it < 40; it++) spacing(0.5, true);
    /* 開いた瞬間から空にも一群れ */
    var g = makeGroup(W * rnd(0.3, 0.7), H * rnd(0.3, 0.6), rnd(5, 8));
    var cnt = Math.round(n * 0.18);
    for (var j = 0; j < cnt; j++) {
      var bb = birds[(Math.random() * birds.length) | 0];
      if (bb.st !== 'perch') continue;
      bb.st = 'fly'; bb.g = g;
      var a = Math.random() * 6.28, r = rnd(20, 120) * U;
      bb.x = g.cx + Math.cos(a) * r; bb.y = g.cy + Math.sin(a) * r * 0.6;
      bb.vx = -Math.sin(a) * 160 * g.dir; bb.vy = Math.cos(a) * 90 * g.dir;
    }
  }

  function makeGroup(x, y, life) {
    var g = { id: gid++, age: 0, cx: x, cy: y, hx: x, hy: y, ang: Math.random() * 6.28, life: life,
      dir: Math.random() < 0.5 ? -1 : 1, follow: false, rad: rnd(70, 140) * U,
      dx: rnd(-1, 1), dy: rnd(-0.4, 0.4) };
    groups.push(g);
    return g;
  }

  function takeoff(b, g, ax, ay) {
    var w = wires[b.w];
    b.y = wireY(w, b.x) - 5 * U;
    kick(w, b.x, 70, 3);
    b.st = 'fly'; b.g = g; b.leave = -1;
    var dx = b.x - ax, dy = b.y - ay, d = Math.hypot(dx, dy) || 1;
    b.vx = dx / d * 110 + rnd(-50, 50);
    b.vy = -150 - Math.random() * 90 + Math.min(0, dy / d * 60);
  }

  function sendLand(b, spot) {
    if (!spot) return;
    b.st = 'land'; b.tw = spot.w; b.tx = spot.x; b.g = null;
    b.leave = -1; b.lg = null;   /* 前の離陸予約を残さない */
  }

  /* 同じ電線の鳥どうしは間をあける。近すぎる相手からは横歩きで離れる */
  function spacing(rate, snap) {
    var by = [];
    for (var i = 0; i < wires.length; i++) by.push([]);
    for (var j = 0; j < birds.length; j++) if (birds[j].st === 'perch') by[birds[j].w].push(birds[j]);
    for (var w = 0; w < by.length; w++) {
      var L = by[w];
      L.sort(function (a, b) { return a.gx - b.gx; });
      for (var k = 1; k < L.length; k++) {
        var a = L[k - 1], c = L[k];
        var need = (a.gap + c.gap) * 0.5 * U, d = c.gx - a.gx;
        if (d < need) { var m = (need - d) * rate; a.gx -= m; c.gx += m; }
      }
      /* 端に押し出された鳥は、端から順に並べ直す（重なって団子にならないように） */
      for (var q = 0; q < L.length; q++) {
        var mn = q ? L[q - 1].gx + L[q].gap * 0.8 * U : 8;
        if (L[q].gx < mn) L[q].gx = mn;
      }
      for (var r = L.length - 1; r >= 0; r--) {
        var mx = r < L.length - 1 ? L[r + 1].gx - L[r].gap * 0.8 * U : W - 8;
        if (L[r].gx > mx) L[r].gx = mx;
      }
      for (var z = 0; z < L.length; z++) {
        var bb = L[z];
        if (bb.gx < 8) bb.gx = 8; if (bb.gx > W - 8) bb.gx = W - 8;
        if (snap) bb.x = bb.gx;
      }
    }
  }

  /* ---------------------------------------------------------- 更新 */
  function stepWires(dt) {
    var n = 3, h = dt / n, K = 1800, damp = 1.6;
    for (var wI = 0; wI < wires.length; wI++) {
      var w = wires[wI];
      w.load.fill(0);
    }
    for (var j = 0; j < birds.length; j++) {
      var b = birds[j];
      if (b.st === 'perch') wires[b.w].load[idx(b.x)] += 26;
    }
    var gust = (0.3 + wind * 1.6);
    for (var s = 0; s < n; s++) {
      for (var wi = 0; wi < wires.length; wi++) {
        var ww = wires[wi], u = ww.u, v = ww.v, ld = ww.load, pin = ww.pin;
        var force = Math.sin(time * 0.9 + ww.ph) * 14 * gust + Math.sin(time * 0.37 + ww.ph * 2) * 10 * gust;
        for (var i = 1; i < M - 1; i++) {
          if (pin[i]) { u[i] = 0; v[i] = 0; continue; }
          v[i] += (K * (u[i - 1] + u[i + 1] - 2 * u[i]) + ld[i] + force - damp * v[i]) * h;
        }
        for (var k = 1; k < M - 1; k++) u[k] += v[k] * h;
      }
    }
  }

  function stepGroups(dt) {
    for (var n = groups.length - 1; n >= 0; n--) {
      var g = groups[n];
      if (g.follow && press) {
        g.cx += (press.x - g.cx) * Math.min(1, dt * 9);
        g.cy += (press.y - g.cy) * Math.min(1, dt * 9);
        g.ang += dt * 2.2 * g.dir;
        continue;
      }
      g.life -= dt; g.age += dt;
      g.ang += dt * 0.9 * g.dir;
      g.hx += g.dx * 40 * U * dt; g.hy += g.dy * 30 * U * dt;
      if (g.hx < W * 0.2 || g.hx > W * 0.8) g.dx = -g.dx * 0.9 + (g.hx < W * 0.5 ? 0.2 : -0.2);
      if (g.hy < H * 0.2 || g.hy > H * 0.75) g.dy = -g.dy;
      g.cx = g.hx + Math.cos(g.ang) * g.rad * 1.4;
      g.cy = g.hy + Math.sin(g.ang) * g.rad * 0.6;
      var members = 0;
      for (var i = 0; i < birds.length; i++) {
        var b = birds[i];
        if (b.g !== g) continue;
        members++;
        if (g.life <= 0 && b.land < 0) b.land = time + Math.random() * 2.6;
        if (b.land >= 0 && time >= b.land) {
          b.land = -1;
          var near = g.landX != null ? g.landX : g.cx;
          var wl = g.landY != null ? wiresNear(g.landY, 3) : null;
          sendLand(b, freeSpot(near, g.landY, g.landY != null ? 120 * U : 260 * U, wl));
        }
      }
      if (!members && g.age > 5) groups.splice(n, 1);
    }
  }

  function stepBirds(dt) {
    var maxS = 250 * U, minS = 95 * U;
    var flying = [];
    for (var i = 0; i < birds.length; i++) if (birds[i].st !== 'perch') flying.push(birds[i]);
    for (var j = 0; j < birds.length; j++) {
      var b = birds[j];
      b.glow = Math.max(0, b.glow - dt * 0.22);
      if (b.st === 'perch') {
        var w = wires[b.w];
        b.next -= dt;
        if (b.next < 0) {
          b.next = rnd(2, 8);
          if (Math.random() < 0.5) b.f = -b.f;
          else b.gx += rnd(-1, 1) * 10 * U;
        }
        var mv = b.gx - b.x, sp = 26 * U * dt;
        b.x += Math.abs(mv) < sp ? mv : (mv > 0 ? sp : -sp);
        b.y = wireY(w, b.x);
        b.bob += dt * (0.8 + Math.random() * 0.4);
        if (b.leave >= 0 && time >= b.leave) takeoff(b, b.lg, b.lx, b.ly);
        continue;
      }
      b.ph += dt * b.flap * 6.28;
      var ax = 0, ay = 0;
      if (b.st === 'fly' && b.g) {
        var g = b.g;
        var dx = g.cx - b.x, dy = g.cy - b.y, d = Math.hypot(dx, dy) || 1;
        var kc = g.follow ? 5 : 1.3, kt = g.follow ? 260 : 120;
        ax += dx * kc; ay += dy * kc;
        ax += -dy / d * kt * g.dir; ay += dx / d * kt * g.dir;
        if (g.follow && d < 34 * U) { ax -= dx / d * 400; ay -= dy / d * 400; }
      } else if (b.st === 'fly') {
        b.g = null; sendLand(b, freeSpot(b.x, b.y, 200 * U));
      } else if (b.st === 'away') {
        ay -= 120; ax += (b.x < W / 2 ? -1 : 1) * 160;
      }
      if (b.st === 'land') {
        var tw = wires[b.tw], ty = wireY(tw, b.tx) - 1;
        var lx = b.tx - b.x, ly = ty - b.y, ld = Math.hypot(lx, ly);
        if (ld < 3 * U) {
          b.st = 'perch'; b.w = b.tw; b.x = b.gx = b.tx; b.vx = b.vy = 0;
          b.f = lx >= 0 ? 1 : -1;
          kick(tw, b.x, 90, 3);
          if (Math.random() < 0.08) SFX.play('tick', 2600);
          continue;
        }
        var want = Math.min(maxS, ld * 2.6 + 10);
        var tvx = lx / (ld || 1) * want, tvy = ly / (ld || 1) * want;
        if (ld > 60 * U) tvy -= 40;
        var kk = Math.min(1, dt * (ld < 50 * U ? 9 : 3));
        b.vx += (tvx - b.vx) * kk; b.vy += (tvy - b.vy) * kk;
      } else {
        /* 群れの中ではとなりとぶつからないように */
        for (var q = 0; q < flying.length; q++) {
          var o = flying[q];
          if (o === b) continue;
          var ex = b.x - o.x, ey = b.y - o.y;
          if (ex > 16 * U || ex < -16 * U || ey > 16 * U || ey < -16 * U) continue;
          var e2 = ex * ex + ey * ey + 1;
          ax += ex / e2 * 2200; ay += ey / e2 * 2200;
        }
        /* 画面の上と左右から出すぎない */
        if (b.st === 'fly') {
          if (b.y < 30 * U) ay += (30 * U - b.y) * 12;
          if (b.x < 10) ax += (10 - b.x) * 8; else if (b.x > W - 10) ax -= (b.x - W + 10) * 8;
        }
        b.vx += ax * dt; b.vy += ay * dt;
        var s = Math.hypot(b.vx, b.vy) || 1;
        var cap = b.g && b.g.follow ? maxS * 1.5 : maxS;
        if (s > cap) { b.vx *= cap / s; b.vy *= cap / s; }
        else if (s < minS) { b.vx *= minS / s; b.vy *= minS / s; }
      }
      b.x += b.vx * dt; b.y += b.vy * dt;
      if (Math.abs(b.vx) > 8) b.f = b.vx > 0 ? 1 : -1;
      if (b.st === 'away' && (b.x < -60 || b.x > W + 60 || b.y < -60)) b.dead = true;
    }
    if (birds.some(function (x) { return x.dead; })) birds = birds.filter(function (x) { return !x.dead; });
  }

  /* 自動: ときどき一羽が飛び立ち、近くの鳥が順に続く。ほかにも 1 羽ずつの小さな移動 */
  function auto(dt) {
    var nFly = 0, perched = [];
    for (var i = 0; i < birds.length; i++) { if (birds[i].st === 'perch') { if (birds[i].leave < 0) perched.push(birds[i]); } else nFly++; }
    idleFly = nFly ? 0 : idleFly + dt;
    nextFlock -= dt * (0.6 + wind * 1.2);
    if ((nextFlock < 0 || idleFly > 3) && perched.length > 8 && nFly < birds.length * 0.45) {
      nextFlock = rnd(5, 9); idleFly = 0;
      var s = perched[(Math.random() * perched.length) | 0];
      var w0 = wires[s.w], sy = w0.y0, reach = rnd(90, 230) * U;
      var g = makeGroup(s.x, Math.max(H * 0.2, sy - 120 * U), rnd(4, 8));
      g.hx = Math.max(W * 0.25, Math.min(W * 0.75, s.x)); g.hy = Math.max(H * 0.22, Math.min(H * 0.7, sy - 80 * U));
      for (var j = 0; j < perched.length; j++) {
        var b = perched[j];
        var dx = Math.abs(b.x - s.x), dy = Math.abs(wires[b.w].y0 - sy);
        if (dx < reach && dy < 70 * U) {
          b.leave = time + (dx + dy * 2) / (180 * U) + Math.random() * 0.15;
          b.lg = g; b.lx = s.x; b.ly = sy + 40;
        }
      }
      SFX.play('ripple', 4000);
    }
    nextHop -= dt * (0.6 + wind);
    if (nextHop < 0 && perched.length) {
      nextHop = rnd(0.8, 1.8);
      var h = perched[(Math.random() * perched.length) | 0];
      var spot = freeSpot(h.x, wires[h.w].y0, 160 * U, wiresNear(wires[h.w].y0, 4));
      if (spot && (spot.w !== h.w || Math.abs(spot.x - h.x) > 30 * U)) {
        var ww = wires[h.w];
        h.y = wireY(ww, h.x) - 4 * U;
        kick(ww, h.x, 60, 3);
        h.vx = (spot.x - h.x) * 0.6; h.vy = -120 * U;
        sendLand(h, spot);
      }
    }
    /* 数の調整 */
    var want = targetCount(), live = birds.filter(function (x) { return x.st !== 'away'; }).length;
    if (live < want) {
      var gg = null;
      for (var a = 0; a < Math.min(6, want - live); a++) {
        if (!gg) gg = makeGroup(W * rnd(0.3, 0.7), H * rnd(0.25, 0.5), rnd(3, 5));
        var nb = newBird(); nb.st = 'fly'; nb.g = gg;
        var side = Math.random() < 0.5 ? -40 : W + 40;
        nb.x = side; nb.y = rnd(H * 0.1, H * 0.6); nb.vx = side < 0 ? 200 : -200; nb.vy = rnd(-30, 30);
        birds.push(nb);
      }
    } else if (live > want) {
      for (var r = birds.length - 1, cut = live - want; r >= 0 && cut > 0; r--) {
        if (birds[r].st === 'away') continue;
        var bb = birds[r];
        if (bb.st === 'perch') { bb.y = wireY(wires[bb.w], bb.x) - 4; bb.vy = -100; }
        bb.st = 'away'; bb.g = null; cut--;
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.7 });
    /* 電柱と腕木 */
    ctx.strokeStyle = P.rgba(P.steel, 0.55);
    ctx.lineWidth = 2 * U;
    ctx.beginPath();
    for (var p = 0; p < poles.length; p++) {
      var x = poles[p];
      if (x < -20 || x > W + 20) continue;
      ctx.moveTo(x, arms[0] - 30 * U); ctx.lineTo(x, H);
      for (var a = 0; a < arms.length; a++) { ctx.moveTo(x - 30 * U, arms[a] + 8 * U); ctx.lineTo(x + 30 * U, arms[a] + 8 * U); }
    }
    ctx.stroke();
    /* 電線 */
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.line, 0.62);
    ctx.beginPath();
    for (var wi = 0; wi < wires.length; wi++) {
      var w = wires[wi];
      for (var i = 0; i < M; i++) {
        var xx = i * DX, yy = restY(w, xx) + w.u[i];
        if (i) ctx.lineTo(xx, yy); else ctx.moveTo(xx, yy);
      }
    }
    ctx.stroke();
    /* 碍子 */
    ctx.fillStyle = P.rgba(P.steel, 0.9);
    for (var q = 0; q < poles.length; q++) {
      if (poles[q] < -20 || poles[q] > W + 20) continue;
      for (var k = 0; k < wires.length; k++) ctx.fillRect(poles[q] - 1.5 * U, wires[k].y0 - 3 * U, 3 * U, 4 * U);
    }
    for (var j = 0; j < birds.length; j++) {
      var b = birds[j];
      ctx.fillStyle = ctx.strokeStyle = P.rgba(b.glow > 0.01 ? P.mix(P.line, P.accent, Math.min(1, b.glow * 1.2)) : P.line, 0.93);
      if (b.st === 'perch') drawPerched(b); else drawFlying(b);
    }
  }

  function drawPerched(b) {
    var s = U, f = b.f, x = b.x, y = b.y;
    var nod = Math.sin(b.bob) * 0.5 * s;
    ctx.beginPath();
    ctx.ellipse(x, y - 4.2 * s, 4.4 * s, 3.3 * s, f * 0.35, 0, 6.2832);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(x + f * 3.6 * s, y - 8 * s + nod, 2.4 * s, 0, 6.2832);
    ctx.fill();
    ctx.lineWidth = 1.3 * s;
    ctx.beginPath();
    ctx.moveTo(x + f * 5.6 * s, y - 8.3 * s + nod); ctx.lineTo(x + f * 7.4 * s, y - 7.8 * s + nod);
    ctx.moveTo(x - f * 3.4 * s, y - 2.6 * s); ctx.lineTo(x - f * 7.6 * s, y + 1.6 * s);
    ctx.stroke();
  }

  function drawFlying(b) {
    var s = U, x = b.x, y = b.y;
    var land = b.st === 'land';
    var fl = land ? 0.25 + Math.sin(b.ph) * 0.15 : Math.sin(b.ph);
    var tilt = Math.max(-0.5, Math.min(0.5, b.vy / 400)) * (b.f);
    var c = Math.cos(tilt), sn = Math.sin(tilt);
    var span = 8 * s, lift = 6 * s * fl;
    function px(lx, ly) { return x + lx * c - ly * sn; }
    function py(lx, ly) { return y + lx * sn + ly * c; }
    ctx.lineWidth = 1.5 * s;
    ctx.beginPath();
    ctx.moveTo(px(-span, -lift), py(-span, -lift));
    ctx.quadraticCurveTo(px(-span * 0.45, -lift * 0.2 - 2 * s), py(-span * 0.45, -lift * 0.2 - 2 * s), px(0, 0), py(0, 0));
    ctx.quadraticCurveTo(px(span * 0.45, -lift * 0.2 - 2 * s), py(span * 0.45, -lift * 0.2 - 2 * s), px(span, -lift), py(span, -lift));
    ctx.stroke();
    ctx.beginPath();
    ctx.ellipse(x, y + 0.5 * s, 3.4 * s, 1.9 * s, tilt, 0, 6.2832);
    ctx.fill();
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; press = null; build(); placeAll(); });

  /* ---------------------------------------------------------- 操作 */
  var last = null;
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  function scare(x, y, r, g) {
    var hit = 0;
    for (var i = 0; i < birds.length; i++) {
      var b = birds[i];
      if (b.st === 'away') continue;
      var d = Math.hypot(b.x - x, b.y - y);
      if (d > r) continue;
      b.glow = 1;
      if (b.st === 'perch') {
        if (b.leave < 0 || b.lg !== g) { b.leave = time + d / (900 * U); b.lg = g; b.lx = x; b.ly = y; hit++; }
      } else {
        b.g = g; b.st = 'fly'; b.land = -1;
        var k = (1 - d / r) * 160;
        b.vx += (b.x - x) / (d || 1) * k; b.vy += (b.y - y) / (d || 1) * k - 40;
      }
    }
    return hit;
  }
  function pluck(x, y, r, amp) {
    for (var i = 0; i < wires.length; i++) {
      var w = wires[i], d = Math.abs(wireY(w, x) - y);
      if (d < r) kick(w, x, -amp * (1 - d / r), 7);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    if (press) return;   /* 操作を始めた指だけを扱う */
    last = pos(e);
    var g = makeGroup(last.x, last.y, 99);
    g.follow = true; g.rad = 50 * U;
    press = { x: last.x, y: last.y, g: g, id: e.pointerId };
    scare(last.x, last.y, 140 * U, g);
    pluck(last.x, last.y, 130 * U, 520);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!press || e.pointerId !== press.id) return;
    var p = pos(e), dist = Math.hypot(p.x - last.x, p.y - last.y);
    press.x = p.x; press.y = p.y;
    if (dist < 8) return;
    /* 指の通り道にとまっていた鳥も加わり、指が横切った電線が跳ねる */
    scare(p.x, p.y, 60 * U, press.g);
    for (var i = 0; i < wires.length; i++) {
      var w = wires[i], a = wireY(w, last.x) - last.y, b = wireY(w, p.x) - p.y;
      if (a * b <= 0) kick(w, (last.x + p.x) / 2, (p.y > last.y ? 1 : -1) * 380, 6);
    }
    last = p;
    SFX.play('move', 160);
  });
  function up(e) {
    if (!press || e.pointerId !== press.id) return;
    var g = press.g;
    g.follow = false; g.life = 0.4; g.landX = press.x; g.landY = press.y;
    g.hx = press.x; g.hy = press.y; g.rad = 40 * U;
    press = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-birds').addEventListener('input', function () { amt = this.value / 100; });
  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  /* 共有リンクから開いたとき、復元された値を拾う */
  amt = document.getElementById('k-birds').value / 100;
  wind = document.getElementById('k-wind').value / 100;
  if (birds.length !== targetCount()) placeAll();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), sp = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    time += dt;
    auto(dt);
    stepGroups(dt);
    stepBirds(dt);
    sp += dt;
    if (sp > 0.05) { spacing(0.25, false); sp = 0; }
    stepWires(dt);
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
