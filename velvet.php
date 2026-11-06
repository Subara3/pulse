<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('velvet');

$pageName = 'velvet';
$pageTitle = 'VELVET - PULSE';
$pageDesc = '画面いっぱいに短い毛が生えていて、風が吹くたびに毛が帯になって倒れ、明るい筋と暗い筋が横切っていく。ときどき一筋だけ毛並みがなでつけられ、しばらくすると元の向きに戻る。押すとまわりの毛が外へ倒れて水色に光り、なぞると指の跡に沿って毛並みがそろって筋が残る。';
$pageCanonical = 'https://pulse.subara3.com/velvet.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-pile">PILE</label><input type="range" id="k-pile" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" id="k-smooth">SMOOTH</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 毛は格子状に植えて、根元を少しずらす。
     lean = いまの倒れ方、nap = 癖（なでつけられた向き）、base = 放っておいたときの毛並み */
  var S = 9, COLS = 0, ROWS = 0, N = 0, R = 80;
  var rx, ry, lx, ly, vx, vy, nx, ny, bx, by, en, ph;
  var NB = 8, NA = 3, segs = [], cnt = new Int32Array(NB + NA), cols = [];
  var t = 0, windAmp = 0.7, pile = 1, baseCursor = 0;
  var strokes = [], nextStroke = 4;
  var down = false, px = 0, py = 0;
  var LDX = 0.35, LDY = 0.94;   /* 光の向き。こちらへ倒れた毛ほど明るい */

  for (var c0 = 0; c0 < NB; c0++) cols.push(P.rgba(P.ramp(0.24 + 0.72 * c0 / (NB - 1)), 1));
  for (var c1 = 0; c1 < NA; c1++) cols.push(P.rgba(P.rampAccent(0.5 + 0.17 * c1), 1));

  /* 放っておいたときの毛並み。大きなつむじがゆっくり動く */
  function base(k) {
    var x = rx[k], y = ry[k];
    var a = 1.5708 + 0.95 * Math.sin(x * 0.0042 + t * 0.031) * Math.cos(y * 0.0051 - t * 0.023)
          + 0.55 * Math.sin((x - y) * 0.0027 + t * 0.017);
    var m = 0.5 + 0.12 * Math.sin(x * 0.006 + y * 0.004 - t * 0.04);
    bx[k] = Math.cos(a) * m; by[k] = Math.sin(a) * m;
  }

  function alloc() {
    S = Math.max(8, Math.min(12, Math.round(Math.sqrt(W * H / 16000))));
    COLS = Math.ceil(W / S) + 2; ROWS = Math.ceil(H / S) + 3; N = COLS * ROWS;
    R = Math.max(55, Math.min(120, Math.min(W, H) * 0.13));
    rx = new Float32Array(N); ry = new Float32Array(N);
    lx = new Float32Array(N); ly = new Float32Array(N);
    vx = new Float32Array(N); vy = new Float32Array(N);
    nx = new Float32Array(N); ny = new Float32Array(N);
    bx = new Float32Array(N); by = new Float32Array(N);
    en = new Float32Array(N); ph = new Float32Array(N);
    for (var j = 0; j < ROWS; j++) {
      for (var i = 0; i < COLS; i++) {
        var k = j * COLS + i;
        rx[k] = (i - 0.5 + (Math.random() - 0.5) * 0.9) * S;
        ry[k] = (j - 1.5 + (Math.random() - 0.5) * 0.9) * S;
        ph[k] = Math.random() * 6.2832;
        base(k);
        nx[k] = lx[k] = bx[k]; ny[k] = ly[k] = by[k];
      }
    }
    segs = [];
    for (var b = 0; b < NB + NA; b++) segs.push(new Float32Array(N * 4));
    /* 開いたときから筋が見えるように、2 本だけなでつけておく */
    strokes = [];
    for (var s = 0; s < 2; s++) {
      var st = newStroke();
      while (st.life > 0) moveStroke(st, 1 / 60);
    }
  }

  /* 線分 (x0,y0)-(x1,y1) のまわりの毛を、進む向きへなでつける。真ん中の毛は左右に分かれる */
  function comb(x0, y0, x1, y1, r, str, mark) {
    var dx = x1 - x0, dy = y1 - y0, len = Math.hypot(dx, dy);
    if (len < 0.5) return;
    var ux = dx / len, uy = dy / len;
    var i0 = Math.max(0, Math.floor((Math.min(x0, x1) - r) / S)), i1 = Math.min(COLS - 1, Math.ceil((Math.max(x0, x1) + r) / S) + 1);
    var j0 = Math.max(0, Math.floor((Math.min(y0, y1) - r) / S)), j1 = Math.min(ROWS - 1, Math.ceil((Math.max(y0, y1) + r) / S) + 2);
    for (var j = j0; j <= j1; j++) {
      for (var i = i0; i <= i1; i++) {
        var k = j * COLS + i;
        var qx = rx[k] - x0, qy = ry[k] - y0;
        var s = (qx * ux + qy * uy) / len;
        s = s < 0 ? 0 : s > 1 ? 1 : s;
        var ex = rx[k] - (x0 + dx * s), ey = ry[k] - (y0 + dy * s);
        var d = Math.sqrt(ex * ex + ey * ey);
        if (d >= r) continue;
        var f = 1 - d / r; f = f * f * (3 - 2 * f);
        var w = f * str;
        var sd = ex * -uy + ey * ux, sg = sd < 0 ? -1 : 1;
        var tx = ux * (1 - 0.35 * f) - uy * sg * 0.5 * f;
        var ty = uy * (1 - 0.35 * f) + ux * sg * 0.5 * f;
        nx[k] += (tx - nx[k]) * w; ny[k] += (ty - ny[k]) * w;
        vx[k] += ux * 3 * w; vy[k] += uy * 3 * w;
        if (mark && f > en[k]) en[k] = f;
      }
    }
  }

  /* 点 (x,y) のまわりの毛を外へ倒す */
  function press(x, y, r, amt, kick) {
    var i0 = Math.max(0, Math.floor((x - r) / S)), i1 = Math.min(COLS - 1, Math.ceil((x + r) / S) + 1);
    var j0 = Math.max(0, Math.floor((y - r) / S)), j1 = Math.min(ROWS - 1, Math.ceil((y + r) / S) + 2);
    for (var j = j0; j <= j1; j++) {
      for (var i = i0; i <= i1; i++) {
        var k = j * COLS + i;
        var ex = rx[k] - x, ey = ry[k] - y, d = Math.sqrt(ex * ex + ey * ey);
        if (d >= r || d < 0.01) continue;
        var f = 1 - d / r; f = f * f * (3 - 2 * f);
        var ox = ex / d, oy = ey / d, w = Math.min(1, amt * f);
        nx[k] += (ox - nx[k]) * w; ny[k] += (oy - ny[k]) * w;
        vx[k] += ox * kick * f; vy[k] += oy * kick * f;
        if (f > en[k]) en[k] = f;
      }
    }
  }

  /* ときどき、一筋だけ毛並みがなでつけられる */
  function newStroke() {
    var st = {
      x: W * (0.1 + Math.random() * 0.8), y: H * (0.1 + Math.random() * 0.8),
      a: Math.random() * 6.2832, curv: (Math.random() - 0.5) * 1.4,
      speed: 220 + Math.random() * 140, life: 1.4 + Math.random() * 1.6
    };
    strokes.push(st);
    return st;
  }
  function moveStroke(st, dt) {
    var step = st.speed * dt;
    var x1 = st.x + Math.cos(st.a) * step, y1 = st.y + Math.sin(st.a) * step;
    comb(st.x, st.y, x1, y1, R * 0.5, 0.55, false);
    st.x = x1; st.y = y1; st.a += st.curv * dt; st.life -= dt;
    if (st.life <= 0) strokes.splice(strokes.indexOf(st), 1);
  }

  function update(dt) {
    t += dt;
    var chunk = Math.ceil(N / 6);
    for (var n = 0; n < chunk; n++) { base(baseCursor); baseCursor = (baseCursor + 1) % N; }

    /* 風: 向きがゆっくり変わり、強いところが帯になって吹き抜ける */
    var wd = 0.2 + 0.9 * Math.sin(t * 0.037), wdx = Math.cos(wd), wdy = Math.sin(wd);
    var K = 6.2832 / 380, OM = 1.7;
    var relax = dt / 14, ed = dt / 2.8, kS = 55, cD = 4.2;
    for (var k = 0; k < N; k++) {
      var x = rx[k], y = ry[k];
      var u = x * wdx + y * wdy, v = y * wdx - x * wdy;
      var g = Math.sin(u * K - t * OM + 0.8 * Math.sin(v * 0.004 + t * 0.21));
      g = g > 0 ? g * g : 0;
      var env = 0.55 + 0.45 * Math.sin(v * 0.0055 - t * 0.13);
      var w = windAmp * (g * env * 0.8 + 0.08 * Math.sin(t * 2.3 + ph[k]));
      nx[k] += (bx[k] - nx[k]) * relax; ny[k] += (by[k] - ny[k]) * relax;
      var tx = nx[k] + wdx * w, ty = ny[k] + wdy * w;
      vx[k] += (kS * (tx - lx[k]) - cD * vx[k]) * dt;
      vy[k] += (kS * (ty - ly[k]) - cD * vy[k]) * dt;
      var a = lx[k] + vx[k] * dt, b = ly[k] + vy[k] * dt, m = a * a + b * b;
      if (m > 1.1) { m = 1.05 / Math.sqrt(m); a *= m; b *= m; }
      lx[k] = a; ly[k] = b;
      if (en[k] > 0) en[k] -= ed;
    }
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    cnt.fill(0);
    var Ll = S * 1.9 * pile, off = -Ll * 0.16;
    for (var k = 0; k < N; k++) {
      var a = lx[k], b = ly[k], m = Math.sqrt(a * a + b * b);
      var bi;
      if (en[k] > 0.12) {
        bi = NB + Math.min(NA - 1, (en[k] * NA) | 0);
      } else {
        var s = 0.18 + 0.5 * m + 0.32 * (a * LDX + b * LDY);
        bi = (s * (NB - 1) + 0.5) | 0;
        bi = bi < 0 ? 0 : bi > NB - 1 ? NB - 1 : bi;
      }
      var arr = segs[bi], c = cnt[bi];
      arr[c] = rx[k]; arr[c + 1] = ry[k];
      arr[c + 2] = rx[k] + a * Ll; arr[c + 3] = ry[k] + b * Ll + off;
      cnt[bi] = c + 4;
    }
    ctx.lineWidth = 1;
    ctx.lineCap = 'butt';
    for (var q = 0; q < NB + NA; q++) {
      var n = cnt[q];
      if (!n) continue;
      var sg = segs[q];
      ctx.strokeStyle = cols[q];
      ctx.beginPath();
      for (var p = 0; p < n; p += 4) { ctx.moveTo(sg[p], sg[p + 1]); ctx.lineTo(sg[p + 2], sg[p + 3]); }
      ctx.stroke();
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY;
    press(px, py, R, 0.85, 7);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY;
    if (Math.hypot(x - px, y - py) < 3) return;
    comb(px, py, x, y, R * 0.6, 0.8, true);
    px = x; py = y;
    SFX.play('move', 130);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { windAmp = 0.15 + this.value / 100 * 1.1; });
  document.getElementById('k-pile').addEventListener('input', function () { pile = 0.6 + this.value / 100 * 0.9; });
  document.getElementById('k-smooth').addEventListener('click', function () {
    for (var k = 0; k < N; k++) { nx[k] = bx[k]; ny[k] = by[k]; en[k] = 0; }
    strokes.length = 0;
    SFX.play('click');
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  windAmp = 0.15 + document.getElementById('k-wind').value / 100 * 1.1;
  pile = 0.6 + document.getElementById('k-pile').value / 100 * 0.9;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    nextStroke -= dt;
    if (nextStroke <= 0) {
      newStroke();
      nextStroke = 6 + Math.random() * 5;
      SFX.play('move', 3000);
    }
    for (var s = strokes.length - 1; s >= 0; s--) moveStroke(strokes[s], dt);
    if (down) press(px, py, R * 0.45, dt * 6, 0);
    update(dt);
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
