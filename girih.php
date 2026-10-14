<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('girih');

$pageName = 'girih';
$pageTitle = 'GIRIH - PULSE';
$pageDesc = '多角形で埋めた床の各辺の中点から線が2本ずつ伸び、となりの線と交わって星形の帯模様になる。線の角度がゆっくり変わり、星が細くなったり太くなったりする波が床を渡っていく。押すとまわりの星が鋭くとがって水色に光り、なぞると跡に沿って星の形が変わる。';
$pageCanonical = 'https://pulse.subara3.com/girih.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-size">SIZE</label><input type="range" id="k-size" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" class="active" data-tiling="eight">EIGHT</button><button type="button" data-tiling="six">SIX</button><button type="button" data-tiling="three">THREE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 床の多角形は毎フレーム作り直す（流れていくので）。確保し直さないよう平らな配列に詰める */
  var MAXV = 40000, MAXP = 8000;
  var vx = new Float32Array(MAXV), vy = new Float32Array(MAXV);
  var pStart = new Int32Array(MAXP), pN = new Int32Array(MAXP), pR = new Float32Array(MAXP);
  var nv = 0, np = 0;
  /* 1 本の多角形ぶんの作業用 */
  var mx = new Float32Array(16), my = new Float32Array(16), th = new Float32Array(16), gl = new Float32Array(16);
  var qx = new Float32Array(16), qy = new Float32Array(16), qok = new Uint8Array(16);

  var tiling = 'eight';
  var speed = 0.45, size = 0.45, S = 80;
  var t = 0, panX = 0, panY = 0;
  var blooms = [];          /* 押した跡: 星の角度を変える場所 */
  var autoT = 2.5;

  function cellSize() {
    var base = Math.max(44, Math.min(110, Math.min(W, H) / 8));
    S = base * (0.6 + size * 0.9);
  }

  function poly(cx, cy, n, R, a0) {
    if (np >= MAXP || nv + n > MAXV) return;
    if (cx < -2 * R || cy < -2 * R || cx > W + 2 * R || cy > H + 2 * R) return;
    pStart[np] = nv; pN[np] = n; pR[np] = R;
    for (var k = 0; k < n; k++) {
      var a = a0 + k * 2 * Math.PI / n;
      vx[nv] = cx + R * Math.cos(a); vy[nv] = cy + R * Math.sin(a); nv++;
    }
    np++;
  }
  function tri(ax, ay, bx, by, cx, cy) {
    if (np >= MAXP || nv + 3 > MAXV) return;
    var ox = (ax + bx + cx) / 3, oy = (ay + by + cy) / 3;
    if (ox < -S || oy < -S || ox > W + S || oy > H + S) return;
    pStart[np] = nv; pN[np] = 3; pR[np] = Math.hypot(ax - ox, ay - oy);
    vx[nv] = ax; vy[nv] = ay; nv++;
    vx[nv] = bx; vy[nv] = by; nv++;
    vx[nv] = cx; vy[nv] = cy; nv++;
    np++;
  }

  function mod(a, m) { return ((a % m) + m) % m; }

  /* 床を敷く。ずらし量は周期で割った余りだけ使うので、いくら流れても範囲は変わらない */
  function build() {
    nv = 0; np = 0;
    var i, j;
    if (tiling === 'eight') {
      /* 正八角形と正方形 (4.8.8) */
      var a = S / (1 + Math.SQRT2);
      var R8 = a / (2 * Math.sin(Math.PI / 8)), R4 = a / Math.SQRT2;
      var ox = mod(panX, S), oy = mod(panY, S);
      for (j = -2; j * S < H + 2 * S; j++) {
        for (i = -2; i * S < W + 2 * S; i++) {
          var cx = i * S + ox, cy = j * S + oy;
          poly(cx, cy, 8, R8, Math.PI / 8);
          poly(cx + S / 2, cy + S / 2, 4, R4, 0);
        }
      }
    } else if (tiling === 'six') {
      /* 正六角形 (6.6.6) */
      var h = S * 0.6, dx = 1.5 * h, dy = Math.sqrt(3) * h;
      var px = mod(panX, 2 * dx), py = mod(panY, dy);
      for (i = -3; i * dx < W + 3 * dx; i++) {
        for (j = -2; j * dy < H + 2 * dy; j++) {
          poly(i * dx + px, j * dy + py + ((i & 1) ? dy / 2 : 0), 6, h, 0);
        }
      }
    } else {
      /* 六角形と三角形 (3.6.3.6) */
      var b = S * 0.5, ux = 2 * b, vxx = b, vyy = Math.sqrt(3) * b;
      var qx0 = mod(panX, ux), qy0 = mod(panY, 2 * vyy);
      var jMin = -3, jMax = Math.ceil((H + 3 * vyy) / vyy);
      for (j = jMin; j <= jMax; j++) {
        var shift = j * vxx, iMin = Math.floor((-3 * ux - shift) / ux) - 1, iMax = Math.ceil((W + 3 * ux - shift) / ux) + 1;
        for (i = iMin; i <= iMax; i++) {
          var x0 = i * ux + shift + qx0 - 2 * ux, y0 = j * vyy + qy0 - 2 * vyy;
          poly(x0, y0, 6, b, 0);
          /* 上向き: p, p+u, p+v の辺の中点 / 下向き: p+u, p+u+v, p+v の辺の中点 */
          tri(x0 + ux / 2, y0, x0 + (ux + vxx) / 2, y0 + vyy / 2, x0 + vxx / 2, y0 + vyy / 2);
          tri(x0 + ux + vxx / 2, y0 + vyy / 2, x0 + vxx + ux / 2, y0 + vyy, x0 + (ux + vxx) / 2, y0 + vyy / 2);
        }
      }
    }
  }

  /* 押した跡の強さ: 0.18 秒で立ち上がり、ゆっくり引く */
  function env(b) {
    if (b.hold) return 1;
    if (b.age < 0.18) { var u = b.age / 0.18; return u * u * (3 - 2 * u); }
    return Math.exp(-(b.age - 0.18) / 1.8);
  }
  var bx = new Float32Array(80), by = new Float32Array(80), br2 = new Float32Array(80), ba = new Float32Array(80), bg = new Uint8Array(80), nb = 0;
  function packBlooms() {
    nb = 0;
    for (var k = 0; k < blooms.length && nb < 80; k++) {
      var b = blooms[k], e = env(b) * b.amp;
      if (e < 0.01) continue;
      bx[nb] = b.x; by[nb] = b.y; br2[nb] = b.r * b.r; ba[nb] = e; bg[nb] = b.glow ? 1 : 0; nb++;
    }
  }
  var outGlow = 0;
  function bloomAt(x, y) {
    var s = 0, g = 0;
    for (var k = 0; k < nb; k++) {
      var dx = x - bx[k], dy = y - by[k], d2 = dx * dx + dy * dy;
      if (d2 > br2[k] * 6) continue;
      var w = ba[k] * Math.exp(-d2 / br2[k]);
      s += w; if (bg[k]) g += w;
    }
    outGlow = g > 1 ? 1 : g;
    return s > 1 ? 1 : s;
  }

  /* 床全体を渡る、星の角度の波 */
  var waveA = 0.6;
  function theta(x, y) {
    var L = Math.max(W, H);
    var c = Math.cos(waveA), s = Math.sin(waveA);
    var u = (x * c + y * s) / L, v = (-x * s + y * c) / L;
    var w = 0.86 + 0.26 * Math.sin(u * 6.0 - t * 0.55) + 0.06 * Math.sin(v * 9.0 + t * 0.31);
    var b = bloomAt(x, y);
    return w + (1.42 - w) * b;
  }

  var LW = 4;
  function draw() {
    P.fill(ctx, W, H, 1);
    var fillDeep = new Path2D(), edges = new Path2D(), band = new Path2D();
    var glowPaths = [new Path2D(), new Path2D(), new Path2D()], glowFill = [new Path2D(), new Path2D(), new Path2D()];
    var anyGlow = [false, false, false];
    LW = Math.max(2.2, S * 0.05);
    for (var p = 0; p < np; p++) {
      var st = pStart[p], n = pN[p], R = pR[p];
      /* 向き（どちら回りに並んでいるか）で、内側へ回す向きを決める */
      var area = 0;
      for (var k = 0; k < n; k++) {
        var k1 = (k + 1) % n;
        area += vx[st + k] * vy[st + k1] - vx[st + k1] * vy[st + k];
      }
      var sg = area > 0 ? 1 : -1;
      var gmax = 0;
      for (k = 0; k < n; k++) {
        k1 = (k + 1) % n;
        mx[k] = (vx[st + k] + vx[st + k1]) * 0.5; my[k] = (vy[st + k] + vy[st + k1]) * 0.5;
        th[k] = theta(mx[k], my[k]); gl[k] = outGlow;
        if (outGlow > gmax) gmax = outGlow;
        edges.moveTo(vx[st + k], vy[st + k]); edges.lineTo(vx[st + k1], vy[st + k1]);
      }
      /* 辺 k の中点から頂点 k+1 へ向かう線と、辺 k+1 の中点から同じ頂点へ向かう線は、
         正多角形なら中心と頂点を結ぶ線の上で交わる。2 本の線を直接交わらせると、
         角度が 180/n 度の近くで 2 本がほぼ一直線に並んで交点が暴れるので、こちらで解く */
      var ccx = 0, ccy = 0;
      for (k = 0; k < n; k++) { ccx += vx[st + k]; ccy += vy[st + k]; }
      ccx /= n; ccy /= n;
      for (k = 0; k < n; k++) {
        k1 = (k + 1) % n;
        var ex = vx[st + k1] - vx[st + k], ey = vy[st + k1] - vy[st + k];
        var el = Math.hypot(ex, ey); ex /= el; ey /= el;
        var a1 = sg * (th[k] + th[k1]) * 0.5;
        var c1 = Math.cos(a1), s1 = Math.sin(a1);
        var d1x = ex * c1 - ey * s1, d1y = ex * s1 + ey * c1;
        var wx = vx[st + k1] - ccx, wy = vy[st + k1] - ccy;
        var den = wx * d1y - d1x * wy;
        qok[k] = 0;
        if (Math.abs(den) < 1e-6) continue;
        var rx = ccx - mx[k], ry = ccy - my[k];
        var tt = (wx * ry - rx * wy) / den;
        if (tt < 0 || tt > 2.2 * R) continue;
        qx[k] = mx[k] + d1x * tt; qy[k] = my[k] + d1y * tt; qok[k] = 1;
      }
      /* 星の内側を暗く塗る */
      var okAll = true;
      for (k = 0; k < n; k++) if (!qok[k]) { okAll = false; break; }
      if (okAll) {
        var fp = gmax > 0.12 ? glowFill[gmax > 0.66 ? 2 : gmax > 0.36 ? 1 : 0] : fillDeep;
        fp.moveTo(mx[0], my[0]);
        for (k = 0; k < n; k++) { fp.lineTo(qx[k], qy[k]); fp.lineTo(mx[(k + 1) % n], my[(k + 1) % n]); }
        fp.closePath();
      }
      for (k = 0; k < n; k++) {
        if (!qok[k]) continue;
        k1 = (k + 1) % n;
        var g = Math.max(gl[k], gl[k1]);
        var path = band;
        if (g > 0.12) { var gi = g > 0.66 ? 2 : g > 0.36 ? 1 : 0; path = glowPaths[gi]; anyGlow[gi] = true; }
        path.moveTo(mx[k], my[k]); path.lineTo(qx[k], qy[k]); path.lineTo(mx[k1], my[k1]);
      }
    }
    ctx.fillStyle = P.rgba(P.deep, 0.75);
    ctx.fill(fillDeep);
    for (var q = 0; q < 3; q++) {
      if (!anyGlow[q]) continue;
      ctx.fillStyle = P.rgba(P.accent, 0.035 + q * 0.03);
      ctx.fill(glowFill[q]);
    }
    ctx.lineJoin = 'miter'; ctx.miterLimit = 5; ctx.lineCap = 'butt';
    ctx.lineWidth = 1;
    ctx.strokeStyle = P.rgba(P.steel, 0.22);
    ctx.stroke(edges);
    /* 帯: 白い太線の中を地の色で抜く */
    ctx.lineWidth = LW;
    ctx.strokeStyle = P.rgba(P.line, 0.86);
    ctx.stroke(band);
    for (q = 0; q < 3; q++) {
      if (!anyGlow[q]) continue;
      ctx.strokeStyle = P.rgba(P.accent, 0.55 + q * 0.2);
      ctx.stroke(glowPaths[q]);
    }
    ctx.lineWidth = LW * 0.42;
    ctx.strokeStyle = P.rgba(P.bg, 1);
    ctx.stroke(band);
    for (q = 0; q < 3; q++) if (anyGlow[q]) ctx.stroke(glowPaths[q]);
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; cellSize(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, hold = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY };
    hold = { x: last.x, y: last.y, r: S * 1.8, amp: 1, age: 0, glow: true, hold: false };
    blooms.push(hold);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    if (hold) { hold.x = p.x; hold.y = p.y; }
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < S * 0.3) return;
    blooms.push({ x: p.x, y: p.y, r: S * 1.1, amp: 0.9, age: 0, glow: true, hold: false });
    last = p;
    SFX.play('move', 140);
  });
  function up() {
    down = false;
    if (hold) { hold.hold = false; hold.age = Math.max(hold.age, 0.18); hold = null; }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = this.value / 100; });
  document.getElementById('k-size').addEventListener('input', function () { size = this.value / 100; cellSize(); });
  document.querySelectorAll('[data-tiling]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-tiling]').forEach(function (o) { o.classList.toggle('active', o === b); });
      tiling = b.dataset.tiling;
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = document.getElementById('k-speed').value / 100;
  size = document.getElementById('k-size').value / 100;
  cellSize();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    var v = 0.25 + speed * 1.5;
    t += dt * v;
    waveA += dt * v * 0.02;
    panX += dt * v * 7; panY += dt * v * 3.5;
    /* 放っておいても、ときどきどこかの星がひとりでにとがる（水色にはしない） */
    autoT -= dt * v;
    if (autoT <= 0) {
      autoT = 3.5 + Math.random() * 3;
      blooms.push({ x: W * (0.15 + Math.random() * 0.7), y: H * (0.15 + Math.random() * 0.7), r: S * 2.6, amp: 0.7, age: 0, glow: false, hold: false });
      SFX.play('grow', 4000);
    }
    for (var k = blooms.length - 1; k >= 0; k--) {
      var b = blooms[k];
      if (b === hold && down) { b.age = Math.min(b.age + dt, 0.18); continue; }
      b.age += b.glow ? dt : dt * v * 0.6;
      if (b.age > 9) blooms.splice(k, 1);
    }
    if (blooms.length > 70) blooms.splice(0, blooms.length - 70);
    packBlooms();
    build();
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
