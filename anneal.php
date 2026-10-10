<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('anneal');

$pageName = 'anneal';
$pageTitle = 'ANNEAL - PULSE';
$pageDesc = '散らばった点が1本の線で順に結ばれている。線は最初は大きく乱れているが、時間とともに交差がほどけて短い輪にまとまる。触ると点が増え、線が少し乱れてから結び直される。';
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
  }

  function d(a, b) { var dx = X[a] - X[b], dy = Y[a] - Y[b]; return Math.sqrt(dx * dx + dy * dy); }
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

  /* 新しい点は、順路がいちばん伸びないところへ差し込む */
  function insert(x, y) {
    if (N >= MAXN) return;
    var id = N;
    X[id] = x; Y[id] = y; TX[id] = FX[id] = x; TY[id] = FY[id] = y; born[id] = 0; N++;
    var best = 0, bd = 1e18;
    for (var i = 0; i < N - 1; i++) {
      var a = tour[i], b = tour[(i + 1) % (N - 1)];
      var dd = Math.hypot(X[a] - x, Y[a] - y) + Math.hypot(X[b] - x, Y[b] - y) - d(a, b);
      if (dd < bd) { bd = dd; best = i; }
    }
    for (var k = N - 1; k > best + 1; k--) tour[k] = tour[k - 1];
    tour[best + 1] = id;
  }

  /* ---------------------------------------------------------- 操作 */
  var drag = -1, touchFx = { x: 0, y: 0, a: 0 };
  canvas.addEventListener('pointerdown', function (e) {
    var x = e.clientX, y = e.clientY, best = -1, bd = 20 * 20;
    for (var i = 0; i < N; i++) { var dx = X[i] - x, dy = Y[i] - y, dd = dx * dx + dy * dy; if (dd < bd) { bd = dd; best = i; } }
    if (best >= 0) { drag = best; born[best] = 0; }
    else {
      insert(x, y);
      drag = N - 1;                       // 置いた点はそのまま指で動かせる
      if (N > want + 40) {
        /* 増えすぎたら古い点を 1 つ静かに抜く */
        var victim = Math.floor(Math.random() * (N - 1)), k = 0;
        for (var p = 0; p < N; p++) if (tour[p] !== victim) tour[k++] = tour[p];
        for (var q = 0; q < N - 1; q++) if (tour[q] === N - 1) tour[q] = victim;
        X[victim] = X[N - 1]; Y[victim] = Y[N - 1]; TX[victim] = TX[N - 1]; TY[victim] = TY[N - 1];
        FX[victim] = FX[N - 1]; FY[victim] = FY[N - 1]; born[victim] = born[N - 1]; N--;
        drag = victim;
      }
      SFX.play('spawn', 150);
    }
    touchFx.x = x; touchFx.y = y; touchFx.a = 1;
    /* 触ったところだけ少し温め直す */
    if (phase !== 'hot') T = Math.max(T, T0 * 0.06);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (drag < 0) return;
    X[drag] = e.clientX; Y[drag] = e.clientY; born[drag] = 0;
    if (glide < 1) { TX[drag] = X[drag]; TY[drag] = Y[drag]; FX[drag] = X[drag]; FY[drag] = Y[drag]; }
    touchFx.x = e.clientX; touchFx.y = e.clientY; touchFx.a = 1;
    if (phase !== 'hot') T = Math.max(T, T0 * 0.03);
    SFX.play('move', 200);
  });
  function up() { drag = -1; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

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
    tctx.strokeStyle = P.rgba(c, 0.16 + 0.69 * cold);
    tctx.lineWidth = 0.8 + 0.5 * cold;
    tctx.lineJoin = 'round';
    tctx.beginPath();
    tctx.moveTo(X[tour[0]], Y[tour[0]]);
    for (var i = 1; i < N; i++) tctx.lineTo(X[tour[i]], Y[tour[i]]);
    tctx.closePath();
    tctx.stroke();
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.6 });
    ctx.drawImage(trail, 0, 0, W, H);
    ctx.fillStyle = P.rgba(P.line, 0.9);
    ctx.beginPath();
    for (var k = 0; k < N; k++) {
      if (born[k] < 2.5) continue;
      ctx.moveTo(X[k] + 2.2, Y[k]); ctx.arc(X[k], Y[k], 2.2, 0, Math.PI * 2);
    }
    ctx.fill();
    /* 触った点はシアンで灯り、少しずつ白に戻る */
    for (var m = 0; m < N; m++) {
      if (born[m] >= 2.5) continue;
      born[m] += drag === m ? 0 : dt;
      var a = 1 - born[m] / 2.5;
      ctx.fillStyle = P.rgba(P.mix(P.line, P.accent, a), 1);
      ctx.beginPath(); ctx.arc(X[m], Y[m], 2.4 + a * 1.4, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = P.rgba(P.accent, a * 0.45);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(X[m], Y[m], 7 + (1 - a) * 14, 0, Math.PI * 2); ctx.stroke();
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
      for (var i = 0; i < N; i++) if (i !== drag) { X[i] = FX[i] + (TX[i] - FX[i]) * g; Y[i] = FY[i] + (TY[i] - FY[i]) * g; }
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
