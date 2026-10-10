<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('marbling');

$pageName = 'marbling';
$pageTitle = 'MARBLING - PULSE';
$pageDesc = '水面にインクが一滴ずつ落ちて輪が広がり、前からある輪を押しのけて形を変えていく。押さえるとそこにインクが湧き出し、なぞると輪がその向きに引き伸ばされる。';
$pageCanonical = 'https://pulse.subara3.com/marbling.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-drip">DRIP</label><input type="range" id="k-drip" min="0" max="100" value="45"></div>
  <div class="ctl-btns"><button type="button" class="active" data-comb="on">COMB</button><button type="button" data-comb="off">DROPS</button></div>
  <div class="ctl-btns"><button type="button" id="k-clear">CLEAR</button></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0;

  /*
   * インクの輪は閉じた折れ線で持つ。水面の動きは「点をどこへ動かすか」の式だけで書ける。
   *   滴: 中心 c から距離 d の点を  c + d·√(1 + r²/|d|²)  へ（面積を保ったまま押しのける）
   *   引き: なぞった線の近くの点を、なぞった向きに、離れるほど弱く動かす
   * 押しのけられた古い輪はやがて画面の外へ出て、自然に入れ替わる。
   */
  var curves = [];          /* { p: [x0, y0, x1, y1, ...], born, mine, fade } */
  var drops = [];           /* 広がっている途中の滴 { x, y, left, rate, curve } */
  var combs = [];           /* 自動でゆっくり横切る櫛 */
  var queue = [];           /* これから落ちる滴 */
  var drip = 0.45, combOn = true, clock = 0;
  var ctx = P.fit(canvas, function (w, h) {
    var first = W === 0;
    W = w; H = h;
    if (first) seed();
  });

  function circle(x, y, r, mine) {
    var n = Math.max(48, Math.round(Math.PI * 2 * r / 5)), p = new Array(n * 2);
    for (var i = 0; i < n; i++) {
      var a = i / n * Math.PI * 2;
      p[i * 2] = x + Math.cos(a) * r; p[i * 2 + 1] = y + Math.sin(a) * r;
    }
    var c = { p: p, born: clock, mine: mine ? 1 : 0, fade: 1 };
    curves.push(c);
    return c;
  }

  /* 中心 (x, y) に、面積 area の分だけインクを足す。すべての点が外へ押される。
     滴のすぐそばを通る線は大きく引き伸ばされるので、元の線の上で点を足してから押す
     （押した後で足すと、滴を横切る弦になってしまう） */
  function spread(x, y, area, skip) {
    for (var n = 0; n < curves.length; n++) {
      var c = curves[n];
      if (c === skip) continue;
      var p = c.p, len = p.length, out = null, fx = p[0], fy = p[1];
      for (var i = 0; i < len; i += 2) {
        var ax = p[i], ay = p[i + 1];
        var j = i + 2, bx = j < len ? p[j] : fx, by = j < len ? p[j + 1] : fy;
        var dx = ax - x, dy = ay - y, L = dx * dx + dy * dy, s = Math.sqrt(1 + area / Math.max(L, 1e-6));
        var tx = x + dx * s, ty = y + dy * s;
        var ex = bx - x, ey = by - y, M = ex * ex + ey * ey, sb = Math.sqrt(1 + area / Math.max(M, 1e-6));
        var ux = x + ex * sb, uy = y + ey * sb;
        var gap = Math.hypot(ux - tx, uy - ty);
        if (gap > 6 && !out) out = p.slice(0, i);
        if (out) {
          out.push(tx, ty);
          if (gap > 6) {
            var m = Math.min(96, Math.ceil(gap / 4));
            for (var k = 1; k < m; k++) {
              var qx = ax + (bx - ax) * k / m - x, qy = ay + (by - ay) * k / m - y;
              var Q = qx * qx + qy * qy, sq = Math.sqrt(1 + area / Math.max(Q, 1e-6));
              out.push(x + qx * sq, y + qy * sq);
            }
          }
        } else { p[i] = tx; p[i + 1] = ty; }
      }
      if (out) c.p = out;
    }
  }

  /* なぞった短い線分 a→b の近くの点を、同じ向きへ引く */
  function pull(ax, ay, bx, by, sigma) {
    var mx = bx - ax, my = by - ay, len2 = mx * mx + my * my;
    if (len2 < 1e-6) return;
    var s2 = sigma * sigma, reach = sigma * 3;
    var minX = Math.min(ax, bx) - reach, maxX = Math.max(ax, bx) + reach;
    var minY = Math.min(ay, by) - reach, maxY = Math.max(ay, by) + reach;
    for (var n = 0; n < curves.length; n++) {
      var p = curves[n].p, hit = false;
      for (var i = 0; i < p.length; i += 2) {
        var x = p[i], y = p[i + 1];
        if (x < minX || x > maxX || y < minY || y > maxY) continue;
        hit = true;
        var t = ((x - ax) * mx + (y - ay) * my) / len2;
        t = t < 0 ? 0 : t > 1 ? 1 : t;
        var dx = x - (ax + mx * t), dy = y - (ay + my * t);
        var w = Math.exp(-(dx * dx + dy * dy) / s2);
        p[i] += mx * w; p[i + 1] += my * w;
      }
      /* 引かれて伸びた所はすぐ点を足す（後でまとめて足すと、直線の弦が出る） */
      if (hit) resample(curves[n]);
    }
  }

  /* 伸びた所に点を足し、詰まった所の点を間引く */
  function resample(c) {
    var p = c.p, n = p.length >> 1, out = [], maxSeg = 5, minSeg = 1.4;
    var lx = p[0], ly = p[1];
    out.push(lx, ly);
    for (var i = 1; i <= n; i++) {
      var k = (i % n) * 2, x = p[k], y = p[k + 1];
      var dx = x - lx, dy = y - ly, d = Math.sqrt(dx * dx + dy * dy);
      if (d > maxSeg) {
        var m = Math.ceil(d / maxSeg);
        for (var s = 1; s < m; s++) out.push(lx + dx * s / m, ly + dy * s / m);
      }
      if (i === n) break;
      if (d < minSeg) continue;
      out.push(x, y); lx = x; ly = y;
    }
    c.p = out;
  }

  function startDrop(x, y, area, mine) {
    var c = circle(x, y, 1, mine);
    drops.push({ x: x, y: y, left: area, rate: area / 1.4, curve: c, hold: false });
    return drops[drops.length - 1];
  }

  /* 同じ場所に何滴か続けて落とす（的のような同心の輪になる） */
  function dropSet(x, y) {
    var k = 2 + Math.floor(Math.random() * 3), base = Math.min(W, H) * (0.05 + Math.random() * 0.05);
    for (var i = 0; i < k; i++) queue.push({ x: x, y: y, area: base * base * (0.6 + Math.random() * 0.8), at: clock + i * 1.1 });
  }

  function seed() {
    curves.length = 0; drops.length = 0; queue.length = 0; combs.length = 0;
    /* 開いた瞬間から模様が見えるように、何滴か一度に落としておく */
    for (var s = 0; s < 9; s++) {
      var x = W * (0.12 + Math.random() * 0.76), y = H * (0.15 + Math.random() * 0.7);
      var k = 2 + Math.floor(Math.random() * 3), base = Math.min(W, H) * (0.05 + Math.random() * 0.06);
      for (var i = 0; i < k; i++) {
        var a = base * base * (0.6 + Math.random() * 0.8);
        spread(x, y, a, null);
        circle(x, y, Math.sqrt(a), false);
      }
    }
    for (var n = 0; n < curves.length; n++) resample(curves[n]);
    nextDrop = 2.5;
    nextComb = 9;
  }

  /* 自動の櫛: 数本の歯が平行に並び、ゆっくり画面を横切る */
  function startComb() {
    var horiz = Math.random() < 0.5, teeth = 4 + Math.floor(Math.random() * 4);
    var dir = Math.random() < 0.5 ? 1 : -1;
    combs.push({ horiz: horiz, teeth: teeth, dir: dir, t: 0, dur: 9 + Math.random() * 4,
      wave: Math.random() < 0.5 ? 0 : (0.6 + Math.random()) , ph: Math.random() * 6.28, last: null });
  }
  function combPoints(cb, t) {
    var pts = [], span = (cb.horiz ? H : W);
    var u = cb.dir > 0 ? t : 1 - t;
    for (var i = 0; i < cb.teeth; i++) {
      var off = span * (i + 0.5) / cb.teeth;
      var along = (cb.horiz ? W : H) * (-0.1 + 1.2 * u);
      var wob = cb.wave * span / cb.teeth * 0.35 * Math.sin(u * 9 + cb.ph);
      pts.push(cb.horiz ? [along, off + wob] : [off + wob, along]);
    }
    return pts;
  }

  /* ---------------------------------------------------------- 描く */
  function draw() {
    P.fill(ctx, W, H);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.5 });
    ctx.lineJoin = 'round';
    for (var n = 0; n < curves.length; n++) {
      var c = curves[n], p = c.p;
      if (p.length < 6) continue;
      ctx.beginPath();
      ctx.moveTo(p[0], p[1]);
      for (var i = 2; i < p.length; i += 2) ctx.lineTo(p[i], p[i + 1]);
      ctx.closePath();
      /* 輪の内側をうっすら塗る。重なるほど明るくなり、インクの層が見える */
      ctx.fillStyle = P.rgba(P.line, 0.035 * c.fade);
      ctx.fill();
      var m = Math.min(1, c.mine * 1.6);
      var col = m > 0 ? P.mix(P.line, P.accent, m) : P.line;
      ctx.strokeStyle = P.rgba(col, (0.5 + 0.4 * m) * c.fade);
      ctx.lineWidth = 1 + m * 0.4;
      ctx.stroke();
    }
  }

  /* ---------------------------------------------------------- 操作 */
  var down = false, last = null, moved = 0, held = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true; last = { x: e.clientX, y: e.clientY }; moved = 0;
    var base = Math.min(W, H) * 0.06;
    held = startDrop(last.x, last.y, base * base, true);
    held.hold = true;
    SFX.play('spawn', 200);
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var p = { x: e.clientX, y: e.clientY };
    var d = Math.hypot(p.x - last.x, p.y - last.y);
    if (d < 2) return;
    moved += d;
    if (moved > 12 && held) { held.hold = false; held = null; }
    var n = Math.ceil(d / 3);
    for (var i = 0; i < n; i++) {
      pull(last.x + (p.x - last.x) * i / n, last.y + (p.y - last.y) * i / n,
           last.x + (p.x - last.x) * (i + 1) / n, last.y + (p.y - last.y) * (i + 1) / n, 16);
    }
    last = p;
    SFX.play('move', 150);
  });
  function up() { down = false; if (held) { held.hold = false; held = null; } }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  document.getElementById('k-drip').addEventListener('input', function () { drip = this.value / 100; });
  document.querySelectorAll('[data-comb]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-comb]').forEach(function (o) { o.classList.toggle('active', o === b); });
      combOn = b.dataset.comb === 'on';
      SFX.play('toggle');
    });
  });
  document.getElementById('k-clear').addEventListener('click', function () { seed(); SFX.play('click'); });
  drip = document.getElementById('k-drip').value / 100;
  combOn = !!document.querySelector('[data-comb="on"].active');

  /* ---------------------------------------------------------- 回す */
  var nextDrop = 2.5, nextComb = 9, lastT = performance.now();
  function frame(now) {
    var dt = Math.min(0.05, (now - lastT) / 1000);
    lastT = now;
    clock += dt;

    /* 自動の滴 */
    nextDrop -= dt * (0.3 + drip * 1.6);
    if (nextDrop <= 0) {
      nextDrop = 4 + Math.random() * 3;
      dropSet(W * (0.1 + Math.random() * 0.8), H * (0.12 + Math.random() * 0.76));
    }
    for (var q = queue.length - 1; q >= 0; q--) {
      if (queue[q].at <= clock) {
        startDrop(queue[q].x, queue[q].y, queue[q].area, false);
        if (Math.random() < 0.5) SFX.play('ripple', 2500);
        queue.splice(q, 1);
      }
    }
    /* 広がっている滴を少しずつ押し広げる */
    for (var d = drops.length - 1; d >= 0; d--) {
      var dr = drops[d];
      var a = dr.hold ? dr.rate * 0.5 * dt : Math.min(dr.left, Math.max(dr.rate * dt, dr.left * dt * 2.2));
      if (!dr.hold) dr.left -= a;
      spread(dr.x, dr.y, a, null);
      if (!dr.hold && dr.left <= dr.rate * 0.01) drops.splice(d, 1);
    }
    /* 自動の櫛 */
    if (combOn) {
      nextComb -= dt;
      if (nextComb <= 0 && !combs.length) { startComb(); nextComb = 16 + Math.random() * 10; }
    }
    for (var b = combs.length - 1; b >= 0; b--) {
      var cb = combs[b];
      cb.t += dt / cb.dur;
      var pts = combPoints(cb, Math.min(1, cb.t));
      if (cb.last) for (var k = 0; k < pts.length; k++) {
        var ax = cb.last[k][0], ay = cb.last[k][1], bx = pts[k][0], by = pts[k][1];
        var ns = Math.max(1, Math.ceil(Math.hypot(bx - ax, by - ay) / 3));
        for (var st = 0; st < ns; st++) pull(ax + (bx - ax) * st / ns, ay + (by - ay) * st / ns, ax + (bx - ax) * (st + 1) / ns, ay + (by - ay) * (st + 1) / ns, 14);
      }
      cb.last = pts;
      if (cb.t >= 1) combs.splice(b, 1);
    }

    /* 点の並びを整え、画面の外へ出た輪と古すぎる輪を消す */
    var total = 0;
    for (var n = curves.length - 1; n >= 0; n--) {
      var c = curves[n];
      resample(c);
      if (c.mine > 0) c.mine = Math.max(0, c.mine - dt * 0.12);
      var p = c.p, minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9;
      for (var i = 0; i < p.length; i += 2) {
        if (p[i] < minX) minX = p[i]; if (p[i] > maxX) maxX = p[i];
        if (p[i + 1] < minY) minY = p[i + 1]; if (p[i + 1] > maxY) maxY = p[i + 1];
      }
      var off = minX > W + 20 || maxX < -20 || minY > H + 20 || maxY < -20;
      /* 画面を何周りも覆うほど大きくなった輪は、ゆっくり薄れて退場する */
      if (off || c.dying || (minX < -W * 0.6 && maxX > W * 1.6 && minY < -H * 0.6 && maxY > H * 1.6)) {
        c.dying = true;
        c.fade -= dt * 0.5;
        if (c.fade <= 0 || off) { curves.splice(n, 1); continue; }
      }
      if (!c.dying) total += p.length;
    }
    /* 点が増えすぎたら、いちばん古い輪から退場させる */
    if (total > 55000) for (n = 0; n < curves.length; n++) if (!curves[n].dying) { curves[n].dying = true; break; }

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
