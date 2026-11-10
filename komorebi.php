<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('komorebi');

$pageName = 'komorebi';
$pageTitle = 'KOMOREBI - PULSE';
$pageDesc = '木の下の地面に、葉のすき間を通った日の光が丸い光の点になって何百も重なり、風が吹くたびに揺れて明るさが変わる。押すとそこの葉が押し分けられて光の点が増え、水色に光りながら外へ広がる。なぞると光の点が指の向きに引きずられ、離すと揺れながら元に戻る。';
$pageCanonical = 'https://pulse.subara3.com/komorebi.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-wind">WIND</label><input type="range" id="k-wind" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-sun">SUN</label><input type="range" id="k-sun" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" class="active" data-sun="day">DAY</button><button type="button" data-sun="eclipse">ECLIPSE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, DPR = 1, ctx;

  /* 葉のすき間 1 つが、地面に太陽の像を 1 つ落とす（ピンホール）。
     すき間が高いほど像は大きく、風で大きく揺れる。 */
  var N = 0, S = 1;
  var bx, by, hz, ph, fr, dx, dy, vx, vy, glow, boost;
  var extra = [];              /* 押したときに開く一時的なすき間 */
  var wind = 0.45, sunH = 0.55, sunMode = 'day';
  var time = 0, bite = -1.3;
  var SPR = 128, sprW, sprC;   /* 光の点の型（白とシアン） */

  function makeSprites() {
    sprW = document.createElement('canvas'); sprW.width = sprW.height = SPR;
    sprC = document.createElement('canvas'); sprC.width = sprC.height = SPR;
  }
  /* 太陽の像: やわらかい縁の円に、細い輪郭線。日食のときは月が欠けを作る */
  function drawSprite(cv, col) {
    var c = cv.getContext('2d'), m = SPR / 2, r = m * 0.9;
    c.setTransform(1, 0, 0, 1, 0, 0);
    c.globalCompositeOperation = 'source-over';
    c.clearRect(0, 0, SPR, SPR);
    var g = c.createRadialGradient(m, m, 0, m, m, m);
    g.addColorStop(0, P.rgba(col, 0.68));
    g.addColorStop(0.7, P.rgba(col, 0.55));
    g.addColorStop(0.9, P.rgba(col, 0.22));
    g.addColorStop(1, P.rgba(col, 0));
    c.fillStyle = g;
    c.beginPath(); c.arc(m, m, m, 0, 6.2832); c.fill();
    c.strokeStyle = P.rgba(col, 0.65); c.lineWidth = 2;
    c.beginPath(); c.arc(m, m, r, 0, 6.2832); c.stroke();
    if (sunMode === 'eclipse' && Math.abs(bite) < 1.95) {
      var ox = m + bite * r, oy = m - bite * r * 0.25;
      c.globalCompositeOperation = 'destination-out';
      c.fillStyle = P.rgba(P.deep, 1);
      c.beginPath(); c.arc(ox, oy, r * 0.97, 0, 6.2832); c.fill();
      c.globalCompositeOperation = 'source-over';
      c.save();
      c.beginPath(); c.arc(m, m, r, 0, 6.2832); c.clip();
      c.strokeStyle = P.rgba(col, 0.9); c.lineWidth = 2.4;
      c.beginPath(); c.arc(ox, oy, r * 0.97, 0, 6.2832); c.stroke();
      c.restore();
    }
  }

  function alloc() {
    S = Math.max(0.6, Math.min(1.6, Math.min(W, H) / 720));
    N = Math.round(Math.max(360, Math.min(1000, W * H / 1150)));
    bx = new Float32Array(N); by = new Float32Array(N); hz = new Float32Array(N);
    ph = new Float32Array(N); fr = new Float32Array(N);
    dx = new Float32Array(N); dy = new Float32Array(N); vx = new Float32Array(N); vy = new Float32Array(N);
    glow = new Float32Array(N); boost = new Float32Array(N);
    var m = 60 * S;
    for (var i = 0; i < N; i++) {
      bx[i] = -m + Math.random() * (W + 2 * m);
      by[i] = -m + Math.random() * (H + 2 * m);
      hz[i] = Math.pow(Math.random(), 1.6);
      ph[i] = Math.random() * 6.2832;
      fr[i] = 0.25 + Math.random() * 0.45;
    }
    extra.length = 0;
  }

  /* 葉の薄いところ。ゆっくり流れて、光の点の集まる場所が入れ替わる */
  function thin(x, y, t) {
    var u = x / (W + H), v = y / (W + H);
    return Math.sin(u * 7.1 + t * 0.05) * Math.cos(v * 6.3 - t * 0.04)
         + 0.6 * Math.sin((u + v) * 11.7 - t * 0.07 + 1.3)
         + 0.4 * Math.cos((u - v) * 15.1 + t * 0.06);
  }
  /* 風の波: 画面を斜めに渡る */
  function gustAt(x, y, t) {
    var a = (x * 0.8 + y * 0.6) / (W + H);
    var w = Math.sin(a * 9 - t * 0.9) * 0.6 + Math.sin(a * 4.3 - t * 0.45 + 2) * 0.4;
    var env = 0.5 + 0.5 * Math.sin(t * 0.13);
    return w * (0.35 + env * 0.65);
  }

  function smooth(e0, e1, x) { var t = (x - e0) / (e1 - e0); t = t < 0 ? 0 : t > 1 ? 1 : t; return t * t * (3 - 2 * t); }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  makeSprites();
  ctx = P.fit(canvas, function (w, h, dpr) { W = w; H = h; DPR = dpr || 1; alloc(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, px = 0, py = 0, pvx = 0, pvy = 0, lastMove = 0, holeR = 0;
  function open(x, y, n) {
    for (var k = 0; k < n; k++) {
      var a = Math.random() * 6.2832, r = Math.sqrt(Math.random()) * 120 * S;
      extra.push({ x: x + Math.cos(a) * r, y: y + Math.sin(a) * r, h: 0.3 + Math.random() * 0.7,
        vx: Math.cos(a) * (40 + Math.random() * 60) * S, vy: Math.sin(a) * (40 + Math.random() * 60) * S,
        t: 0, life: 2.2 + Math.random() * 1.6, ph: Math.random() * 6.2832 });
    }
    if (extra.length > 160) extra.splice(0, extra.length - 160);
  }
  function push(x, y, power) {
    var R = 170 * S, R2 = R * R;
    for (var i = 0; i < N; i++) {
      var ex = bx[i] + dx[i] - x, ey = by[i] + dy[i] - y, d2 = ex * ex + ey * ey;
      if (d2 > R2) continue;
      var d = Math.sqrt(d2) + 1, f = 1 - d / R;
      vx[i] += ex / d * f * power * S; vy[i] += ey / d * f * power * S;
      glow[i] = Math.max(glow[i], f * 1.2);
      boost[i] = Math.max(boost[i], f);
    }
  }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; px = e.clientX; py = e.clientY; pvx = pvy = 0; lastMove = performance.now();
    holeR = 0;
    push(px, py, 260);
    open(px, py, 18);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var now = performance.now(), dtm = Math.max(0.008, (now - lastMove) / 1000);
    var nx = e.clientX, ny = e.clientY;
    pvx = (nx - px) / dtm; pvy = (ny - py) / dtm;
    var sp = Math.hypot(pvx, pvy);
    if (sp > 2400) { pvx *= 2400 / sp; pvy *= 2400 / sp; }
    var R = 150 * S, R2 = R * R;
    for (var i = 0; i < N; i++) {
      var ex = bx[i] + dx[i] - nx, ey = by[i] + dy[i] - ny, d2 = ex * ex + ey * ey;
      if (d2 > R2) continue;
      var f = 1 - Math.sqrt(d2) / R;
      vx[i] += (pvx * 0.9 - vx[i]) * f * 0.35; vy[i] += (pvy * 0.9 - vy[i]) * f * 0.35;
      glow[i] = Math.max(glow[i], f);
      boost[i] = Math.max(boost[i], f * 0.8);
    }
    if (Math.hypot(nx - px, ny - py) > 26 * S) open(nx, ny, 2);
    px = nx; py = ny; lastMove = now;
    SFX.play('move', 140);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-wind').addEventListener('input', function () { wind = this.value / 100; });
  document.getElementById('k-sun').addEventListener('input', function () { sunH = this.value / 100; });
  document.querySelectorAll('[data-sun]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-sun]').forEach(function (o) { o.classList.toggle('active', o === b); });
      sunMode = b.dataset.sun;
      bite = -1.3;
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  wind = document.getElementById('k-wind').value / 100;
  sunH = document.getElementById('k-sun').value / 100;

  /* ---------------------------------------------------------- 描く */
  function spot(spr, x, y, rl, rs, ca, sa, a) {
    if (a < 0.004) return;
    ctx.globalAlpha = a > 1 ? 1 : a;
    ctx.setTransform(DPR * ca * rl, DPR * sa * rl, -DPR * sa * rs, DPR * ca * rs, DPR * x, DPR * y);
    ctx.drawImage(spr, -1, -1, 2, 2);
  }

  var lastT = performance.now(), gustSnd = 0;
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    time += dt;

    /* 太陽: 方角はゆっくり回り、低いほど光の点が細長くなる */
    var elev = (22 + sunH * 68) * Math.PI / 180;
    var stretch = Math.min(2.6, 1 / Math.sin(elev));
    var az = time * 0.012 + 0.6, ca = Math.cos(az), sa = Math.sin(az);
    if (sunMode === 'eclipse') { bite += dt * 0.07; if (bite > 2.1) bite = -2.1; }
    drawSprite(sprW, P.line);
    drawSprite(sprC, P.accent);

    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 36, major: 4, alpha: 0.6 });

    var wamp = 4 + wind * 26, wsp = 0.5 + wind * 1.4;
    var gx = 0.8, gy = 0.6;
    var k = 26, damp = Math.exp(-dt * 2.2), gd = Math.exp(-dt * 1.1), bd = Math.exp(-dt * (down ? 0.25 : 0.9));
    var R0 = 9 * S, R1 = 34 * S;

    /* 押さえている間は、指の下の葉が開いたまま */
    if (down) {
      holeR = Math.min(1, holeR + dt * 3);
      var HR = 110 * S, HR2 = HR * HR;
      for (var j = 0; j < N; j++) {
        var hx = bx[j] + dx[j] - px, hy = by[j] + dy[j] - py, h2 = hx * hx + hy * hy;
        if (h2 < HR2) { var hf = 1 - Math.sqrt(h2) / HR; boost[j] = Math.max(boost[j], hf * holeR); glow[j] = Math.max(glow[j], hf * holeR); }
      }
    }

    ctx.globalCompositeOperation = 'lighter';
    var tw = time * wsp, tt = time * (0.6 + wind * 0.8);
    for (var i = 0; i < N; i++) {
      /* ばね: 押しのけられた点は揺れながら元の場所へ戻る */
      vx[i] = (vx[i] - dx[i] * k * dt) * damp; vy[i] = (vy[i] - dy[i] * k * dt) * damp;
      dx[i] += vx[i] * dt; dy[i] += vy[i] * dt;
      glow[i] *= gd; boost[i] *= bd;

      var h = hz[i];
      var g = gustAt(bx[i], by[i], tw);
      var sw = wamp * (0.3 + h) * (g + 0.25 * Math.sin(tt * fr[i] * 2 + ph[i]));
      var x = bx[i] + dx[i] + gx * sw, y = by[i] + dy[i] + gy * sw;
      if (x < -R1 * 3 || y < -R1 * 3 || x > W + R1 * 3 || y > H + R1 * 3) continue;

      /* すき間の開き: 葉の薄さ + 風で葉がめくれる + 1 枚ずつのゆっくりした揺れ */
      var o = thin(bx[i], by[i], time) * 0.55 + g * (0.25 + wind * 0.4)
            + 0.35 * Math.sin(tt * fr[i] + ph[i]) - 0.05;
      o = smooth(-0.2, 0.9, o);
      var b = boost[i];
      o = o + (1 - o) * b;
      if (o < 0.02) continue;

      var r = (R0 + (R1 - R0) * h) * (0.9 + 0.25 * b);
      var a = o * (0.34 + 0.2 * (1 - h)) + b * 0.25;
      var gl = Math.min(1, glow[i]);
      spot(sprW, x, y, r * stretch, r, ca, sa, a * (1 - gl * 0.7));
      if (gl > 0.02) spot(sprC, x, y, r * stretch, r, ca, sa, a * gl * 1.1);
    }

    /* 押したところで新しく開いたすき間 */
    for (var n = extra.length - 1; n >= 0; n--) {
      var q = extra[n];
      q.t += dt;
      if (q.t > q.life) { extra.splice(n, 1); continue; }
      q.vx *= Math.exp(-dt * 1.8); q.vy *= Math.exp(-dt * 1.8);
      q.x += q.vx * dt; q.y += q.vy * dt;
      var life = Math.min(1, q.t / 0.12) * smooth(q.life, q.life * 0.45, q.t);
      var qr = (R0 + (R1 - R0) * q.h) * (0.8 + 0.2 * Math.sin(q.t * 1.5 + q.ph));
      var qg = smooth(q.life * 0.9, 0, q.t);
      spot(sprC, q.x, q.y, qr * stretch, qr, ca, sa, life * 0.6 * qg);
      spot(sprW, q.x, q.y, qr * stretch, qr, ca, sa, life * 0.45 * (1 - qg * 0.6));
    }

    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';

    /* 強い風が渡るときだけ、まれに 1 音 */
    gustSnd -= dt;
    if (gustSnd <= 0 && Math.sin(time * 0.13) > 0.92) { SFX.play('ripple', 6000); gustSnd = 8; }

    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
