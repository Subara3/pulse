<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('tendril');

$pageName = 'tendril';
$pageTitle = 'TENDRIL - PULSE';
$pageDesc = '画面の端からつるが伸びてきて、ところどころで葉を開き、細い巻きひげを渦の形に巻いていく。古いつるは薄れて消え、また新しいつるが伸びてくる。押すとそこから水色のつるが何本も伸びて、まわりのつるが指のほうへ寄る。なぞると指の跡に沿ってつるが伸び、葉と巻きひげがついていく。';
$pageCanonical = 'https://pulse.subara3.com/tendril.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="45"></div>
  <div class="ctl-row"><label for="k-dense">DENSITY</label><input type="range" id="k-dense" min="0" max="100" value="50"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* つるは点の列。茎は曲がり具合がゆっくり揺れ、巻きひげは進むほど曲がりが強くなって渦に巻く */
  var vines = [];
  var sc = 1, STEP = 4, R = 110;
  var speed = 0.45, dense = 0.5;
  var time = 0, totalPts = 0, capWait = 0, warm = 1;
  var px = new Float32Array(4096), py = new Float32Array(4096);   /* 揺らしたあとの座標。描くたびに使い回す */
  var pull = { x: 0, y: 0, s: 0, v: 0, on: false };   /* 指のまわりのつるを寄せる力。離すとばねで揺れ戻る */
  var lead = null;                                     /* なぞっているあいだ指についてくるつる */

  function rnd(a, b) { return a + Math.random() * (b - a); }

  function makeVine(x, y, a, kind, depth, maxLen, root) {
    var cap = Math.max(16, Math.ceil(maxLen / STEP) + 8);
    var v = {
      xs: new Float32Array(cap), ys: new Float32Array(cap), n: 0, cap: cap,
      x: x, y: y, a: a, k: 0, kT: 0, kTimer: 0,
      kind: kind, depth: depth, len: 0, maxLen: maxLen, acc: 0,
      leafAcc: rnd(10, 30) * sc, branchAcc: rnd(30, 70) * sc, side: Math.random() < 0.5 ? 1 : -1,
      leaves: [], done: false, life: 0, hold: rnd(10, 18), alpha: 1, glow: 0,
      sgn: Math.random() < 0.5 ? 1 : -1, k0: 0, kc: 0, spd: 1, follow: false, kids: []
    };
    v.root = root || v;
    if (root) root.kids.push(v);
    if (kind === 1) {
      /* 巻きひげ: 曲率を進んだ長さに比例して強める（クロソイド）。最後は半径 3px ほどの渦になる */
      v.k0 = v.sgn / (45 * sc);
      v.kc = (1 / (3 * sc) - 1 / (45 * sc)) / maxLen * v.sgn;
      v.spd = 0.75;
    }
    push(v, x, y);
    vines.push(v);
    return v;
  }

  function push(v, x, y) {
    if (v.n >= v.cap) {
      var c = v.cap * 2, nx = new Float32Array(c), ny = new Float32Array(c);
      nx.set(v.xs); ny.set(v.ys); v.xs = nx; v.ys = ny; v.cap = c;
    }
    v.xs[v.n] = x; v.ys[v.n] = y; v.n++; totalPts++;
  }

  function spawnRoot() {
    /* 長い辺ほど選ばれやすくする。縦長の画面では左右から伸びて真ん中まで届く */
    var u = Math.random() * (W + H), e = u < W / 2 ? 0 : u < W ? 2 : u < W + H / 2 ? 1 : 3, x, y, a;
    if (e === 0) { x = rnd(0, W); y = -4; a = Math.PI / 2; }
    else if (e === 1) { x = W + 4; y = rnd(0, H); a = Math.PI; }
    else if (e === 2) { x = rnd(0, W); y = H + 4; a = -Math.PI / 2; }
    else { x = -4; y = rnd(0, H); a = 0; }
    a += rnd(-0.6, 0.6);
    var v = makeVine(x, y, a, 0, 0, rnd(0.55, 1.0) * Math.max(W, H), null);
    v.kT = rnd(-1, 1) / (70 * sc);
    return v;
  }

  /* 1 歩進むたびに、葉と枝分かれの間合いを数える */
  function sprout(v) {
    v.leafAcc -= STEP;
    if (v.leafAcc <= 0) {
      v.leafAcc = rnd(26, 46) * sc;
      v.side = -v.side;
      v.leaves.push({ i: v.n - 1, side: v.side, size: rnd(7, 12) * sc * (1 - v.depth * 0.18), g: 0 });
    }
    v.branchAcc -= STEP;
    if (v.branchAcc <= 0) {
      v.branchAcc = rnd(55, 95) * sc;
      var r = Math.random();
      if (r < 0.6) {
        makeVine(v.x, v.y, v.a + rnd(0.4, 0.9) * (Math.random() < 0.5 ? 1 : -1), 1, v.depth + 1, rnd(60, 130) * sc, v.root).glow = v.glow;
      } else if (r < 0.85 && v.depth < 2 && totalPts < 26000) {
        var b = makeVine(v.x, v.y, v.a + rnd(0.5, 1.0) * (Math.random() < 0.5 ? 1 : -1), 0, v.depth + 1,
          Math.max(0, v.maxLen - v.len) * rnd(0.3, 0.6) + 40 * sc, v.root);
        b.kT = rnd(-1, 1) / (60 * sc); b.glow = v.glow; b.spd = v.spd;
      }
    }
  }

  function grow(v, dt) {
    if (v.done || v.follow) return;
    v.acc += 42 * sc * (0.25 + speed * 1.6) * v.spd * warm * dt;
    var margin = 30 * sc;
    while (v.acc >= STEP) {
      v.acc -= STEP;
      if (v.kind === 1) {
        v.k = v.k0 + v.kc * v.len;
      } else {
        /* 画面の外へ向かいかけたら内側へ曲げる */
        var steer = 0;
        if (v.x < margin || v.x > W - margin || v.y < margin || v.y > H - margin) {
          var want = Math.atan2(H / 2 - v.y, W / 2 - v.x), d = want - v.a;
          d = Math.atan2(Math.sin(d), Math.cos(d));
          steer = d * 0.02;
        }
        /* 押さえているあいだは、近くの先端が指へ向かう */
        if (pull.on && v.glow < 0.3) {
          var dx = pull.x - v.x, dy = pull.y - v.y, dd = Math.hypot(dx, dy);
          if (dd < R * 2.6 && dd > 6) {
            var w = Math.atan2(dy, dx) - v.a;
            w = Math.atan2(Math.sin(w), Math.cos(w));
            steer += w * 0.05 * (1 - dd / (R * 2.6));
          }
        }
        v.k += (v.kT - v.k) * 0.03;
        v.a += steer;
      }
      v.a += v.k * STEP;
      v.x += Math.cos(v.a) * STEP;
      v.y += Math.sin(v.a) * STEP;
      v.len += STEP;
      push(v, v.x, v.y);
      if (v.kind === 0) sprout(v);
      if (v.len >= v.maxLen || v.x < -80 || v.x > W + 80 || v.y < -80 || v.y > H + 80) { v.done = true; break; }
    }
    if (v.kind === 0) {
      v.kTimer -= dt;
      if (v.kTimer <= 0) {
        v.kTimer = rnd(0.6, 1.6);
        v.kT = rnd(-1, 1) / (rnd(35, 90) * sc);
      }
    }
  }

  function anyGrowing(root) {
    if (!root.done) return true;
    for (var i = 0; i < root.kids.length; i++) if (!root.kids[i].done) return true;
    return false;
  }

  function update(dt) {
    time += dt;
    for (var i = 0; i < vines.length; i++) grow(vines[i], dt);

    /* 根ごとに寿命を数え、育ち終えてしばらくしたら一族ごと薄れて消える */
    var live = 0, oldest = null;
    for (i = 0; i < vines.length; i++) {
      var v = vines[i];
      if (v.glow > 0) v.glow = Math.max(0, v.glow - dt * 0.18);
      for (var l = 0; l < v.leaves.length; l++) if (v.leaves[l].g < 1) v.leaves[l].g = Math.min(1, v.leaves[l].g + dt * 0.9);
      if (v.root !== v) continue;
      if (v.fading) { v.alpha -= dt / 6; continue; }
      if (v.depth === 0) { live++; if (!oldest || v.born < oldest.born) oldest = v; }
      if (!v.follow && !anyGrowing(v)) {
        v.life += dt;
        if (v.life > v.hold) v.fading = true;
      }
    }
    /* 線で埋まりすぎたら、いちばん古いつるから薄れさせる */
    capWait -= dt;
    if (totalPts > 24000 && oldest && capWait <= 0) { oldest.fading = true; capWait = 3; }

    for (i = vines.length - 1; i >= 0; i--) {
      if (vines[i].root.alpha <= 0) { totalPts -= vines[i].n; vines.splice(i, 1); }
    }

    var target = Math.round((3 + dense * 7) * Math.min(1.6, Math.max(0.9, W * H / 900000)));
    if (live < target) {
      var r = spawnRoot(); r.born = time;
      if (Math.random() < 0.5) SFX.play('grow', 2500);
    }

    /* 寄せる力: 押している間は 1 へ、離すとばねで 0 へ戻り、少し揺れる */
    var tgt = pull.on ? 1 : 0;
    pull.v += ((tgt - pull.s) * 60 - pull.v * 6) * dt;
    pull.s += pull.v * dt;
  }

  var dX = 0, dY = 0;
  function disp(x, y) {
    /* 風でわずかに揺らし、指のまわりは指のほうへ寄せる */
    var A = 2.4 * sc;
    dX = x + A * Math.sin(time * 0.5 + y * 0.006);
    dY = y + A * 0.6 * Math.cos(time * 0.43 + x * 0.006);
    if (Math.abs(pull.s) > 0.002) {
      var ex = pull.x - x, ey = pull.y - y, d2 = ex * ex + ey * ey;
      if (d2 < R * R * 9) {
        var f = pull.s * 0.55 * Math.exp(-d2 / (R * R));
        dX += ex * f; dY += ey * f;
      }
    }
  }

  function draw() {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { step: 32, major: 4, alpha: 0.7 });
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    for (var i = 0; i < vines.length; i++) {
      var v = vines[i];
      if (v.n < 2) continue;
      var al = Math.max(0, Math.min(1, v.root.alpha));
      var col = v.glow > 0 ? P.mix(P.line, P.accent, Math.min(1, v.glow * 1.4)) : P.line;
      var base = v.kind === 1 ? 0.55 : (0.9 - v.depth * 0.15);
      ctx.strokeStyle = P.rgba(col, base * al);
      ctx.lineWidth = v.kind === 1 ? 0.8 : Math.max(0.8, 1.7 - v.depth * 0.4);
      var xs = v.xs, ys = v.ys, n = v.n;
      if (px.length < n) { px = new Float32Array(n * 2); py = new Float32Array(n * 2); }
      ctx.beginPath();
      for (var j = 0; j < n; j++) {
        disp(xs[j], ys[j]); px[j] = dX; py[j] = dY;
        if (j) ctx.lineTo(dX, dY); else ctx.moveTo(dX, dY);
      }
      ctx.stroke();

      /* 葉: 茎の向きから横へ開く、細い輪郭と中央の筋 */
      if (v.leaves.length) {
        ctx.lineWidth = 0.9;
        ctx.strokeStyle = P.rgba(col, 0.75 * al);
        ctx.beginPath();
        for (var l = 0; l < v.leaves.length; l++) {
          var lf = v.leaves[l], k = lf.i;
          if (k >= n) continue;
          var k0 = Math.max(0, k - 2), k1 = Math.min(n - 1, k + 2);
          var ta = Math.atan2(py[k1] - py[k0], px[k1] - px[k0]) + lf.side * 0.95;
          var g = lf.g * (2 - lf.g), L = lf.size * g, wd = L * 0.38;
          var bx = px[k], by = py[k], ca = Math.cos(ta), sa = Math.sin(ta);
          var tx = bx + ca * L, ty = by + sa * L, mx = bx + ca * L * 0.5, my = by + sa * L * 0.5;
          ctx.moveTo(bx, by);
          ctx.quadraticCurveTo(mx - sa * wd, my + ca * wd, tx, ty);
          ctx.quadraticCurveTo(mx + sa * wd, my - ca * wd, bx, by);
          ctx.moveTo(bx, by); ctx.lineTo(bx + ca * L * 0.8, by + sa * L * 0.8);
        }
        ctx.stroke();
      }
      /* 伸びている先端に小さな芽 */
      if (!v.done && v.kind === 0) {
        ctx.fillStyle = P.rgba(col, 0.9 * al);
        ctx.beginPath(); ctx.arc(px[n - 1], py[n - 1], 1.7, 0, 6.2832); ctx.fill();
      }
    }
  }

  function init() {
    vines = []; totalPts = 0; lead = null; time = 0;
    sc = Math.max(0.65, Math.min(1.5, Math.sqrt(W * H) / 900));
    STEP = 4 * sc; R = 110 * sc;
    /* 開いた時点で、もう何本か伸びている状態にしておく */
    warm = 2.5;
    for (var i = 0; i < 160; i++) update(0.05);
    warm = 1;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, function (w, h) { W = w; H = h; init(); });

  /* ---------------------------------------------------------- 操作 */
  var down = false, lastP = null;
  canvas.addEventListener('pointerdown', function (e) {
    down = true;
    var x = e.clientX, y = e.clientY;
    lastP = { x: x, y: y };
    pull.x = x; pull.y = y; pull.on = true;
    /* 押した点から水色のつるが四方へ。1 本は指についてくる */
    var a0 = Math.random() * Math.PI * 2;
    var root = null;
    for (var i = 0; i < 4; i++) {
      var v = makeVine(x, y, a0 + i * Math.PI / 2 + rnd(-0.3, 0.3), 0, 1, rnd(150, 260) * sc, root);
      if (!root) { root = v; v.born = time; }
      v.glow = 1; v.spd = 3; v.kT = rnd(-1, 1) / (40 * sc); v.leafAcc = rnd(4, 12) * sc; v.branchAcc = rnd(16, 40) * sc;
      if (i) grow(v, 0.6 / (0.25 + speed * 1.6));   /* 押した瞬間に、もう指のまわりまで伸びている */
    }
    root.follow = true; lead = root;
    for (i = 0; i < 2; i++) makeVine(x, y, a0 + Math.PI / 4 + i * Math.PI, 1, 2, rnd(70, 110) * sc, root).glow = 1;
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    var x = e.clientX, y = e.clientY;
    pull.x = x; pull.y = y;
    if (!lead) return;
    var dx = x - lead.x, dy = y - lead.y, d = Math.hypot(dx, dy);
    if (d < STEP) return;
    var a = Math.atan2(dy, dx), n = Math.floor(d / STEP);
    for (var i = 0; i < n; i++) {
      lead.a = a;
      lead.x += Math.cos(a) * STEP; lead.y += Math.sin(a) * STEP;
      lead.len += STEP;
      push(lead, lead.x, lead.y);
      sprout(lead);
    }
    lead.glow = 1;
    SFX.play('move', 160);
  });
  function up() {
    if (!down) return;
    down = false; pull.on = false;
    if (lead) {
      /* 離したあとは、指の向きのまま自分で伸び続ける */
      lead.follow = false; lead.maxLen = lead.len + rnd(160, 260) * sc; lead.done = false;
      lead.k = 0; lead.kT = rnd(-1, 1) / (50 * sc);
      lead = null;
    }
  }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  var kSpeed = document.getElementById('k-speed'), kDense = document.getElementById('k-dense');
  kSpeed.addEventListener('input', function () { speed = this.value / 100; });
  kDense.addEventListener('input', function () { dense = this.value / 100; });
  /* 共有リンクから開いたとき、復元された値を拾う */
  speed = kSpeed.value / 100;
  dense = kDense.value / 100;

  /* ---------------------------------------------------------- 回す */
  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
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
