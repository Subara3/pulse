<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('droste');

$pageName = 'droste';
$pageTitle = 'DROSTE - PULSE';
$pageDesc = '四角い通路の図面の奥に同じ図面が描かれ、それが渦を巻きながら中心へ果てしなく続いている。画面はその渦に沿って、ゆっくり回りながら拡大し続ける。押すとそこの線が丸くふくらんで水色に光り、渦の中心が指のほうへ寄る。なぞるとふくらみが指についてきて、線が指の動いた向きへ引きずられる。';
$pageCanonical = 'https://pulse.subara3.com/droste.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-arms="1">ONE</button><button type="button" data-arms="2">TWO</button><button type="button" data-arms="3">THREE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 元の図面: 四角い通路を正面から見たもの。外枠(半辺 1)から内枠(半辺 1/S)までを 1 部屋として描き、
     内枠の中には同じ部屋が 1/S の大きさで入っている。点は対数 (log r, 角度) で持っておき、
     毎フレーム w' = α (w + (k+φ) log S) を exp で戻すと、入れ子が 1 本の渦につながる */
  var S = 4, LS = Math.log(S), TAU = Math.PI * 2;
  var U = [], V = [], starts = [], weights = [];

  function addPoly(pts, wgt) {
    var pu = [], pv = [];
    for (var i = 0; i < pts.length - 1; i++) {
      var x1 = pts[i][0], y1 = pts[i][1], x2 = pts[i + 1][0], y2 = pts[i + 1][1];
      var len = Math.hypot(x2 - x1, y2 - y1);
      var rr = Math.min(Math.hypot(x1, y1), Math.hypot(x2, y2));
      var n = Math.min(60, Math.ceil(len / (0.035 * rr)));
      for (var j = (i ? 1 : 0); j <= n; j++) {
        var x = x1 + (x2 - x1) * j / n, y = y1 + (y2 - y1) * j / n;
        var a = Math.atan2(y, x);
        if (pv.length) {   /* 角度は途中で 2π 飛ばないようにつなぐ */
          var pa = pv[pv.length - 1];
          while (a - pa > Math.PI) a -= TAU;
          while (a - pa < -Math.PI) a += TAU;
        }
        pu.push(Math.log(Math.hypot(x, y))); pv.push(a);
      }
    }
    starts.push(U.length); weights.push(wgt);
    for (var k = 0; k < pu.length; k++) { U.push(pu[k]); V.push(pv[k]); }
  }
  function square(c, wgt) { addPoly([[c, -c], [c, c], [-c, c], [-c, -c], [c, -c]], wgt); }
  function rot(p, q) {   /* q 回 90 度回す */
    var x = p[0], y = p[1];
    for (var i = 0; i < q; i++) { var t = x; x = -y; y = t; }
    return [x, y];
  }
  function build() {
    var q = 1 / S, i, s, t;
    square(1, 1);
    square(0.955, 0.5);
    for (i = 1; i <= 3; i++) square(Math.pow(q, i / 4), 0.45);
    for (s = 0; s < 4; s++) {
      /* 奥へ向かう線: 角は太く、辺の途中は細く */
      for (t = -1; t <= 1; t += 0.5) {
        var a = rot([1, t], s), b = rot([q, q * t], s);
        addPoly([a, b], Math.abs(t) === 1 ? 1 : 0.4);
      }
      /* 外枠の目盛り */
      for (i = -8; i <= 8; i++) {
        t = i / 8;
        var d = i % 4 === 0 ? 0.92 : 0.965;
        addPoly([rot([1, t], s), rot([d, d * t], s)], 0.7);
      }
    }
    /* 左右の壁に窓、上下の壁に丸窓 */
    var win = [[0.86, 0.66, -0.55, -0.15], [0.62, 0.48, 0.2, 0.6]];
    for (s = 0; s < 4; s += 2) {
      for (i = 0; i < win.length; i++) {
        var w = win[s ? 1 - i : i], A = w[0], B = w[1];
        addPoly([rot([A, A * w[2]], s), rot([A, A * w[3]], s), rot([B, B * w[3]], s), rot([B, B * w[2]], s), rot([A, A * w[2]], s)], 0.6);
      }
    }
    for (s = 1; s < 4; s += 2) {
      var cx = s === 1 ? -0.3 : 0.25, cy = 0.72, r = 0.075, pts = [];
      for (i = 0; i <= 24; i++) pts.push(rot([cx + Math.cos(i / 24 * TAU) * r, cy + Math.sin(i / 24 * TAU) * r * 0.7], s));
      addPoly(pts, 0.6);
    }
  }
  build();
  var NPT = U.length, NPOLY = starts.length;
  starts.push(NPT);

  /* 渦の腕の数で α が決まる */
  var arms = 1, ar = 0, ai = 0, m0 = 0, M0 = 0;
  function setArms(p) {
    arms = p;
    var D = p * p * LS * LS + 4 * Math.PI * Math.PI;
    ar = 4 * Math.PI * Math.PI / D;
    ai = TAU * p * LS / D;
    m0 = 1e9; M0 = -1e9;
    for (var i = 0; i < NPT; i++) {
      var re = ar * U[i] - ai * V[i];
      if (re < m0) m0 = re;
      if (re > M0) M0 = re;
    }
  }
  setArms(1);

  var phase = 0.3, speed = 0.11;
  var G = 1, cx = 0, cy = 0, tcx = 0, tcy = 0, half = 1, logIn = 0, logSpan = 1;
  var lens = 0, fx = 0, fy = 0, vx = 0, vy = 0, down = false, lastP = null, lastMove = 0;
  var R = 100;
  var NL = 6, styles = [], widths = [];

  function layout() {
    half = Math.hypot(W, H) * 0.5;
    G = half;
    cx = tcx = W / 2; cy = tcy = H / 2;
    R = Math.max(70, Math.min(W, H) * 0.17);
    logIn = Math.log(3);
    logSpan = Math.log(half) - logIn;
    styles.length = 0; widths.length = 0;
    for (var l = 0; l < NL; l++) {
      var t = (l + 0.5) / NL;
      for (var wg = 0; wg < 2; wg++) {
        var al = (0.06 + 0.86 * Math.pow(t, 1.15)) * (wg ? 1 : 0.5);
        styles.push(P.rgba(P.mix(P.steel, P.line, t), al));
        widths.push(0.6 + t * (wg ? 1.0 : 0.5));
      }
    }
    for (var h = 0; h < 3; h++) { styles.push(P.rgba(P.accent, 0.4 + h * 0.28)); widths.push(1.2 + h * 0.3); }
  }

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; layout(); });

  var NB = NL * 2 + 3, paths = new Array(NB);
  var px = 0, py = 0;

  /* 1 点を画面へ: 渦に写してから、指のまわりをふくらませて引きずる。戻り値はシアンの強さ */
  function place(u, v, shift) {
    var uu = u + shift;
    var re = ar * uu - ai * v, im = ai * uu + ar * v;
    var r = G * Math.exp(re);
    var x = cx + r * Math.cos(im), y = cy + r * Math.sin(im);
    var hw = 0;
    if (lens > 0.01) {
      var dx = x - fx, dy = y - fy, d2 = dx * dx + dy * dy;
      if (d2 < 9 * R * R) {
        var g = Math.exp(-d2 / (R * R)) * lens;
        x += dx * 0.75 * g + vx * g;
        y += dy * 0.75 * g + vy * g;
        hw = g;
      }
    }
    px = x; py = y;
    return hw;
  }
  function bucket(hw) {
    if (hw > 0.22) return NL * 2 + (hw > 0.7 ? 2 : hw > 0.45 ? 1 : 0);
    var dx = px - cx, dy = py - cy;
    var t = (Math.log(Math.sqrt(dx * dx + dy * dy) + 1e-6) - logIn) / logSpan;
    var l = t <= 0 ? 0 : t >= 1 ? NL - 1 : (t * NL) | 0;
    return l * 2;
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.5 });
    for (var b = 0; b < NB; b++) paths[b] = new Path2D();
    var step = ar * LS;
    var kmin = Math.ceil((Math.log(1.5 / G) - M0) / step - phase);
    var kmax = Math.floor((Math.log(half * 2.2 / G) - m0) / step - phase);
    var mg = 40, x0 = -mg, x1 = W + mg, y0 = -mg, y1 = H + mg;
    for (var k = kmin; k <= kmax; k++) {
      var shift = (k + phase) * LS;
      for (var n = 0; n < NPOLY; n++) {
        var wg = weights[n] >= 0.7 ? 1 : 0;
        var e = starts[n + 1], pb = -1, qx = 0, qy = 0, qin = false;
        for (var i = starts[n]; i < e; i++) {
          var hw = place(U[i], V[i], shift);
          var inside = px > x0 && px < x1 && py > y0 && py < y1;
          var bk = bucket(hw);
          if (bk < NL * 2) bk += wg;
          if (i > starts[n] && (inside || qin)) {
            var pa = paths[bk];
            if (pb !== bk) pa.moveTo(qx, qy);
            pa.lineTo(px, py);
            pb = bk;
          } else pb = -1;
          qx = px; qy = py; qin = inside;
        }
      }
    }
    ctx.lineCap = 'round';
    for (b = 0; b < NB; b++) {
      ctx.strokeStyle = styles[b];
      ctx.lineWidth = widths[b];
      ctx.stroke(paths[b]);
    }
  }

  /* ---------------------------------------------------------- 操作 */
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; lastP = pos(e); lastMove = performance.now();
    fx = lastP.x; fy = lastP.y;
    lens = Math.max(lens, 0.55);   /* 押した瞬間からふくらみが見える */
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now();
    var dt = Math.max(0.008, (now - lastMove) / 1000);
    var dvx = (p.x - lastP.x) / dt * 0.12, dvy = (p.y - lastP.y) / dt * 0.12;
    vx += (dvx - vx) * 0.5; vy += (dvy - vy) * 0.5;
    var m = Math.hypot(vx, vy), cap = R * 0.8;
    if (m > cap) { vx *= cap / m; vy *= cap / m; }
    fx = p.x; fy = p.y;
    lastP = p; lastMove = now;
    if (Math.hypot(dvx, dvy) > 20) SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-speed').addEventListener('input', function () { speed = 0.02 + this.value / 100 * 0.22; });
  document.querySelectorAll('[data-arms]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-arms]').forEach(function (o) { o.classList.toggle('active', o === b); });
      setArms(+b.dataset.arms);
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = 0.02 + document.getElementById('k-speed').value / 100 * 0.22;
  var act = document.querySelector('[data-arms].active');
  if (act) setArms(+act.dataset.arms);

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    phase += dt * speed;
    if (phase >= 1) { phase -= 1; SFX.play('tick'); }
    /* 押しているあいだはふくらみが育ち、渦の中心が指のほうへ寄る */
    lens += ((down ? 1 : 0) - lens) * Math.min(1, dt * (down ? 9 : 1.6));
    if (down) { tcx = W / 2 + (fx - W / 2) * 0.4; tcy = H / 2 + (fy - H / 2) * 0.4; }
    else { tcx = W / 2; tcy = H / 2; }
    cx += (tcx - cx) * Math.min(1, dt * 1.8);
    cy += (tcy - cy) * Math.min(1, dt * 1.8);
    var dk = Math.exp(-dt * 5);
    vx *= dk; vy *= dk;
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
