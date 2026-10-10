<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('apollonian');

$pageName = 'apollonian';
$pageTitle = 'APOLLONIAN - PULSE';
$pageDesc = '大きな円の中が、互いに接する大小の円ですき間なく埋まっている。全体はゆっくり動き、円の大きさが入れ替わっていく。押すとその円が水色になり、大きくなりながら中央へ寄る。なぞると通った円が順に灯る。';
$pageCanonical = 'https://pulse.subara3.com/apollonian.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-flow">FLOW</label><input type="range" id="k-flow" min="0" max="100" value="25"></div>
  <div class="ctl-row"><label for="k-fine">FINE</label><input type="range" id="k-fine" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" data-look="rings">RINGS</button><button type="button" class="active" data-look="solid">SOLID</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, R = 1;
  var ctx = P.fit(canvas, function (w, h) { W = w; H = h; R = Math.min(w, h) * 0.46; });

  /*
   * 単位円の中に、互いに接する 2 円（半径 ra と 1-ra）を置き、残りのすき間を
   * デカルトの円定理（複素数版）で次々に埋める。
   * それを単位円盤の自己写像 f(z) = e^{iφ}(z - a)/(1 - ā z) で動かすと、
   * 円は円のまま、外周は外周のまま、中身だけが流れる。
   */
  var K = new Float64Array(12000), X = new Float64Array(12000), Y = new Float64Array(12000);
  var N = 0, rmin = 0.002;
  function add(k, x, y) { K[N] = k; X[N] = x; Y[N] = y; N++; }

  function gasket(ra) {
    N = 0;
    var rb = 1 - ra;
    var c0 = [-1, 0, 0], c1 = [1 / ra, -rb, 0], c2 = [1 / rb, ra, 0];
    /* ra + rb = 1 なので根号の中は 0。丸め誤差で負になると全部 NaN になるので 0 で止める */
    var k3 = c0[0] + c1[0] + c2[0] + 2 * Math.sqrt(Math.max(0, c0[0] * c1[0] + c1[0] * c2[0] + c2[0] * c0[0]));
    var c3 = [k3, (c1[0] * c1[1] + c2[0] * c2[1]) / k3, 2 / k3];
    var c4 = [k3, c3[1], -c3[2]];
    add(c0[0], 0, 0); add(c1[0], c1[1], 0); add(c2[0], c2[1], 0); add(k3, c3[1], c3[2]); add(k3, c4[1], c4[2]);
    var kmax = 1 / rmin;
    /* 円は (k, k·x, k·y) で持つと、置き換えが足し算だけになる */
    function v(c) { return [c[0], c[0] * c[1], c[0] * c[2]]; }
    var stack = [[v(c0), v(c1), v(c2), v(c3)], [v(c0), v(c1), v(c2), v(c4)]];
    while (stack.length && N < 11990) {
      var q = stack.pop(), a = q[0], b = q[1], c = q[2], d = q[3];
      for (var s = 0; s < 3; s++) {
        var x = s === 0 ? a : s === 1 ? b : c, y = s === 0 ? b : a, z = s === 2 ? b : c;
        var nk = 2 * (d[0] + y[0] + z[0]) - x[0];
        if (nk > kmax) continue;
        var n = [nk, 2 * (d[1] + y[1] + z[1]) - x[1], 2 * (d[2] + y[2] + z[2]) - x[2]];
        add(nk, n[1] / nk, n[2] / nk);
        stack.push([y, z, d, n]);
      }
    }
  }

  /* ---------------------------------------------------------- 円盤の自己写像 */
  var ax = 0, ay = 0, phi = 0;
  function fwd(zx, zy, out) {
    /* (z - a) / (1 - ā z) */
    var nx = zx - ax, ny = zy - ay;
    var dx = 1 - (ax * zx + ay * zy), dy = -(ax * zy - ay * zx);
    var dd = dx * dx + dy * dy;
    var wx = (nx * dx + ny * dy) / dd, wy = (ny * dx - nx * dy) / dd;
    var c = Math.cos(phi), s = Math.sin(phi);
    out[0] = wx * c - wy * s; out[1] = wx * s + wy * c;
  }
  function inv(wx, wy, out) {
    var c = Math.cos(-phi), s = Math.sin(-phi);
    var ux = wx * c - wy * s, uy = wx * s + wy * c;
    var nx = ux + ax, ny = uy + ay;
    var dx = 1 + (ax * ux + ay * uy), dy = ax * uy - ay * ux;
    var dd = dx * dx + dy * dy;
    out[0] = (nx * dx + ny * dy) / dd; out[1] = (ny * dx - nx * dy) / dd;
  }
  /* 円の像: 円周上の 3 点を写して外接円を取る */
  var p1 = [0, 0], p2 = [0, 0], p3 = [0, 0];
  var IX = new Float32Array(12000), IY = new Float32Array(12000), IR = new Float32Array(12000);
  function mapAll() {
    IR[0] = 1; IX[0] = 0; IY[0] = 0;
    for (var i = 1; i < N; i++) {
      var r = 1 / K[i], x = X[i], y = Y[i];
      fwd(x + r, y, p1); fwd(x, y + r, p2); fwd(x - r, y, p3);
      var bx = p2[0] - p1[0], by = p2[1] - p1[1], cx = p3[0] - p1[0], cy = p3[1] - p1[1];
      var d = 2 * (bx * cy - by * cx);
      if (Math.abs(d) < 1e-14) { IR[i] = 0; continue; }
      var b2 = bx * bx + by * by, c2 = cx * cx + cy * cy;
      var ux = (cy * b2 - by * c2) / d, uy = (bx * c2 - cx * b2) / d;
      IX[i] = p1[0] + ux; IY[i] = p1[1] + uy; IR[i] = Math.sqrt(ux * ux + uy * uy);
    }
  }

  /* ---------------------------------------------------------- 状態 */
  var t = 0, flow = 0.4, look = 'solid', ra = 0.5;
  var touch = { on: false, x: 0, y: 0 };
  var lits = [];           // 灯った円 { bx, by, br, a, on }。なぞった跡の円が順に灯って、ゆっくり消える
  var tax = 0, tay = 0, pull = 0;

  function pick(sx, sy) {
    /* 指の下にあるいちばん小さい円 */
    var wx = (sx - W / 2) / R, wy = -(sy - H / 2) / R, best = -1, br = 9;
    for (var i = 1; i < N; i++) {
      var dx = wx - IX[i], dy = wy - IY[i];
      if (dx * dx + dy * dy < IR[i] * IR[i] && IR[i] < br && IR[i] * R > 16) { br = IR[i]; best = i; }
    }
    return best;
  }
  function light(i) {
    if (i < 1) return;
    var last = lits[lits.length - 1];
    if (last && last.on && Math.abs(last.bx - X[i]) + Math.abs(last.by - Y[i]) < 1e-9) return;
    if (last) last.on = false;
    lits.push({ bx: X[i], by: Y[i], br: 1 / K[i], a: 0.8, on: true });
    if (lits.length > 16) lits.shift();
    SFX.play('tick', 140);
  }

  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; touch.x = e.clientX; touch.y = e.clientY;
    canvas.setPointerCapture(e.pointerId);
    light(pick(e.clientX, e.clientY));
    SFX.play('ripple', 200);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    touch.x = e.clientX; touch.y = e.clientY;
    light(pick(e.clientX, e.clientY));
  });
  function up() { touch.on = false; lits.forEach(function (l) { l.on = false; }); }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-flow').addEventListener('input', function () { flow = this.value / 100; });
  document.getElementById('k-fine').addEventListener('input', function () { rmin = 0.006 * Math.pow(0.18, this.value / 100); });
  flow = document.getElementById('k-flow').value / 100;
  rmin = 0.006 * Math.pow(0.18, document.getElementById('k-fine').value / 100);
  document.querySelectorAll('[data-look]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-look]').forEach(function (o) { o.classList.toggle('active', o === b); });
      look = b.dataset.look;
      SFX.play('toggle');
    });
  });

  /* ---------------------------------------------------------- 描く */
  var BINS = 7, paths = [];
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.7 });
    var cx = W / 2, cy = H / 2;
    for (var b = 0; b < BINS; b++) paths[b] = new Path2D();
    var solid = look === 'solid';
    for (var i = 1; i < N; i++) {
      var r = IR[i] * R;
      if (!(r >= 0.7) || r > R * 1.01) continue;
      /* 大きい円ほど濃く。細かい円は薄く溶けていく */
      var lv = Math.log(r) / Math.log(R * 0.5);
      var bin = Math.max(0, Math.min(BINS - 1, Math.floor(lv * BINS)));
      var px = cx + IX[i] * R, py = cy - IY[i] * R;
      paths[bin].moveTo(px + r, py);
      paths[bin].arc(px, py, r, 0, Math.PI * 2);
    }
    for (var b2 = 0; b2 < BINS; b2++) {
      var f = (b2 + 1) / BINS;
      if (solid) {
        /* 小さい円ほど明るく塗る。細かいところが白く浮き、大きな円は地に近い */
        ctx.fillStyle = P.rgba(P.mix(P.bg, P.steel, 0.12 + 0.5 * (1 - f)), 1);
        ctx.fill(paths[b2]);
      }
      ctx.strokeStyle = P.rgba(P.line, 0.38 + 0.5 * f * f);
      ctx.lineWidth = 1 + 0.5 * f;
      ctx.stroke(paths[b2]);
    }
    /* 外周 */
    ctx.strokeStyle = P.rgba(P.line, 0.85);
    ctx.lineWidth = 1.4;
    ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.stroke();

    /* 灯った円（形が少しずつ変わるので、元の位置がいちばん近い円を追う） */
    for (var li = 0; li < lits.length; li++) {
      var lit = lits[li], best = -1, bd = 1e9;
      for (var j = 1; j < N; j++) {
        var dx = X[j] - lit.bx, dy = Y[j] - lit.by, dr = 1 / K[j] - lit.br;
        var dd = dx * dx + dy * dy + dr * dr * 4;
        if (dd < bd) { bd = dd; best = j; }
      }
      if (best > 0) {
        lit.bx = X[best]; lit.by = Y[best]; lit.br = 1 / K[best];
        var rr = IR[best] * R, qx = cx + IX[best] * R, qy = cy - IY[best] * R;
        ctx.fillStyle = P.rgba(P.accent, 0.32 * lit.a);
        ctx.strokeStyle = P.rgba(P.accent, 0.85 * lit.a);
        ctx.lineWidth = 1.3;
        ctx.beginPath(); ctx.arc(qx, qy, rr, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      }
    }
  }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), tmp = [0, 0];
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var sp = 0.15 + flow * 1.7;
    t += dt * sp;
    ra = 0.5 + 0.2 * Math.sin(t * 0.045) * Math.sin(t * 0.017 + 0.6);
    phi += dt * sp * 0.025;
    /* 自動の流れ: a は円盤の中をゆっくり巡る */
    var autoX = 0.42 * Math.sin(t * 0.061), autoY = 0.36 * Math.sin(t * 0.047 + 1.1);
    if (touch.on) {
      /* 指の下の点が中央側へ寄ってくるように a を引く */
      inv((touch.x - W / 2) / R * 0.92, -(touch.y - H / 2) / R * 0.92, tmp);
      var m = Math.hypot(tmp[0], tmp[1]);
      if (m > 0.66) { tmp[0] *= 0.66 / m; tmp[1] *= 0.66 / m; }
      tax = tmp[0]; tay = tmp[1];
      pull = 1;
    } else pull = Math.max(0, pull - dt * 0.25);
    var gx = autoX + (tax - autoX) * pull, gy = autoY + (tay - autoY) * pull;
    var k = Math.min(1, dt * (touch.on ? 5 : 0.5));
    ax += (gx - ax) * k; ay += (gy - ay) * k;
    var am = Math.hypot(ax, ay);
    if (am > 0.7) { ax *= 0.7 / am; ay *= 0.7 / am; }
    for (var lq = lits.length - 1; lq >= 0; lq--) {
      var L2 = lits[lq];
      L2.a += ((L2.on ? 1 : 0) - L2.a) * Math.min(1, dt * (L2.on ? 6 : 0.7));
      if (!L2.on && L2.a < 0.01) lits.splice(lq, 1);
    }
    gasket(ra);
    mapAll();
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
