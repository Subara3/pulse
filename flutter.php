<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('flutter');

$pageName = 'flutter';
$pageTitle = 'FLUTTER - PULSE';
$pageDesc = '細長い紙片が何十枚も、左右に揺れたりくるくる回ったりしながら落ちてくる。通った跡には、少し前の向きが細い線で点々と残る。押すとそこから風が吹いて紙片が舞い上がり、同じ向きで放たれた水色の紙片が数枚、すぐにばらばらの道に分かれて落ちていく。なぞると指の向きに紙片が流される。';
$pageCanonical = 'https://pulse.subara3.com/flutter.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-count">COUNT</label><input type="range" id="k-count" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="60"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 紙片は横から見た細い板。長さの半分を 1、重力を 1 とした単位で解き、TS 倍の速さで画面に出す。
     板の向きに沿った抵抗は小さく、面に当たる抵抗は大きい（滑空する）。
     面を流れに向ける回転力と、回転が生む横向きの力で、揺れ落ちたり回り落ちたりする */
  var TS = 6, KN = 1.2, KT = 0.08, CR = 0.5, KW = 0.6, SUB = 0.02;
  var SNAP = 28, SNAP_DT = 0.11;
  var plates = [], gusts = [];
  var N = 60, BASE = 12;
  var windK = 0.4, countK = 0.5, trailK = 0.6, t = 0;

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function makePlate(p, y0) {
    p = p || {};
    p.z = rnd(0.45, 1);
    p.L = BASE * p.z * rnd(0.8, 1.2);
    p.I = rnd(0.25, 1.0);
    p.cp = rnd(0.6, 2.0);
    p.x = rnd(-0.1, 1.1) * W;
    p.y = y0 == null ? -3 * p.L : y0;
    p.vx = rnd(-0.2, 0.2); p.vy = rnd(0.3, 0.8);
    p.th = rnd(0, 6.283); p.w = rnd(-1, 1);
    p.g = 0; p.extra = false;
    p.sx = new Float32Array(SNAP); p.sy = new Float32Array(SNAP); p.sa = new Float32Array(SNAP);
    p.si = 0; p.sn = 0; p.st = rnd(0, SNAP_DT);
    return p;
  }

  function targetN() {
    return Math.round(Math.max(24, Math.min(130, W * H / 9000)) * (0.3 + countK * 1.4));
  }

  function build() {
    BASE = Math.max(9, Math.min(17, Math.min(W, H) * 0.02));
    N = targetN();
    plates = []; gusts = [];
    for (var i = 0; i < N; i++) {
      var p = makePlate(null, rnd(-0.1, 1.0) * H);
      /* 少し先まで落としておき、開いた瞬間から揺れと跡が見えるようにする */
      var pre = rnd(1.2, 2.2);
      for (var k = 0; k < pre / 0.05; k++) advance(p, 0.05);
      plates.push(p);
    }
  }

  /* 画面上の空気の速さ（px/s）。ゆっくり向きの変わるそよ風と、触ったところの一吹き */
  var air = [0, 0];
  function wind(x, y) {
    var k = windK;
    var ax = k * (34 * Math.sin(t * 0.13) + 22 * Math.sin(y * 0.004 + t * 0.31) + 12 * Math.sin(x * 0.006 - t * 0.21));
    var ay = k * (10 * Math.sin(x * 0.005 + t * 0.27) - 6);
    for (var i = 0; i < gusts.length; i++) {
      var g = gusts[i];
      var dx = x - g.x, dy = y - g.y, d2 = dx * dx + dy * dy, r2 = g.r * g.r;
      if (d2 > r2 * 6) continue;
      var f = Math.exp(-d2 / r2) * g.life;
      if (g.radial) {
        var d = Math.sqrt(d2) + 12;
        ax += (dx / d) * g.s * f; ay += (dy / d - 0.6) * g.s * f;
      } else {
        ax += g.vx * f; ay += g.vy * f;
      }
    }
    air[0] = ax; air[1] = ay;
  }

  function advance(p, dt) {
    var px = p.L * TS;           /* 1 単位の速さが何 px/s か */
    wind(p.x, p.y);
    var ux = air[0] / px, uy = air[1] / px;
    var steps = Math.max(1, Math.ceil(dt * TS / SUB)), h = dt * TS / steps;
    for (var n = 0; n < steps; n++) {
      var c = Math.cos(p.th), s = Math.sin(p.th);
      var rx = p.vx - ux, ry = p.vy - uy;
      var vt = rx * c + ry * s, vn = -rx * s + ry * c, V = Math.sqrt(rx * rx + ry * ry);
      var q = 0.3 + V;
      var fx = -(KN * vn * -s + KT * vt * c) * q;
      var fy = -(KN * vn * c + KT * vt * s) * q + 1;
      var G = CR * p.w;
      fx -= G * ry; fy += G * rx;
      var tq = -p.cp * vt * vn - KW * p.w * (0.2 + Math.abs(p.w));
      p.vx += fx * h; p.vy += fy * h;
      p.w += tq / p.I * h;
      if (p.w > 9) p.w = 9; else if (p.w < -9) p.w = -9;
      p.th += p.w * h;
      p.x += p.vx * h * p.L; p.y += p.vy * h * p.L;
    }
    p.g = Math.max(0, p.g - dt * (p.extra ? 0.16 : 0.5));
    /* 少し前の向きを一定の間隔で残す（ストロボ写真のように） */
    p.st += dt;
    if (p.st >= SNAP_DT) {
      p.st -= SNAP_DT;
      p.sx[p.si] = p.x; p.sy[p.si] = p.y; p.sa[p.si] = p.th;
      p.si = (p.si + 1) % SNAP; if (p.sn < SNAP) p.sn++;
    }
  }

  /* 押したところから、同じ向き・同じ形の紙片を数枚まとめて放つ。最初はそろって落ちる */
  function release(x, y) {
    var n = 5, I = rnd(0.55, 0.75), cp = rnd(1.0, 1.4), th = rnd(0, 6.283), sg = Math.random() < 0.5 ? -1 : 1;
    var L = BASE * rnd(1.0, 1.15);
    for (var i = 0; i < n; i++) {
      var p = makePlate(null, y);
      p.x = x; p.y = y; p.z = 1; p.L = L; p.I = I; p.cp = cp;
      p.th = th + (i - 2) * 0.1; p.w = (1.5 + (i - 2) * 0.12) * sg; p.vx = 0; p.vy = -0.5;
      p.g = 1; p.extra = true; p.sn = 0;
      plates.push(p);
    }
    /* 増えすぎたら、ふつうの紙片から古い順に間引く */
    var cap = Math.round(N * 1.5) + 10;
    for (var k = 0; k < plates.length && plates.length > cap; k++) {
      if (!plates[k].extra) { plates.splice(k, 1); k--; }
    }
  }

  function step(dt) {
    t += dt;
    for (var i = gusts.length - 1; i >= 0; i--) {
      gusts[i].life -= dt / gusts[i].tau;
      if (gusts[i].life <= 0) gusts.splice(i, 1);
    }
    for (var j = plates.length - 1; j >= 0; j--) {
      var p = plates[j];
      advance(p, dt);
      var m = 3 * p.L;
      if (p.y > H + m || p.y < -H * 0.6 || p.x < -0.3 * W || p.x > 1.3 * W) {
        if (plates.length > N) plates.splice(j, 1);
        else makePlate(p);
      }
    }
    while (plates.length < N) plates.push(makePlate());
  }

  /* ---------------------------------------------------------- 描く */
  var LN = P.line, ST = P.steel, AC = P.accent;
  function seg(x, y, a, L) {
    var c = Math.cos(a) * L, s = Math.sin(a) * L;
    ctx.moveTo(x - c, y - s); ctx.lineTo(x + c, y + s);
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.7 });
    ctx.lineCap = 'round';
    var m = Math.round(3 + trailK * (SNAP - 3));
    if (trailK > 0.02) {
      /* 跡: 古さごとに 1 本のパスにまとめて描く */
      ctx.lineWidth = 0.9;
      for (var age = m; age >= 1; age--) {
        var fade = 1 - age / (m + 1);
        ctx.strokeStyle = P.rgba(P.mix(ST, LN, 0.25 * fade), fade * (0.18 + 0.4 * trailK));
        ctx.beginPath();
        for (var i = 0; i < plates.length; i++) {
          var p = plates[i];
          if (p.g > 0.15 || age > p.sn) continue;
          var k = (p.si - age + SNAP) % SNAP;
          seg(p.sx[k], p.sy[k], p.sa[k], p.L * (0.6 + 0.4 * p.z));
        }
        ctx.stroke();
      }
      /* 触った紙片の跡は水色で */
      for (var i2 = 0; i2 < plates.length; i2++) {
        var q = plates[i2];
        if (q.g <= 0.15) continue;
        for (var a2 = Math.min(m, q.sn); a2 >= 1; a2--) {
          var k2 = (q.si - a2 + SNAP) % SNAP, f2 = 1 - a2 / (m + 1);
          ctx.strokeStyle = P.rgba(P.mix(ST, AC, q.g), f2 * 0.6);
          ctx.beginPath(); seg(q.sx[k2], q.sy[k2], q.sa[k2], q.L); ctx.stroke();
        }
      }
    }
    /* 紙片そのもの。奥のものほど細く暗い */
    for (var n = 0; n < plates.length; n++) {
      var r = plates[n];
      ctx.strokeStyle = P.rgba(P.mix(LN, AC, r.g), 0.35 + 0.65 * r.z);
      ctx.lineWidth = 0.8 + 1.6 * r.z;
      ctx.beginPath(); seg(r.x, r.y, r.th, r.L); ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 操作 */
  function light(x, y, R, k) {
    for (var i = 0; i < plates.length; i++) {
      var p = plates[i];
      var d = Math.hypot(p.x - x, p.y - y);
      if (d < R) p.g = Math.max(p.g, (1 - d / R) * k);
    }
  }
  var down = false, last = null, lastMoveT = 0;
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    last = { x: e.clientX, y: e.clientY };
    lastMoveT = performance.now();
    var R = Math.max(90, Math.min(W, H) * 0.22);
    gusts.push({ x: last.x, y: last.y, r: R, s: 420, life: 1, tau: 0.7, radial: true });
    light(last.x, last.y, R * 1.2, 0.9);
    release(last.x, last.y);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now();
    var p = { x: e.clientX, y: e.clientY };
    var dist = Math.hypot(p.x - last.x, p.y - last.y);
    if (dist < 4) return;
    var dtm = Math.max(0.008, (now - lastMoveT) / 1000);
    var vx = (p.x - last.x) / dtm, vy = (p.y - last.y) / dtm, v = Math.hypot(vx, vy);
    if (v > 1100) { vx *= 1100 / v; vy *= 1100 / v; }
    var R = Math.max(60, Math.min(W, H) * 0.12);
    gusts.push({ x: p.x, y: p.y, r: R, vx: vx * 1.1, vy: vy * 1.1, life: 1, tau: 0.45, radial: false });
    if (gusts.length > 48) gusts.shift();
    light(p.x, p.y, R, 0.7);
    last = p; lastMoveT = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { windK = this.value / 100; });
  document.getElementById('k-count').addEventListener('input', function () { countK = this.value / 100; N = targetN(); });
  document.getElementById('k-trail').addEventListener('input', function () { trailK = this.value / 100; });
  /* 共有リンクから開いたとき、復元された値を拾う */
  windK = document.getElementById('k-wind').value / 100;
  countK = document.getElementById('k-count').value / 100;
  trailK = document.getElementById('k-trail').value / 100;
  N = targetN();

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), chime = rnd(2, 5);
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    step(dt);
    chime -= dt;
    if (chime < 0) { chime = rnd(4, 7); SFX.play('tick'); }
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
