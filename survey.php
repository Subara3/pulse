<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('survey');

$pageName = 'survey';
$pageTitle = 'SURVEY - PULSE';
$pageDesc = '暗い洞窟の中を小さな探査機が何台か進み、四方へ伸ばした細い光で壁を測って、測った壁を白い線で図面に描き足していく。まだ測っていないところへ向かって進むが、古い線は少しずつ薄れるので、探査は終わらない。押すとそこから輪が広がってまわりの壁が水色に浮かび、近くの探査機が向かってくる。なぞると指のまわりの壁が浮かび、探査機が1台ついてくる。';
$pageCanonical = 'https://pulse.subara3.com/survey.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-range">RANGE</label><input type="range" id="k-range" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-mode="cave">CAVE</button><button type="button" data-mode="rooms">ROOMS</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 地図は粗い格子。壁の輪郭は格子の中心を結ぶ等値線で描く */
  var map = null, old = null, oldT = 0, mapAge = 0;
  var probes = [], pings = [];
  var mode = 'cave', speed = 0.4, range = 0.5;
  var NRAY = 72, MAP_LIFE = 80, FADE = 1 / 40;
  var queue = null, parent = null;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  /* ---------------------------------------------------------- 地図を作る */
  function keepLargest(w, nx, ny) {
    var lab = new Int32Array(nx * ny), q = new Int32Array(nx * ny), best = 0, bestN = 0, id = 0;
    for (var s = 0; s < nx * ny; s++) {
      if (w[s] || lab[s]) continue;
      id++; var h = 0, t = 0; q[t++] = s; lab[s] = id;
      while (h < t) {
        var k = q[h++], x = k % nx, y = (k / nx) | 0;
        if (x > 0 && !w[k - 1] && !lab[k - 1]) { lab[k - 1] = id; q[t++] = k - 1; }
        if (x < nx - 1 && !w[k + 1] && !lab[k + 1]) { lab[k + 1] = id; q[t++] = k + 1; }
        if (y > 0 && !w[k - nx] && !lab[k - nx]) { lab[k - nx] = id; q[t++] = k - nx; }
        if (y < ny - 1 && !w[k + nx] && !lab[k + nx]) { lab[k + nx] = id; q[t++] = k + nx; }
      }
      if (t > bestN) { bestN = t; best = id; }
    }
    for (var k2 = 0; k2 < nx * ny; k2++) if (!w[k2] && lab[k2] !== best) w[k2] = 1;
    return bestN;
  }

  function genCave(nx, ny) {
    var w = new Uint8Array(nx * ny), w2 = new Uint8Array(nx * ny), open = 0;
    for (var tries = 0; tries < 12; tries++) {
      for (var j = 0; j < ny; j++) for (var i = 0; i < nx; i++) {
        w[j * nx + i] = (i < 1 || j < 1 || i > nx - 2 || j > ny - 2 || Math.random() < 0.46) ? 1 : 0;
      }
      for (var it = 0; it < 5; it++) {
        for (var y = 0; y < ny; y++) for (var x = 0; x < nx; x++) {
          var n = 0;
          for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) {
            if (!dx && !dy) continue;
            var xx = x + dx, yy = y + dy;
            n += (xx < 0 || yy < 0 || xx >= nx || yy >= ny) ? 1 : w[yy * nx + xx];
          }
          var k = y * nx + x;
          w2[k] = (x < 1 || y < 1 || x > nx - 2 || y > ny - 2) ? 1 : n > 4 ? 1 : n < 4 ? 0 : w[k];
        }
        var tmp = w; w = w2; w2 = tmp;
      }
      open = keepLargest(w, nx, ny);
      if (open > nx * ny * 0.38) break;
    }
    return w;
  }

  function genRooms(nx, ny) {
    var w = new Uint8Array(nx * ny).fill(1), rooms = [];
    function carve(x0, y0, x1, y1) {
      for (var y = Math.max(1, y0); y <= Math.min(ny - 2, y1); y++)
        for (var x = Math.max(1, x0); x <= Math.min(nx - 2, x1); x++) w[y * nx + x] = 0;
    }
    function hall(a, b) {
      var ax = a.cx, ay = a.cy, bx = b.cx, by = b.cy;
      if (Math.random() < 0.5) { carve(Math.min(ax, bx), ay, Math.max(ax, bx), ay + 1); carve(bx, Math.min(ay, by), bx + 1, Math.max(ay, by)); }
      else { carve(ax, Math.min(ay, by), ax + 1, Math.max(ay, by)); carve(Math.min(ax, bx), by, Math.max(ax, bx), by + 1); }
    }
    var want = Math.max(5, Math.round(nx * ny / 110));
    for (var t = 0; t < 400 && rooms.length < want; t++) {
      var rw = 4 + (Math.random() * 9 | 0), rh = 4 + (Math.random() * 6 | 0);
      var x0 = 2 + (Math.random() * (nx - rw - 4) | 0), y0 = 2 + (Math.random() * (ny - rh - 4) | 0);
      var ok = true;
      for (var r = 0; r < rooms.length && ok; r++) {
        var o = rooms[r];
        if (x0 - 2 < o.x1 && x0 + rw + 2 > o.x0 && y0 - 2 < o.y1 && y0 + rh + 2 > o.y0) ok = false;
      }
      if (!ok) continue;
      var room = { x0: x0, y0: y0, x1: x0 + rw, y1: y0 + rh, cx: x0 + (rw >> 1), cy: y0 + (rh >> 1) };
      carve(x0, y0, room.x1 - 1, room.y1 - 1);
      rooms.push(room);
    }
    for (var i = 1; i < rooms.length; i++) {
      var best = 0, bd = 1e9;
      for (var k = 0; k < i; k++) {
        var d = Math.abs(rooms[k].cx - rooms[i].cx) + Math.abs(rooms[k].cy - rooms[i].cy);
        if (d < bd) { bd = d; best = k; }
      }
      hall(rooms[i], rooms[best]);
    }
    for (var e = 0; e < 2 && rooms.length > 3; e++) hall(rooms[Math.random() * rooms.length | 0], rooms[Math.random() * rooms.length | 0]);
    /* 広い部屋には柱を立てる */
    rooms.forEach(function (rm) {
      if (rm.x1 - rm.x0 < 8 || rm.y1 - rm.y0 < 7 || Math.random() < 0.4) return;
      for (var y = rm.y0 + 2; y < rm.y1 - 2; y += 3) for (var x = rm.x0 + 2; x < rm.x1 - 2; x += 3) w[y * nx + x] = 1;
    });
    keepLargest(w, nx, ny);
    return w;
  }

  function buildMap(kind) {
    var cs = Math.max(11, Math.min(24, Math.min(W, H) / 32));
    var nx = Math.ceil(W / cs) + 2, ny = Math.ceil(H / cs) + 2, N = nx * ny;
    var w = kind === 'rooms' ? genRooms(nx, ny) : genCave(nx, ny);
    /* 等値線の元になる場。洞窟は少しなまして丸く、部屋は角をそのまま */
    var F = new Float32Array(N);
    for (var j = 0; j < ny; j++) for (var i = 0; i < nx; i++) {
      var k = j * nx + i;
      if (kind === 'rooms') { F[k] = w[k]; continue; }
      var s = (i > 0 ? w[k - 1] : 1) + (i < nx - 1 ? w[k + 1] : 1) + (j > 0 ? w[k - nx] : 1) + (j < ny - 1 ? w[k + nx] : 1);
      F[k] = 0.6 * w[k] + 0.1 * s;
    }
    /* マーチング・スクエア */
    var sx = [], sy = [], ex = [], ey = [], sq = [];
    function lerp(va, vb) { return (0.5 - va) / (vb - va); }
    for (var y = 0; y < ny - 1; y++) for (var x = 0; x < nx - 1; x++) {
      var a = F[y * nx + x], b = F[y * nx + x + 1], c = F[(y + 1) * nx + x + 1], d = F[(y + 1) * nx + x];
      var cfg = (a > 0.5 ? 8 : 0) | (b > 0.5 ? 4 : 0) | (c > 0.5 ? 2 : 0) | (d > 0.5 ? 1 : 0);
      if (cfg === 0 || cfg === 15) continue;
      var cx = x + 0.5, cy = y + 0.5;
      var T = [cx + lerp(a, b), cy], R = [cx + 1, cy + lerp(b, c)], B = [cx + lerp(d, c), cy + 1], L = [cx, cy + lerp(a, d)];
      var segs;
      switch (cfg) {
        case 1: case 14: segs = [L, B]; break;
        case 2: case 13: segs = [B, R]; break;
        case 3: case 12: segs = [L, R]; break;
        case 4: case 11: segs = [T, R]; break;
        case 6: case 9: segs = [T, B]; break;
        case 7: case 8: segs = [L, T]; break;
        case 5: segs = (a + b + c + d) / 4 > 0.5 ? [L, B, T, R] : [L, T, B, R]; break;
        case 10: segs = (a + b + c + d) / 4 > 0.5 ? [L, T, B, R] : [L, B, T, R]; break;
      }
      for (var n = 0; n < segs.length; n += 2) {
        sx.push(segs[n][0]); sy.push(segs[n][1]); ex.push(segs[n + 1][0]); ey.push(segs[n + 1][1]); sq.push(y * nx + x);
      }
    }
    /* 断面のハッチ: 床から 2 マス以内の壁だけ */
    var near = new Uint8Array(N), hatch = [], floor = [];
    for (var k3 = 0; k3 < N; k3++) if (!w[k3]) {
      floor.push(k3);
      var fx = k3 % nx, fy = (k3 / nx) | 0;
      for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) {
        var xx = fx + dx, yy = fy + dy;
        if (xx >= 0 && yy >= 0 && xx < nx && yy < ny) near[yy * nx + xx] = 1;
      }
    }
    for (var k4 = 0; k4 < N; k4++) if (w[k4] && near[k4] && F[k4] > 0.5) hatch.push(k4);
    if (!queue || queue.length < N) { queue = new Int32Array(N); parent = new Int32Array(N); }
    return {
      kind: kind, cs: cs, nx: nx, ny: ny, w: w, F: F,
      ox: (W - nx * cs) / 2, oy: (H - ny * cs) / 2,
      sx: new Float32Array(sx), sy: new Float32Array(sy), ex: new Float32Array(ex), ey: new Float32Array(ey),
      sq: new Int32Array(sq), lev: new Uint8Array(sq.length),
      hatch: new Int32Array(hatch), floor: new Int32Array(floor),
      seen: new Float32Array(N), glow: new Float32Array(N)
    };
  }

  /* ---------------------------------------------------------- 探査機 */
  function nearestFloor(m, gx, gy) {
    var bi = m.floor[0], bd = 1e9;
    for (var n = 0; n < m.floor.length; n++) {
      var k = m.floor[n], dx = (k % m.nx) + 0.5 - gx, dy = ((k / m.nx) | 0) + 0.5 - gy, d = dx * dx + dy * dy;
      if (d < bd) { bd = d; bi = k; }
    }
    return bi;
  }

  function makeProbe(m, k) {
    return { x: (k % m.nx) + 0.5, y: ((k / m.nx) | 0) + 0.5, vx: 0, vy: 0, dir: Math.random() * 6.28,
      path: [], pi: 0, target: -1, replan: 0, hold: 0, hits: new Float32Array(NRAY), trail: [], trailT: 0, sweep: Math.random() * 6.28 };
  }

  function spawnProbes(m) {
    probes = [];
    var n = Math.max(3, Math.min(6, Math.round(m.floor.length / 260)));
    for (var i = 0; i < n; i++) probes.push(makeProbe(m, m.floor[Math.random() * m.floor.length | 0]));
  }

  /* 幅優先で道を探す。goal が -1 なら、まだ測っていない床のうち最寄りで、ほかの探査機の行き先から離れたところへ */
  function plan(p, goal) {
    var m = map, nx = m.nx, N = nx * m.ny;
    var start = ((p.y | 0) * nx + (p.x | 0));
    if (start < 0 || start >= N || m.w[start]) start = nearestFloor(m, p.x, p.y);
    parent.fill(-2, 0, N);
    var h = 0, t = 0, found = -1;
    queue[t++] = start; parent[start] = -1;
    var R2 = 64;
    while (h < t) {
      var k = queue[h++];
      if (goal >= 0 ? k === goal : (m.seen[k] < 0.35 && k !== start)) {
        if (goal >= 0) { found = k; break; }
        var kx = k % nx, ky = (k / nx) | 0, ok = true;
        for (var q = 0; q < probes.length && ok; q++) {
          var o = probes[q];
          if (o === p || o.target < 0) continue;
          var dx = (o.target % nx) - kx, dy = ((o.target / nx) | 0) - ky;
          if (dx * dx + dy * dy < R2) ok = false;
        }
        if (ok) { found = k; break; }
      }
      var x = k % nx;
      if (x > 0 && !m.w[k - 1] && parent[k - 1] === -2) { parent[k - 1] = k; queue[t++] = k - 1; }
      if (x < nx - 1 && !m.w[k + 1] && parent[k + 1] === -2) { parent[k + 1] = k; queue[t++] = k + 1; }
      if (k >= nx && !m.w[k - nx] && parent[k - nx] === -2) { parent[k - nx] = k; queue[t++] = k - nx; }
      if (k + nx < N && !m.w[k + nx] && parent[k + nx] === -2) { parent[k + nx] = k; queue[t++] = k + nx; }
    }
    if (found < 0) found = goal >= 0 ? -1 : m.floor[Math.random() * m.floor.length | 0];
    if (found < 0 || parent[found] === -2) { p.path = []; p.target = -1; return; }
    var path = [];
    for (var c = found; c !== -1 && path.length < 4000; c = parent[c]) path.push(c);
    path.reverse();
    p.path = path; p.pi = Math.min(1, path.length - 1); p.target = found;
  }

  /* 光線で壁を測る。当たった壁と通り抜けた床に「測った」を書き込む */
  function scan(p) {
    var m = map, nx = m.nx, ny = m.ny, R = 5 + range * 10;
    for (var r = 0; r < NRAY; r++) {
      var a = r / NRAY * 6.2832, cx = Math.cos(a), cy = Math.sin(a), d = 0, hit = R;
      while (d < R) {
        d += 0.3;
        var gx = p.x + cx * d, gy = p.y + cy * d;
        if (gx < 0 || gy < 0 || gx >= nx || gy >= ny) { hit = d; break; }
        var k = (gy | 0) * nx + (gx | 0), v = 1 - d / R * 0.45;
        if (m.w[k]) {
          hit = d;
          if (m.seen[k] < v) m.seen[k] = v;
          break;
        }
        if (m.seen[k] < v) m.seen[k] = v;
      }
      p.hits[r] = hit;
    }
  }

  function moveProbe(p, dt) {
    var m = map, nx = m.nx;
    p.replan -= dt;
    if (p.hold > 0) p.hold -= dt;
    var arrived = !p.path.length || p.pi >= p.path.length;
    var spoiled = p.hold <= 0 && p.target >= 0 && m.seen[p.target] > 0.7;
    if (p !== leader && (arrived || spoiled || p.replan < 0)) {
      if (p.hold > 0 && p.target >= 0 && !arrived) plan(p, p.target); else plan(p, -1);
      p.replan = 4;
      SFX.play('tick', 2600);
    }
    var v = 1.6 + speed * 6, tx = p.x, ty = p.y;
    if (p.pi < p.path.length) {
      var c = p.path[p.pi];
      tx = (c % nx) + 0.5; ty = ((c / nx) | 0) + 0.5;
      if (Math.hypot(tx - p.x, ty - p.y) < 0.45) p.pi++;
    }
    var dx = tx - p.x, dy = ty - p.y, dl = Math.hypot(dx, dy) || 1;
    var want = p.pi < p.path.length ? v : 0;
    var k = Math.min(1, dt * 6);
    p.vx += (dx / dl * want - p.vx) * k;
    p.vy += (dy / dl * want - p.vy) * k;
    /* 探査機どうしは少しだけ離れる */
    for (var q = 0; q < probes.length; q++) {
      var o = probes[q];
      if (o === p) continue;
      var ox = p.x - o.x, oy = p.y - o.y, d2 = ox * ox + oy * oy;
      if (d2 < 2.2 && d2 > 1e-4) { p.vx += ox / d2 * dt * 3; p.vy += oy / d2 * dt * 3; }
    }
    var nx2 = p.x + p.vx * dt, ny2 = p.y + p.vy * dt;
    var cell = (ny2 | 0) * nx + (nx2 | 0);
    if (cell >= 0 && cell < m.w.length && !m.w[cell]) { p.x = nx2; p.y = ny2; }
    else { p.vx *= 0.3; p.vy *= 0.3; }
    var sp = Math.hypot(p.vx, p.vy);
    if (sp > 0.2) {
      var ta = Math.atan2(p.vy, p.vx), da = ta - p.dir;
      while (da > Math.PI) da -= 6.2832;
      while (da < -Math.PI) da += 6.2832;
      p.dir += da * Math.min(1, dt * 5);
    }
    p.sweep += dt * 2.4;
    p.trailT -= dt;
    if (p.trailT < 0) {
      p.trailT = 0.18;
      p.trail.push(p.x, p.y);
      if (p.trail.length > 360) p.trail.splice(0, 2);
    }
    scan(p);
  }

  /* ---------------------------------------------------------- 触る */
  function revealAround(gx, gy, rad) {
    var m = map, nx = m.nx, ny = m.ny;
    for (var j = Math.max(0, Math.floor(gy - rad)); j <= Math.min(ny - 1, Math.ceil(gy + rad)); j++) {
      for (var i = Math.max(0, Math.floor(gx - rad)); i <= Math.min(nx - 1, Math.ceil(gx + rad)); i++) {
        var d = Math.hypot(i + 0.5 - gx, j + 0.5 - gy);
        if (d > rad) continue;
        var k = j * nx + i, g = 1 - d / rad * 0.4;
        if (m.seen[k] < 1) m.seen[k] = 1;
        if (m.glow[k] < g) m.glow[k] = g;
      }
    }
  }

  function stepPings(dt) {
    var m = map;
    for (var n = pings.length - 1; n >= 0; n--) {
      var pg = pings[n], r0 = pg.r;
      pg.r += dt * 22;
      pg.t += dt;
      if (pg.r > pg.max + 4) { pings.splice(n, 1); continue; }
      var r1 = Math.min(pg.r, pg.max);
      if (r1 <= r0) continue;
      for (var j = Math.max(0, Math.floor(pg.y - r1)); j <= Math.min(m.ny - 1, Math.ceil(pg.y + r1)); j++) {
        for (var i = Math.max(0, Math.floor(pg.x - r1)); i <= Math.min(m.nx - 1, Math.ceil(pg.x + r1)); i++) {
          var d = Math.hypot(i + 0.5 - pg.x, j + 0.5 - pg.y);
          if (d < r0 || d > r1) continue;
          var k = j * m.nx + i, g = 1 - d / pg.max * 0.5;
          m.seen[k] = 1;
          if (m.glow[k] < g) m.glow[k] = g;
        }
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  var LV = 7;
  function drawMap(m, mult) {
    var cs = m.cs, ox = m.ox, oy = m.oy, nx = m.nx, seen = m.seen, glow = m.glow;
    /* 断面ハッチ */
    ctx.lineWidth = 1;
    for (var lv = 1; lv <= 3; lv++) {
      ctx.strokeStyle = P.rgba(P.steel, 0.09 * lv * mult);
      ctx.beginPath();
      for (var n = 0; n < m.hatch.length; n++) {
        var k = m.hatch[n], s = seen[k];
        if (Math.min(3, Math.ceil(s * 3 - 0.2)) !== lv) continue;
        var x = ox + (k % nx + 0.5) * cs, y = oy + (((k / nx) | 0) + 0.5) * cs, hh = cs * 0.36;
        ctx.moveTo(x - hh, y + hh); ctx.lineTo(x + hh, y - hh);
      }
      ctx.stroke();
    }
    /* 測った床の点 */
    var dot = Math.max(1.2, cs * 0.09);
    for (var lv2 = 1; lv2 <= 4; lv2++) {
      ctx.fillStyle = P.rgba(P.steel, 0.16 * lv2 * mult);
      ctx.beginPath();
      for (var n2 = 0; n2 < m.floor.length; n2++) {
        var k2 = m.floor[n2];
        if (glow[k2] > 0.08 || Math.ceil(seen[k2] * 4) !== lv2) continue;
        ctx.rect(ox + (k2 % nx + 0.5) * cs - dot / 2, oy + (((k2 / nx) | 0) + 0.5) * cs - dot / 2, dot, dot);
      }
      ctx.fill();
    }
    for (var lv3 = 1; lv3 <= 3; lv3++) {
      ctx.fillStyle = P.rgba(P.accent, 0.28 * lv3 * mult);
      ctx.beginPath();
      for (var n3 = 0; n3 < m.floor.length; n3++) {
        var k3 = m.floor[n3];
        if (glow[k3] <= 0.08 || Math.min(3, Math.ceil(glow[k3] * 3)) !== lv3) continue;
        ctx.rect(ox + (k3 % nx + 0.5) * cs - dot * 0.75, oy + (((k3 / nx) | 0) + 0.5) * cs - dot * 0.75, dot * 1.5, dot * 1.5);
      }
      ctx.fill();
    }
    /* 壁の輪郭。測った新しさで濃さを分け、触ったところは水色 */
    var lev = m.lev, sq = m.sq;
    for (var i = 0; i < sq.length; i++) {
      var q = sq[i];
      var s2 = Math.max(seen[q], seen[q + 1], seen[q + nx], seen[q + nx + 1]);
      var g2 = Math.max(glow[q], glow[q + 1], glow[q + nx], glow[q + nx + 1]);
      lev[i] = g2 > 0.08 ? 100 + Math.min(3, Math.ceil(g2 * 3)) : Math.min(LV, Math.ceil(s2 * LV));
    }
    function stroke(code, style, wdt) {
      ctx.strokeStyle = style; ctx.lineWidth = wdt;
      ctx.beginPath();
      for (var j = 0; j < sq.length; j++) {
        if (lev[j] !== code) continue;
        ctx.moveTo(ox + m.sx[j] * cs, oy + m.sy[j] * cs);
        ctx.lineTo(ox + m.ex[j] * cs, oy + m.ey[j] * cs);
      }
      ctx.stroke();
    }
    stroke(0, P.rgba(P.steel, 0.16 * mult), 1);
    for (var l = 1; l <= LV; l++) stroke(l, P.rgba(P.line, (0.1 + 0.85 * Math.pow(l / LV, 1.4)) * mult), 1 + 0.6 * l / LV);
    for (var g = 1; g <= 3; g++) stroke(100 + g, P.rgba(P.accent, (0.3 + 0.23 * g) * mult), 1.6);
  }

  function drawProbes() {
    var m = map, cs = m.cs, ox = m.ox, oy = m.oy;
    for (var n = 0; n < probes.length; n++) {
      var p = probes[n], px = ox + p.x * cs, py = oy + p.y * cs;
      /* 通った道 */
      if (p.trail.length > 4) {
        ctx.strokeStyle = P.rgba(P.steel, 0.32);
        ctx.lineWidth = 1;
        ctx.setLineDash([2, 4]);
        ctx.beginPath();
        ctx.moveTo(ox + p.trail[0] * cs, oy + p.trail[1] * cs);
        for (var t = 2; t < p.trail.length; t += 2) ctx.lineTo(ox + p.trail[t] * cs, oy + p.trail[t + 1] * cs);
        ctx.lineTo(px, py);
        ctx.stroke();
        ctx.setLineDash([]);
      }
      /* 見えている範囲 */
      ctx.fillStyle = P.rgba(P.line, 0.035);
      ctx.beginPath();
      for (var r = 0; r < NRAY; r++) {
        var a = r / NRAY * 6.2832, x = px + Math.cos(a) * p.hits[r] * cs, y = py + Math.sin(a) * p.hits[r] * cs;
        if (r) ctx.lineTo(x, y); else ctx.moveTo(x, y);
      }
      ctx.closePath();
      ctx.fill();
      /* 回る走査線と、当たった点 */
      ctx.lineWidth = 1;
      for (var r2 = 0; r2 < NRAY; r2++) {
        var a2 = r2 / NRAY * 6.2832, lag = (p.sweep - a2) % 6.2832;
        if (lag < 0) lag += 6.2832;
        if (lag > 1.1) continue;
        var f = 1 - lag / 1.1, hx = px + Math.cos(a2) * p.hits[r2] * cs, hy = py + Math.sin(a2) * p.hits[r2] * cs;
        ctx.strokeStyle = P.rgba(P.line, 0.22 * f * f);
        ctx.beginPath(); ctx.moveTo(px, py); ctx.lineTo(hx, hy); ctx.stroke();
        ctx.fillStyle = P.rgba(P.line, 0.85 * f);
        ctx.fillRect(hx - 1.2, hy - 1.2, 2.4, 2.4);
      }
      /* 本体 */
      var hot = p === leader || p.hold > 0;
      ctx.strokeStyle = P.rgba(hot ? P.accent : P.line, 0.9);
      ctx.lineWidth = 1.4;
      ctx.beginPath(); ctx.arc(px, py, 5, 0, 6.2832); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(px + Math.cos(p.dir) * 5, py + Math.sin(p.dir) * 5); ctx.lineTo(px + Math.cos(p.dir) * 10, py + Math.sin(p.dir) * 10); ctx.stroke();
      ctx.fillStyle = P.rgba(P.line, 0.95);
      ctx.beginPath(); ctx.arc(px, py, 1.8, 0, 6.2832); ctx.fill();
    }
  }

  function drawPings() {
    var m = map;
    ctx.lineWidth = 1.2;
    for (var n = 0; n < pings.length; n++) {
      var pg = pings[n], a = Math.max(0, 1 - pg.r / (pg.max + 4));
      ctx.strokeStyle = P.rgba(P.accent, 0.5 * a);
      ctx.beginPath(); ctx.arc(m.ox + pg.x * m.cs, m.oy + pg.y * m.cs, pg.r * m.cs, 0, 6.2832); ctx.stroke();
    }
  }

  function newMap() {
    if (map) { old = map; oldT = 0; }
    map = buildMap(mode);
    mapAge = 0;
    if (old && probes.length) {
      probes = probes.map(function (p) { return makeProbe(map, nearestFloor(map, p.x * old.cs / map.cs, p.y * old.cs / map.cs)); });
    } else spawnProbes(map);
    leader = null; pings.length = 0;
    probes.forEach(scan);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  var leader = null;
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; map = null; old = null; probes = []; newMap(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, leadT = 0;
  function toGrid(e) { return { x: (e.clientX - map.ox) / map.cs, y: (e.clientY - map.oy) / map.cs, sx: e.clientX, sy: e.clientY }; }
  function closest(g, skip) {
    var best = null, bd = 1e9;
    probes.forEach(function (p) {
      if (p === skip) return;
      var d = Math.hypot(p.x - g.x, p.y - g.y);
      if (d < bd) { bd = d; best = p; }
    });
    return best;
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    var g = toGrid(e);
    last = g;
    revealAround(g.x, g.y, 2.2);
    pings.push({ x: g.x, y: g.y, r: 0, t: 0, max: 7 + range * 8 });
    leader = closest(g, null);
    var goal = nearestFloor(map, g.x, g.y);
    if (leader) { plan(leader, goal); leadT = 0.25; }
    var second = closest(g, leader);
    if (second) { second.hold = 6; plan(second, goal); }
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var g = toGrid(e), dist = Math.hypot(g.sx - last.sx, g.sy - last.sy);
    if (dist < 6) return;
    var n = Math.ceil(dist / 12);
    for (var i = 1; i <= n; i++) revealAround(last.x + (g.x - last.x) * i / n, last.y + (g.y - last.y) * i / n, 3.2);
    last = g;
    SFX.play('move', 140);
  });
  function up() {
    down = false;
    if (leader) { leader.hold = 3; leader.replan = 3; }
    leader = null;
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = this.value / 100; });
  document.getElementById('k-range').addEventListener('input', function () { range = this.value / 100; });
  document.querySelectorAll('[data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-mode]').forEach(function (o) { o.classList.toggle('active', o === b); });
      if (mode !== b.dataset.mode) { mode = b.dataset.mode; newMap(); }
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = document.getElementById('k-speed').value / 100;
  range = document.getElementById('k-range').value / 100;
  var act = document.querySelector('[data-mode].active');
  if (act && act.dataset.mode !== mode) { mode = act.dataset.mode; map = null; old = null; probes = []; newMap(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    mapAge += dt;
    if (mapAge > MAP_LIFE && !down) { newMap(); SFX.play('grow'); }
    /* 古い測量は少しずつ薄れる。だから探査は終わらない */
    var seen = map.seen, glow = map.glow, fd = dt * FADE, gd = dt * 0.55;
    for (var k = 0; k < seen.length; k++) {
      if (seen[k] > 0) seen[k] = Math.max(0, seen[k] - fd);
      if (glow[k] > 0) glow[k] = Math.max(0, glow[k] - gd);
    }
    if (down && leader) {
      leadT -= dt;
      if (leadT < 0) { leadT = 0.25; plan(leader, nearestFloor(map, last.x, last.y)); }
    }
    stepPings(dt);
    for (var n = 0; n < probes.length; n++) moveProbe(probes[n], dt);

    P.fill(ctx, W, H);
    if (old) {
      oldT += dt;
      if (oldT > 3) old = null; else drawMap(old, 1 - oldT / 3);
    }
    drawMap(map, old ? Math.min(1, oldT / 1.5) : 1);
    drawProbes();
    drawPings();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
