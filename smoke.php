<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('smoke');

$pageName = 'smoke';
$pageTitle = 'SMOKE - PULSE';
$pageDesc = '画面の下に並んだ線香から、細い煙が何本も立ちのぼる。下のほうではまっすぐ上がるが、高くなるとゆらぎ始め、隣り合っていた煙の筋がばらばらに分かれて渦を巻く。押すとそこから水色の煙が出てまわりの煙が押しのけられ、なぞると煙が指の動いた向きに流される。';
$pageCanonical = 'https://pulse.subara3.com/smoke.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="50"></div>
  <div class="ctl-row"><label for="k-draft">DRAFT</label><input type="range" id="k-draft" min="0" max="100" value="55"></div>
  <div class="ctl-btns"><button type="button" class="active" data-sticks="few">FEW</button><button type="button" data-sticks="many">MANY</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 煙は「流れ線（streakline）」で描く。線香の先から一定間隔で粒を放ち、
     放った順に粒を結ぶと、それが煙の筋になる。流れ場は上昇流に、高さとともに強くなる
     渦の場（流れ関数の微分なので湧き出しがない）を重ねたもの。 */
  var CAP = 420, NL = 5;
  var lines = [];          /* {x,y,a: Float32Array, s, n, sx, sy, off, acc, emit, accent, on} */
  var sticks = [];
  var waves = [];
  var vort = [], puffs = [], blobs = [];
  var U = 50, S = 600, Y0 = 0, LIFE = 20;
  var speed = 1, draft = 0.9, many = false, T = 0;

  function makeLine(sx, sy, off, accent) {
    return { x: new Float32Array(CAP), y: new Float32Array(CAP), a: new Float32Array(CAP),
             s: 0, n: 0, sx: sx, sy: sy, off: off, acc: Math.random() * 0.1,
             emit: accent ? 0.035 : 0.055, accent: accent, on: true };
  }

  function build() {
    S = Math.sqrt(W * H);
    U = Math.max(28, Math.min(70, H / 15));
    Y0 = H * (H > W ? 0.9 : 0.86);
    var n = many ? Math.max(5, Math.min(8, Math.round(W / 170))) : Math.max(3, Math.min(6, Math.round(W / 300)));
    sticks = []; lines = [];
    for (var i = 0; i < n; i++) {
      var x = W * (i + 0.5) / n + (Math.random() - 0.5) * W / n * 0.3;
      var y = Y0 + (Math.random() - 0.5) * H * 0.04;
      sticks.push({ x: x, y: y, ph: Math.random() * 6.28 });
      for (var k = 0; k < NL; k++) lines.push(makeLine(x, y, (k - (NL - 1) / 2) * 1.8, false));
    }
    /* 渦の場: 波長の違う 6 本の波。上昇流と同じ速さで上へ運ばれる */
    waves = [];
    for (var j = 0; j < 6; j++) {
      var lam = S * (0.07 + 0.33 * Math.pow(j / 5, 1.3));
      var ang = Math.random() * Math.PI;
      var kk = 2 * Math.PI / lam;
      waves.push({ p: Math.cos(ang) * kk, q: Math.sin(ang) * kk, w: (Math.random() - 0.5) * 0.6,
                   ph: Math.random() * 6.28, amp: (0.6 + Math.random() * 0.4) * Math.pow(j / 5 * 0.8 + 0.2, 0.7) / kk });
    }
    vort.length = puffs.length = blobs.length = 0;
    /* 開いた瞬間から煙が上まで届いているように、先に時間を進めておく */
    for (var s = 0; s < LIFE / 0.08; s++) advance(0.08);
  }

  var vu = 0, vv = 0;
  function vel(x, y) {
    var h = (Y0 - y) / H;
    var u = 0, v = -U * (1 + 0.25 * Math.max(0, h));
    /* 渦の強さ A(h): 線香の近くは 0、高いところほど強い */
    var e0 = 0.04, e1 = 0.55, A = 0, dA = 0;
    if (h > e0) {
      var z = Math.min(1, (h - e0) / (e1 - e0));
      A = z * z * (3 - 2 * z);
      dA = h < e1 ? 6 * z * (1 - z) / (e1 - e0) : 0;
    }
    if (A > 0) {
      var g = U * 1.25 * draft * (0.7 + 0.3 * Math.sin(T * 0.045));
      var ys = y + U * T, sS = 0, cq = 0, cp = 0;
      for (var i = 0; i < 6; i++) {
        var wv = waves[i], arg = wv.p * x + wv.q * ys + wv.w * T + wv.ph;
        var sn = Math.sin(arg), cs = Math.cos(arg);
        sS += wv.amp * sn; cq += wv.amp * wv.q * cs; cp += wv.amp * wv.p * cs;
      }
      /* u = dψ/dy, v = -dψ/dx（dh/dy = -1/H） */
      u += g * (-dA / H * sS + A * cq);
      v += -g * A * cp;
      /* 全体が左右にゆっくり傾く */
      u += U * 0.3 * Math.sin(T * 0.06 + 1.3) * A;
    }
    var i2, d2, f, dx, dy;
    for (i2 = 0; i2 < vort.length; i2++) {
      var vo = vort[i2]; dx = x - vo.x; dy = y - vo.y; d2 = dx * dx + dy * dy + 1;
      if (d2 > 40000) continue;
      f = vo.g * vo.k / (6.2832 * d2) * (1 - Math.exp(-d2 / 700));
      u -= dy * f; v += dx * f;
    }
    for (i2 = 0; i2 < puffs.length; i2++) {
      var pf = puffs[i2]; dx = x - pf.x; dy = y - pf.y; d2 = dx * dx + dy * dy;
      if (d2 > 40000) continue;
      var d = Math.sqrt(d2) + 1;
      f = pf.k * Math.exp(-d2 / 4500) / d;
      u += dx * f; v += dy * f;
    }
    for (i2 = 0; i2 < blobs.length; i2++) {
      var b = blobs[i2]; dx = x - b.x; dy = y - b.y; d2 = dx * dx + dy * dy;
      if (d2 > 22500) continue;
      f = b.k * Math.exp(-d2 / 2500);
      u += b.vx * f; v += b.vy * f;
    }
    vu = u; vv = v;
  }

  function advance(dt) {
    T += dt;
    var i, n;
    for (i = vort.length - 1; i >= 0; i--) {
      var vo = vort[i]; vo.t += dt; vo.y -= U * dt;
      var q = 1 - vo.t / 3.5; if (q <= 0) { vort.splice(i, 1); continue; }
      vo.k = q * q;
    }
    for (i = puffs.length - 1; i >= 0; i--) {
      var pf = puffs[i]; pf.t += dt;
      var q2 = 1 - pf.t / 0.6; if (q2 <= 0) { puffs.splice(i, 1); continue; }
      pf.k = 170 * q2 * q2;
    }
    for (i = blobs.length - 1; i >= 0; i--) {
      var b = blobs[i]; b.t += dt; b.y -= U * dt * 0.5;
      var q3 = 1 - b.t / 1.1; if (q3 <= 0) { blobs.splice(i, 1); continue; }
      b.k = q3 * q3;
    }
    for (var l = lines.length - 1; l >= 0; l--) {
      var L = lines[l];
      if (L.on) {
        L.acc += dt;
        while (L.acc > L.emit) {
          L.acc -= L.emit;
          var idx = (L.s + L.n) % CAP;
          if (L.n === CAP) L.s = (L.s + 1) % CAP; else L.n++;
          /* ほんのわずかな揺らぎ。これが高いところで別々の道になる */
          L.x[idx] = L.sx + L.off + (Math.random() - 0.5) * 0.3;
          L.y[idx] = L.sy - 2;
          L.a[idx] = 0;
        }
      }
      for (n = 0; n < L.n; n++) {
        var k = (L.s + n) % CAP;
        vel(L.x[k], L.y[k]);
        L.x[k] += vu * dt; L.y[k] += vv * dt; L.a[k] += dt;
      }
      while (L.n > 0 && (L.a[L.s] > LIFE || L.y[L.s] < -30)) { L.s = (L.s + 1) % CAP; L.n--; }
      if (!L.on && L.n === 0) lines.splice(l, 1);
    }
  }

  function drawSticks() {
    ctx.lineWidth = 1.2;
    ctx.strokeStyle = P.rgba(P.steel, 0.7);
    ctx.beginPath();
    for (var i = 0; i < sticks.length; i++) {
      var s = sticks[i];
      ctx.moveTo(s.x, s.y); ctx.lineTo(s.x, H + 2);
    }
    ctx.stroke();
    /* 土台の線と、線香の先の小さな火 */
    ctx.strokeStyle = P.rgba(P.steel, 0.35);
    ctx.beginPath(); ctx.moveTo(0, H * 0.965 + 0.5); ctx.lineTo(W, H * 0.965 + 0.5); ctx.stroke();
    for (var j = 0; j < sticks.length; j++) {
      var t = sticks[j], gl = 0.75 + 0.25 * Math.sin(T * 0.9 + t.ph);
      ctx.fillStyle = P.rgba(P.line, 0.08 * gl);
      ctx.beginPath(); ctx.arc(t.x, t.y, 7, 0, 6.2832); ctx.fill();
      ctx.fillStyle = P.rgba(P.line, 0.9 * gl);
      ctx.beginPath(); ctx.arc(t.x, t.y, 1.8, 0, 6.2832); ctx.fill();
    }
  }

  function drawLines() {
    ctx.lineWidth = 1;
    ctx.lineJoin = 'round';
    for (var l = 0; l < lines.length; l++) {
      var L = lines[l], col = L.accent ? P.accent : P.line, base = L.accent ? 0.8 : 0.5;
      if (L.n < 2) continue;
      var n = L.n - 1, k = (L.s + n) % CAP, px = L.x[k], py = L.y[k];
      while (n > 0) {
        var m = Math.max(0, n - 6), mid = (L.s + ((n + m) >> 1)) % CAP;
        var age = L.a[mid], h = (Y0 - L.y[mid]) / H;
        var a = base * Math.min(1, age * 2) * Math.pow(Math.max(0, 1 - age / LIFE), 1.3)
              * (1 - 0.35 * Math.max(0, Math.min(1, h))) * Math.min(1, (L.y[mid] + 30) / 90);
        ctx.beginPath(); ctx.moveTo(px, py);
        var len = 0;
        for (var i = n - 1; i >= m; i--) {
          k = (L.s + i) % CAP;
          var x = L.x[k], y = L.y[k], dx = x - px, dy = y - py, d2 = dx * dx + dy * dy;
          if (d2 > 19600) { ctx.moveTo(x, y); len += 140; } else { ctx.lineTo(x, y); len += Math.sqrt(d2); }
          px = x; py = y;
        }
        /* 引き伸ばされて粒の間が空いたところは、薄くほどけていく */
        var gap = len / Math.max(1, n - m), st = gap < 7 ? 1 : Math.max(0, 1 - (gap - 7) / 40);
        a *= L.accent ? 0.15 + 0.85 * st : st * st;
        if (a > 0.01) { ctx.strokeStyle = P.rgba(col, a); ctx.stroke(); }
        n = m;
      }
    }
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; build(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, lastTime = 0, mine = [];
  function pos(e) { return { x: e.clientX, y: e.clientY }; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = pos(e); lastTime = performance.now();
    /* 指の下から押し広げる風と、上向きに巻き上がる渦の対 */
    puffs.push({ x: last.x, y: last.y, t: 0, k: 170 });
    vort.push({ x: last.x - 24, y: last.y, g: -20000, t: 0, k: 1 });
    vort.push({ x: last.x + 24, y: last.y, g: 20000, t: 0, k: 1 });
    /* 指の先から水色の煙 */
    mine = [];
    for (var k = 0; k < 4; k++) {
      var L = makeLine(last.x, last.y, (k - 1.5) * 2.2, true);
      lines.push(L); mine.push(L);
    }
    var acc = lines.filter(function (o) { return o.accent; });
    for (var j = 0; j < acc.length - 16; j++) acc[j].on = false;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = pos(e), now = performance.now();
    for (var i = 0; i < mine.length; i++) { mine[i].sx = p.x; mine[i].sy = p.y; }
    var dx = p.x - last.x, dy = p.y - last.y, dist = Math.hypot(dx, dy);
    if (dist < 12) return;
    var dts = Math.max(0.008, (now - lastTime) / 1000), sc = Math.min(1, 700 / (dist / dts));
    blobs.push({ x: p.x, y: p.y, vx: dx / dts * sc, vy: dy / dts * sc, t: 0, k: 1 });
    if (blobs.length > 28) blobs.shift();
    last = p; lastTime = now;
    SFX.play('move', 140);
  });
  function up() {
    if (!down) return;
    down = false;
    for (var i = 0; i < mine.length; i++) mine[i].on = false;
    mine = [];
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { speed = 0.35 + document.getElementById('k-speed').value / 100 * 1.3; }
  function readDraft() { draft = 0.1 + document.getElementById('k-draft').value / 100 * 1.45; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-draft').addEventListener('input', readDraft);
  document.querySelectorAll('[data-sticks]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-sticks]').forEach(function (o) { o.classList.toggle('active', o === b); });
      many = b.dataset.sticks === 'many';
      build();
      SFX.play('toggle');
    });
  });
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readDraft();
  var restored = document.querySelector('[data-sticks].active');
  if (restored && restored.dataset.sticks === 'many') { many = true; build(); }

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));
    lastT = now;
    advance(dt * speed);
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { alpha: 0.7 });
    drawSticks();
    drawLines();
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
<?php require_once __DIR__ . '/includes/sfx.php'; ?>
<?php require_once __DIR__ . '/includes/page-end.php'; ?>
</body>
</html>
