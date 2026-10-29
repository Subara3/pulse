<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/access_log.php';
recordPageView('siteswap');

$pageName = 'siteswap';
$pageTitle = 'SITESWAP - PULSE';
$pageDesc = '左右の手が交互に玉を投げ上げ、玉は高さの違う放物線を描いて反対の手や同じ手に落ちる。投げる高さの並びと玉の数はしばらくごとに替わり、通った跡の線が何本も重なって、高さの違う山形が並ぶ。押すと近くの放物線が指のほうへ曲がって水色に光り、次に投げる玉はその高さまで上がる。なぞると曲がった放物線が指についてくる。';
$pageCanonical = 'https://pulse.subara3.com/siteswap.php';
$extraStyle = '';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/floating-nav.php';
?>
<canvas id="canvas"></canvas>

<div id="controls" class="drawer-controls">
  <div class="ctl-row"><label for="k-speed">SPEED</label><input type="range" id="k-speed" min="0" max="100" value="40"></div>
  <div class="ctl-row"><label for="k-trail">TRAIL</label><input type="range" id="k-trail" min="0" max="100" value="55"></div>
</div>
<?php require_once __DIR__ . '/includes/drawer-controls.php'; ?>
<?php require_once __DIR__ . '/includes/colors.php'; ?>
<script>
(function () {
  'use strict';
  var canvas = document.getElementById('canvas');
  var W = 0, H = 0, ctx;

  /* 時間は「拍」で数える。拍ごとに左右の手が交互に 1 回ずつ投げる。
     高さ h で投げた玉は h 拍あとにまた投げられる。奇数なら反対の手、偶数なら同じ手に落ちる */
  var DW = 0.6;          /* 手の中にいる長さ（拍） */
  var HMAX = 9;
  var PATTERNS = [
    [3], [4, 2, 3], [5, 3, 1], [4, 4, 1], [5, 1],
    [4], [5, 3], [5, 5, 2], [6, 4, 5, 1], [7, 3, 4, 2], [7, 5, 3, 1],
    [5], [6, 4], [7, 4, 4], [9, 7, 5, 3, 1],
    [7], [8, 6], [9, 7, 5]
  ];
  var pat = PATTERNS[2], patN = 3, patI = 0, patClock = 0;
  var balls = [];
  var tau = 0, beat = 0;          /* tau: 経った拍。beat: 次に投げる拍の番号 */
  var beatSec = 0.32, trailLife = 5;
  var hs = 1, hsGoal = 1, peakH = 3;
  var cx, base, spread, reach, top, rb, dpr = 1;
  var trail, tctx;
  var down = false, fx = 0, fy = 0, lastRetire = -9;

  function par(k) { return ((k % 2) + 2) % 2; }
  function avg(p) { var s = 0; for (var i = 0; i < p.length; i++) s += p[i]; return s / p.length; }

  function layout(w, h, d) {
    W = w; H = h; dpr = d || 1;
    cx = W / 2;
    base = H * 0.84;
    top = H * 0.07;
    spread = Math.min(W * 0.3, H * 0.62);
    reach = spread * 0.22;
    rb = Math.max(3.5, Math.min(W, H) * 0.009);
    trail = document.createElement('canvas');
    trail.width = Math.round(W * dpr); trail.height = Math.round(H * dpr);
    tctx = trail.getContext('2d');
    tctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    tctx.lineCap = 'round';
    for (var i = 0; i < balls.length; i++) balls[i].lx = null;
    hs = hsGoal = (base - top) / ((peakH - DW) * (peakH - DW));
  }

  /* 手の位置。投げるのは内側、受けるのは外側。受けたら下へすくって内側へ運ぶ */
  function hand(hd, t, out) {
    var side = hd ? 1 : -1;
    var hx = cx + side * spread;
    var ix = hx - side * reach, ox = hx + side * reach;
    var ph = (((t - hd) % 2) + 2) % 2 / 2;
    var cph = (2 - DW) / 2, s, e;
    if (ph < cph) {
      s = ph / cph; e = s * s * (3 - 2 * s);
      out.x = ix + (ox - ix) * e; out.y = base - reach * 0.45 * Math.sin(Math.PI * s);
    } else {
      s = (ph - cph) / (1 - cph); e = s * s * (3 - 2 * s);
      out.x = ox + (ix - ox) * e; out.y = base + reach * 0.5 * Math.sin(Math.PI * s);
    }
    return out;
  }

  function busy(b) {
    for (var i = 0; i < balls.length; i++) if (!balls[i].gone && balls[i].next === b) return true;
    return false;
  }

  /* 決まった並びに近い高さのうち、落ちる拍が空いているものを選ぶ。玉が同じ拍に重ならない */
  function choose(k) {
    var want = pat[patI % pat.length];
    if (down) {
      var f = Math.max(0, Math.min(1, (base - fy) / (base - top)));
      want = Math.round(2 + f * (HMAX - 2));
    }
    var best = 0, bd = 99;
    for (var h = 1; h <= HMAX; h++) {
      if (busy(k + h)) continue;
      var d = Math.abs(h - want) + (h === 1 ? 0.6 : 0);
      if (d < bd) { bd = d; best = h; }
    }
    return best || 1;
  }

  function throwBall(b, k, h) {
    b.from = k; b.h = h; b.next = k + h;
    b.side = par(k) ? 1 : -1;
    b.w = b.w || 0;
    if (h > peakH) peakH = h;
  }

  function onBeat(k) {
    var hd = par(k), held = null;
    for (var i = 0; i < balls.length; i++) if (!balls[i].gone && balls[i].next === k) { held = balls[i]; break; }
    var live = 0;
    for (var j = 0; j < balls.length; j++) if (!balls[j].gone) live++;
    if (held) {
      if (live > patN && k - lastRetire > 3 && !down) {
        held.gone = true; held.goneAt = k; held.hand = hd; lastRetire = k;
      } else {
        throwBall(held, k, choose(k));
      }
    } else if (live < patN) {
      var nb = { born: k, w: 0, px: 0, py: 0, lx: null, ly: null };
      throwBall(nb, k, choose(k));
      balls.push(nb);
    }
    patI++;
  }

  function nextPattern() {
    var cand = [];
    for (var i = 0; i < PATTERNS.length; i++) {
      var p = PATTERNS[i], n = avg(p);
      if (p !== pat && Math.abs(n - patN) <= 1) cand.push(p);
    }
    pat = cand[(Math.random() * cand.length) | 0];
    patN = Math.round(avg(pat));
    patI = 0;
  }

  /* 玉の今の位置。飛んでいる間は放物線、手の中では手について動く */
  var tmpA = { x: 0, y: 0 }, tmpB = { x: 0, y: 0 };
  function flight(b, u, out) {
    var span = b.h - DW;
    hand(par(b.from), b.from, tmpA);
    hand(par(b.next), b.from + span, tmpB);
    var bow = 4 * u * (1 - u);
    out.x = tmpA.x + (tmpB.x - tmpA.x) * u;
    out.y = tmpA.y + (tmpB.y - tmpA.y) * u - Math.min(hs * span * span, base - top) * bow;
    if (b.h % 2 === 0) out.x += b.side * spread * 0.05 * b.h * bow;
    out.x += b.px * b.w * bow;
    out.y += b.py * b.w * bow;
    return out;
  }
  function where(b, t, out) {
    var u = (t - b.from) / (b.h - DW);
    if (u < 1) { out.fly = true; return flight(b, Math.max(0, u), out); }
    out.fly = false;
    return hand(par(b.next), t, out);
  }

  function seed() {
    for (var i = 0; i < 3; i++) {
      var b = { born: -9, w: 0, px: 0, py: 0, lx: null, ly: null };
      throwBall(b, i - 3, 3);
      balls.push(b);
    }
    tau = 0; beat = 0;
  }

  /* 状態をすべて宣言してから呼ぶ。P.fit はその場で一度コールバックを走らせる */
  ctx = P.fit(canvas, layout);
  seed();

  /* ---------------------------------------------------------- 操作 */
  function setFinger(e) { fx = e.clientX; fy = e.clientY; }
  canvas.addEventListener('pointerdown', function (e) {
    down = true; setFinger(e);
    SFX.play('spawn');
    canvas.setPointerCapture(e.pointerId);
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!down) return;
    setFinger(e);
    SFX.play('move', 160);
  });
  function up() { down = false; }
  canvas.addEventListener('pointerup', up);
  canvas.addEventListener('pointercancel', up);

  function readSpeed() { beatSec = 0.44 - document.getElementById('k-speed').value / 100 * 0.3; }
  function readTrail() { trailLife = 1.5 + document.getElementById('k-trail').value / 100 * 8; }
  document.getElementById('k-speed').addEventListener('input', readSpeed);
  document.getElementById('k-trail').addEventListener('input', readTrail);
  /* 共有リンクから開いたとき、復元された値を拾う */
  readSpeed(); readTrail();

  /* ---------------------------------------------------------- 描く */
  var pos = { x: 0, y: 0, fly: false }, q = { x: 0, y: 0 }, hp = { x: 0, y: 0 };
  var R = 0;

  function update(dt) {
    var db = dt / beatSec;
    tau += db;
    while (tau >= beat) { onBeat(beat); beat++; }
    patClock += dt;
    if (patClock > 15) { patClock = 0; nextPattern(); }

    /* 高さの縮尺: いま投げている最も高い玉が画面の上まで届くように、ゆっくり合わせる */
    peakH = Math.max(3, peakH - db * 0.05);
    var sp = peakH - DW;
    hsGoal = (base - top) / (sp * sp);
    hs += (hsGoal - hs) * Math.min(1, dt * (hsGoal < hs ? 5 : 0.8));

    R = Math.max(W, H) * 0.32;
    for (var i = balls.length - 1; i >= 0; i--) {
      var b = balls[i];
      if (b.gone && tau - b.goneAt > 1.2) { balls.splice(i, 1); continue; }
      var target = 0;
      if (down && !b.gone && tau < b.from + b.h - DW) {
        var ap = flight(b, 0.5, q);
        var ax = ap.x - b.px * b.w, ay = ap.y - b.py * b.w;
        var d = Math.hypot(fx - ax, fy - ay);
        target = Math.exp(-(d * d) / (R * R));
        /* 指が速く動いても線が折れないよう、引く向きは少し遅れて追う */
        var kf = b.w < 0.05 ? 1 : Math.min(1, dt * 12);
        b.px += (fx - ax - b.px) * kf; b.py += (fy - ay - b.py) * kf;
      }
      b.w += (target - b.w) * Math.min(1, dt * (target > b.w ? 9 : 2.5));
    }
  }

  function draw(dt) {
    P.fill(ctx, W, H, 1);
    P.grid(ctx, W, H, { alpha: 0.7 });

    /* 通った跡: 別の層に描き足し、少しずつ消す */
    tctx.globalCompositeOperation = 'destination-out';
    tctx.fillStyle = P.rgba(P.bg, Math.min(1, dt * 3 / trailLife));
    tctx.fillRect(0, 0, W, H);
    tctx.globalCompositeOperation = 'source-over';
    for (var i = 0; i < balls.length; i++) {
      var b = balls[i];
      if (b.gone) continue;
      where(b, tau, pos);
      if (b.lx !== null && pos.fly) {
        tctx.strokeStyle = b.w > 0.15 ? P.rgba(P.mix(P.line, P.accent, b.w), 0.5) : P.rgba(P.line, 0.32);
        tctx.lineWidth = 1.1;
        tctx.beginPath(); tctx.moveTo(b.lx, b.ly); tctx.lineTo(pos.x, pos.y); tctx.stroke();
      }
      b.lx = pos.x; b.ly = pos.y;
    }
    ctx.drawImage(trail, 0, 0, W, H);

    /* 飛んでいる玉の、これから通る放物線 */
    ctx.lineWidth = 1;
    for (var j = 0; j < balls.length; j++) {
      var c = balls[j];
      if (c.gone) continue;
      var u0 = (tau - c.from) / (c.h - DW);
      if (u0 >= 1) continue;
      ctx.strokeStyle = c.w > 0.05 ? P.rgba(P.accent, 0.2 + c.w * 0.45) : P.rgba(P.steel, 0.3);
      ctx.beginPath();
      for (var s = 0; s <= 24; s++) {
        var u = Math.max(0, u0) + (1 - Math.max(0, u0)) * s / 24;
        flight(c, u, q);
        if (s) ctx.lineTo(q.x, q.y); else ctx.moveTo(q.x, q.y);
      }
      ctx.stroke();
    }

    /* 手: 鋼色の小さな受け皿 */
    ctx.strokeStyle = P.rgba(P.steel, 0.85);
    ctx.lineWidth = 1.6;
    for (var hd = 0; hd < 2; hd++) {
      hand(hd, tau, hp);
      ctx.beginPath();
      ctx.arc(hp.x, hp.y + rb * 0.6, rb * 2.6, Math.PI * 0.15, Math.PI * 0.85);
      ctx.stroke();
    }

    /* 玉 */
    for (var n = 0; n < balls.length; n++) {
      var e = balls[n];
      var a = 1;
      if (e.gone) {
        hand(par(e.goneAt), e.goneAt, pos);
        var k = Math.min(1, (tau - e.goneAt) / 1.2);
        pos.y += k * rb * 6;
        a = 1 - k;
      } else {
        where(e, tau, pos);
        if (e.born >= 0) a = Math.min(1, (tau - e.born) / 1.5);
      }
      if (a <= 0) continue;
      ctx.fillStyle = P.rgba(P.mix(P.line, P.accent, Math.min(1, e.w * 1.4)), a);
      ctx.beginPath(); ctx.arc(pos.x, pos.y, rb, 0, Math.PI * 2); ctx.fill();
      if (e.w > 0.1) {
        ctx.strokeStyle = P.rgba(P.accent, e.w * 0.5 * a);
        ctx.lineWidth = 1;
        ctx.beginPath(); ctx.arc(pos.x, pos.y, rb * 2.4, 0, Math.PI * 2); ctx.stroke();
      }
    }
  }

  var lastT = performance.now();
  function frame(now) {
    var dt = Math.max(0, Math.min(0.05, (now - lastT) / 1000));   /* 最初の数フレームは負になることがある */
    lastT = now;
    update(dt);
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
