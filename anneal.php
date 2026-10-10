<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('anneal');

$pageName = 'anneal';
$pageTitle = 'ANNEAL - PULSE';
$pageDesc = '散らばった点が1本の線で順に結ばれている。線は最初は大きく乱れているが、時間とともに交差がほどけて短い輪にまとまる。押したところの点は指から押しのけられて線が結び直され、離すと点はゆっくり元の位置へ戻る。';
$pageCanonical = 'https://pulse.subara3.com/anneal.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-cities">CITIES</label><input type="range" id="k-cities" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" id="k-next">NEXT</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, sized = false;
  var trail = document.createElement('canvas'), tctx = trail.getContext('2d');
  var ctx = P.fit(canvas, function (w, h, dpr) {
    W = w; H = h;
    /* 順路の残像は別のキャンバスに溜めて薄めていく（方眼まで濃くならないように） */
    trail.width = Math.round(w * dpr); trail.height = Math.round(h * dpr);
    tctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    if (sized) newLayout(true);
    sized = true;
  });

  /*
   * 焼きなまし: 一筆書きの順路の一部を逆向きにつなぎ替える（2-opt）。
   * 短くなるなら必ず、長くなるなら温度に応じた確率で受け入れる。
   * 熱いうちは線がもつれ、冷めるにつれて交差がほどけて、短い輪に落ち着く。
   * 冷え切ってしばらく眺めたら、点が新しい配置へ移り、また熱するところから始まる。
   */
  var MAXN = 260;
  var X = new Float32Array(MAXN), Y = new Float32Array(MAXN), TX = new Float32Array(MAXN), TY = new Float32Array(MAXN);
  var FX = new Float32Array(MAXN), FY = new Float32Array(MAXN), born = new Float32Array(MAXN);
  var tour = new Int32Array(MAXN), N = 0, want = 90;

  function layout(kind, n, outX, outY) {
    var mx = W * 0.1, my = H * 0.12, w = W - mx * 2, h = H - my * 2, cx = W / 2, cy = H / 2;
    var minD = Math.sqrt(w * h / n) * 0.55;
    for (var i = 0; i < n; i++) {
      var x, y, ok = false;
      for (var tries = 0; tries < 40 && !ok; tries++) {
        if (kind === 0) { x = mx + Math.random() * w; y = my + Math.random() * h; }
        else if (kind === 1) {
          /* いくつかの島 */
          var c = i % 4, ang = c * 1.9 + 0.7;
          var ccx = cx + Math.cos(ang) * w * 0.28, ccy = cy + Math.sin(ang) * h * 0.28;
          var r = Math.sqrt(-2 * Math.log(Math.random() + 1e-9)) * Math.min(w, h) * 0.1, a = Math.random() * 6.283;
          x = ccx + Math.cos(a) * r; y = ccy + Math.sin(a) * r;
        } else {
          /* 二重の輪 */
          var ring = Math.random() < 0.55 ? 0.42 : 0.24, a2 = Math.random() * 6.283;
          var rr = ring * Math.min(w, h) * (1 + (Math.random() - 0.5) * 0.16);
          x = cx + Math.cos(a2) * rr * 1.25; y = cy + Math.sin(a2) * rr;
        }
        if (x < mx * 0.6 || x > W - mx * 0.6 || y < my * 0.6 || y > H - my * 0.6) continue;
        ok = true;
        for (var j = 0; j < i; j++) { var dx = outX[j] - x, dy = outY[j] - y; if (dx * dx + dy * dy < minD * minD) { ok = false; break; } }
      }
      outX[i] = x; outY[i] = y;
    }
  }

  var kind = Math.floor(Math.random() * 3), glide = 1;
  var T = 1, T0 = 1, Tmin = 0.001, phase = 'hot', clock = 0, pace = 0.45, cooledSound = false;
  function scaleT() {
    var d = Math.sqrt(W * H / Math.max(10, N));
    T0 = d * 0.9; Tmin = d * 0.0015;
  }
  function newLayout(first) {
    var n = want;
    kind = (kind + 1 + Math.floor(Math.random() * 2)) % 3;
    if (first || N === 0) {
      N = n;
      layout(kind, N, X, Y);
      for (var i = 0; i < N; i++) { tour[i] = i; born[i] = 99; }
      for (var s = N - 1; s > 0; s--) { var r = Math.floor(Math.random() * (s + 1)), tmp = tour[s]; tour[s] = tour[r]; tour[r] = tmp; }
      glide = 1;
    } else {
      /* 点の数を合わせてから、今の位置から新しい配置へ滑らせる */
      while (N < n) { X[N] = W / 2 + (Math.random() - 0.5) * 40; Y[N] = H / 2 + (Math.random() - 0.5) * 40; born[N] = 99; tour[N] = N; N++; }
      if (N > n) {
        var keep = []; for (var k = 0; k < N; k++) if (tour[k] < n) keep.push(tour[k]);
        for (var k2 = 0; k2 < keep.length; k2++) tour[k2] = keep[k2];
        N = n;
      }
      layout(kind, N, TX, TY);
      for (var m = 0; m < N; m++) { FX[m] = X[m]; FY[m] = Y[m]; }
      glide = 0;
    }
    scaleT();
    T = T0; phase = 'hot'; clock = 0; cooledSound = false;
    if (first) { T = T0 * 0.12; phase = 'cool'; }   // 開いた直後から、ほどけていく途中を見せる
  }

  function d(a, b) { var dx = EX[a] - EX[b], dy = EY[a] - EY[b]; return Math.sqrt(dx * dx + dy * dy); }
  function anneal(iters) {
    for (var n = 0; n < iters; n++) {
      var i = Math.floor(Math.random() * N), j = Math.floor(Math.random() * N);
      if (i === j) continue;
      if (i > j) { var t = i; i = j; j = t; }
      if (j - i < 2 || (i === 0 && j === N - 1)) continue;
      var a = tour[i], b = tour[i + 1], c = tour[j], e = tour[(j + 1) % N];
      var delta = d(a, c) + d(b, e) - d(a, b) - d(c, e);
      if (delta < 0 || Math.random() < Math.exp(-delta / T)) {
        for (var p = i + 1, q = j; p < q; p++, q--) { var s = tour[p]; tour[p] = tour[q]; tour[q] = s; }
      }
    }
  }

  /* ---------------------------------------------------------- 操作 */
  /* 押したところの点を指から押しのける。離すと点はゆっくり元の場所へ戻る */
  var OX = new Float32Array(MAXN), OY = new Float32Array(MAXN), EX = new Float32Array(MAXN), EY = new Float32Array(MAXN);
  var touch = { on: false, x: 0, y: 0 }, PR = 150;
  canvas.addEventListener('pointerdown', function (e) {
    touch.on = true; touch.x = e.clientX; touch.y = e.clientY;
    push(1);
    if (phase !== 'hot') T = Math.max(T, T0 * 0.05);
    SFX.play('spawn', 150);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!touch.on) return;
    touch.x = e.clientX; touch.y = e.clientY;
    push(1);
    if (phase !== 'hot') T = Math.max(T, T0 * 0.04);
    SFX.play('move', 200);
  });
  function up() { touch.on = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);
  function push(k) {
    for (var i = 0; i < N; i++) {
      var hx = X[i] + OX[i], hy = Y[i] + OY[i], dx = hx - touch.x, dy = hy - touch.y, r = Math.sqrt(dx * dx + dy * dy);
      if (r >= PR) continue;
      if (r < 1) { dx = Math.random() - 0.5; dy = Math.random() - 0.5; r = Math.hypot(dx, dy); }
      var tx = touch.x + dx / r * PR, ty = touch.y + dy / r * PR;
      OX[i] += (tx - hx) * k; OY[i] += (ty - hy) * k;
      born[i] = 0;
    }
  }

  document.getElementById('k-cities').addEventListener('input', function () { want = Math.round(40 + this.value / 100 * 160); });
  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  want = Math.round(40 + document.getElementById('k-cities').value / 100 * 160);
  pace = document.getElementById('k-pace').value / 100;
  document.getElementById('k-next').addEventListener('click', function () { newLayout(false); SFX.play('click'); });

  /* ---------------------------------------------------------- 描く */
  function draw(dt) {
    var cold = Math.max(0, Math.min(1, Math.log(T0 / T) / Math.log(T0 / Tmin)));
    /* 熱いうちは残像を長く残して、もつれを霞のように見せる */
    tctx.globalCompositeOperation = 'destination-out';
    tctx.fillStyle = P.rgba(P.bg, 0.1 + 0.6 * cold * cold);
    tctx.fillRect(0, 0, W, H);
    tctx.globalCompositeOperation = 'source-over';
    var c = P.mix(P.steel, P.line, cold);
    tctx.strokeStyle = P.rgba(c, 0.38 + 0.52 * cold);
    tctx.lineWidth = 1.2 + 0.6 * cold;
    tctx.lineJoin = 'round';
    tctx.beginPath();
    tctx.moveTo(EX[tour[0]], EY[tour[0]]);
    for (var i = 1; i < N; i++) tctx.lineTo(EX[tour[i]], EY[tour[i]]);
    tctx.closePath();
    tctx.stroke();
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.6 });
    /* 順路が囲む内側を薄く塗る。ほどけるほど、ひとつながりの形が見えてくる */
    ctx.fillStyle = P.rgba(P.steel, 0.08 + 0.3 * cold);
    ctx.beginPath();
    ctx.moveTo(EX[tour[0]], EY[tour[0]]);
    for (var i2 = 1; i2 < N; i2++) ctx.lineTo(EX[tour[i2]], EY[tour[i2]]);
    ctx.closePath();
    ctx.fill('evenodd');
    ctx.drawImage(trail, 0, 0, W, H);
    ctx.fillStyle = P.rgba(P.line, 0.9);
    ctx.beginPath();
    for (var k = 0; k < N; k++) {
      if (born[k] < 2.5) continue;
      ctx.moveTo(EX[k] + 2.8, EY[k]); ctx.arc(EX[k], EY[k], 2.8, 0, Math.PI * 2);
    }
    ctx.fill();
    /* 触った点はシアンで灯り、少しずつ白に戻る */
    for (var m = 0; m < N; m++) {
      if (born[m] >= 2.5) continue;
      born[m] += touch.on ? 0 : dt;
      var a = 1 - born[m] / 2.5;
      ctx.fillStyle = P.rgba(P.mix(P.line, P.accent, a), 1);
      ctx.beginPath(); ctx.arc(EX[m], EY[m], 2.8 + a * 1.4, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 回す */
  newLayout(true);
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    var sp = 0.5 + pace * 1.3;
    clock += dt * sp;
    if (glide < 1) {
      glide = Math.min(1, glide + dt * sp / 7);
      var g = glide * glide * (3 - 2 * glide);
      for (var i = 0; i < N; i++) { X[i] = FX[i] + (TX[i] - FX[i]) * g; Y[i] = FY[i] + (TY[i] - FY[i]) * g; }
    }
    if (phase === 'hot') {
      T = T0;
      if (clock > 5) { phase = 'cool'; clock = 0; }
    } else if (phase === 'cool') {
      /* 約 35 秒で冷え切る */
      T = Math.max(Tmin, T * Math.exp(-dt * sp * 0.19));
      if (T <= Tmin * 1.0001) {
        phase = 'rest'; clock = 0;
        if (!cooledSound) { SFX.play('grow', 4000); cooledSound = true; }
      }
    } else {
      T = Math.max(Tmin, T * Math.exp(-dt * 0.8));
      if (clock > 14) newLayout(false);
    }
    var back = touch.on ? 1 : Math.exp(-dt * 0.9);
    for (var e2 = 0; e2 < N; e2++) {
      OX[e2] *= back; OY[e2] *= back;
      EX[e2] = X[e2] + OX[e2]; EY[e2] = Y[e2] + OY[e2];
    }
    anneal(Math.round(N * 22));
    draw(dt);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
