<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('network');

$pageName = 'network';
$pageTitle = 'NETWORK - PULSE';
$pageDesc = 'ばらばらの点が近くの点と1本ずつ線で結ばれ、小さなまとまりがつながって大きな網になる。しばらくすると線が減って、またばらける。点をドラッグすると、つながった点もいっしょに引っぱられる。';
$pageCanonical = 'https://pulse.subara3.com/network.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-pace">PACE</label><input type="range" id="k-pace" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-dense">DENSE</label><input type="range" id="k-dense" min="0" max="100" value="50"></div>
  <div class="ctl-btns"><button type="button" class="active" data-wire="near">NEAR</button><button type="button" data-wire="any">ANY</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  var MAXN = 400;
  var px = new Float32Array(MAXN), py = new Float32Array(MAXN), vx = new Float32Array(MAXN), vy = new Float32Array(MAXN);
  var deg = new Uint16Array(MAXN), comp = new Int16Array(MAXN), hot = new Float32Array(MAXN), alive = new Float32Array(MAXN);
  var n = 0, E = [], fading = [];
  var pace = 0.4, dense = 0.5, wire = 'near', clock = 0;
  var grab = -1, gx = 0, gy = 0;
  var linked = {};

  function ek(a, b) { return a < b ? a * 1024 + b : b * 1024 + a; }
  function seed() {
    n = Math.round(Math.min(220, Math.max(70, W * H / 5200)));
    for (var i = 0; i < n; i++) {
      px[i] = W * (0.06 + Math.random() * 0.88); py[i] = H * (0.08 + Math.random() * 0.84);
      vx[i] = vy[i] = 0; hot[i] = 0; alive[i] = 1;
    }
    E = []; fading = []; linked = {};
    for (i = 0; i < n * 0.75; i++) addEdge(-1, true);
  }

  /* 糸を 1 本足す: 適当な点を選び、近くの点か、まったく適当な点と結ぶ */
  function addEdge(from, quiet, tint) {
    var a = from >= 0 ? from : (Math.random() * n) | 0, b = -1;
    if (wire === 'near' || from >= 0) {
      var best = [], i;
      for (i = 0; i < n; i++) {
        if (i === a || linked[ek(a, i)]) continue;
        var d = Math.hypot(px[i] - px[a], py[i] - py[a]);
        best.push([d, i]);
      }
      best.sort(function (p, q) { return p[0] - q[0]; });
      if (!best.length) return;
      b = best[(Math.random() * Math.min(5, best.length)) | 0][1];
    } else {
      for (var t = 0; t < 10 && b < 0; t++) { var c = (Math.random() * n) | 0; if (c !== a && !linked[ek(a, c)]) b = c; }
      if (b < 0) return;
    }
    var merging = comp[a] !== comp[b];
    E.push({ a: a, b: b, t: clock, tint: tint || 0 });
    linked[ek(a, b)] = 1;
    deg[a]++; deg[b]++;
    if (!quiet && merging && size[comp[a]] > 4 && size[comp[b]] > 4) SFX.play('collide', 900);
  }
  function dropEdge() {
    if (!E.length) return;
    /* 古い糸ほど切れやすい */
    var i = (Math.random() * Math.random() * E.length) | 0, e = E[i];
    E.splice(i, 1);
    delete linked[ek(e.a, e.b)];
    deg[e.a]--; deg[e.b]--;
    fading.push({ a: e.a, b: e.b, k: 1 });
  }

  /* つながりの塊を数える */
  var parent = new Int16Array(MAXN), size = new Int16Array(MAXN), giant = 0;
  function find(x) { while (parent[x] !== x) { parent[x] = parent[parent[x]]; x = parent[x]; } return x; }
  function components() {
    var i;
    for (i = 0; i < n; i++) { parent[i] = i; size[i] = 0; }
    for (i = 0; i < E.length; i++) {
      var ra = find(E[i].a), rb = find(E[i].b);
      if (ra !== rb) parent[ra] = rb;
    }
    var big = -1, bs = 0;
    for (i = 0; i < n; i++) { var r = find(i); comp[i] = r; size[r]++; }
    for (i = 0; i < n; i++) if (size[i] > bs) { bs = size[i]; big = i; }
    giant = bs > 6 ? big : -1;
  }

  function physics(dt) {
    var i, j, rest = 34 + (1 - dense) * 26, cx = W / 2, cy = H / 2;
    for (i = 0; i < n; i++) {
      var fx = 0, fy = 0;
      for (j = 0; j < n; j++) {
        if (j === i) continue;
        var dx = px[i] - px[j], dy = py[i] - py[j], d2 = dx * dx + dy * dy;
        if (d2 > 26000) continue;
        var d = Math.sqrt(d2) + 0.01, f = 900 / (d2 + 60);
        fx += dx / d * f; fy += dy / d * f;
      }
      /* 画面の縁はやわらかく押し返す */
      var m = 40;
      if (px[i] < m) fx += (m - px[i]) * 0.8; if (px[i] > W - m) fx -= (px[i] - W + m) * 0.8;
      if (py[i] < m) fy += (m - py[i]) * 0.8; if (py[i] > H - m) fy -= (py[i] - H + m) * 0.8;
      fx += (cx - px[i]) * 0.008; fy += (cy - py[i]) * 0.008;
      vx[i] += fx * dt; vy[i] += fy * dt;
    }
    for (i = 0; i < E.length; i++) {
      var e = E[i], ddx = px[e.b] - px[e.a], ddy = py[e.b] - py[e.a], dd = Math.sqrt(ddx * ddx + ddy * ddy) + 0.01;
      var k = (dd - rest) * 1.6 * dt;
      vx[e.a] += ddx / dd * k; vy[e.a] += ddy / dd * k;
      vx[e.b] -= ddx / dd * k; vy[e.b] -= ddy / dd * k;
    }
    var damp = Math.pow(0.12, dt);
    for (i = 0; i < n; i++) {
      if (i === grab) { px[i] += (gx - px[i]) * 0.35; py[i] += (gy - py[i]) * 0.35; vx[i] = vy[i] = 0; continue; }
      vx[i] *= damp; vy[i] *= damp;
      var sp = Math.hypot(vx[i], vy[i]);
      if (sp > 140) { vx[i] *= 140 / sp; vy[i] *= 140 / sp; }
      px[i] += vx[i] * dt; py[i] += vy[i] * dt;
    }
  }

  /* ---------------------------------------------------------- 描く */
  function draw(dt) {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.5 });
    var i, e;
    ctx.lineCap = 'round';
    /* 切れた糸は鋼色で薄れて消える */
    ctx.lineWidth = 1;
    for (i = fading.length - 1; i >= 0; i--) {
      var f = fading[i];
      f.k -= dt * 0.8;
      if (f.k <= 0) { fading.splice(i, 1); continue; }
      ctx.strokeStyle = P.rgba(P.steel, f.k * 0.4);
      ctx.beginPath(); ctx.moveTo(px[f.a], py[f.a]); ctx.lineTo(px[f.b], py[f.b]); ctx.stroke();
    }
    /* 小さな塊の糸は鋼色、ひとつにまとまった網は白 */
    var small = [], bigE = [], fresh = [];
    for (i = 0; i < E.length; i++) {
      e = E[i];
      if (clock - e.t < 1.6 || e.tint > 0) fresh.push(e);
      else if (comp[e.a] === giant) bigE.push(e);
      else small.push(e);
    }
    ctx.strokeStyle = P.rgba(P.steel, 0.6);
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (i = 0; i < small.length; i++) { e = small[i]; ctx.moveTo(px[e.a], py[e.a]); ctx.lineTo(px[e.b], py[e.b]); }
    ctx.stroke();
    ctx.strokeStyle = P.rgba(P.line, 0.55);
    ctx.lineWidth = 1.1;
    ctx.beginPath();
    for (i = 0; i < bigE.length; i++) { e = bigE[i]; ctx.moveTo(px[e.a], py[e.a]); ctx.lineTo(px[e.b], py[e.b]); }
    ctx.stroke();
    /* 結ばれたばかりの糸は、端から端へ伸びていく */
    for (i = 0; i < fresh.length; i++) {
      e = fresh[i];
      var age = clock - e.t, grow = Math.min(1, age / 0.7);
      var base = comp[e.a] === giant ? P.line : P.steel;
      var col = e.tint > 0 ? P.mix(base, P.accent, e.tint) : base;
      ctx.strokeStyle = P.rgba(col, e.tint > 0 ? 0.85 : 0.55 + (1 - Math.min(1, age / 1.6)) * 0.35);
      ctx.lineWidth = 1.1;
      ctx.beginPath();
      ctx.moveTo(px[e.a], py[e.a]);
      ctx.lineTo(px[e.a] + (px[e.b] - px[e.a]) * grow, py[e.a] + (py[e.b] - py[e.a]) * grow);
      ctx.stroke();
      if (e.tint > 0) e.tint = Math.max(0, e.tint - dt * 0.35);
    }
    /* 点: つながりが多いほど少し大きい */
    for (var g = 0; g < 2; g++) {
      ctx.fillStyle = g ? P.rgba(P.line, 0.95) : P.rgba(P.steel, 0.9);
      ctx.beginPath();
      for (i = 0; i < n; i++) {
        if ((comp[i] === giant) !== !!g) continue;
        var r = 1.4 + Math.sqrt(deg[i]) * 0.75;
        ctx.moveTo(px[i] + r, py[i]); ctx.arc(px[i], py[i], r, 0, Math.PI * 2);
      }
      ctx.fill();
    }
    /* つまんでいる点だけシアン */
    for (i = 0; i < n; i++) {
      if (hot[i] <= 0) continue;
      if (i !== grab) hot[i] = Math.max(0, hot[i] - dt * 0.6);
      ctx.strokeStyle = P.rgba(P.accent, hot[i] * 0.8);
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(px[i], py[i], 7 + (1 - hot[i]) * 8, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = P.rgba(P.accent, hot[i]);
      ctx.beginPath(); ctx.arc(px[i], py[i], 2.6, 0, Math.PI * 2); ctx.fill();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  function nearest(x, y, lim) {
    var b = -1, bd = lim;
    for (var i = 0; i < n; i++) { var d = Math.hypot(px[i] - x, py[i] - y); if (d < bd) { bd = d; b = i; } }
    return b;
  }
  canvas.addEventListener('pointerdown', function (e) {
    gx = e.clientX; gy = e.clientY;
    grab = nearest(gx, gy, 44);
    if (grab < 0 && n < MAXN) {
      /* 何もないところでは、新しい点が生まれて近くの 2 点と結ばれる */
      grab = n++;
      px[grab] = gx; py[grab] = gy; vx[grab] = vy[grab] = 0; deg[grab] = 0; alive[grab] = 1;
      comp[grab] = -2;
      addEdge(grab, true, 1); addEdge(grab, true, 1);
      SFX.play('spawn');
    } else SFX.play('tick');
    if (grab >= 0) hot[grab] = 1;
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (grab < 0) return;
    gx = e.clientX; gy = e.clientY;
    SFX.play('move', 260);
  });
  function up() { grab = -1; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kp = document.getElementById('k-pace'), kd = document.getElementById('k-dense');
  kp.addEventListener('input', function () { pace = this.value / 100; });
  kd.addEventListener('input', function () { dense = this.value / 100; });
  pace = kp.value / 100; dense = kd.value / 100;
  document.querySelectorAll('[data-wire]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-wire]').forEach(function (o) { o.classList.toggle('active', o === b); });
      wire = b.dataset.wire;
      SFX.play('toggle');
    });
  });

  ctx = P.fit(canvas, function (w, h) { W = w; H = h; seed(); components(); });

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now(), acc = 0, cycle = 0.27;
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now; clock += dt;
    /* 糸の本数の目標がゆっくり上下する。ほどけた状態から、網、また疎らへ */
    cycle += dt / (110 - pace * 70);
    var tgt = n * (0.45 + (0.75 + dense * 0.8) * (0.5 - 0.5 * Math.cos(cycle * Math.PI * 2)));
    acc += dt * (3 + pace * 10);
    while (acc > 1) {
      acc -= 1;
      if (E.length < tgt - 1) addEdge(-1, false);
      else if (E.length > tgt + 1) dropEdge();
      else if (Math.random() < 0.5) { dropEdge(); addEdge(-1, false); }
    }
    components();
    physics(dt);
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
