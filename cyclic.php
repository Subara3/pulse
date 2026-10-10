<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('cyclic');

$pageName = 'cyclic';
$pageTitle = 'CYCLIC - PULSE';
$pageDesc = '短い線が画面いっぱいに並び、明るい帯が渦を巻きながら外へ広がっていく。触ったところに新しい渦の中心ができ、そこからも帯が広がる。';
$pageCanonical = 'https://pulse.subara3.com/cyclic.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-btns"><button type="button" class="active" data-net="round">ROUND</button><button type="button" data-net="square">SQUARE</button></div>
  <div class="ctl-btns"><button type="button" id="k-shuffle">SHUFFLE</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 巡回セルオートマトン。各マスは 0..N-1 の状態を持ち、隣に「次の状態」がいれば 1 つ進む。
     状態を針の向き（半回転で一周）として描くので、状態が進むたびに針が少し回る */
  var N = 14, NX, NY, S, cur, nxt, moved, mark;
  var net = 'round', pace = 0.4;

  function alloc() {
    S = Math.max(9, Math.min(14, Math.round(Math.min(W, H) / 62)));
    NX = Math.ceil(W / S) + 1; NY = Math.ceil(H / S) + 1;
    var n = NX * NY;
    cur = new Uint8Array(n); nxt = new Uint8Array(n); moved = new Uint8Array(n); mark = new Float32Array(n);
    wrapTables();
    smoothStart();
    for (var g = 0; g < 260; g++) gen();
  }

  /* 近所の形: ROUND は半径 2 ほどの円（12 マス）に「次の状態」が 2 つ以上、SQUARE は 8 近傍に 1 つ以上で進む */
  var NETS = {
    round: { r: 2, lim: 5, t: 2, n: 12 },
    square: { r: 1, lim: 2, t: 1, n: 14 }
  };
  var offX, offY, wx, wy, T = 3, RR = 2;
  function setNet(name) {
    net = name;
    var o = NETS[name];
    RR = o.r; T = o.t;
    var oldN = N; N = o.n;
    offX = []; offY = [];
    for (var dy = -RR; dy <= RR; dy++) for (var dx = -RR; dx <= RR; dx++) {
      if ((dx || dy) && dx * dx + dy * dy <= o.lim) { offX.push(dx); offY.push(dy); }
    }
    if (cur && oldN !== N) for (var k = 0; k < cur.length; k++) cur[k] = Math.floor(cur[k] * N / oldN);
    if (NX) wrapTables();
  }
  function wrapTables() {
    wx = new Int32Array(NX + 8); wy = new Int32Array(NY + 8);
    for (var i = 0; i < NX + 8; i++) wx[i] = ((i - 4) % NX + NX) % NX;
    for (var j = 0; j < NY + 8; j++) wy[j] = (((j - 4) % NY + NY) % NY) * NX;
  }

  function gen() {
    var no = offX.length;
    for (var j = 0; j < NY; j++) {
      var row = j * NX;
      for (var i = 0; i < NX; i++) {
        var k = row + i, s = cur[k], nx = s + 1 === N ? 0 : s + 1, hit = 0;
        for (var q = 0; q < no; q++) {
          if (cur[wy[j + offY[q] + 4] + wx[i + offX[q] + 4]] === nx && ++hit >= T) break;
        }
        var go = hit >= T;
        nxt[k] = go ? nx : s;
        moved[k] = go ? 1 : 0;
      }
    }
    var t = cur; cur = nxt; nxt = t;
  }

  /* なめらかな位相の場に、渦の芯をいくつか埋めこんで始める。でたらめから始めるより早く渦がそろう */
  function smoothStart() {
    var waves = [], cores = [], q;
    for (q = 0; q < 4; q++) waves.push({ kx: (Math.random() - 0.5) * 0.12, ky: (Math.random() - 0.5) * 0.12, p: Math.random() * 6.3, a: 0.6 + Math.random() });
    for (q = 0; q < 5; q++) cores.push({ x: Math.random() * NX, y: Math.random() * NY, w: Math.random() < 0.5 ? 1 : -1 });
    for (var j = 0; j < NY; j++) for (var i = 0; i < NX; i++) {
      var ph = 0;
      for (q = 0; q < waves.length; q++) ph += waves[q].a * Math.sin(waves[q].kx * i + waves[q].ky * j + waves[q].p);
      ph *= N * 0.6;
      for (q = 0; q < cores.length; q++) ph += cores[q].w * Math.atan2(j - cores[q].y, i - cores[q].x) / (Math.PI * 2) * N;
      cur[j * NX + i] = ((Math.floor(ph) % N) + N) % N;
    }
    moved.fill(0);
  }
  /* 触った所に渦の芯を 1 つ置く。まわりの状態を角度に合わせて並べ直すと、そこから渦が回り出す */
  function vortex(cx, cy, r, wind, touched) {
    for (var j = Math.floor(cy - r); j <= cy + r; j++) {
      for (var i = Math.floor(cx - r); i <= cx + r; i++) {
        var d = Math.hypot(i - cx, j - cy) / r;
        if (d > 1) continue;
        var k = ((j % NY + NY) % NY) * NX + (i % NX + NX) % NX;
        var a = Math.atan2(j - cy, i - cx) / (Math.PI * 2) * N * wind;
        cur[k] = ((Math.floor(a) % N) + N) % N; moved[k] = 0;
        if (touched) mark[k] = Math.max(mark[k], 1 - d * 0.5);
      }
    }
  }

  /* 一部をでたらめに戻す。ばらばらになった所から新しい渦の芯が生まれる */
  function scramble(cx, cy, r, touched) {
    for (var j = Math.floor(cy - r); j <= cy + r; j++) {
      for (var i = Math.floor(cx - r); i <= cx + r; i++) {
        var d = Math.hypot(i - cx, j - cy) / r;
        if (d > 1) continue;
        var k = ((j % NY + NY) % NY) * NX + (i % NX + NX) % NX;
        cur[k] = (Math.random() * N) | 0; moved[k] = 0;
        if (touched) mark[k] = Math.max(mark[k], 1 - d * 0.5);
      }
    }
  }

  /* ---------------------------------------------------------- 描く */
  /* f: 世代の進み具合。この世代で進んだマスは、前の状態から f だけ進めた値で描く。
     細線は波の面に沿わせる（状態の勾配に直交）。波の先頭（状態 0 付近）を明るく、後ろを暗く */
  var BK = 5, ph, GO = [];
  for (var gdy = -2; gdy <= 2; gdy++) for (var gdx = -2; gdx <= 2; gdx++) {
    var rr = gdx * gdx + gdy * gdy;
    if (rr && rr <= 6) GO.push(gdx, gdy, 1 / rr);
  }
  function wrapDiff(a, b) {
    var d = a - b;
    if (d > N / 2) d -= N; else if (d < -N / 2) d += N;
    return d;
  }
  function draw(f) {
    P.fill(ctx, W, H);
    if (!ph || ph.length !== cur.length) ph = new Float32Array(cur.length);
    var k;
    for (k = 0; k < cur.length; k++) ph[k] = cur[k] - (moved[k] ? 1 - f : 0);
    var groups = [], hot = [], b;
    for (b = 0; b < BK; b++) { groups.push([]); hot.push([]); }
    var len = S * 0.62, flat = [];
    for (var j = 0; j < NY; j++) {
      var y = j * S, row = j * NX;
      for (var i = 0; i < NX; i++) {
        k = row + i;
        /* 半径 2 の近所で傾きをならす（隣 2 マスだけだと向きが 8 方向に固まる） */
        var gx = 0, gy = 0, c0 = ph[k];
        for (var q = 0; q < GO.length; q += 3) {
          var dd = wrapDiff(ph[wy[j + GO[q + 1] + 4] + wx[i + GO[q] + 4]], c0) * GO[q + 2];
          gx += dd * GO[q]; gy += dd * GO[q + 1];
        }
        var g = Math.hypot(gx, gy);
        var t = ph[k] / N;
        var lum = 0.5 + 0.5 * Math.cos(t * Math.PI * 2);
        var dx, dy;
        if (g < 0.5) { flat.push(i * S, y); continue; }
        dx = -gy / g * len; dy = gx / g * len;
        b = Math.min(BK - 1, (lum * lum * BK) | 0);
        var x = i * S;
        (mark[k] > 0.12 ? hot : groups)[b].push(x - dx, y - dy, x + dx, y + dy);
      }
    }
    /* 傾きのない（一様な）所は、方眼の交点に小さな点だけ打つ */
    ctx.fillStyle = P.rgba(P.steel, 0.28);
    ctx.beginPath();
    for (var d = 0; d < flat.length; d += 2) ctx.rect(flat[d] - 0.6, flat[d + 1] - 0.6, 1.2, 1.2);
    ctx.fill();
    ctx.lineCap = 'round';
    for (b = 0; b < BK; b++) {
      var a = 0.14 + 0.82 * (b / (BK - 1));
      stroke(groups[b], P.rgba(b < 2 ? P.steel : P.line, a), b < 2 ? 0.9 : 1.15);
      stroke(hot[b], P.rgba(P.accent, 0.3 + 0.7 * (b / (BK - 1))), 1.15);
    }
  }
  function stroke(list, style, w) {
    if (!list.length) return;
    ctx.strokeStyle = style; ctx.lineWidth = w;
    ctx.beginPath();
    for (var i = 0; i < list.length; i += 4) { ctx.moveTo(list[i], list[i + 1]); ctx.lineTo(list[i + 2], list[i + 3]); }
    ctx.stroke();
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null;
  function cellOf(e) { return { x: e.clientX / S, y: e.clientY / S, px: e.clientX, py: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = cellOf(e);
    vortex(last.x, last.y, 6, Math.random() < 0.5 ? 1 : -1, true);
    SFX.play('ripple');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = cellOf(e), dist = Math.hypot(p.px - last.px, p.py - last.py);
    if (dist < S * 1.5) return;
    var n = Math.ceil(dist / (S * 1.5));
    for (var i = 1; i <= n; i++) scramble(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n, 2.2, true);
    last = p;
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-pace').addEventListener('input', function () { pace = this.value / 100; });
  document.querySelectorAll('[data-net]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-net]').forEach(function (o) { o.classList.toggle('active', o === b); });
      setNet(b.dataset.net);
      SFX.play('toggle');
    });
  });
  document.getElementById('k-shuffle').addEventListener('click', function () {
    smoothStart();
    SFX.play('click');
  });
  pace = document.getElementById('k-pace').value / 100;
  setNet('round');

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; alloc(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), phase = 0, stirT = 0;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    phase += dt * (2.5 + pace * 10);
    while (phase >= 1) { phase -= 1; gen(); }
    for (var k = 0; k < mark.length; k++) if (mark[k] > 0) mark[k] *= 0.99;
    /* ときどき、どこか 1 か所を小さくかき混ぜる。渦の並びが少しずつ入れ替わっていく */
    stirT += dt;
    if (stirT > 16) { stirT = 0; vortex(Math.random() * NX, Math.random() * NY, 5, Math.random() < 0.5 ? 1 : -1, false); }
    draw(phase);
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
