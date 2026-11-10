<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('floe');

$pageName = 'floe';
$pageTitle = 'FLOE - PULSE';
$pageDesc = '大小の流氷が海面をすき間なく覆い、ゆっくり横へ流れていく。うねりが通るたびに氷のあいだのすき間が開いたり閉じたりし、ときどき一筋の割れ目が何枚もの氷を横切る。押すとそこから氷が放射状に割れて水色に光りながら押し広げられ、なぞると指の跡に水路が開いて、しばらくすると氷が寄ってきて閉じる。';
$pageCanonical = 'https://pulse.subara3.com/floe.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drift">DRIFT</label><input type="range" id="k-drift" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-swell">SWELL</label><input type="range" id="k-swell" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" id="k-freeze">FREEZE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 状態 */
  var S = 80;                 /* 氷 1 枚の目安の大きさ */
  var floes = [], seeds = [];
  var seedEdge = 0, cellEdge = 0;
  var drift = 0.4, swell = 0.55, T = 0;
  var GAP = 2.2;
  var WATER = P.mix(P.deep, P.bg, 0.45);
  var finger = { down: false, x: 0, y: 0, px: 0, py: 0, dx: 1, dy: 0, r: 0, cut: 0 };
  var nextCrack = 3;

  /* ---------------------------------------------------------- 多角形 */
  function polyArea(xs, ys) {
    var a = 0, n = xs.length;
    for (var i = 0, j = n - 1; i < n; j = i++) a += xs[j] * ys[i] - xs[i] * ys[j];
    return a * 0.5;
  }
  function makeFloe(xs, ys, base) {
    var n = xs.length, A = polyArea(xs, ys);
    if (A < 0) { xs.reverse(); ys.reverse(); A = -A; }
    var cx = 0, cy = 0;
    for (var i = 0, j = n - 1; i < n; j = i++) {
      var c = xs[j] * ys[i] - xs[i] * ys[j];
      cx += (xs[j] + xs[i]) * c; cy += (ys[j] + ys[i]) * c;
    }
    cx /= 6 * A; cy /= 6 * A;
    var a = base ? base.a : 0, ca = Math.cos(-a), sa = Math.sin(-a);
    var lx = new Float32Array(n), ly = new Float32Array(n), R = 0;
    for (var k = 0; k < n; k++) {
      var dx = xs[k] - cx, dy = ys[k] - cy;
      lx[k] = dx * ca - dy * sa; ly[k] = dx * sa + dy * ca;
      R = Math.max(R, Math.hypot(dx, dy));
    }
    return {
      x: cx, y: cy, a: a, vx: base ? base.vx : 0, vy: base ? base.vy : 0, va: base ? base.va : 0,
      hx: base ? base.hx + (cx - base.x) : cx, hy: base ? base.hy + (cy - base.y) : cy,
      n: n, lx: lx, ly: ly, wx: new Float32Array(n), wy: new Float32Array(n),
      A: A, R: R, ph: base ? base.ph : Math.random() * 6.28,
      glow: base ? base.glow : 0, flash: base ? base.flash : 0, loose: base ? base.loose : 0, press: 0, comp: 0
    };
  }
  function toWorld(f) {
    var c = Math.cos(f.a), s = Math.sin(f.a);
    for (var i = 0; i < f.n; i++) {
      f.wx[i] = f.x + f.lx[i] * c - f.ly[i] * s;
      f.wy[i] = f.y + f.lx[i] * s + f.ly[i] * c;
    }
  }
  /* 半平面 (v - m)·n <= off で切り取る */
  function clip(xs, ys, mx, my, nx, ny, off) {
    var ox = [], oy = [], n = xs.length;
    for (var i = 0; i < n; i++) {
      var j = (i + 1) % n;
      var di = (xs[i] - mx) * nx + (ys[i] - my) * ny - off;
      var dj = (xs[j] - mx) * nx + (ys[j] - my) * ny - off;
      if (di <= 0) { ox.push(xs[i]); oy.push(ys[i]); }
      if ((di <= 0) !== (dj <= 0)) {
        var t = di / (di - dj);
        ox.push(xs[i] + (xs[j] - xs[i]) * t); oy.push(ys[i] + (ys[j] - ys[i]) * t);
      }
    }
    return [ox, oy];
  }

  /* ---------------------------------------------------------- 氷を作る */
  function addSeeds(x0, x1) {
    var rows = Math.ceil((H + 3 * S) / S);
    for (var x = x0; x < x1; x += S) {
      for (var r = 0; r < rows; r++) {
        if (Math.random() < 0.16) continue;               /* 抜けたところは大きな氷になる */
        seeds.push({ x: x + Math.random() * S, y: -1.5 * S + (r + Math.random()) * S });
        if (Math.random() < 0.18) seeds.push({ x: x + Math.random() * S, y: -1.5 * S + (r + Math.random()) * S });
      }
    }
  }
  function buildCells(x0, x1) {
    for (var i = 0; i < seeds.length; i++) {
      var p = seeds[i];
      if (p.x < x0 || p.x >= x1) continue;
      var B = 2.2 * S;
      var xs = [p.x - B, p.x + B, p.x + B, p.x - B], ys = [p.y - B, p.y - B, p.y + B, p.y + B];
      for (var j = 0; j < seeds.length && xs.length > 2; j++) {
        if (j === i) continue;
        var q = seeds[j], dx = q.x - p.x, dy = q.y - p.y;
        if (Math.abs(dx) > 2.6 * S || Math.abs(dy) > 2.6 * S) continue;
        var d = Math.hypot(dx, dy);
        if (d < 1e-3) continue;
        var cl = clip(xs, ys, (p.x + q.x) / 2, (p.y + q.y) / 2, dx / d, dy / d, -GAP);
        xs = cl[0]; ys = cl[1];
      }
      if (xs.length > 2 && Math.abs(polyArea(xs, ys)) > S * S * 0.04) floes.push(makeFloe(xs, ys, null));
    }
  }
  function extend() {
    while (cellEdge < W + 2 * S) {
      while (seedEdge < cellEdge + 7 * S) { addSeeds(seedEdge, seedEdge + 4 * S); seedEdge += 4 * S; }
      buildCells(cellEdge, cellEdge + 4 * S);
      cellEdge += 4 * S;
    }
  }
  function regen() {
    floes = []; seeds = [];
    seedEdge = cellEdge = -3 * S;
    extend();
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i], sw = swellAt(f.hx, f.hy);
      f.x = f.hx + sw[0]; f.y = f.hy + sw[1];
    }
  }

  /* ---------------------------------------------------------- 割る */
  function split(f, px, py, ux, uy) {
    toWorld(f);
    var nx = -uy, ny = ux;
    var ax = [], ay = [], bx = [], by = [];
    for (var i = 0; i < f.n; i++) {
      var j = (i + 1) % f.n;
      var di = (f.wx[i] - px) * nx + (f.wy[i] - py) * ny;
      var dj = (f.wx[j] - px) * nx + (f.wy[j] - py) * ny;
      if (di >= 0) { ax.push(f.wx[i] + nx * GAP * 0.5); ay.push(f.wy[i] + ny * GAP * 0.5); }
      if (di < 0) { bx.push(f.wx[i] - nx * GAP * 0.5); by.push(f.wy[i] - ny * GAP * 0.5); }
      if ((di >= 0) !== (dj >= 0)) {
        var t = di / (di - dj);
        var cx = f.wx[i] + (f.wx[j] - f.wx[i]) * t, cy = f.wy[i] + (f.wy[j] - f.wy[i]) * t;
        ax.push(cx + nx * GAP * 0.5); ay.push(cy + ny * GAP * 0.5);
        bx.push(cx - nx * GAP * 0.5); by.push(cy - ny * GAP * 0.5);
      }
    }
    if (ax.length < 3 || bx.length < 3) return null;
    var minA = S * S * 0.05;
    if (Math.abs(polyArea(ax, ay)) < minA || Math.abs(polyArea(bx, by)) < minA) return null;
    var a = makeFloe(ax, ay, f), b = makeFloe(bx, by, f);
    a.side = 1; b.side = -1; a.nx = nx; a.ny = ny; b.nx = nx; b.ny = ny;
    return [a, b];
  }
  /* p から向き u へ長さ L の割れ目を走らせる。full なら反対向きにも */
  function crack(px, py, ux, uy, L, full, touch) {
    if (floes.length > 900) return 0;
    var out = [], count = 0, nx = -uy, ny = ux;
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i];
      var t = (f.x - px) * ux + (f.y - py) * uy, o = (f.x - px) * nx + (f.y - py) * ny;
      var hit = Math.abs(o) < f.R * 0.9 && t < L + f.R * 0.5 && t > (full ? -L - f.R * 0.5 : -f.R);
      var pcs = hit && f.A > S * S * 0.14 ? split(f, px, py, ux, uy) : null;
      if (!pcs) { out.push(f); continue; }
      count++;
      for (var k = 0; k < 2; k++) {
        var q = pcs[k], s = q.side;
        q.flash = 1;
        q.vx += q.nx * s * (touch ? 40 : 18); q.vy += q.ny * s * (touch ? 40 : 18);
        q.hx += q.nx * s * GAP; q.hy += q.ny * s * GAP;
        q.loose = Math.max(q.loose, touch ? 1 : 0.45);
        if (touch) q.glow = 1;
        out.push(q);
      }
    }
    floes = out;
    return count;
  }

  /* ---------------------------------------------------------- うねり */
  function swellAt(x, y) {
    var A = swell * 0.13 * S, k1 = 6.2832 / (7 * S), k2 = 6.2832 / (11 * S);
    var t1 = Math.PI + 0.35 * Math.sin(T * 0.021), t2 = 2.1 + 0.4 * Math.sin(T * 0.017 + 1);
    var d1x = Math.cos(t1), d1y = Math.sin(t1), d2x = Math.cos(t2), d2y = Math.sin(t2);
    var f1 = k1 * (x * d1x + y * d1y) - T * 0.63, f2 = k2 * (x * d2x + y * d2y) - T * 0.45;
    var s1 = A * Math.sin(f1), s2 = A * 0.55 * Math.sin(f2);
    return [s1 * d1x + s2 * d2x, s1 * d1y + s2 * d2y, -(A * k1 * Math.cos(f1) + A * 0.55 * k2 * Math.cos(f2))];
  }

  /* ---------------------------------------------------------- ぶつかり（分離軸） */
  var grid = new Map();
  function project(f, ax, ay, out) {
    var mn = 1e9, mx = -1e9;
    for (var i = 0; i < f.n; i++) { var p = f.wx[i] * ax + f.wy[i] * ay; if (p < mn) mn = p; if (p > mx) mx = p; }
    out[0] = mn; out[1] = mx;
  }
  var pa = [0, 0], pb = [0, 0];
  function collide(a, b) {
    var best = 1e9, bx = 0, by = 0;
    for (var pass = 0; pass < 2; pass++) {
      var f = pass ? b : a;
      for (var i = 0; i < f.n; i++) {
        var j = (i + 1) % f.n, ex = f.wx[j] - f.wx[i], ey = f.wy[j] - f.wy[i], l = Math.hypot(ex, ey);
        if (l < 1e-6) continue;
        var ax = -ey / l, ay = ex / l;
        project(a, ax, ay, pa); project(b, ax, ay, pb);
        var o = Math.min(pa[1] - pb[0], pb[1] - pa[0]);
        if (o <= 0) return;
        if (o < best) { best = o; bx = ax; by = ay; }
      }
    }
    if ((b.x - a.x) * bx + (b.y - a.y) * by < 0) { bx = -bx; by = -by; }
    var ma = a.A, mb = b.A, wa = mb / (ma + mb), wb = ma / (ma + mb);
    var push = best * 0.8;
    shift(a, -bx * push * wa, -by * push * wa);
    shift(b, bx * push * wb, by * push * wb);
    var vr = (b.vx - a.vx) * bx + (b.vy - a.vy) * by;
    if (vr < 0) {
      a.vx += bx * vr * wa * 0.9; a.vy += by * vr * wa * 0.9;
      b.vx -= bx * vr * wb * 0.9; b.vy -= by * vr * wb * 0.9;
    }
    var pr = Math.min(1, best / 4);
    if (pr > a.press) a.press = pr;
    if (pr > b.press) b.press = pr;
    if (a.glow > 0.3 && b.glow < a.glow * 0.5) b.glow = a.glow * 0.5;
    if (b.glow > 0.3 && a.glow < b.glow * 0.5) a.glow = b.glow * 0.5;
  }
  function shift(f, dx, dy) {
    f.x += dx; f.y += dy;
    for (var i = 0; i < f.n; i++) { f.wx[i] += dx; f.wy[i] += dy; }
  }
  function collisions() {
    var cs = 2.4 * S;
    grid.clear();
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i], key = (Math.floor(f.x / cs) + 512) * 4096 + Math.floor(f.y / cs) + 512;
      var c = grid.get(key);
      if (c) c.push(f); else grid.set(key, [f]);
      f.cx = Math.floor(f.x / cs); f.cy = Math.floor(f.y / cs);
    }
    for (var k = 0; k < floes.length; k++) {
      var a = floes[k];
      for (var gx = -1; gx <= 1; gx++) for (var gy = -1; gy <= 1; gy++) {
        var cell = grid.get((a.cx + gx + 512) * 4096 + a.cy + gy + 512);
        if (!cell) continue;
        for (var m = 0; m < cell.length; m++) {
          var b = cell[m];
          if (b === a || b.x < a.x || (b.x === a.x && b.y <= a.y)) continue;
          var dx = b.x - a.x, dy = b.y - a.y, rr = a.R + b.R;
          if (dx * dx + dy * dy < rr * rr) collide(a, b);
        }
      }
    }
  }

  /* 指: 押さえているあいだは円い穴。中に入った氷は割るか押しのける */
  function fingerPush() {
    var fx = finger.x, fy = finger.y, r = finger.r;
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i], dx = f.x - fx, dy = f.y - fy;
      if (dx * dx + dy * dy > (r + f.R) * (r + f.R)) continue;
      var best = 1e9, cxp = 0, cyp = 0, inside = true;
      for (var a = 0; a < f.n; a++) {
        var b = (a + 1) % f.n, ex = f.wx[b] - f.wx[a], ey = f.wy[b] - f.wy[a];
        var ll = ex * ex + ey * ey || 1;
        if (ex * (fy - f.wy[a]) - ey * (fx - f.wx[a]) < 0) inside = false;
        var t = Math.max(0, Math.min(1, ((fx - f.wx[a]) * ex + (fy - f.wy[a]) * ey) / ll));
        var qx = f.wx[a] + ex * t, qy = f.wy[a] + ey * t, d = Math.hypot(qx - fx, qy - fy);
        if (d < best) { best = d; cxp = qx; cyp = qy; }
      }
      var nx, ny, amt;
      if (inside) {
        var dl = Math.hypot(dx, dy) || 1;
        nx = dx / dl; ny = dy / dl; amt = Math.min(r * 0.25, 6 + best * 0.3);
      } else {
        if (best >= r) continue;
        nx = (cxp - fx) / (best || 1); ny = (cyp - fy) / (best || 1); amt = r - best;
      }
      shift(f, nx * amt, ny * amt);
      var vn = f.vx * nx + f.vy * ny;
      if (vn < 60) { f.vx += nx * (60 - vn) * 0.5; f.vy += ny * (60 - vn) * 0.5; }
      f.loose = 1; f.glow = Math.max(f.glow, 0.9);
    }
  }

  function press(x, y) {
    var th = Math.random() * 6.2832, n = 0;
    for (var k = 0; k < 3; k++) {
      var a = th + k * 2.0944 + (Math.random() - 0.5) * 0.6;
      n += crack(x, y, Math.cos(a), Math.sin(a), 1.7 * S, false, true);
    }
    var R = 2.0 * S;
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i], dx = f.x - x, dy = f.y - y, d = Math.hypot(dx, dy);
      if (d > R) continue;
      var w = Math.pow(1 - d / R, 0.7), dl = d || 1;
      f.vx += dx / dl * 150 * w; f.vy += dy / dl * 150 * w;
      f.va += (Math.random() - 0.5) * 0.5 * w;
      f.loose = Math.max(f.loose, w > 0.2 ? 1 : 0.6);
      f.glow = Math.max(f.glow, 0.35 + 0.65 * w);
    }
    return n;
  }

  /* ---------------------------------------------------------- 描く */
  function shape(xs, ys, n, cx, cy, sc) {
    ctx.beginPath();
    for (var i = 0; i < n; i++) {
      var h = (i + n - 1) % n, j = (i + 1) % n;
      var vx = cx + (xs[i] - cx) * sc, vy = cy + (ys[i] - cy) * sc;
      var hx = cx + (xs[h] - cx) * sc, hy = cy + (ys[h] - cy) * sc;
      var jx = cx + (xs[j] - cx) * sc, jy = cy + (ys[j] - cy) * sc;
      var lh = Math.hypot(hx - vx, hy - vy) || 1, lj = Math.hypot(jx - vx, jy - vy) || 1;
      var ch = Math.min(0.35, S * 0.09 / lh), cj = Math.min(0.35, S * 0.09 / lj);
      var ax = vx + (hx - vx) * ch, ay = vy + (hy - vy) * ch;
      if (i === 0) ctx.moveTo(ax, ay); else ctx.lineTo(ax, ay);
      ctx.quadraticCurveTo(vx, vy, vx + (jx - vx) * cj, vy + (jy - vy) * cj);
    }
    ctx.closePath();
  }
  function draw() {
    ctx.fillStyle = P.rgba(WATER, 1);
    ctx.fillRect(0, 0, W, H);
    P.grid(ctx, W, H, { step: Math.round(S / 2.5), alpha: 0.7 });
    ctx.lineJoin = 'round';
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i];
      if (f.x + f.R < 0 || f.x - f.R > W || f.y + f.R < 0 || f.y - f.R > H) continue;
      var c = Math.max(0, Math.min(1, f.comp * 6));
      var ice = P.mix(P.bg, P.line, 0.07 + 0.07 * c + 0.06 * f.flash + 0.04 * f.press);
      if (f.glow > 0) ice = P.mix(ice, P.accent, 0.16 * f.glow);
      shape(f.wx, f.wy, f.n, f.x, f.y, 1);
      ctx.fillStyle = P.rgba(ice, 1);
      ctx.fill();
      var ed = f.glow > 0 ? P.mix(P.line, P.accent, f.glow) : P.line;
      ctx.strokeStyle = P.rgba(ed, Math.min(1, 0.42 + 0.25 * f.press + 0.45 * f.flash + 0.35 * f.glow));
      ctx.lineWidth = 1.1 + 0.6 * f.flash;
      ctx.stroke();
      if (f.A > S * S * 0.35) {
        shape(f.wx, f.wy, f.n, f.x, f.y, 0.68);
        ctx.strokeStyle = P.rgba(f.glow > 0.2 ? P.accent : P.steel, 0.22 + 0.2 * f.glow);
        ctx.lineWidth = 1;
        ctx.stroke();
      }
    }
  }

  /* 状態をすべて宣言してから呼ぶ */
  ctx = P.fit(canvas, function (w, h) {
    W = w; H = h;
    S = Math.max(46, Math.min(96, Math.min(W, H) / 7.5));
    regen();
  });

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    finger.down = true;
    finger.x = finger.px = e.clientX; finger.y = finger.py = e.clientY;
    finger.r = S * 0.3; finger.cut = 0;
    press(finger.x, finger.y);
    SFX.play('pop');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!finger.down) return;
    var x = e.clientX, y = e.clientY, dx = x - finger.px, dy = y - finger.py, d = Math.hypot(dx, dy);
    finger.x = x; finger.y = y;
    if (d < 4) return;
    finger.dx = dx / d; finger.dy = dy / d;
    finger.px = x; finger.py = y;
    if (d > S * 0.25 || finger.cut <= 0) {
      if (crack(x, y, finger.dx, finger.dy, S * 0.4, true, true) > 0) SFX.play('tick', 160);
      finger.cut = 0.12;
    }
    SFX.play('move', 140);
  });
  function up() { finger.down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-drift').addEventListener('input', function () { drift = this.value / 100; });
  document.getElementById('k-swell').addEventListener('input', function () { swell = this.value / 100; });
  document.getElementById('k-freeze').addEventListener('click', function () { regen(); SFX.play('click'); });
  drift = document.getElementById('k-drift').value / 100;
  swell = document.getElementById('k-swell').value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    T += dt;
    var v = (2 + drift * 16) * dt;
    for (var s = 0; s < seeds.length; s++) seeds[s].x -= v;
    seedEdge -= v; cellEdge -= v;

    var K = 1.4, C = 1.5;
    for (var i = 0; i < floes.length; i++) {
      var f = floes[i];
      f.hx -= v; f.x -= v;
      var sw = swellAt(f.hx, f.hy), k = K * (1 - f.loose) * (1 - f.loose);
      f.comp = sw[2];
      f.vx += (k * (f.hx + sw[0] - f.x) - C * f.vx) * dt;
      f.vy += (k * (f.hy + sw[1] - f.y) - C * f.vy) * dt;
      f.x += f.vx * dt; f.y += f.vy * dt;
      f.va += (0.02 * Math.sin(T * 0.11 + f.ph) - f.va) * 0.6 * dt;
      f.a += f.va * dt;
      f.loose = Math.max(0, f.loose - 0.2 * dt);
      f.glow = Math.max(0, f.glow - 0.35 * dt);
      f.flash = Math.max(0, f.flash - 0.5 * dt);
      f.press *= Math.max(0, 1 - 3 * dt);
      toWorld(f);
    }
    if (finger.down) {
      finger.r = Math.min(S * 0.85, finger.r + S * 1.2 * dt);
      finger.cut -= dt;
      fingerPush();
    }
    collisions(); collisions();

    /* 左へ出た氷と種を捨て、右から新しい氷を足す */
    floes = floes.filter(function (f) { return f.x > -3 * S && f.hx > -3 * S; });
    seeds = seeds.filter(function (p) { return p.x > -4 * S; });
    extend();

    /* ときどき、一筋の割れ目が何枚もの氷を横切る */
    nextCrack -= dt;
    if (nextCrack <= 0) {
      nextCrack = 5 + Math.random() * 4;
      var a = Math.random() * 3.1416;
      if (crack(W * (0.15 + Math.random() * 0.7), H * (0.15 + Math.random() * 0.7), Math.cos(a), Math.sin(a), 1.8 * S, true, false) > 0) SFX.play('tick', 3000);
    }

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
